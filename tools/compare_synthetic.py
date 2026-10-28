#!/usr/bin/env python3
"""Compare target-product recovery for locally generated, public-name typos.

Input snapshots must be aligned by locale and query. The reference snapshot
contains product cards with Doofinder IDs and URLs; the local snapshot contains
Shopware IDs. Only aggregate counts are emitted. Unmapped reference cards are
reported as uncertainty, never treated as proof that a target was absent.
This small synthetic test is not a comprehensive relevance comparison.
"""
import argparse
import collections
import json
import urllib.parse
from pathlib import Path


def candidate_from_url(raw_url, locale, paths, numbers, allowed_hosts):
    url = urllib.parse.urlsplit(raw_url or '')
    if url.hostname not in allowed_hosts:
        return None
    prefix, sep, suffix = urllib.parse.unquote(url.path).strip('/').partition('/')
    if not sep or prefix.casefold() != locale[:2].casefold():
        return None
    possible = paths.get(suffix.casefold(), set())
    if not possible:
        possible = numbers.get(suffix.split('/', 1)[0].casefold(), set())
    return next(iter(possible)) if len(possible) == 1 else None


def compare(reference, local, catalog, benchmark, allowed_hosts):
    families = catalog.get('families')
    if families is not None and not isinstance(families, dict):
        raise ValueError('Product families must be an object')
    numbers = collections.defaultdict(set)
    for number, product_id in catalog['numbers']:
        numbers[number.casefold()].add(product_id)
    if set(reference) != set(local):
        raise ValueError('Snapshots must cover the same locales')
    output = {}
    for locale, cases in reference.items():
        local_cases = local[locale]
        if len(cases) != len(local_cases):
            raise ValueError('Snapshots must contain the same number of cases')
        paths = collections.defaultdict(set)
        for path, product_id in catalog['paths'].get(locale, []):
            paths[urllib.parse.unquote(path).strip('/').casefold()].add(product_id)
        known_ids = benchmark['languages'][locale]['referenceMap']
        counts = collections.Counter()
        for case, rendered in zip(cases, local_cases):
            if case['query'] != rendered['query']:
                raise ValueError('Snapshot query order differs')
            mapped = []
            unknown = 0
            for card in case['cards']:
                product_id = candidate_from_url(card.get('href'), locale, paths, numbers, allowed_hosts)
                product_id = product_id or known_ids.get(card['id'])
                if product_id:
                    mapped.append(product_id)
                else:
                    unknown += 1
            target = case['productId']
            reference_hit = target in mapped
            counts['queries'] += 1
            counts['referenceCards'] += len(case['cards'])
            counts['unmappedReferenceCards'] += unknown
            counts['referenceTargetFound'] += reference_hit
            counts['referenceTargetUnknown'] += not reference_hit and unknown > 0
            counts['localTargetFound'] += target in rendered['ids']
            counts['commonProducts'] += len(set(mapped).intersection(rendered['ids']))
            if families is not None:
                group = families.get(target)
                family_unknown = unknown + sum(pid not in families for pid in mapped)
                family_found = group is not None and any(families.get(pid) == group for pid in mapped)
                counts['referenceFamilyFound'] += family_found
                counts['referenceOtherVariantOnly'] += family_found and not reference_hit
                counts['referenceFamilyUnknown'] += group is None or (not family_found and family_unknown > 0)
                counts['referenceFamilyNotIdentified'] += group is not None and not family_found and family_unknown == 0
        output[locale] = dict(counts)
    return output


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('reference', 'local', 'catalog', 'benchmark'):
        parser.add_argument('--' + name, required=True, type=Path)
    parser.add_argument('--host', action='append', required=True, help='Allowed shop hostname; repeat if needed')
    args = parser.parse_args()
    inputs = [json.loads(getattr(args, name).read_text(encoding='utf-8')) for name in ('reference', 'local', 'catalog', 'benchmark')]
    print(json.dumps(compare(*inputs, {host.lower() for host in args.host}), ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
