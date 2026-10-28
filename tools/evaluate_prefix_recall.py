#!/usr/bin/env python3
"""Measure local storefront recall while a product-name word is being typed.

Only aggregate counts are saved. This does not judge ambiguous customer intent.
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
from pathlib import Path

from evaluate_catalog_recall import Cards
from local_http import local_origin, opener


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--shop', required=True)
    p.add_argument('--host', required=True)
    p.add_argument('--engine', required=True)
    p.add_argument('--index-map', type=Path, required=True)
    p.add_argument('--per-locale', type=int, default=20)
    p.add_argument('--output', type=Path, required=True)
    p.add_argument('--locales', nargs='+', help='Optional subset of configured locales')
    a = p.parse_args()
    if not re.fullmatch(r'[A-Za-z0-9.-]+(?::[0-9]+)?', a.host) or not 1 <= a.per_locale <= 100:
        p.error('Invalid host or sample size')
    shop, engine = local_origin(a.shop), local_origin(a.engine)
    key = os.environ.get('SHOPWARE_MEILISEARCH_KEY')
    if not key:
        p.error('SHOPWARE_MEILISEARCH_KEY is required')
    indexes = json.loads(a.index_map.read_text(encoding='utf-8'))
    if set(indexes) != {'de-DE', 'en-GB', 'es-ES', 'fr-FR', 'it-IT'}:
        p.error('Expected exactly the five test locales')
    if a.locales and not set(a.locales) <= set(indexes):
        p.error('Unknown locale')
    http = opener(); results = {}
    for locale, index in indexes.items():
        if a.locales and locale not in a.locales:
            continue
        if not re.fullmatch(r'[A-Za-z0-9_-]+', index):
            raise ValueError('Invalid index name')
        req = urllib.request.Request(engine + '/indexes/' + index + '/documents?limit=10000', headers={'Authorization': 'Bearer ' + key})
        with http.open(req, timeout=60) as response:
            payload = json.load(response)
        docs = payload['results']
        if payload['total'] != len(docs):
            raise ValueError('Index exceeds evaluation limit of 10000 documents')
        groups = collections.Counter(d.get('groupId') for d in docs)
        words = collections.Counter()
        for d in docs:
            name = unicodedata.normalize('NFC', d.get('name') or '')
            words.update(set(w.casefold() for w in re.findall(r'[^\W\d_]{8,}', name)))
        candidates = []
        for d in docs:
            name, pid = unicodedata.normalize('NFC', d.get('name') or ''), d.get('id')
            if not isinstance(pid, str) or groups[d.get('groupId')] != 1:
                continue
            eligible = [w for w in re.findall(r'[^\W\d_]{8,}', name) if words[w.casefold()] == 1]
            if eligible:
                word = max(eligible, key=lambda w: (len(w), w))
                candidates.append((hashlib.sha256((locale + '\0' + pid).encode()).digest(), pid, word))
        candidates.sort()
        def search(query, page=1):
            path = '/' + locale[:2] + '/shopware-meilisearch?' + urllib.parse.urlencode({'q': query, 'page': page})
            req = urllib.request.Request(shop + path, headers={'Host': a.host, 'X-Requested-With': 'XMLHttpRequest'})
            try:
                with http.open(req, timeout=90) as response:
                    html = response.read().decode('utf-8')
            except Exception:
                raise RuntimeError('Local storefront request failed for ' + locale) from None
            cards = Cards(); cards.feed(html)
            if not cards.status:
                raise RuntimeError('Unexpected storefront response for ' + locale)
            return cards.ids[:20]
        counts = {'cases': 0, 'whole_word_not_top20': 0, 'prefix_3_top20': 0, 'prefix_4_top20': 0, 'prefix_5_top20': 0, 'prefix_3_top40': 0}
        for _, pid, word in candidates:
            if pid not in search(word):
                counts['whole_word_not_top20'] += 1
                continue
            counts['cases'] += 1
            for n in (3, 4, 5):
                first = pid in search(word[:n])
                counts[f'prefix_{n}_top20'] += first
                if n == 3:
                    counts['prefix_3_top40'] += first or pid in search(word[:n], 2)
            if counts['cases'] == a.per_locale:
                break
        if counts['cases'] != a.per_locale:
            raise RuntimeError('Not enough eligible products in ' + locale)
        results[locale] = counts
        print(locale, json.dumps(counts, sort_keys=True), flush=True)
    fd = os.open(a.output, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(fd, 'w', encoding='utf-8') as out:
        json.dump({'method': 'Unique >=8 letter name word of standalone products; conditioned on whole-word first-page visibility; first 3/4/5 letters; anonymous local storefront', 'results': results}, out, indent=2)
        out.write('\n')


if __name__ == '__main__':
    main()
