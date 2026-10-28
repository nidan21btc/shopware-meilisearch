#!/usr/bin/env python3
"""Record index metadata/settings to detect mutations during a benchmark."""
import argparse
import json
import os
import urllib.parse
import urllib.request
from pathlib import Path



def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--index-map', required=True, type=Path)
    p.add_argument('--output', required=True, type=Path)
    p.add_argument('--url', default='http://127.0.0.1:7700')
    args = p.parse_args()
    from local_http import local_origin, opener as local_opener
    try:
        args.url = local_origin(args.url)
    except ValueError as error:
        p.error(str(error))
    key = os.environ.get('SHOPWARE_MEILISEARCH_KEY', '')
    if not key:
        p.error('SHOPWARE_MEILISEARCH_KEY is required')
    opener = local_opener()
    indexes = json.loads(args.index_map.read_text(encoding='utf-8'))
    result = {}
    for locale, index in indexes.items():
        if not isinstance(index, str) or not index.replace('_', '').replace('-', '').isalnum():
            p.error('Invalid index name')
        def get(path):
            request = urllib.request.Request(args.url.rstrip('/') + path, headers={'Authorization': 'Bearer ' + key})
            with opener.open(request, timeout=10) as response:
                return json.load(response)
        meta = get('/indexes/' + index)
        stats = get('/indexes/' + index + '/stats')
        result[locale] = {'uid': index, 'updatedAt': meta['updatedAt'], 'documents': stats['numberOfDocuments'],
                          'settings': get('/indexes/' + index + '/settings')}
    from private_output import write_private_text
    write_private_text(args.output, json.dumps(result, ensure_ascii=False, sort_keys=True, indent=2) + '\n')


if __name__ == '__main__':
    main()
