#!/usr/bin/env python3
"""Read-only regression check for differing parent/variant manufacturers in a test copy."""
import sys
from pathlib import Path as _Path
sys.path.insert(0, str(_Path(__file__).resolve().parents[1] / 'tools'))
from local_http import local_origin, opener as local_opener

import collections
import json
import os
import re
import subprocess
import urllib.parse
import urllib.request


def main():
    db = urllib.parse.urlparse(os.environ.get('DATABASE_URL', ''))
    shop = urllib.parse.urlparse(os.environ.get('EL_SEARCH_TEST_URL', ''))
    engine = urllib.parse.urlparse(os.environ.get('SHOPWARE_MEILISEARCH_URL', ''))
    host = os.environ.get('EL_SEARCH_TEST_HOST', '')
    key = os.environ.get('SHOPWARE_MEILISEARCH_KEY', '')
    if (db.hostname not in ('127.0.0.1', 'localhost') or not re.fullmatch(r'/el_search_test_[a-f0-9]+', db.path)
            or shop.scheme != 'http' or shop.hostname != '127.0.0.1'
            or engine.scheme != 'http' or engine.hostname != '127.0.0.1'
            or not re.fullmatch(r'[a-zA-Z0-9.-]+', host) or not key):
        raise ValueError('An isolated local database, test storefront, search server, host and key are required')
    local_origin(os.environ['EL_SEARCH_TEST_URL'])
    local_origin(os.environ['SHOPWARE_MEILISEARCH_URL'])
    opener = local_opener()
    env = os.environ.copy()
    env['MYSQL_PWD'] = urllib.parse.unquote(db.password or '')
    mysql = ['mysql', '-N', '-h', db.hostname, '-P', str(db.port or 3306), '-u', urllib.parse.unquote(db.username or ''), db.path[1:]]

    def query(sql):
        return subprocess.check_output(mysql + ['-e', sql], env=env, text=True).splitlines()

    domain = query("SELECT LOWER(HEX(d.sales_channel_id)),LOWER(HEX(d.language_id)) FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE o.code='de-DE' AND s.active=1 LIMIT 1")
    if len(domain) != 1:
        raise ValueError('An active German test sales channel is required')
    channel, language = domain[0].split('\t')
    index = 'shopware_' + channel + '_' + language
    rows = query("SELECT LOWER(HEX(p.id)),LOWER(HEX(c.id)),LOWER(HEX(p.product_manufacturer_id)) FROM product c JOIN product p ON p.id=c.parent_id AND p.version_id=c.parent_version_id WHERE c.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND c.product_manufacturer_id IS NOT NULL AND p.product_manufacturer_id IS NOT NULL AND NOT(c.product_manufacturer_id <=> p.product_manufacturer_id)")

    def engine_call(method, path, payload=None):
        request = urllib.request.Request(os.environ['SHOPWARE_MEILISEARCH_URL'].rstrip('/') + path, method=method,
            data=json.dumps(payload).encode() if payload is not None else None,
            headers={'Authorization': 'Bearer ' + key, 'Content-Type': 'application/json'})
        with opener.open(request, timeout=30) as response:
            return json.load(response)

    def storefront(params):
        request = urllib.request.Request(os.environ['EL_SEARCH_TEST_URL'].rstrip('/') + '/de/shopware-meilisearch?' + urllib.parse.urlencode(params), headers={'Host': host})
        with opener.open(request, timeout=30) as response:
            return response.read().decode()

    counts = collections.Counter()
    for row in rows:
        parent, child, brand = row.split('\t')
        try:
            document = engine_call('GET', '/indexes/' + index + '/documents/' + parent)
            engine_call('GET', '/indexes/' + index + '/documents/' + child)
        except urllib.error.HTTPError as error:
            if error.code == 404:
                continue
            raise
        name, number = document.get('name'), document.get('productNumber')
        if not isinstance(name, str) or not isinstance(number, str) or not name or not number:
            continue
        hits = engine_call('POST', '/indexes/' + index + '/search', {'q': name, 'limit': 10000, 'attributesToRetrieve': ['id']})['hits']
        if not {parent, child}.issubset({hit['id'] for hit in hits}):
            continue
        # Use only cases where the parent itself is visible with this filter.
        if 'data-product-id="' + parent + '"' not in storefront({'q': number, 'manufacturer[]': brand}):
            continue
        counts['eligible'] += 1
        if 'value="' + brand + '"' not in storefront({'q': name}):
            raise AssertionError('A matching parent manufacturer is missing from the facet choices')
        if 'data-product-id="' + parent + '"' not in storefront({'q': name, 'manufacturer[]': brand}):
            raise AssertionError('A matching parent disappeared after filtering out its variant')
    if not counts['eligible']:
        raise ValueError('No suitable indexed parent/variant test case found')
    print('Variant manufacturer facets and filtered parent cards verified:', counts['eligible'], 'families')


if __name__ == '__main__':
    main()
