#!/usr/bin/env python3
"""Compare each displayed facet count with filtered totals in a local test shop."""
import argparse
import collections
import html.parser
import re
import sys
import urllib.parse
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
from local_http import local_origin, opener as local_opener


class Result(html.parser.HTMLParser):
    def __init__(self):
        super().__init__()
        self.current = None
        self.counting = False
        self.options = {}
        self.status = None
        self.may_change = False
        self.unmarked = 0

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'label' and 'data-el-facet-value' in attrs:
            self.current = [None, None, '']
        if self.current is not None and tag == 'input' and attrs.get('name') in ('manufacturer[]', 'category[]'):
            self.current[0], self.current[1] = attrs['name'], attrs.get('value')
            if self.may_change and attrs.get('aria-describedby') != 'el-search-facet-counts-note':
                self.unmarked += 1
        if self.current is not None and tag == 'span' and 'el-search__facet-count' in attrs.get('class', '').split():
            self.counting = True
        if 'data-el-result-status' in attrs:
            self.status = attrs['data-el-result-status']
        if 'data-el-facet-counts-may-change' in attrs:
            self.may_change = True

    def handle_data(self, data):
        if self.counting and self.current:
            self.current[2] += data

    def handle_endtag(self, tag):
        if tag == 'span' and self.counting:
            self.counting = False
        if tag == 'label' and self.current is not None:
            name, identifier, count = self.current
            text = count.strip()
            if self.may_change and not text.endswith('*'):
                self.unmarked += 1
            text = text.removesuffix('*').strip()
            if name and identifier and text.isdigit():
                self.options[(name, identifier)] = int(text)
            self.current = None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url', required=True)
    parser.add_argument('--host', required=True)
    parser.add_argument('--locale', choices=['de', 'en', 'es', 'fr', 'it'], default='de')
    parser.add_argument('--query', default='bach', help='A synthetic test query; never printed')
    parser.add_argument('--all', action='store_true', help='Check every offered value instead of the first 15 per facet')
    parser.add_argument('--expect-changing', action='store_true', help='Verify the notice for a multiword query with partial expansion')
    args = parser.parse_args()
    origin = local_origin(args.url)
    if not re.fullmatch(r'[a-zA-Z0-9.-]+', args.host):
        parser.error('Invalid local test host')
    client = local_opener()

    def load(params):
        url = origin + '/' + args.locale + '/shopware-meilisearch?' + urllib.parse.urlencode(params, doseq=True)
        with client.open(urllib.request.Request(url, headers={'Host': args.host}), timeout=90) as response:
            result = Result()
            result.feed(response.read().decode('utf-8'))
            return result

    initial = load({'q': args.query})
    if initial.status is None:
        raise ValueError('Unexpected storefront response')
    if initial.may_change != args.expect_changing or initial.unmarked:
        raise AssertionError('Facet count change notice or markers are incorrect')
    selected = []
    for kind in ['category[]', 'manufacturer[]']:
        choices = [item for item in initial.options.items() if item[0][0] == kind]
        selected.extend(choices if args.all else choices[:15])
    stats = collections.Counter()

    def compare(choices, other_filters, label=''):
        for (name, identifier), count in choices:
            result = load({'q': args.query, **other_filters, name: [identifier]})
            match = re.search(r'\d+', result.status or '')
            if not match:
                raise ValueError('Missing filtered result count')
            if result.options and (result.may_change != args.expect_changing or result.unmarked):
                raise AssertionError('Filtered facet count change notice or markers are incorrect')
            total = int(match.group())
            stats[label + name + '_checked'] += 1
            stats[label + name + '_mismatched'] += count != total
            stats[label + name + '_display_less_than_total'] += count < total
            stats[label + name + '_display_more_than_total'] += count > total

    compare(selected, {})
    brands = [identifier for (kind, identifier) in initial.options if kind == 'manufacturer[]']
    if brands:
        brand = brands[0]
        with_brand = load({'q': args.query, 'manufacturer[]': [brand]})
        categories = [((kind, identifier), count) for (kind, identifier), count in with_brand.options.items()
                      if kind == 'category[]' and count > 0]
        narrow = next((item for item in categories if item[1] < 20), categories[0] if categories else None)
        if narrow:
            category = narrow[0][1]
            context = load({'q': args.query, 'manufacturer[]': [brand], 'category[]': [category]})
            for kind, other in [('category[]', {'manufacturer[]': [brand]}), ('manufacturer[]', {'category[]': [category]})]:
                choices = [item for item in context.options.items() if item[0][0] == kind]
                chosen = choices if args.all else choices[:5] + choices[-5:]
                compare(list(dict(chosen).items()), other, 'context_')
    for extra, label in [({'similar': '1'}, 'similar_')]:
        context = load({'q': args.query, **extra})
        for kind in ('category[]', 'manufacturer[]'):
            choices = [item for item in context.options.items() if item[0][0] == kind]
            compare(choices if args.all else choices[:5], extra, label)
    if stats['category[]_checked'] < 5:
        raise ValueError('Too few category facets in the isolated catalog')
    print('Aggregate comparison:', dict(stats))
    if not args.expect_changing and any(value for key, value in stats.items() if key.endswith('_mismatched')):
        raise AssertionError('Facet counts do not match filtered result totals')
    print('Facet count labels and change notices verified.' if args.expect_changing else 'Facet counts match filtered result totals.')


if __name__ == '__main__':
    main()
