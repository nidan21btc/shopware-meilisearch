#!/usr/bin/env python3
"""Read-only HTTP checks against an explicitly provided test storefront."""
import sys
from pathlib import Path as _Path
sys.path.insert(0, str(_Path(__file__).resolve().parents[1] / 'tools'))
from local_http import local_origin, opener as local_opener

import argparse
import re
import urllib.parse
import urllib.request
from html.parser import HTMLParser


class ResultParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.products = []
        self.facets = {'category[]': [], 'manufacturer[]': []}
        self.status = None
        self.suggestions = 0
        self.slider_count = 0
        self.slider_bounds = None
        self.prices = []
        self._price_text = None
        self.names = []
        self._name_text = None

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'article' and 'el-search__card' in attrs.get('class', '').split():
            self.products.append(attrs['data-product-id'])
        if tag == 'input' and attrs.get('name') in self.facets:
            self.facets[attrs['name']].append(attrs['value'])
        if 'data-el-result-status' in attrs:
            self.status = attrs['data-el-result-status']
        if 'data-el-suggestion' in attrs:
            self.suggestions += 1
        if 'data-el-price-slider' in attrs:
            self.slider_count += 1
            self.slider_bounds = (float(attrs['min']), float(attrs['max']))
        if tag == 'span' and 'el-search__current-price' in attrs.get('class', '').split():
            self._price_text = ''
        if tag == 'span' and 'el-search__name' in attrs.get('class', '').split():
            self._name_text = ''

    def handle_data(self, data):
        if self._price_text is not None:
            self._price_text += data
        if self._name_text is not None:
            self._name_text += data

    def handle_endtag(self, tag):
        if tag == 'span' and self._price_text is not None:
            match = re.search(r'(\d[\d.]*)[,](\d\d)', self._price_text)
            if match:
                self.prices.append(float(match.group(1).replace('.', '') + '.' + match.group(2)))
            self._price_text = None
        if tag == 'span' and self._name_text is not None:
            self.names.append(self._name_text.strip())
            self._name_text = None


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--url', required=True)
    p.add_argument('--host', help='Optional virtual-host header')
    args = p.parse_args()
    try: args.url = local_origin(args.url)
    except ValueError as error: p.error(str(error))
    opener = local_opener()
    headers = {'X-Requested-With': 'XMLHttpRequest'}
    if args.host:
        headers['Host'] = args.host

    def load(locale, params):
        path = f'/{locale}/shopware-meilisearch?' + urllib.parse.urlencode(params, doseq=True)
        req = urllib.request.Request(args.url.rstrip('/') + path, headers=headers)
        with opener.open(req, timeout=60) as response:
            if 'no-store' not in response.headers.get('Cache-Control', ''):
                raise AssertionError('Contextual search response must not be shared-cached')
            html = response.read().decode('utf-8')
        result = ResultParser()
        result.feed(html)
        if result.status is None:
            raise AssertionError('Missing rendered search status')
        return result

    for locale in ['de', 'en', 'es', 'fr', 'it']:
        start = load(locale, {'q': ''})
        assert len(start.products) == 10, f'{locale}: expected 10 discovery products'
        assert start.suggestions > 0, f'{locale}: missing locale-specific suggestions'
        search = load(locale, {'q': 'bach'})
        assert search.products, f'{locale}: expected Bach products'
        assert len(search.products) <= 20
        alphabetical = load(locale, {'q': 'bach', 'sort': 'name-asc'})
        assert len(alphabetical.names) == len(alphabetical.products), f'{locale}: missing displayed product names'
        # ASCII-only names have an unambiguous, language-independent order for
        # this fixture. Leave accented names to locale-specific collation tests.
        ascii_names = [name.casefold() for name in alphabetical.names if name.isascii()]
        assert len(ascii_names) >= 10, f'{locale}: not enough ASCII names to check sorting'
        assert ascii_names == sorted(ascii_names), f'{locale}: displayed names are not sorted'
        print(locale, len(search.products), 'products on page one')

    first = load('de', {'q': 'bach'})
    second = load('de', {'q': 'bach', 'page': '2'})
    assert first.products and second.products and set(first.products).isdisjoint(second.products), 'Pagination duplicates products'
    assert first.facets['category[]'] and first.facets['manufacturer[]'], 'Expected product facets'
    assert first.slider_count == 2, 'Expected two price slider handles'
    assert len(first.prices) == len(first.products) and all(first.slider_bounds[0] <= price <= first.slider_bounds[1] for price in first.prices), 'A displayed price is outside the slider bounds'
    expanded = load('de', {'q': 'bach', 'similar': '1'})
    assert expanded.slider_bounds[0] <= first.slider_bounds[0] and expanded.slider_bounds[1] >= first.slider_bounds[1], 'Expanding results unexpectedly narrowed the price bounds'
    assert len(expanded.prices) == len(expanded.products) and all(expanded.slider_bounds[0] <= price <= expanded.slider_bounds[1] for price in expanded.prices), 'An expanded result is outside the slider bounds'
    category = load('de', {'q': 'bach', 'category[]': first.facets['category[]'][0]})
    manufacturer = load('de', {'q': 'bach', 'manufacturer[]': first.facets['manufacturer[]'][0]})
    assert category.products and manufacturer.products, 'Selected facets lost all expected products'
    no_price_match = load('de', {'q': 'bach', 'maxPrice': '0'})
    assert not no_price_match.products, 'Price filtering failed'
    low = load('de', {'q': 'bach', 'maxPrice': '10'})
    assert low.products and len(low.prices) == len(low.products) and all(price <= 10 for price in low.prices), 'A displayed price exceeds the selected maximum'
    for direction in ['price-asc', 'price-desc']:
        priced = load('de', {'q': 'bach', 'minPrice': '10', 'maxPrice': '20', 'sort': direction})
        assert priced.products and len(priced.prices) == len(priced.products), 'Could not read each displayed price'
        assert all(10 <= price <= 20 for price in priced.prices), 'Displayed price is outside the requested range'
        assert priced.prices == sorted(priced.prices, reverse=direction == 'price-desc'), 'Cards are not sorted by their displayed price'
    print('Shopware integration checks passed: five locales, discovery, pagination, facets, prices, private caching.')


if __name__ == '__main__':
    main()
