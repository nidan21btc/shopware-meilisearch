#!/usr/bin/env python3
"""Read-only comparison of short-query results and filtered facet counts."""
import argparse
import re
import sys
import urllib.parse
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
from local_http import local_origin, opener
from compare_search_snapshots import Results


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--url', required=True)
    ap.add_argument('--host', required=True)
    ap.add_argument('--query', required=True)
    ap.add_argument('--category', help='Optional category identifier from the local test catalog')
    args = ap.parse_args()
    origin = local_origin(args.url)
    if not re.fullmatch(r'[a-zA-Z0-9.-]+', args.host):
        ap.error('Invalid local host')
    client = opener()

    def load(extra):
        url = origin + '/de/shopware-meilisearch?' + urllib.parse.urlencode({'q': args.query, **extra}, doseq=True)
        with client.open(urllib.request.Request(url, headers={'Host':args.host, 'X-Requested-With':'XMLHttpRequest'}), timeout=60) as response:
            parsed = Results()
            parsed.feed(response.read().decode('utf-8'))
        match = re.search(r'\d+', parsed.status or '')
        if not match:
            raise RuntimeError('Result count missing')
        return parsed, int(match.group())

    def all_products(extra):
        first, total = load(extra)
        if total > 1000:
            raise RuntimeError('Use a narrower synthetic query with at most 1000 matches')
        ids = list(first.products)
        for page in range(2, (total + 19) // 20 + 1):
            part, count = load({**extra, 'page':page})
            if count != total:
                raise RuntimeError('Total changed during pagination')
            ids.extend(part.products)
        if len(ids) != total or len(set(ids)) != total:
            raise RuntimeError('Unexpected missing or duplicate results')
        return first, total, set(ids)

    initial, total, original = all_products({})
    if not total:
        raise RuntimeError('The comparison query must have results')
    category = args.category
    if category is None:
        choice = next((f for f in initial.facets if f['type']=='category[]' and f['count'].isdigit() and int(f['count']) > 0), None)
        if choice is None:
            raise RuntimeError('The comparison query must have a category facet')
        category = choice['id']
    visible = next((f for f in initial.facets if f['type']=='category[]' and f['id']==category), None)
    if visible is None or not visible['count'].isdigit():
        raise RuntimeError('Selected category is not an ordinary facet')
    if int(visible['count']) > total:
        raise AssertionError('Category facet exceeds unfiltered total')
    filtered, filtered_total, filtered_ids = all_products({'category[]':[category]})
    if filtered_total != int(visible['count']) or not filtered_ids <= original:
        raise AssertionError('Filtering changed the precision mode or facet count')
    _, similar_total, similar_ids = all_products({'category[]':[category], 'similar':'1'})
    if not filtered_ids <= similar_ids or similar_total < filtered_total:
        raise AssertionError('Explicit similar results do not include the precise results')
    print('Short-query category subset, facet count, pagination and optional similar results verified.')


if __name__ == '__main__':
    main()
