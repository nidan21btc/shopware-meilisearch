#!/usr/bin/env python3
"""Create a private, review-only CSV of missing facet labels, ordered by reach.

Reference names are for manual review and must not be imported automatically.
Product counts can overlap between categories and are not a measure of demand.
"""
import argparse
import collections
import csv
import json
import os
import re
from pathlib import Path

UUID = re.compile(r'^[a-f0-9]{32}$')
LOCALE = re.compile(r'^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})+$')


def validate(rows):
    seen = set()
    result = []
    for row in rows:
        if not isinstance(row, dict):
            raise ValueError('Invalid report entry')
        channel, language, product = (row.get(key) for key in ('salesChannelId', 'languageId', 'id'))
        locale, kind, affected = row.get('locale'), row.get('kind'), row.get('affectedProducts')
        if (not all(isinstance(value, str) and UUID.fullmatch(value) for value in (channel, language, product))
                or not isinstance(locale, str) or not LOCALE.fullmatch(locale)
                or kind not in ('manufacturer', 'category') or not isinstance(affected, int)
                or isinstance(affected, bool) or affected <= 0):
            raise ValueError('Invalid report entry')
        key = (channel, language, kind, product)
        if key in seen:
            raise ValueError('Duplicate report entry')
        seen.add(key)
        ref = row.get('referenceOnly')
        if ref is not None and (not isinstance(ref, dict) or not isinstance(ref.get('name'), str)
                                or not ref['name'].strip() or (ref.get('locale') is not None and not isinstance(ref['locale'], str))):
            raise ValueError('Invalid reference label')
        result.append(row)
    return sorted(result, key=lambda row: (row['locale'], -row['affectedProducts'], row['kind'], row['id']))


def safe_cell(value):
    value = str(value or '')
    return "'" + value if value.lstrip().startswith(('=', '+', '-', '@')) or value.startswith(('\t', '\r', '\n')) else value


def write_csv(path, rows):
    fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(fd, 'w', encoding='utf-8-sig', newline='') as output:
        writer = csv.writer(output, delimiter=';')
        writer.writerow(['Sprache', 'Art', 'Betroffene indexierbare Produkte', 'ID', 'Verkaufskanal-ID', 'Referenzsprache', 'Referenzname (nur zur Prüfung)'])
        for row in rows:
            ref = row.get('referenceOnly') or {}
            writer.writerow([safe_cell(row['locale']), safe_cell(row['kind']), row['affectedProducts'],
                             safe_cell(row['id']), safe_cell(row['salesChannelId']),
                             safe_cell(ref.get('locale')), safe_cell(ref.get('name'))])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--report', required=True, type=Path, help='JSONL from audit-facet-translations')
    parser.add_argument('--output', required=True, type=Path, help='New private CSV')
    args = parser.parse_args()
    with args.report.open(encoding='utf-8') as source:
        rows = validate([json.loads(line) for line in source if line.strip()])
    write_csv(args.output, rows)
    counts = collections.Counter(row['locale'] for row in rows)
    print(json.dumps(dict(counts), ensure_ascii=False, sort_keys=True))


if __name__ == '__main__':
    main()
