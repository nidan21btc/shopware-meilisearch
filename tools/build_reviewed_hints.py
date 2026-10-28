#!/usr/bin/env python3
"""Convert explicitly reviewed CSV rows into private, language-specific hints.

No data is imported into Shopware by this tool. The resulting file contains
customer search text and needs the same protection as the review CSV.
"""
import argparse
import collections
import csv
import json
import os
import re
from pathlib import Path

LOCALE = re.compile(r'^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})+$')
PRODUCT = re.compile(r'^[a-f0-9]{32}$')
REQUIRED = {'Sprache', 'Suchbegriff', 'Shopware Produkt-ID', 'Indexstatus', 'Passt zur Suche?'}


def build(rows):
    output = collections.defaultdict(lambda: collections.defaultdict(list))
    approved = 0
    for number, row in enumerate(rows, start=2):
        decision = (row.get('Passt zur Suche?') or '').strip().casefold()
        if decision in ('', 'nein', 'no', 'n'):
            continue
        if decision not in ('ja', 'yes', 'j', 'y'):
            raise ValueError(f'Row {number}: use ja, nein or leave the decision empty')
        locale = (row.get('Sprache') or '').strip()
        product = (row.get('Shopware Produkt-ID') or '').strip()
        query = (row.get('Suchbegriff') or '').strip()
        if not LOCALE.fullmatch(locale) or not PRODUCT.fullmatch(product):
            raise ValueError(f'Row {number}: invalid language or product ID')
        if row.get('Indexstatus', '').strip() != 'indexed':
            raise ValueError(f'Row {number}: the product was not in the language index')
        if not query or len(query) > 80:
            raise ValueError(f'Row {number}: query must contain 1–80 characters')
        if query.startswith("'") and query[1:].lstrip().startswith(('=', '+', '-', '@')):
            raise ValueError(f'Row {number}: spreadsheet-escaped query needs manual review before import')
        terms = output[locale][product]
        if query not in terms:
            terms.append(query)
            approved += 1
        if len(terms) > 50:
            raise ValueError(f'Row {number}: at most 50 hints per product are supported')
    return {locale: dict(products) for locale, products in output.items()}, approved


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--review', required=True, type=Path, help='Reviewed semicolon-delimited CSV')
    parser.add_argument('--output', required=True, type=Path, help='New private JSON; must not exist')
    args = parser.parse_args()
    with args.review.open(encoding='utf-8-sig', newline='') as source:
        reader = csv.DictReader(source, delimiter=';')
        if not reader.fieldnames or not REQUIRED.issubset(reader.fieldnames):
            raise ValueError('Review CSV is missing required columns')
        result, count = build(reader)
    if count == 0:
        raise ValueError('No rows are explicitly approved; no file was written')
    fd = os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w', encoding='utf-8') as target:
        json.dump(result, target, ensure_ascii=False, separators=(',', ':'))
        target.write('\n')
    print('Prepared', count, 'reviewed product-language associations in', len(result), 'languages; not imported.')


if __name__ == '__main__':
    main()
