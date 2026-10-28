#!/usr/bin/env python3
"""Prepare a private review sheet from historical cases and the local storefront.

Historical clicks are context, not relevance labels. Search terms can contain
personal data. Only loopback storefront requests are allowed; no public search
service is contacted. The resulting CSV must be handled as confidential.
"""
import argparse
import csv
import hashlib
import json
import os
import re
import urllib.parse
import urllib.error
import urllib.request
from html.parser import HTMLParser
from pathlib import Path

from local_http import local_origin, opener
from review_zero_results import spreadsheet_text

LOCALE = re.compile(r'^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})+$')
PRODUCT = re.compile(r'^[a-f0-9]{32}$')


class ProductCards(HTMLParser):
    def __init__(self):
        super().__init__()
        self.status = False
        self.cards = []
        self.current = None
        self.name_depth = 0

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if 'data-el-result-status' in attrs:
            self.status = True
        if tag == 'article' and 'el-search__card' in attrs.get('class', '').split():
            identifier = attrs.get('data-product-id', '')
            if not PRODUCT.fullmatch(identifier):
                raise ValueError('Invalid product card identifier')
            self.current = {'id': identifier, 'name': []}
        elif self.current is not None and 'el-search__name' in attrs.get('class', '').split():
            self.name_depth = 1
        elif self.name_depth:
            self.name_depth += 1

    def handle_endtag(self, tag):
        if self.name_depth:
            self.name_depth -= 1
        if tag == 'article' and self.current is not None:
            self.current['name'] = ' '.join(''.join(self.current['name']).split())
            self.cards.append(self.current)
            self.current = None
            self.name_depth = 0

    def handle_data(self, data):
        if self.current is not None and self.name_depth:
            self.current['name'].append(data)


def choose_cases(benchmark, partition, per_language):
    selected = {}
    for locale, language in benchmark['languages'].items():
        if not LOCALE.fullmatch(locale):
            raise ValueError('Invalid locale')
        eligible = []
        for case in language['cases']:
            query = case.get('query')
            clicks = case.get('relevantProducts')
            if (case.get('partition') != partition or not isinstance(query, str)
                    or not query.strip() or len(query) > 150 or not isinstance(clicks, dict) or not clicks):
                continue
            if any(not PRODUCT.fullmatch(pid) or not isinstance(n, int) or isinstance(n, bool) or n < 1 for pid, n in clicks.items()):
                raise ValueError('Invalid mapped click data')
            if not isinstance(case.get('searches'), int) or isinstance(case['searches'], bool) or case['searches'] < 0:
                raise ValueError('Invalid search count')
            eligible.append(case)
        eligible.sort(key=lambda c: hashlib.sha256((locale+'\0'+c['query']).encode('utf-8')).digest())
        selected[locale] = eligible[:per_language]
    return selected


