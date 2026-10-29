#!/usr/bin/env python3
"""Compare storefront search output before/after a change in a local test shop.

Snapshots contain shop data: keep them outside the repository and delete them
when finished. The tool makes only read-only requests to a loopback origin.
"""
import argparse
import html.parser
import json
from pathlib import Path
import re
import sys
import urllib.parse
import urllib.request

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
from local_http import local_origin, opener


class Results(html.parser.HTMLParser):
    def __init__(self):
        super().__init__()
        self.products = []
        self.facets = []
        self.status = None
        self.current = None
        self.count = None

    def handle_starttag(self, tag, attributes):
        a = dict(attributes)
        if tag == 'article' and a.get('data-product-id'):
            self.products.append(a['data-product-id'])
        if 'data-el-result-status' in a:
            self.status = a['data-el-result-status']
        if tag == 'label' and 'data-el-facet-value' in a:
            self.current = {'type': None, 'id': None, 'checked': False, 'count': ''}
        if self.current is not None and tag == 'input' and a.get('name') in ('category[]', 'manufacturer[]'):
            self.current.update(type=a['name'], id=a.get('value'), checked='checked' in a)
        if self.current is not None and tag == 'span' and 'el-search__facet-count' in a.get('class', '').split():
            self.count = True

    def handle_data(self, data):
        if self.count and self.current is not None:
            self.current['count'] += data

    def handle_endtag(self, tag):
        if tag == 'span' and self.count:
            self.count = None
        if tag == 'label' and self.current is not None:
            if self.current['id']:
                self.current['count'] = self.current['count'].strip()
                self.facets.append(self.current)
            self.current = None


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('mode', choices=['capture', 'compare'])
    ap.add_argument('--url', required=True, help='Loopback origin, e.g. http://127.0.0.1')
    ap.add_argument('--host', required=True, help='Local virtual-host name')
    ap.add_argument('--snapshot', required=True, help='Private snapshot file outside the repository')
    ap.add_argument('--query', action='append', required=True, help='Representative query; repeat for multiple cases')
    args = ap.parse_args()
    origin = local_origin(args.url)
    if not re.fullmatch(r'[a-zA-Z0-9.-]+', args.host):
        ap.error('Invalid local host')
    snapshot = Path(args.snapshot).resolve()
    if snapshot.is_relative_to(Path(__file__).resolve().parents[1]):
        ap.error('Snapshot must be outside the repository')
    client = opener()

    def load(q, extra):
        params = {'q': q, **extra}
        url = origin + '/de/shopware-meilisearch?' + urllib.parse.urlencode(params, doseq=True)
        req = urllib.request.Request(url, headers={'Host': args.host, 'X-Requested-With': 'XMLHttpRequest'})
        with client.open(req, timeout=60) as response:
            parser = Results()
            parser.feed(response.read().decode('utf-8'))
        if parser.status is None:
            raise RuntimeError('Search response did not contain a result status')
        return {'status': parser.status, 'products': parser.products, 'facets': parser.facets}

    # Freeze filter IDs selected from the baseline, so the exact same requests are
    # repeated after the change even if a facet disappears or changes order.
    if args.mode == 'capture':
        cases = []
        for q in args.query:
            first = load(q, {})
            cases.append({'query': q, 'params': {}, 'result': first})
            for extra in ({'sort': 'price-asc'}, {'page': '2'}):
                cases.append({'query': q, 'params': extra, 'result': load(q, extra)})
            for kind in ('category[]', 'manufacturer[]'):
                facet = next((x for x in first['facets'] if x['type'] == kind), None)
                if facet:
                    extra = {kind: [facet['id']]}
                    cases.append({'query': q, 'params': extra, 'result': load(q, extra)})
        # Private file permissions even with a permissive caller umask.
        import os
        fd = os.open(snapshot, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            json.dump(cases, f, ensure_ascii=False)
        print(f'Captured {len(cases)} search cases.')
    else:
        with snapshot.open(encoding='utf-8') as f:
            cases = json.load(f)
        if sorted(set(c['query'] for c in cases)) != sorted(set(args.query)):
            raise ValueError('Queries differ from the snapshot')
        mismatches = []
        for i, case in enumerate(cases, 1):
            if load(case['query'], case['params']) != case['result']:
                mismatches.append(i)
        print(f'Compared {len(cases)} search cases; differences: {len(mismatches)}.')
        if mismatches:
            print('Differing case numbers:', ', '.join(map(str, mismatches)))
            raise SystemExit(1)


if __name__ == '__main__':
    main()
