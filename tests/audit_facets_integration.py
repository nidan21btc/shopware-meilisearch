#!/usr/bin/env python3
"""Verify the read-only facet translation report in an isolated Shopware copy."""
import collections
import json
import os
import re
import stat
import subprocess
import tempfile
import urllib.parse
from pathlib import Path

root = Path(os.environ.get('PROJECT_ROOT', ''))
db = urllib.parse.urlparse(os.environ.get('DATABASE_URL', ''))
if not (root / 'vendor/autoload.php').is_file() or db.hostname not in ('localhost', '127.0.0.1') or not re.fullmatch(r'/el_search_test_[a-f0-9]+', db.path):
    raise ValueError('An isolated test project and database are required')
with tempfile.TemporaryDirectory(prefix='el-facet-audit-') as directory:
    path = Path(directory) / 'audit.jsonl'
    command = ['php', 'bin/console', 'shopware-meilisearch:search:audit-facet-translations', '--output=' + str(path)]
    subprocess.run(command, cwd=root, env=os.environ.copy(), check=True, stdout=subprocess.DEVNULL, timeout=300)
    before = path.read_bytes()
    assert stat.S_IMODE(path.stat().st_mode) == 0o600
    rows = [json.loads(line) for line in before.splitlines()]
    assert rows, 'Test catalog must contain missing translations'
    keys = {(r['salesChannelId'], r['languageId'], r['kind'], r['id']) for r in rows}
    assert len(keys) == len(rows), 'Duplicate facet entries'
    for row in rows:
        assert row['kind'] in ('manufacturer', 'category') and row['affectedProducts'] > 0
        assert all(re.fullmatch('[a-f0-9]{32}', row[key]) for key in ('salesChannelId', 'languageId', 'id'))
        reference = row['referenceOnly']
        assert reference is None or (reference['name'].strip() and reference['locale'] != row['locale'])
    second = subprocess.run(command, cwd=root, env=os.environ.copy(), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=60)
    assert second.returncode != 0 and path.read_bytes() == before, 'Existing reports must never be overwritten'
    print('Facet translation audit verified:', len(rows), 'unique missing labels across', len({r['locale'] for r in rows}), 'languages; private and no overwrite.')
