#!/usr/bin/env python3
"""Create protected, index-scoped read/write credentials and an independent feedback secret.

Run locally as the service administrator. Never pass keys in command arguments.
Output directory must not exist. Does not alter documents, index settings or the master key.
"""
import argparse
import http.client
import json
import os
from pathlib import Path
import re
import secrets
import socket
import urllib.parse
from managed_socket_search import read_secret

class UnixConnection(http.client.HTTPConnection):
    def __init__(self, path):
        super().__init__('localhost', timeout=10)
        self.path = path
    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect(self.path)

def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--master-file', type=Path, required=True)
    p.add_argument('--output-dir', type=Path, required=True)
    p.add_argument('--socket')
    p.add_argument('--url', default='http://127.0.0.1:7700')
    p.add_argument('--indexes', nargs='+', required=True)
    a = p.parse_args()
    if any(not re.fullmatch(r'shopware_[a-f0-9]{32}_[a-f0-9]{32}', s) for s in a.indexes): p.error('Expected published Shopware index names')
    u = urllib.parse.urlsplit(a.url)
    if u.scheme != 'http' or u.hostname not in ('127.0.0.1', '::1') or u.username or u.password or u.path not in ('', '/') or u.query or u.fragment: p.error('Local HTTP origin required')
    key = read_secret(a.master_file)
    a.output_dir.mkdir(mode=0o700, parents=False, exist_ok=False)
    created = []
    def request(method, path, body=None):
        c = UnixConnection(a.socket) if a.socket else http.client.HTTPConnection(u.hostname, u.port or 80, timeout=10)
        try:
            c.request(method, path, body=None if body is None else json.dumps(body), headers={'Authorization': 'Bearer '+key, 'Content-Type': 'application/json'})
            r=c.getresponse(); data=r.read(65536)
            if r.status >= 400: raise RuntimeError('Credential API refused request (HTTP %s, %s)' % (r.status, json.loads(data).get('code', 'unknown')))
            return json.loads(data) if data else {}
        finally: c.close()
    try:
        roles = {'status': (['version', 'tasks.get'], ['*']), 'search': (['search', 'settings.get'], a.indexes), 'index': ([ 'indexes.create', 'indexes.get', 'indexes.delete', 'indexes.swap', 'settings.get', 'settings.update', 'documents.add', 'documents.get'], a.indexes + [s+'_building' for s in a.indexes])}
        for role, (actions, indexes) in roles.items():
            result = request('POST', '/keys', {'name': 'Shopware '+role, 'actions': actions, 'indexes': indexes, 'expiresAt': None})
            created.append(result['uid'])
            with os.fdopen(os.open(a.output_dir/role, os.O_WRONLY|os.O_CREAT|os.O_EXCL, 0o600), 'w') as f: f.write(result['key'])
        with os.fdopen(os.open(a.output_dir/'feedback', os.O_WRONLY|os.O_CREAT|os.O_EXCL, 0o600), 'w') as f: f.write(secrets.token_hex(32))
        with os.fdopen(os.open(a.output_dir/'key-ids.json', os.O_WRONLY|os.O_CREAT|os.O_EXCL, 0o600), 'w') as f: json.dump(created, f)
    except Exception:
        for uid in created:
            try: request('DELETE', '/keys/'+uid)
            except Exception: pass
        for f in a.output_dir.iterdir(): f.unlink()
        a.output_dir.rmdir()
        raise
    print('Created private search, index and feedback credentials. No keys printed.')

if __name__ == '__main__': main()