def collect(selected, shop, host, maximum_cards, engine=None, indexes=None, key=None):
    http = opener()
    for locale, cases in selected.items():
        for case in cases:
            url = shop + '/' + locale[:2].lower() + '/shopware-meilisearch?' + urllib.parse.urlencode({'q': case['query']})
            request = urllib.request.Request(url, headers={'Host': host, 'X-Requested-With': 'XMLHttpRequest'})
            try:
                with http.open(request, timeout=60) as response:
                    html = response.read().decode('utf-8')
            except Exception:
                # Request exceptions can include the private query in the URL.
                raise RuntimeError('Local storefront request failed; review the test service.') from None
            parser = ProductCards()
            parser.feed(html)
            if not parser.status:
                raise ValueError('Unexpected local storefront response')
            cards = parser.cards[:maximum_cards]
            if not cards:
                yield [locale, case['query'], case['searches'], 'Kein lokales Ergebnis', '', '', '', '', '']
            for rank, card in enumerate(cards, 1):
                yield [locale, case['query'], case['searches'], 'Lokales Ergebnis', rank, card['id'], card['name'],
                       case['relevantProducts'].get(card['id'], 0), '']
            shown = {card['id'] for card in cards}
            missing = sorted(((pid, clicks) for pid, clicks in case['relevantProducts'].items() if pid not in shown),
                             key=lambda item: (-item[1], item[0]))[:5]
            for pid, clicks in missing:
                name = ''
                if engine is not None:
                    request = urllib.request.Request(engine + '/indexes/' + indexes[locale] + '/documents/' + pid,
                                                     headers={'Authorization': 'Bearer ' + key})
                    try:
                        with http.open(request, timeout=30) as response:
                            document = json.load(response)
                        name = document.get('name', '')
                        if not isinstance(name, str):
                            name = ''
                    except urllib.error.HTTPError as error:
                        if error.code != 404:
                            raise RuntimeError('Local index lookup failed.') from None
                    except Exception:
                        raise RuntimeError('Local index lookup failed.') from None
                yield [locale, case['query'], case['searches'], 'Historischer Klick außerhalb der angezeigten Treffer', '', pid, name, clicks, '']


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--benchmark', required=True, type=Path, help='Locally mapped benchmark JSON')
    parser.add_argument('--shop', required=True, help='Loopback test storefront origin')
    parser.add_argument('--host', required=True, help='Test storefront virtual host')
    parser.add_argument('--output', required=True, type=Path, help='New confidential CSV; must not exist')
    parser.add_argument('--engine', help='Optional loopback search engine origin for historical product names')
    parser.add_argument('--indexes', type=Path, help='Locale-to-index JSON; required with --engine')
    parser.add_argument('--partition', choices=['development', 'holdout'], default='holdout')
    parser.add_argument('--per-language', type=int, default=10)
    parser.add_argument('--max-products', type=int, default=10)
    parser.add_argument('--configuration-label', default='Nicht dokumentiert', help='Manually supplied description of the tested search configuration')
    args = parser.parse_args()
    shop = local_origin(args.shop)
    if (not re.fullmatch(r'[A-Za-z0-9.-]+', args.host) or not 1 <= args.per_language <= 50 or not 1 <= args.max_products <= 20
            or not args.configuration_label.strip() or len(args.configuration_label) > 120 or any(ord(c) < 32 for c in args.configuration_label)):
        parser.error('Invalid host, sample size or configuration label')
    benchmark = json.loads(args.benchmark.read_text(encoding='utf-8'))
    selected = choose_cases(benchmark, args.partition, args.per_language)
    if bool(args.engine) != bool(args.indexes):
        parser.error('--engine and --indexes must be supplied together')
    engine = local_origin(args.engine) if args.engine else None
    indexes = json.loads(args.indexes.read_text(encoding='utf-8')) if args.indexes else None
    key = os.environ.get('SHOPWARE_MEILISEARCH_KEY', '') if engine else None
    if engine:
        if not key or not isinstance(indexes, dict):
            parser.error('A local search key and locale-to-index object are required')
        for locale in selected:
            if not isinstance(indexes.get(locale), str) or not re.fullmatch(r'[A-Za-z0-9_-]+', indexes[locale]):
                parser.error('A valid index is required for each locale')
    fd = os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_NOFOLLOW', 0), 0o600)
    count = 0
    with os.fdopen(fd, 'w', encoding='utf-8-sig', newline='') as output:
        writer = csv.writer(output, delimiter=';')
        writer.writerow(['Konfiguration', 'Sprache', 'Suchbegriff', 'Historische Suchen', 'Herkunft', 'Lokaler Rang', 'Shopware Produkt-ID', 'Produktname', 'Historische Klicks', 'Passt zur Suche?'])
        for row in collect(selected, shop, args.host, args.max_products, engine, indexes, key):
            writer.writerow([spreadsheet_text(value) for value in [args.configuration_label.strip(), *row]])
            count += 1
    print(json.dumps({'queries': {locale: len(cases) for locale, cases in selected.items()}, 'rows': count}))


if __name__ == '__main__':
    main()
