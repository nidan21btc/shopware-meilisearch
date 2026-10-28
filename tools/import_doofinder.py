#!/usr/bin/env python3
"""Convert Doofinder CSV feedback into isolated, reproducible evaluation cases.

Run with --help. No feedback is automatically injected into production ranking.
"""
import argparse
import collections
import csv
import hashlib
import json
import math
import re
from pathlib import Path


ENGINES = {}
PRODUCT = re.compile(r'^([a-f0-9]{32})@product@([a-f0-9]{32})$')


def read_rows(path, kind):
    with Path(path).open(encoding='utf-8-sig', newline='') as stream:
        reader = csv.reader(stream)
        header = next(reader)
        expected = (['hashid', 'search_term', 'total_searches', 'total_clicks']
                    if kind == 'terms' else ['hashid', 'clicked_product_ids', 'clicked_product_names', 'clicked_product_link'])
        if header[:4] != expected or len(header) != (13 if kind == 'terms' else 12):
            raise ValueError(f'Unrecognized {kind} schema: {Path(path).name}')
        # Current terms export mislabels the ID/name and click/position columns.
        # Positional access deliberately preserves both duplicate columns.
        if kind == 'terms' and header[8:13] != ['clicked_product_names', 'clicked_product_names', 'clicked_product_link', 'product_clicks', 'product_clicks']:
            raise ValueError('Terms schema changed; review ID and click columns before importing')
        for line, row in enumerate(reader, 2):
            if len(row) != len(header) or row[0] not in ENGINES:
                raise ValueError(f'Unknown engine or malformed row on line {line}')
            yield row


def product_id(value, engine):
    match = PRODUCT.fullmatch(value)
    if not match or match[1] != engine:
        raise ValueError('Product reference belongs to another engine or is malformed')
    return match[2]


def nonnegative(value):
    number = int(value)
    if number < 0:
        raise ValueError('Negative count')
    return number


def import_data(terms_path, no_results_path, products_path):
    catalog = collections.defaultdict(dict)
    product_rows = 0
    for row in read_rows(products_path, 'products'):
        identifier = product_id(row[1], row[0])
        catalog[row[0]].setdefault(identifier, set()).add(row[3])
        product_rows += 1

    terms = {}
    warnings = collections.Counter()
    for row in read_rows(terms_path, 'terms'):
        engine, query = row[:2]
        key = (engine, query)
        if key in terms:
            raise ValueError('Duplicate query/engine row; aggregation semantics require review')
        searches, clicks = nonnegative(row[2]), nonnegative(row[3])
        positives = {}
        positions = {}
        if row[8]:
            ids, counts, position_values = row[8].split(';'), row[11].split(';'), row[12].split(';')
            if len(ids) != len(counts) or len(ids) != len(position_values):
                raise ValueError('Product ID / click count / position alignment is ambiguous')
            for raw_id, count, position_value in zip(ids, counts, position_values):
                identifier = product_id(raw_id, engine)
                weight = nonnegative(count)
                if weight:
                    positives[identifier] = positives.get(identifier, 0) + weight
                if position_value:
                    position = float(position_value)
                    if math.isfinite(position) and position > 0:
                        positions[identifier] = position
                if identifier not in catalog[engine]:
                    warnings['clicked_ids_missing_from_products_export'] += 1
        if sum(positives.values()) != clicks:
            warnings['query_click_totals_differ_from_product_click_totals'] += 1
        terms[key] = {
            'query': query, 'searches': searches, 'clicks': clicks,
            'relevantProducts': positives,
            # This is an average clicked position, not a complete ranking or a
            # record of every individual click's position.
            'historicalPositions': positions,
            # Stable query split prevents evaluating only the queries used to tune ranking.
            'partition': 'holdout' if int(hashlib.sha256((ENGINES[engine] + '\0' + query).encode()).hexdigest()[:8], 16) % 5 == 0 else 'development',
            'reportedNoResults': False,
        }

    extra_no_result = collections.Counter()
    for row in read_rows(no_results_path, 'terms'):
        key = (row[0], row[1])
        if key in terms:
            terms[key]['reportedNoResults'] = True
        else:
            extra_no_result[ENGINES[row[0]]] += 1
            warnings['no_result_terms_missing_from_all_terms'] += 1

    languages = {}
    for engine, locale in ENGINES.items():
        cases = sorted((case for (hashid, _), case in terms.items() if hashid == engine), key=lambda case: (-case['searches'], case['query']))
        languages[locale] = {
            'summary': {
                'queries': len(cases),
                'searches': sum(case['searches'] for case in cases),
                'clicks': sum(case['clicks'] for case in cases),
                'queriesWithClicks': sum(bool(case['relevantProducts']) for case in cases),
                'reportedNoResultQueries': sum(case['reportedNoResults'] for case in cases) + extra_no_result[locale],
                'noResultQueriesAlsoWithClicks': sum(case['reportedNoResults'] and bool(case['relevantProducts']) for case in cases),
                'clickedProducts': len(catalog[engine]),
            },
            'cases': cases,
            # Doofinder hashes are not necessarily Shopware product UUIDs.
            # Preserve public links to resolve the products against the catalog.
            'productReferences': {identifier: sorted(urls) for identifier, urls in catalog[engine].items()},
        }
    return {
        'schemaVersion': 1,
        'evidence': 'historical_click_feedback_not_complete_relevance_judgments_or_current_doofinder_rankings',
        'sources': [{'name': Path(path).name, 'sha256': hashlib.sha256(Path(path).read_bytes()).hexdigest()} for path in [terms_path, no_results_path, products_path]],
        'productExportRows': product_rows,
        'warnings': dict(warnings),
        'languages': languages,
    }


def main():
    global ENGINES
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--terms', required=True, type=Path)
    parser.add_argument('--no-results', required=True, type=Path)
    parser.add_argument('--products', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--engine', action='append', required=True, metavar='ID=LOCALE', help='Map an export engine ID to its locale; repeat for each language')
    args = parser.parse_args()
    for entry in args.engine:
        engine, sep, locale = entry.partition('=')
        if not sep or not re.fullmatch(r'[a-f0-9]{32}', engine) or not re.fullmatch(r'[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})+', locale) or engine in ENGINES or locale in ENGINES.values():
            parser.error('Each engine must have a unique 32-character lowercase hex ID and locale')
        ENGINES[engine] = locale
    result = import_data(args.terms, args.no_results, args.products)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    from private_output import write_private_text
    write_private_text(args.output, json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({locale: data['summary'] for locale, data in result['languages'].items()}, ensure_ascii=False, indent=2))
    print('Warnings:', json.dumps(result['warnings']))


if __name__ == '__main__':
    main()
