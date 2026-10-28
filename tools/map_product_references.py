#!/usr/bin/env python3
"""Resolve Doofinder product references against same-language Shopware SEO paths."""
import argparse
import collections
import json
import urllib.parse
from pathlib import Path


def resolve(benchmark, catalog, allowed_hosts):
    numbers = collections.defaultdict(set)
    for number, identifier in catalog['numbers']:
        numbers[number.casefold()].add(identifier)
    totals = {}
    for locale, language in benchmark['languages'].items():
        paths = collections.defaultdict(set)
        for path, identifier in catalog['paths'].get(locale, []):
            paths[urllib.parse.unquote(path).strip('/').casefold()].add(identifier)
        refs = {}
        for external_id, urls in language['productReferences'].items():
            possible = set()
            for raw_url in urls:
                url = urllib.parse.urlsplit(raw_url)
                if url.hostname not in allowed_hosts:
                    continue
                path = urllib.parse.unquote(url.path).strip('/')
                prefix, separator, suffix = path.partition('/')
                if not separator or prefix.lower() != locale[:2].lower():
                    continue
                exact = paths.get(suffix.casefold(), set())
                if exact:
                    # A reused SEO path may refer to multiple products. Keep
                    # all candidates so another, unique URL cannot silently
                    # hide the conflicting reference.
                    possible.update(exact)
                else:
                    # Product numbers are language-neutral identifiers, but the
                    # URL must still have the expected language prefix.
                    first = suffix.split('/', 1)[0].casefold()
                    matches = numbers.get(first, set())
                    possible.update(matches)
            if len(possible) == 1:
                refs[external_id] = next(iter(possible))
        mapped_clicks = 0
        missing_clicks = 0
        for case in language['cases']:
            translated = collections.Counter()
            missing = 0
            for external_id, clicks in case['relevantProducts'].items():
                if external_id in refs:
                    translated[refs[external_id]] += clicks
                    mapped_clicks += clicks
                else:
                    missing += clicks
                    missing_clicks += clicks
            case['relevantProducts'] = dict(translated)
            case['unmappedHistoricalClicks'] = missing
        totals[locale] = {'references': len(language['productReferences']), 'mappedReferences': len(refs),
                          'mappedClicks': mapped_clicks, 'unmappedClicks': missing_clicks}
        language['referenceMap'] = refs
        del language['productReferences']
    benchmark['referenceMapping'] = {'method': 'same_locale_seo_history_then_unique_product_number', 'languages': totals}
    return benchmark


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--benchmark', required=True, type=Path)
    parser.add_argument('--catalog', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--host', action='append', required=True, help='Allowed shop hostname; repeat if needed')
    args = parser.parse_args()
    result = resolve(json.loads(args.benchmark.read_text(encoding='utf-8')), json.loads(args.catalog.read_text(encoding='utf-8')), {host.lower() for host in args.host})
    from private_output import write_private_text
    write_private_text(args.output, json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps(result['referenceMapping']['languages'], ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
