#!/usr/bin/env python3
"""Evaluate local storefront recall on deterministic catalog-derived examples.

Only aggregates leave this process. The sample covers unique standalone product
names whose exact product number resolves in the test storefront; it is not an
independent relevance benchmark and does not cover ambiguous variant families.
"""
import argparse
import collections
import hashlib
import json
import os
import re
import unicodedata
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from pathlib import Path

from local_http import local_origin, opener


class Cards(HTMLParser):
    def __init__(self):
        super().__init__()
        self.ids = []
        self.status = False

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if 'data-el-result-status' in attrs:
            self.status = True
        if tag == 'article' and 'el-search__card' in attrs.get('class', '').split():
            self.ids.append(attrs.get('data-product-id'))


def normalized(value):
    return unicodedata.normalize('NFC', value).strip().casefold()


def typo(name):
    words = list(re.finditer(r'[^\W\d_]+', name, re.UNICODE))
    if not words:
        return None
    word = max(words, key=lambda match: len(match.group()))
    if len(word.group()) < 6:
        return None
    i = word.start() + len(word.group()) // 2
    return name[:i] + name[i + 1:]


def partial(name):
    words = list(re.finditer(r'[^\W\d_]+', name, re.UNICODE))
    unique = {}
    for match in words:
        word = match.group()
        if len(word) >= 4:
            unique.setdefault(normalized(word), match)
    if len(unique) < 2:
        return None
    selected = sorted(sorted(unique.values(), key=lambda m: (-len(m.group()), m.start()))[:2], key=lambda m: m.start())
    return ' '.join(m.group() for m in selected)


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--shop', required=True)
    p.add_argument('--host', required=True)
    p.add_argument('--engine', required=True)
    p.add_argument('--index-map', required=True, type=Path)
    p.add_argument('--per-locale', type=int, default=50)
    p.add_argument('--output', required=True, type=Path)
    p.add_argument('--mode', choices=('full', 'partial'), default='full')
    p.add_argument('--locales', nargs='+', help='Optional subset of configured locales')
    a = p.parse_args()
    if not re.fullmatch(r'[A-Za-z0-9.-]+(?::[0-9]+)?', a.host) or not 1 <= a.per_locale <= 500:
        p.error('Invalid host or sample size')
    shop, engine = local_origin(a.shop), local_origin(a.engine)
    key = os.environ.get('SHOPWARE_MEILISEARCH_KEY')
    if not key:
        p.error('SHOPWARE_MEILISEARCH_KEY is required')
    http = opener()
    index_map = json.loads(a.index_map.read_text(encoding='utf-8'))
    if set(index_map) != {'de-DE', 'en-GB', 'es-ES', 'fr-FR', 'it-IT'}:
        raise ValueError('Expected exactly the five test locales')
    if a.locales and not set(a.locales) <= set(index_map):
        p.error('Unknown locale')
    results = {}
    for locale, index in index_map.items():
        if a.locales and locale not in a.locales:
            continue
        if not re.fullmatch(r'[A-Za-z0-9_-]+', index):
            raise ValueError('Invalid index name')
        def engine_get(path):
            req = urllib.request.Request(engine + '/indexes/' + index + path, headers={'Authorization': 'Bearer ' + key})
            with http.open(req, timeout=60) as response:
                return json.load(response)
        if 'productNumber' not in engine_get('/settings/searchable-attributes'):
            raise ValueError('Product-number lookup must be enabled')
        response = engine_get('/documents?limit=10000')
        docs = response['results']
        if response['total'] != len(docs):
            raise ValueError('Index exceeds evaluation limit of 10000 documents')
        groups = collections.Counter(d.get('groupId') for d in docs)
        names = collections.Counter(normalized(d.get('name') or '') for d in docs)
        candidates = []
        for d in docs:
            name, pid, number = d.get('name'), d.get('id'), d.get('productNumber')
            if not all(isinstance(v, str) and v for v in (name, pid, number)) or len(name) > 150:
                continue
            query = name if a.mode == 'full' else partial(name)
            broken = typo(query) if query else None
            if groups[d.get('groupId')] != 1 or names[normalized(name)] != 1 or not broken or (a.mode == 'full' and normalized(broken) in names) or (a.mode == 'partial' and normalized(query) == normalized(name)):
                continue
            candidates.append((hashlib.sha256((locale + '\0' + pid).encode()).digest(), pid, number, query, broken))
        candidates.sort()
        def search(query, page=1):
            path = '/' + locale[:2] + '/shopware-meilisearch?' + urllib.parse.urlencode({'q': query, 'page': page})
            req = urllib.request.Request(shop + path, headers={'Host': a.host, 'X-Requested-With': 'XMLHttpRequest'})
            try:
                with http.open(req, timeout=90) as response:
                    html = response.read().decode('utf-8')
            except Exception:
                raise RuntimeError('Local storefront request failed for ' + locale) from None
            parser = Cards(); parser.feed(html)
            if not parser.status:
                raise RuntimeError('Unexpected storefront response for ' + locale)
            return parser.ids
        def engine_rank(query, pid):
            data = json.dumps({'q': query, 'hitsPerPage': 10000, 'attributesToRetrieve': ['id'], 'matchingStrategy': 'frequency'}).encode()
            req = urllib.request.Request(engine + '/indexes/' + index + '/search', data=data, headers={'Authorization': 'Bearer ' + key, 'Content-Type': 'application/json'})
            try:
                with http.open(req, timeout=60) as response:
                    hits = json.load(response)['hits']
            except Exception:
                raise RuntimeError('Local engine request failed for ' + locale) from None
            return next((position for position, hit in enumerate(hits, 1) if hit.get('id') == pid), None)
        counts = {'engine_missing': 0, 'engine_top20_but_storefront_not': 0, 'engine_after20': 0, 'sample': 0, 'sku_not_visible': 0, 'exact_top20': 0, 'typo_top20': 0,
                  'exact_top40': 0, 'typo_top40': 0}
        for _, pid, number, name, broken in candidates:
            if pid not in search(number):
                counts['sku_not_visible'] += 1
                continue
            counts['sample'] += 1
            for label, query in (('exact', name), ('typo', broken)):
                first = search(query)
                if pid in first:
                    counts[label + '_top20'] += 1
                    counts[label + '_top40'] += 1
                elif pid in search(query, 2):
                    counts[label + '_top40'] += 1
                else:
                    rank = engine_rank(query, pid)
                    category = 'engine_missing' if rank is None else ('engine_top20_but_storefront_not' if rank <= 20 else 'engine_after20')
                    counts[category] += 1
            if counts['sample'] == a.per_locale:
                break
        if counts['sample'] != a.per_locale:
            raise RuntimeError('Not enough eligible storefront products for ' + locale)
        results[locale] = counts
        print(locale + ': ' + json.dumps(counts, sort_keys=True), flush=True)
    fd = os.open(a.output, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(fd, 'w', encoding='utf-8') as output:
        json.dump({'method': ('unique standalone catalog names; ' + ('full name' if a.mode == 'full' else 'two longest distinct words in original order') + '; internal letter deletion in longest word; SKU-visible anonymous storefront'), 'results': results}, output, indent=2)
        output.write('\n')


if __name__ == '__main__':
    main()
