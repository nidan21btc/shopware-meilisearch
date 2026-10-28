#!/usr/bin/env python3
"""Run Meilisearch and its Unix-socket bridge as one supervised service.

The service manager must restart this process on failure. Meilisearch binds only
inside this process's loopback network namespace; the bridge must share it.
Secret file: regular, owned by this user, no group/other access, no symlink.
The service manager and network isolation must be verified on the target host.
"""
import argparse
import os
import signal
import stat
import subprocess
import sys
import time
from pathlib import Path


def read_secret(path):
    fd = os.open(path, os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0))
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077:
            raise ValueError('Unsafe secret file')
        with os.fdopen(fd, 'r', encoding='utf-8') as source:
            fd = -1
            secret = source.read(4097).strip()
        if not 32 <= len(secret) <= 4096 or any(c.isspace() or ord(c) < 33 for c in secret):
            raise ValueError('Invalid secret')
        return secret
    finally:
        if fd >= 0:
            os.close(fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--meili', type=Path, required=True)
    parser.add_argument('--data', type=Path, required=True)
    parser.add_argument('--socket', type=Path, required=True)
    parser.add_argument('--secret-file', type=Path, required=True)
    parser.add_argument('--port', type=int, default=7700)
    parser.add_argument('--indexing-memory', default='512MiB')
    parser.add_argument('--indexing-threads', type=int, default=2)
    args = parser.parse_args()
    if not all(p.is_absolute() for p in (args.meili, args.data, args.socket, args.secret_file)):
        parser.error('All paths must be absolute')
    if not 1 <= args.port <= 65535 or not 1 <= args.indexing_threads <= 16:
        parser.error('Invalid port or indexing thread count')
    if not args.meili.is_file() or not os.access(args.meili, os.X_OK) or not args.data.is_dir():
        parser.error('Executable and existing data directory are required')
    try:
        secret = read_secret(args.secret_file)
    except (OSError, UnicodeError, ValueError):
        parser.exit(1, 'Cannot load a valid protected secret file.\n')
    env = os.environ.copy()
    env.update({'MEILI_MASTER_KEY': secret, 'MEILI_ENV': 'production', 'MEILI_NO_ANALYTICS': 'true',
                'MEILI_HTTP_ADDR': '127.0.0.1:' + str(args.port), 'MEILI_DB_PATH': str(args.data),
                'MEILI_MAX_INDEXING_MEMORY': args.indexing_memory, 'MEILI_MAX_INDEXING_THREADS': str(args.indexing_threads)})
    children = []
    stopping = False
    def stop(_signal, _frame):
        nonlocal stopping
        stopping = True
    for sig in (signal.SIGTERM, signal.SIGINT):
        signal.signal(sig, stop)
    try:
        children.append(subprocess.Popen([str(args.meili), '--no-analytics'], env=env))
        # The bridge has no need for the search engine's master key.
        bridge_env = env.copy(); bridge_env.pop('MEILI_MASTER_KEY', None)
        children.append(subprocess.Popen([sys.executable, str(Path(__file__).with_name('socket_bridge.py')),
                                          '--socket', str(args.socket), '--port', str(args.port)], env=bridge_env))
        while not stopping:
            if any(child.poll() is not None for child in children):
                print('A search service process exited; stopping both.', file=sys.stderr)
                return 1
            time.sleep(.25)
        return 0
    except OSError:
        print('Could not start a search service process.', file=sys.stderr)
        return 1
    finally:
        for child in children:
            if child.poll() is None:
                child.terminate()
        for child in children:
            try:
                child.wait(timeout=10)
            except subprocess.TimeoutExpired:
                child.kill(); child.wait()


if __name__ == '__main__':
    sys.exit(main())
