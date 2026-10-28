#!/usr/bin/env python3
"""Count mapped historical clicks involving products with missing local names.

Counts are lower bounds: unmapped Doofinder product references are excluded,
current catalog status may differ from the historical catalog, and other causes
of absence (such as inactive products) are outside this report. Standard output
contains aggregate counts only. The optional private CSV contains product
identifiers and aggregate counts, but no customer queries.
"""
import argparse
import collections
import csv
import json
import os
from pathlib import Path


def summarize(report, benchmark):
    missing = collections.defaultdict(set)
    for row in report:
        missing[row['locale']].add(row['productId'])
    result = {}
    for locale, language in benchmark['languages'].items():
        count = collections.Counter()
        for case in language['cases']:
            products = case['relevantProducts']
            affected = sum(clicks for product_id, clicks in products.items() if product_id in missing[locale])
            count['queries'] += 1
            count['mappedClicks'] += sum(products.values())
            count['affectedClicks'] += affected
            count['queriesWithAffectedClicks'] += affected > 0
        result[locale] = dict(count)
    return result


def prioritize(report, benchmark):
    """Join missing names to local, locale-specific mapped click aggregates."""
    clicks = collections.defaultdict(collections.Counter)
    queries = collections.defaultdict(collections.Counter)
    for locale, language in benchmark['languages'].items():
        for case in language['cases']:
            for product_id, count in case['relevantProducts'].items():
                if not isinstance(count, int) or isinstance(count, bool) or count < 0:
                    raise ValueError('Invalid mapped click count')
                clicks[locale][product_id] += count
                if count:
                    queries[locale][product_id] += 1
    seen = set()
    output = []
    for row in report:
        key = (row['locale'], row['salesChannelId'], row['productId'])
        if key in seen:
            continue
        seen.add(key)
        locale, channel, product = key
        output.append({'locale': locale, 'salesChannelId': channel, 'productId': product,
                       'productNumber': row.get('productNumber') or '',
                       'mappedClicks': clicks[locale][product], 'queriesWithClicks': queries[locale][product]})
    return sorted(output, key=lambda row: (row['locale'], -row['mappedClicks'], row['productNumber'], row['productId']))


def spreadsheet_text(value):
    """Prevent spreadsheet programs from interpreting a product number as a formula."""
    text = str(value)
    return "'" + text if text.lstrip().startswith(('=', '+', '-', '@')) or text.startswith(('\t', '\r', '\n')) else text


def write_priority(path, rows):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w', encoding='utf-8-sig', newline='') as output:
        writer = csv.writer(output, delimiter=';')
        writer.writerow(['Sprache', 'Verkaufskanal-ID', 'Produkt-ID', 'Artikelnummer', 'Zugeordnete historische Klicks', 'Anfragen mit Klicks'])
        for row in rows:
            writer.writerow([spreadsheet_text(row['locale']), spreadsheet_text(row['salesChannelId']),
                             spreadsheet_text(row['productId']), spreadsheet_text(row['productNumber']),
                             row['mappedClicks'], row['queriesWithClicks']])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--report', required=True, type=Path, help='JSONL from audit-translations')
    parser.add_argument('--benchmark', required=True, type=Path, help='Mapped historical benchmark')
    parser.add_argument('--priority-output', type=Path, help='Create a new private CSV of missing products ordered by mapped historical clicks')
    args = parser.parse_args()
    with args.report.open(encoding='utf-8') as source:
        rows = [json.loads(line) for line in source if line.strip()]
    benchmark = json.loads(args.benchmark.read_text(encoding='utf-8'))
    if args.priority_output:
        write_priority(args.priority_output, prioritize(rows, benchmark))
    print(json.dumps(summarize(rows, benchmark), ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
