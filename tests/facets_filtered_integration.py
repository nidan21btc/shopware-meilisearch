#!/usr/bin/env python3
"""Check filter choices against actual cards on an isolated Shopware copy."""
import sys
from pathlib import Path as _Path
sys.path.insert(0, str(_Path(__file__).resolve().parents[1] / 'tools'))
from local_http import local_origin, opener as local_opener

import argparse
import html.parser
import urllib.parse
import urllib.request

class Result(html.parser.HTMLParser):
    def __init__(self):
        super().__init__()
        self.options = {'category[]': [], 'manufacturer[]': []}
        self.checked = {'category[]': [], 'manufacturer[]': []}
        self.cards = 0
        self.status = False
    def handle_starttag(self, tag, attributes):
        a = dict(attributes)
        if tag == 'input' and a.get('name') in self.options:
            self.options[a['name']].append(a['value'])
            if 'checked' in a: self.checked[a['name']].append(a['value'])
        if tag == 'article' and 'data-product-id' in a: self.cards += 1
        if 'data-el-result-status' in a: self.status = True

def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--url', required=True)
    p.add_argument('--host', required=True)
    p.add_argument('--locale', choices=['de', 'en', 'es', 'fr', 'it'], default='de')
    args = p.parse_args()
    try: args.url = local_origin(args.url)
    except ValueError as error: p.error(str(error))
    if not args.host.replace('-', '').replace('.', '').isalnum(): p.error('Invalid test host')
    opener = local_opener()
    def load(filters):
        params = {'q': 'bach', **filters}
        req = urllib.request.Request(args.url.rstrip('/') + '/' + args.locale + '/shopware-meilisearch?' + urllib.parse.urlencode(params, doseq=True), headers={'Host': args.host})
        with opener.open(req, timeout=45) as response: html = response.read().decode('utf-8')
        result = Result(); result.feed(html)
        assert result.status, 'Missing search result status'
        return result
    initial = load({})
    assert initial.options['category[]'], 'The test catalog needs category labels'
    if not initial.options['manufacturer[]']:
        priced = load({'maxPrice': '10'})
        categories = priced.options['category[]'][:5]
        assert categories, 'No categories to check against the price range'
        for option in categories:
            assert load({'maxPrice': '10', 'category[]': option}).cards, 'Category falls outside the active price range'
        print(f'{args.locale}: {len(categories)} price/category combinations verified; no localized manufacturer labels for this query.')
        return
    checked = 0
    for selected, other in [('category[]', 'manufacturer[]'), ('manufacturer[]', 'category[]')]:
        for value in initial.options[selected][:3]:
            filtered = load({selected: value})
            for option in filtered.options[other][:6]:
                if option in filtered.checked[other]: continue
                assert load({selected: value, other: option}).cards, 'Facet offered an option with no matching cards'
                checked += 1
    priced = load({'maxPrice': '10'})
    for kind in ('category[]', 'manufacturer[]'):
        for option in priced.options[kind][:5]:
            assert load({'maxPrice': '10', kind: option}).cards, 'Facet offered an option outside the active price range'
            checked += 1
    assert checked >= 3, 'Too few applicable facet combinations to check'
    # Find one incompatible pair from the original unfiltered choices. Even
    # after it yields zero cards, both selections must remain removable.
    contradiction = None
    for category in initial.options['category[]'][:3]:
        available = set(load({'category[]': category}).options['manufacturer[]'])
        missing = next((brand for brand in initial.options['manufacturer[]'] if brand not in available), None)
        if missing:
            contradiction = (category, missing)
            break
    if contradiction:
        category, brand = contradiction
        empty = load({'category[]': category, 'manufacturer[]': brand})
        assert not empty.cards, 'Test pair unexpectedly matches a product'
        assert category in empty.checked['category[]'] and brand in empty.checked['manufacturer[]'], 'A zero-result selection cannot be removed'
    print(f'{args.locale}: {checked} available combinations verified; incompatible selection checked: {bool(contradiction)}.')

if __name__ == '__main__': main()
