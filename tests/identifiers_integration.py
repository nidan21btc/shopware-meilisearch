#!/usr/bin/env python3
"""Check exact article numbers and unique EANs in all five local storefronts."""
import sys
from pathlib import Path as _Path
sys.path.insert(0, str(_Path(__file__).resolve().parents[1] / 'tools'))
from local_http import local_origin, opener as local_opener

import argparse
import collections
import html.parser
import json
import os
import random
import re
import subprocess
import urllib.parse
import urllib.request

class Cards(html.parser.HTMLParser):
    def __init__(self): super().__init__(); self.ids = []
    def handle_starttag(self, tag, attributes):
        values = dict(attributes)
        if tag == 'article' and values.get('data-product-id'): self.ids.append(values['data-product-id'])

def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--sample-per-group', type=int, default=10)
    p.add_argument('--ean-sample', type=int, default=5)
    p.add_argument('--swap-case', action='store_true')
    args = p.parse_args()
    if not 1 <= args.sample_per_group <= 100 or not 0 <= args.ean_sample <= 100:
        p.error('Sample sizes are out of range')
    database = urllib.parse.urlparse(os.environ.get('DATABASE_URL', ''))
    storefront = urllib.parse.urlparse(os.environ.get('EL_SEARCH_TEST_URL', ''))
    engine = urllib.parse.urlparse(os.environ.get('SHOPWARE_MEILISEARCH_URL', ''))
    host = os.environ.get('EL_SEARCH_TEST_HOST', '')
    key = os.environ.get('SHOPWARE_MEILISEARCH_KEY', '')
    if (database.hostname not in ('127.0.0.1', 'localhost') or not re.fullmatch(r'/el_search_test_[a-f0-9]+', database.path)
            or storefront.scheme != 'http' or storefront.hostname != '127.0.0.1'
            or engine.scheme != 'http' or engine.hostname != '127.0.0.1'
            or not re.fullmatch(r'[a-zA-Z0-9.-]+', host) or not key):
        raise ValueError('An isolated local database, storefront, search server, host and key are required')
    local_origin(os.environ['EL_SEARCH_TEST_URL'])
    local_origin(os.environ['SHOPWARE_MEILISEARCH_URL'])
    opener = local_opener()
    env = os.environ.copy(); env['MYSQL_PWD'] = urllib.parse.unquote(database.password or '')
    sql = "SELECT DISTINCT o.code,LOWER(HEX(d.sales_channel_id)),LOWER(HEX(d.language_id)) FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND o.code IN ('de-DE','en-GB','es-ES','fr-FR','it-IT')"
    mysql = ['mysql', '-N', '-h', database.hostname, '-P', str(database.port or 3306), '-u', urllib.parse.unquote(database.username or ''), database.path[1:], '-e', sql]
    domains = [row.split('\t') for row in subprocess.check_output(mysql, env=env, text=True).splitlines()]
    if len(domains) != 5 or len({row[0] for row in domains}) != 5: raise ValueError('Expected one domain per test language')
    def engine_get(path):
        request = urllib.request.Request(os.environ['SHOPWARE_MEILISEARCH_URL'].rstrip('/') + path, headers={'Authorization': 'Bearer ' + key})
        with opener.open(request, timeout=30) as response: return json.load(response)
    def search(locale, value):
        url = os.environ['EL_SEARCH_TEST_URL'].rstrip('/') + '/' + locale[:2] + '/shopware-meilisearch?' + urllib.parse.urlencode({'q': value})
        request = urllib.request.Request(url, headers={'Host': host})
        with opener.open(request, timeout=30) as response:
            parser = Cards(); parser.feed(response.read().decode('utf-8')); return parser.ids
    for locale, channel, language in domains:
        index = 'shopware_' + channel + '_' + language
        documents = []; offset = 0
        while True:
            page = engine_get('/indexes/' + index + '/documents?limit=1000&offset=' + str(offset))['results']
            documents.extend(page)
            if len(page) < 1000: break
            offset += len(page)
        rng = random.Random(443)
        groups = {True: [], False: []}
        for doc in documents:
            if isinstance(doc.get('productNumber'), str) and doc['productNumber']:
                groups[doc.get('groupId') != doc['id']].append(doc)
        for variant, docs in groups.items():
            if args.swap_case: docs = [doc for doc in docs if doc['productNumber'].lower() != doc['productNumber'].upper()]
            if len(docs) < args.sample_per_group: raise ValueError('Test catalog does not have enough indexed articles in this group')
            rng.shuffle(docs)
            for doc in docs[:args.sample_per_group]:
                value = doc['productNumber'].swapcase() if args.swap_case else doc['productNumber']
                hits = search(locale, value)
                if not hits or hits[0] != doc['id']: raise AssertionError(locale + ': exact article number did not put its product first')
        counts = collections.Counter(doc.get('ean') for doc in documents if isinstance(doc.get('ean'), str) and doc['ean'])
        numbers = {doc.get('productNumber') for doc in documents}
        eans = [doc for doc in documents if isinstance(doc.get('ean'), str) and doc['ean'].isdigit()
                and 8 <= len(doc['ean']) <= 14 and counts[doc['ean']] == 1 and doc['ean'] not in numbers]
        if len(eans) < args.ean_sample: raise ValueError('Test catalog does not have enough unique EANs')
        rng.shuffle(eans)
        for doc in eans[:args.ean_sample]:
            hits = search(locale, doc['ean'])
            if not hits or hits[0] != doc['id']: raise AssertionError(locale + ': unique EAN did not put its product first')
        print(f'{locale}: {args.sample_per_group} variant numbers and {args.sample_per_group} other article numbers verified' + (' with swapped letter case' if args.swap_case else '') + f'; {args.ean_sample} unique EANs verified.', flush=True)

if __name__ == '__main__': main()
