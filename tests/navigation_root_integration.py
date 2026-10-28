#!/usr/bin/env python3
"""Verify navigation-root filtering against a local Shopware storefront."""
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
        self.categories = []
        self.checked = []
        self.products = []
        self.has_status = False

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'input' and attrs.get('name') == 'category[]':
            self.categories.append(attrs.get('value'))
            if 'checked' in attrs:
                self.checked.append(attrs.get('value'))
        if tag == 'article' and attrs.get('data-product-id'):
            self.products.append(attrs['data-product-id'])
        if 'data-el-result-status' in attrs:
            self.has_status = True


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url', required=True, help='Loopback HTTP origin')
    parser.add_argument('--host', required=True, help='Local development storefront hostname')
    parser.add_argument('--locale', default='de', choices=['de', 'en', 'es', 'fr', 'it'])
    parser.add_argument('--root', required=True, help='Navigation root category ID for this sales channel')
    parser.add_argument('--query', required=True, help='Search term with products and child categories')
    args = parser.parse_args()
    try:
        origin = local_origin(args.url)
    except ValueError as error:
        parser.error(str(error))
    if not args.host.replace('-', '').replace('.', '').isalnum():
        parser.error('Invalid test host')
    if len(args.root) != 32 or any(char not in '0123456789abcdef' for char in args.root):
        parser.error('The root must be a lowercase Shopware ID')
    opener = local_opener()

    def search(categories=()):
        time.sleep(0.5)
        parameters = urlencode([('q', args.query), *[('category[]', value) for value in categories]])
        request = Request(origin.rstrip('/') + '/' + args.locale + '/shopware-meilisearch?' + parameters,
                          headers={'Host': args.host, 'X-Requested-With': 'XMLHttpRequest'})
        with opener.open(request, timeout=45) as response:
            result = Results()
            result.feed(response.read().decode('utf-8'))
        assert result.has_status, 'Search results were not rendered'
        return result

    baseline = search()
    assert baseline.products and baseline.categories, 'The test requires products and child categories'
    assert args.root not in baseline.categories, 'Navigation root appears as a filter'
    old_link = search([args.root])
    assert old_link.products == baseline.products and not old_link.checked, 'An old root filter is not ignored'
    child = baseline.categories[0]
    child_only = search([child])
    child_and_root = search([child, args.root])
    assert child in child_only.checked and child in child_and_root.checked, 'Child filter was lost'
    assert child_only.products == child_and_root.products, 'Root changed the child-filtered products'
    assert child_only.checked == child_and_root.checked, 'Root changed selected filters'
    print('Navigation root hidden; old links and child filters behave correctly.')


if __name__ == '__main__':
    main()
