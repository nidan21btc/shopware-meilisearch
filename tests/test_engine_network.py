"""Exercise the PHP search client against local proxy and redirect traps."""
import http.server
import json
import os
import subprocess
import socketserver
import tempfile
import threading
import unittest
from pathlib import Path

ROOT = Path(os.environ.get('SHOPWARE_ROOT', str(Path(__file__).resolve().parents[4])))
ENGINE = Path(__file__).resolve().parents[1] / 'src/Search/Engine.php'
PHP = r'''
require $argv[1];
require $argv[2];
$engine = new ShopwareMeilisearch\Search\Engine(Symfony\Component\HttpClient\HttpClient::create(), $argv[3], 'test-only-secret', $argv[5] ?? '');
try {
    $value = $engine->request('GET', $argv[4]);
    echo json_encode(['ok' => true, 'response' => $value]);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'class' => get_class($error)]);
}
'''


class EngineNetworkTest(unittest.TestCase):
    def setUp(self):
        self.origin_requests = []
        self.proxy_requests = []
        self.redirect_requests = []
        sink_requests = self.redirect_requests
        proxy_requests = self.proxy_requests

        class Sink(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                sink_requests.append(self.path)
                self.send_response(200); self.end_headers()
            def log_message(self, *args):
                pass

        class Proxy(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                proxy_requests.append(self.path)
                self.send_response(502); self.end_headers()
            def log_message(self, *args):
                pass

        self.sink = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Sink)
        self.proxy = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Proxy)
        origin_requests = self.origin_requests
        redirect_to = 'http://127.0.0.1:%d/trap' % self.sink.server_port

        class Origin(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                origin_requests.append((self.path, self.headers.get('Authorization')))
                if self.path == '/redirect':
                    self.send_response(302); self.send_header('Location', redirect_to); self.end_headers()
                else:
                    body = b'{"ok":true}'
                    self.send_response(200); self.send_header('Content-Type', 'application/json')
                    self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
            def log_message(self, *args):
                pass

        self.origin = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Origin)
        self.servers = [self.origin, self.proxy, self.sink]
        self.threads = [threading.Thread(target=server.serve_forever, daemon=True) for server in self.servers]
        for thread in self.threads:
            thread.start()

    def tearDown(self):
        for server in self.servers:
            server.shutdown(); server.server_close()
        for thread in self.threads:
            thread.join()

    def request(self, path, socket_path=""):
        env = os.environ.copy()
        proxy = 'http://127.0.0.1:%d' % self.proxy.server_port
        env.update({'HTTP_PROXY': proxy, 'http_proxy': proxy, 'HTTPS_PROXY': proxy, 'https_proxy': proxy,
                    'ALL_PROXY': proxy, 'all_proxy': proxy, 'NO_PROXY': '', 'no_proxy': ''})
        process = subprocess.run(['php', '-r', PHP, str(ROOT / 'vendor/autoload.php'), str(ENGINE),
                                  'http://127.0.0.1:%d' % self.origin.server_port, path, socket_path],
                                 env=env, capture_output=True, text=True, check=True, timeout=30)
        return json.loads(process.stdout)

    def test_requests_bypass_environment_proxy(self):
        self.assertEqual(self.request('/health'), {'ok': True, 'response': {'ok': True}})
        self.assertEqual(self.origin_requests, [('/health', 'Bearer test-only-secret')])
        self.assertEqual(self.proxy_requests, [])

    def test_unix_socket_is_used_without_tcp_fallback(self):
        requests = []
        class UnixServer(socketserver.UnixStreamServer):
            allow_reuse_address = True
        class UnixHandler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                requests.append((self.path, self.headers.get('Authorization')))
                body = b'{"ok":true}'
                self.send_response(200); self.send_header('Content-Length', str(len(body)))
                self.end_headers(); self.wfile.write(body)
            def log_message(self, *args):
                pass
            def address_string(self):
                return 'local'
        with tempfile.TemporaryDirectory(prefix='el-sock-', dir='/tmp') as directory:
            path = os.path.join(directory, 'search.sock')
            server = UnixServer(path, UnixHandler)
            thread = threading.Thread(target=server.serve_forever, daemon=True); thread.start()
            try:
                self.assertEqual(self.request('/health', path), {'ok': True, 'response': {'ok': True}})
                self.assertEqual(requests, [('/health', 'Bearer test-only-secret')])
                self.assertEqual(self.origin_requests, [])
                self.assertEqual(self.proxy_requests, [])
                self.assertFalse(self.request('/health', os.path.join(directory, 'missing.sock'))['ok'])
                regular = os.path.join(directory, 'regular'); Path(regular).touch()
                self.assertFalse(self.request('/health', regular)['ok'])
                link = os.path.join(directory, 'link'); os.symlink(path, link)
                self.assertFalse(self.request('/health', link)['ok'])
                self.assertEqual(self.origin_requests, [], 'Invalid sockets must not fall back to TCP')
            finally:
                server.shutdown(); server.server_close(); thread.join()

    def test_redirect_does_not_forward_credentials(self):
        self.assertFalse(self.request('/redirect')['ok'])
        self.assertEqual(self.origin_requests, [('/redirect', 'Bearer test-only-secret')])
        self.assertEqual(self.proxy_requests, [])
        self.assertEqual(self.redirect_requests, [])


if __name__ == '__main__':
    unittest.main()
