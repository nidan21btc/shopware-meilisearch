#!/usr/bin/env python3
"""Check initial discovery and normal facets against a local storefront."""
import argparse
from html.parser import HTMLParser
from pathlib import Path
import sys
import time
from urllib.parse import urlencode
from urllib.request import Request

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
from local_http import local_origin, opener as local_opener

class Results(HTMLParser):
    def __init__(self):
        super().__init__()
        self.products = []
        self.facets = []
        self.status = False

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'article' and attrs.get('data-product-id'):
            self.products.append(attrs['data-product-id'])
        if tag == 'input' and attrs.get('name') in ('category[]', 'manufacturer[]'):
            self.facets.append(attrs['name'])
        if 'data-el-result-status' in attrs:
            self.status = True


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url', required=True, help='Loopback HTTP origin')
    parser.add_argument('--host', required=True, help='Development storefront hostname')
    parser.add_argument('--locale', default='de', choices=['de', 'en', 'es', 'fr', 'it'])
    parser.add_argument('--query', required=True, help='Term with products and category/manufacturer facets')
    args = parser.parse_args()
    try:
        origin = local_origin(args.url)
    except ValueError as error:
        parser.error(str(error))
    if not args.host.replace('-', '').replace('.', '').isalnum():
        parser.error('Invalid test host')
    opener = local_opener()

    def search(query):
        time.sleep(0.5)
        url = f'{origin}/{args.locale}/shopware-meilisearch?' + urlencode({'q': query})
        with opener.open(Request(url, headers={'Host': args.host, 'X-Requested-With': 'XMLHttpRequest'}), timeout=45) as response:
            result = Results()
            result.feed(response.read().decode('utf-8'))
        assert result.status, 'Search result status is missing'
        return result

    first, repeat = search(''), search('')
    assert first.products and len(first.products) <= 10, 'Discovery carousel is empty or too large'
    assert first.products == repeat.products, 'Discovery product order changed'
    assert not first.facets, 'Discovery must not show search filters'
    normal = search(args.query)
    assert normal.products and 'category[]' in normal.facets and 'manufacturer[]' in normal.facets, 'Normal search or facets are missing'
    print('Discovery products and normal search facets work.')

if __name__ == '__main__':
    main()
