import importlib.util
import os
import subprocess
import sys
import threading
import unittest
from unittest.mock import patch
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

TOOLS = Path(__file__).resolve().parents[1] / 'tools'
spec = importlib.util.spec_from_file_location('local_http', TOOLS / 'local_http.py')
local = importlib.util.module_from_spec(spec)
spec.loader.exec_module(local)

class LocalHttpTest(unittest.TestCase):
    def test_only_loopback_origins_are_accepted(self):
        for origin in ('http://127.0.0.1:7700/', 'https://[::1]:7700'):
            self.assertEqual(local.local_origin(origin), origin.rstrip('/'))
        for origin in ('http://example.com', 'http://127.0.0.1.example.com', 'http://localhost:7700',
                       'http://user@127.0.0.1', 'http://127.0.0.1/path', 'http://127.0.0.1/?q=secret',
                       'http://127.0.0.1/#x', 'http://127.0.0.1/?', 'http://127.0.0.1/#',
                       'http://127.0.0.1:0', 'http://127.0.0.1:99999'):
            with self.subTest(origin=origin), self.assertRaises(ValueError): local.local_origin(origin)

    def test_local_redirect_is_not_followed(self):
        requests = []
        class Handler(BaseHTTPRequestHandler):
            def do_GET(self):
                requests.append(self.path)
                self.send_response(302); self.send_header('Location', '/would-leak'); self.end_headers()
            def log_message(self, *args): pass
        server = HTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True); thread.start()
        try:
            url = 'http://127.0.0.1:' + str(server.server_port) + '/search?q=synthetic'
            with self.assertRaises(RuntimeError): local.opener().open(url, timeout=3)
            self.assertEqual(requests, ['/search?q=synthetic'])
        finally:
            server.shutdown(); thread.join(); server.server_close()

    def test_environment_proxy_is_not_used(self):
        requests = []
        class Handler(BaseHTTPRequestHandler):
            def do_GET(self):
                requests.append(self.server.server_port)
                self.send_response(200); self.end_headers(); self.wfile.write(b'ok')
            def log_message(self, *args): pass
        target = HTTPServer(('127.0.0.1', 0), Handler)
        proxy = HTTPServer(('127.0.0.1', 0), Handler)
        threads = [threading.Thread(target=s.serve_forever, daemon=True) for s in (target, proxy)]
        for thread in threads: thread.start()
        try:
            proxy_url = 'http://127.0.0.1:' + str(proxy.server_port)
            with patch.dict(os.environ, {'http_proxy': proxy_url, 'HTTP_PROXY': proxy_url, 'no_proxy': '', 'NO_PROXY': ''}):
                with local.opener().open('http://127.0.0.1:' + str(target.server_port) + '/search', timeout=3) as response:
                    self.assertEqual(response.read(), b'ok')
            self.assertEqual(requests, [target.server_port])
        finally:
            for server in (target, proxy): server.shutdown(); server.server_close()
            for thread in threads: thread.join()

    def test_all_data_sending_clis_reject_external_destinations(self):
        fake = '/nonexistent-test-input'
        commands = [
            ('evaluate_meilisearch.py', ['--benchmark', fake, '--index-map', fake, '--output', fake, '--url', 'http://example.invalid']),
            ('evaluate_typo_tolerance.py', ['--index-map', fake, '--url', 'http://example.invalid']),
            ('snapshot_index_state.py', ['--index-map', fake, '--output', fake, '--url', 'http://example.invalid']),
            ('capture_shopware.py', ['--queries', fake, '--output', fake, '--url', 'http://example.invalid']),
            ('evaluate_shopware.py', ['--raw', fake, '--mapped', fake, '--output', fake, '--url', 'http://example.invalid']),
            ('review_zero_results.py', ['--benchmark', fake, '--mapped', fake, '--indexes', fake, '--output', fake,
                                        '--shop', 'http://example.invalid', '--engine', 'http://127.0.0.1:7700', '--host', 'test.local']),
        ]
        for script, args in commands:
            with self.subTest(script=script):
                env = os.environ.copy(); env['SHOPWARE_MEILISEARCH_KEY'] = 'synthetic-key'
                result = subprocess.run([sys.executable, str(TOOLS / script), *args], env=env, capture_output=True, text=True, timeout=5)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('loopback', result.stderr.lower())
                self.assertNotIn('synthetic-key', result.stderr)

if __name__ == '__main__': unittest.main()
