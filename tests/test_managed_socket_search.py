import os
import signal
import socket
import subprocess
import tempfile
import time
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RUNNER = ROOT / 'deploy/managed_socket_search.py'


class ManagedServiceTest(unittest.TestCase):
    def test_process_exit_stops_other_and_removes_socket(self):
        with tempfile.TemporaryDirectory(prefix='el-managed-', dir='/tmp') as directory:
            root = Path(directory); data = root / 'data'; data.mkdir()
            secret = root / 'secret'; secret.write_text('x' * 64); secret.chmod(0o600)
            executable = root / 'fake-meili'
            executable.write_text('#!/usr/bin/env python3\nimport time\ntime.sleep(1)\n')
            executable.chmod(0o700); path = root / 'search.sock'
            result = subprocess.run(['python3', str(RUNNER), '--meili', str(executable), '--data', str(data),
                                     '--socket', str(path), '--secret-file', str(secret)], capture_output=True, timeout=10)
            self.assertEqual(result.returncode, 1)
            self.assertFalse(path.exists())

    def test_existing_socket_path_stops_meilisearch_without_removing_file(self):
        with tempfile.TemporaryDirectory(prefix='el-managed-', dir='/tmp') as directory:
            root = Path(directory); secret = root / 'secret'; secret.write_text('x' * 64); secret.chmod(0o600)
            executable = root / 'fake-meili'; executable.write_text('#!/bin/sh\nexec sleep 30\n'); executable.chmod(0o700)
            path = root / 'sock'; path.write_text('preserve')
            result = subprocess.run(['python3', str(RUNNER), '--meili', str(executable), '--data', directory,
                                     '--socket', str(path), '--secret-file', str(secret)], capture_output=True, timeout=10)
            self.assertEqual(result.returncode, 1)
            self.assertEqual(path.read_text(), 'preserve')

    def test_rejects_readable_secret(self):
        with tempfile.TemporaryDirectory(prefix='el-managed-', dir='/tmp') as directory:
            root = Path(directory); secret = root / 'secret'; secret.write_text('x' * 64); secret.chmod(0o644)
            result = subprocess.run(['python3', str(RUNNER), '--meili', '/usr/bin/true', '--data', directory,
                                     '--socket', str(root / 'sock'), '--secret-file', str(secret)], capture_output=True, timeout=5)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse((root / 'sock').exists())


if __name__ == '__main__':
    unittest.main()
