import http.server
import json
import os
import signal
import socket
import stat
import subprocess
import tempfile
import threading
import time
import unittest
from pathlib import Path

BRIDGE = Path(__file__).resolve().parents[1] / 'deploy/socket_bridge.py'


class BridgeTest(unittest.TestCase):
    def test_forwards_loopback_and_cleans_own_socket(self):
        seen = []
        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                seen.append(self.headers.get('Authorization'))
                self.send_response(200); self.send_header('Content-Length', '2'); self.end_headers(); self.wfile.write(b'{}')
            def log_message(self, *args):
                pass
        origin = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=origin.serve_forever, daemon=True); thread.start()
        with tempfile.TemporaryDirectory(prefix='el-bridge-', dir='/tmp') as directory:
            socket_path = Path(directory) / 'search.sock'
            process = subprocess.Popen(['python3', str(BRIDGE), '--socket', str(socket_path), '--port', str(origin.server_port)], stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
            try:
                deadline = time.monotonic() + 5
                while not socket_path.exists() and time.monotonic() < deadline and process.poll() is None:
                    time.sleep(.02)
                self.assertTrue(socket_path.exists())
                self.assertEqual(stat.S_IMODE(socket_path.stat().st_mode), 0o660)
                with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as client:
                    client.settimeout(5); client.connect(str(socket_path))
                    client.sendall(b'GET /health HTTP/1.0\r\nAuthorization: Bearer test-secret\r\n\r\n')
                    chunks = []
                    while data := client.recv(4096): chunks.append(data)
                self.assertIn(b'200 OK', b''.join(chunks))
                self.assertEqual(seen, ['Bearer test-secret'])
            finally:
                process.send_signal(signal.SIGTERM); process.wait(timeout=5); process.stderr.close()
                origin.shutdown(); origin.server_close(); thread.join()
            self.assertEqual(process.returncode, 0)
            self.assertFalse(socket_path.exists())

    def test_stale_socket_is_replaced_and_concurrent_bridge_is_rejected(self):
        with tempfile.TemporaryDirectory(prefix='el-bridge-', dir='/tmp') as directory:
            path = Path(directory) / 'search.sock'
            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as stale:
                stale.bind(str(path))
            process = subprocess.Popen(['python3', str(BRIDGE), '--socket', str(path)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            try:
                deadline = time.monotonic() + 5
                connected = False
                while time.monotonic() < deadline and process.poll() is None:
                    try:
                        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as probe:
                            probe.connect(str(path)); connected = True; break
                    except OSError:
                        time.sleep(.02)
                self.assertTrue(connected, 'Stale socket was not replaced')
                other = subprocess.run(['python3', str(BRIDGE), '--socket', str(path)], capture_output=True, timeout=5)
                self.assertNotEqual(other.returncode, 0)
                self.assertTrue(path.exists())
            finally:
                if process.poll() is None: process.send_signal(signal.SIGTERM)
                process.wait(timeout=5)
            self.assertFalse(path.exists())

    def test_existing_file_is_never_removed(self):
        with tempfile.TemporaryDirectory(prefix='el-bridge-', dir='/tmp') as directory:
            path = Path(directory) / 'search.sock'; path.write_text('keep')
            result = subprocess.run(['python3', str(BRIDGE), '--socket', str(path)], capture_output=True, timeout=5)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(path.read_text(), 'keep')

    def test_unsafe_directory_is_rejected(self):
        with tempfile.TemporaryDirectory(prefix='el-bridge-', dir='/tmp') as directory:
            os.chmod(directory, 0o777)
            result = subprocess.run(['python3', str(BRIDGE), '--socket', str(Path(directory) / 'search.sock')], capture_output=True, timeout=5)
            self.assertNotEqual(result.returncode, 0)


if __name__ == '__main__':
    unittest.main()
