#!/usr/bin/env python3
"""Create a private, human-reviewable list of historical clicks for local zero results.

Search terms can contain personal data. Output is created with mode 0600 and
must remain local; clicks are incomplete evidence, not relevance judgments.
"""
import argparse
import collections
import csv
import json
import os
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from pathlib import Path


class Cards(HTMLParser):
    def __init__(self):
        super().__init__()
        self.count = 0

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'article' and 'el-search__card' in attrs.get('class', '').split():
            self.count += 1


def spreadsheet_text(value):
    """Keep customer-supplied text from becoming a spreadsheet formula."""
    text = str(value)
    return "'" + text if text.lstrip().startswith(('=', '+', '-', '@')) or text.startswith(('\t', '\r', '\n')) else text


def local_origin(value):
    url = urllib.parse.urlsplit(value)
    if url.scheme != 'http' or url.hostname != '127.0.0.1' or url.username or url.password or url.query or url.fragment or url.path not in ('', '/'):
        raise ValueError('Expected a local HTTP loopback origin without credentials, path or query')
    return value.rstrip('/')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--benchmark', type=Path, required=True)
    parser.add_argument('--mapped', type=Path, required=True)
    parser.add_argument('--indexes', type=Path, required=True)
    parser.add_argument('--shop', required=True)
    parser.add_argument('--host', required=True)
    parser.add_argument('--engine', required=True)
    parser.add_argument('--output', type=Path, required=True, help='New private CSV; must not already exist')
    args = parser.parse_args()
    shop = local_origin(args.shop)
    engine = local_origin(args.engine)
    if not args.host or any(c not in 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-' for c in args.host):
        parser.error('Invalid test storefront host')
    key = os.environ.get('SHOPWARE_MEILISEARCH_KEY', '')
    if not key:
        parser.error('Set SHOPWARE_MEILISEARCH_KEY for the local test engine')
    benchmark = json.loads(args.benchmark.read_text(encoding='utf-8'))
    mapped = json.loads(args.mapped.read_text(encoding='utf-8'))
    indexes = json.loads(args.indexes.read_text(encoding='utf-8'))
    from local_http import opener as local_opener
    opener = local_opener()
    rows = []
    for locale, language in benchmark['languages'].items():
        if locale not in indexes or locale not in mapped['languages']:
            raise ValueError('Missing language index or mapping')
        index = indexes[locale]
        if not isinstance(index, str) or not all(c.isascii() and (c.isalnum() or c in '_-') for c in index):
            raise ValueError('Invalid index name')
        references = mapped['languages'][locale]['referenceMap']
        for case in language['cases']:
            if not case['reportedNoResults'] or not case['relevantProducts']:
                continue
            products = collections.Counter()
            for external, clicks in case['relevantProducts'].items():
                if external in references:
                    products[references[external]] += clicks
            if not products:
                continue
            path = '/' + locale[:2].lower() + '/shopware-meilisearch?' + urllib.parse.urlencode({'q': case['query']})
            req = urllib.request.Request(shop + path, headers={'Host': args.host})
            with opener.open(req, timeout=60) as response:
                html = response.read().decode('utf-8')
            if 'data-el-result-status' not in html:
                raise ValueError('Unexpected storefront response')
            cards = Cards()
            cards.feed(html)
            if cards.count:
                continue
            for product, clicks in products.items():
                if len(product) != 32 or any(c not in '0123456789abcdef' for c in product):
                    raise ValueError('Invalid mapped product ID')
                req = urllib.request.Request(engine + '/indexes/' + index + '/documents/' + product, headers={'Authorization': 'Bearer ' + key})
                try:
                    with opener.open(req, timeout=30) as response:
                        document = json.load(response)
                    name, state = document.get('name', ''), 'indexed'
                except urllib.error.HTTPError as error:
                    if error.code != 404:
                        raise
                    name, state = '', 'not indexed'
                rows.append([locale, spreadsheet_text(case['query']), case['searches'], clicks, product, spreadsheet_text(name), state, ''])
    fd = os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w', encoding='utf-8-sig', newline='') as output:
        writer = csv.writer(output, delimiter=';')
        writer.writerow(['Sprache', 'Suchbegriff', 'Historische Suchen', 'Zugeordnete Klicks', 'Shopware Produkt-ID', 'Produktname', 'Indexstatus', 'Passt zur Suche?'])
        writer.writerows(rows)
    print('Private review CSV created:', len(rows), 'product associations')


if __name__ == '__main__':
    main()
