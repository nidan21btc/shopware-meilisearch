#!/usr/bin/env python3
"""Forward a protected Unix socket to a TCP service on this process's loopback.

Run inside the same network namespace as Meilisearch. The socket directory must
already exist, be owned by this user, and not be writable by other users. The
socket has mode 0660: grant group access only to the shop's PHP/CLI workers.
A lock prevents concurrent bridge instances; an owned, inactive socket may be
removed after an unclean shutdown, but other existing paths are never removed.
This process does not start or supervise Meilisearch and never logs requests.
"""
import argparse
import asyncio
import errno
import fcntl
import os
import signal
import socket
import stat
import sys
from pathlib import Path


def validate_path(path):
    if not path.is_absolute() or len(os.fsencode(path)) > 100:
        raise ValueError('An absolute Unix socket path of at most 100 bytes is required')
    parent = path.parent
    info = parent.stat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o022:
        raise ValueError('Socket directory must be owned by the service user and not writable by others')


def remove_stale_socket(path):
    try:
        info = path.lstat()
    except FileNotFoundError:
        return
    if not stat.S_ISSOCK(info.st_mode) or info.st_uid != os.getuid():
        raise ValueError('Existing path is not a socket owned by the service user')
    probe = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    probe.settimeout(1)
    try:
        probe.connect(str(path))
    except OSError as error:
        if error.errno != errno.ECONNREFUSED:
            raise ValueError('Cannot safely determine whether the socket is in use') from None
    else:
        raise ValueError('Another socket server is already running')
    finally:
        probe.close()
    current = path.lstat()
    if (current.st_dev, current.st_ino) != (info.st_dev, info.st_ino):
        raise ValueError('Socket changed while being checked')
    path.unlink()


async def forward(reader, writer, port):
    upstream_writer = None
    async def copy(source, target):
        try:
            while True:
                data = await source.read(65536)
                if not data:
                    break
                target.write(data)
                await target.drain()
        finally:
            try:
                target.write_eof()
            except (OSError, RuntimeError, NotImplementedError):
                pass
    try:
        upstream_reader, upstream_writer = await asyncio.wait_for(asyncio.open_connection('127.0.0.1', port), 3)
        await asyncio.wait_for(asyncio.gather(copy(reader, upstream_writer), copy(upstream_reader, writer)), 120)
    except (OSError, asyncio.TimeoutError, ConnectionError):
        pass
    finally:
        if upstream_writer is not None:
            upstream_writer.close()
        writer.close()


async def serve(path, port):
    validate_path(path)
    lock_path = str(path) + '.lock'
    flags = os.O_CREAT | os.O_RDWR | getattr(os, 'O_NOFOLLOW', 0)
    lock_fd = os.open(lock_path, flags, 0o600)
    try:
        info = os.fstat(lock_fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid():
            raise ValueError('Unsafe lock file')
        fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        remove_stale_socket(path)
        old_umask = os.umask(0o117)
        try:
            server = await asyncio.start_unix_server(lambda r, w: forward(r, w, port), path=str(path), backlog=128)
        finally:
            os.umask(old_umask)
    except BaseException:
        os.close(lock_fd)
        raise
    identity = path.lstat()
    stop = asyncio.Event()
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, stop.set)
    try:
        async with server:
            await stop.wait()
    finally:
        # Never unlink another process's replacement socket or any other file.
        try:
            current = path.lstat()
            if stat.S_ISSOCK(current.st_mode) and (current.st_dev, current.st_ino) == (identity.st_dev, identity.st_ino):
                path.unlink()
        except FileNotFoundError:
            pass
        os.close(lock_fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--socket', required=True, type=Path)
    parser.add_argument('--port', type=int, default=7700)
    args = parser.parse_args()
    if not 1 <= args.port <= 65535:
        parser.error('Invalid port')
    try:
        asyncio.run(serve(args.socket, args.port))
    except (OSError, ValueError) as error:
        print('Socket bridge failed:', type(error).__name__, file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
