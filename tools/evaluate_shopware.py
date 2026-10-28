#!/usr/bin/env python3
"""Compare actual Shopware top-20 results to historical average click positions.

The Doofinder export only provides average clicked position per query/product.
Counting an average <= 20 as a top-20 hit is an approximation, not a measurement
of every historical result list. Product/stock and ranking may change over time.
"""
import argparse
import json
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from pathlib import Path


class Cards(HTMLParser):
    def __init__(self):
        super().__init__()
        self.ids = []
        self.has_status = False

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'article' and 'el-search__card' in attrs.get('class', '').split():
            self.ids.append(attrs['data-product-id'])
        if 'data-el-result-status' in attrs:
            self.has_status = True


def measure(raw, mapped, retrieve, partition, max_cases):
    result = {}
    for locale, language in raw['languages'].items():
        refmap = mapped['languages'][locale]['referenceMap']
        cases = [case for case in language['cases'] if case['partition'] == partition and case['relevantProducts']]
        if max_cases:
            cases = cases[:max_cases]
        stats = {'queries': 0, 'judgedQueries': 0, 'historicalClicksWithPositionAndMapping': 0,
                 'historicalClicksAvgPositionAtMost20': 0, 'candidateClicksTop20': 0,
                 'historicalQueriesWithHit': 0, 'candidateQueriesWithHit': 0,
                 'clicksWithoutPositionOrMapping': 0, 'noResultQueries': 0}
        for case in cases:
            ranked = set(retrieve(locale, case['query'])[:20])
            valid = {external: count for external, count in case['relevantProducts'].items()
                     if external in refmap and external in case['historicalPositions']}
            historical = sum(count for external, count in valid.items() if case['historicalPositions'][external] <= 20)
            local = sum(count for external, count in valid.items() if refmap[external] in ranked)
            stats['queries'] += 1
            stats['noResultQueries'] += not bool(ranked)
            stats['clicksWithoutPositionOrMapping'] += sum(case['relevantProducts'].values()) - sum(valid.values())
            if valid:
                stats['judgedQueries'] += 1
                stats['historicalClicksWithPositionAndMapping'] += sum(valid.values())
                stats['historicalClicksAvgPositionAtMost20'] += historical
                stats['candidateClicksTop20'] += local
                stats['historicalQueriesWithHit'] += historical > 0
                stats['candidateQueriesWithHit'] += local > 0
        result[locale] = stats
    return result


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--raw', required=True, type=Path)
    p.add_argument('--mapped', required=True, type=Path)
    p.add_argument('--url', required=True)
    p.add_argument('--host')
    p.add_argument('--partition', choices=['development', 'holdout'], default='holdout')
    p.add_argument('--max-cases', type=int, default=0, help='Limit per locale; 0 uses all')
    p.add_argument('--output', required=True, type=Path)
    args = p.parse_args()
    if args.max_cases < 0:
        p.error('max-cases cannot be negative')
    from local_http import local_origin, opener as local_opener
    try:
        args.url = local_origin(args.url)
    except ValueError as error:
        p.error(str(error))
    opener = local_opener()
    raw = json.loads(args.raw.read_text(encoding='utf-8'))
    mapped = json.loads(args.mapped.read_text(encoding='utf-8'))
    headers = {'X-Requested-With': 'XMLHttpRequest'}
    if args.host:
        headers['Host'] = args.host

    def retrieve(locale, query):
        path = '/' + locale[:2].lower() + '/shopware-meilisearch?' + urllib.parse.urlencode({'q': query})
        with opener.open(urllib.request.Request(args.url.rstrip('/') + path, headers=headers), timeout=30) as response:
            parser = Cards()
            parser.feed(response.read().decode('utf-8'))
        if not parser.has_status:
            raise ValueError('Unexpected shop response')
        return parser.ids

    result = {'scope': 'actual_shopware_first_page', 'partition': args.partition, 'maxCasesPerLanguage': args.max_cases,
              'note': 'Historical baseline uses average clicked position <= 20, an approximation; excluded clicks lacked position or mapping.',
              'languages': measure(raw, mapped, retrieve, args.partition, args.max_cases)}
    from private_output import write_private_text
    write_private_text(args.output, json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps(result['languages'], ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
