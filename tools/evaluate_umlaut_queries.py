#!/usr/bin/env python3
"""Evaluate synthetic German two-word searches with transliterated umlauts.

Inputs and requests stay local. Only aggregates are printed and saved; this
measures target recall, not relevance of the other cards or customer intent.
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


def words_for_name(name):
    # casefold() would turn ß into ss before the test can select it.
    return re.findall(r'[^\W\d_]{4,}', unicodedata.normalize('NFC', name).lower())


def transliterate(word):
    return word.translate(str.maketrans({'ä': 'ae', 'ö': 'oe', 'ü': 'ue', 'ß': 'ss'}))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--shop', required=True)
    parser.add_argument('--host', required=True)
    parser.add_argument('--engine', required=True)
    parser.add_argument('--index-map', type=Path, required=True)
    parser.add_argument('--sample', type=int, default=50)
    parser.add_argument('--require-character', choices=['ä', 'ö', 'ü', 'ß'])
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    if not re.fullmatch(r'[A-Za-z0-9.-]+(?::[0-9]+)?', args.host) or not 1 <= args.sample <= 500:
        parser.error('Invalid host or sample size')
    shop, engine = local_origin(args.shop), local_origin(args.engine)
    key = os.environ.get('SHOPWARE_MEILISEARCH_KEY')
    index = json.loads(args.index_map.read_text(encoding='utf-8')).get('de-DE')
    if not key or not isinstance(index, str) or not re.fullmatch(r'[A-Za-z0-9_-]+', index):
        parser.error('A local key and a valid German index are required')
    http = opener()
    request = urllib.request.Request(engine + '/indexes/' + index + '/documents?limit=10000', headers={'Authorization': 'Bearer ' + key})
    with http.open(request, timeout=60) as response:
        payload = json.load(response)
    docs = payload['results']
    if payload['total'] != len(docs):
        raise ValueError('Index exceeds evaluation limit of 10000 documents')
    groups = collections.Counter(doc.get('groupId') for doc in docs)
    candidates = []
    for doc in docs:
        name, pid = doc.get('name') or '', doc.get('id')
        if not isinstance(name, str) or not isinstance(pid, str) or groups[doc.get('groupId')] != 1:
            continue
        words = words_for_name(name)
        accented = [word for word in words if any(char in word for char in 'äöüß')]
        other = [word for word in words if word not in accented]
        if args.require_character:
            accented = [word for word in accented if args.require_character in word]
        if not accented or not other:
            continue
        first, second = max(accented, key=len), max(other, key=len)
        query = transliterate(first) + ' ' + second
        correct = first + ' ' + second
        candidates.append((hashlib.sha256(pid.encode()).digest(), pid, query, correct, first))
    candidates.sort()
    def search(query):
        path = '/de/shopware-meilisearch?' + urllib.parse.urlencode({'q': query})
        request = urllib.request.Request(shop + path, headers={'Host': args.host, 'X-Requested-With': 'XMLHttpRequest'})
        try:
            with http.open(request, timeout=90) as response:
                html = response.read().decode('utf-8')
        except Exception:
            raise RuntimeError('Local storefront request failed') from None
        cards = Cards(); cards.feed(html)
        if not cards.status:
            raise RuntimeError('Unexpected storefront response')
        return cards.ids[:20]
    counts = collections.Counter()
    for _, pid, query, correct, word in candidates:
        if pid not in search(correct):
            counts['correct_not_top20'] += 1
            continue
        counts['cases'] += 1
        counts['transliterated_top20'] += pid in search(query)
        for character in 'äöüß':
            counts['cases_with_' + character] += character in word
        if counts['cases'] == args.sample:
            break
    if counts['cases'] != args.sample:
        raise RuntimeError('Not enough eligible products')
    report = {'method': 'German standalone products, two words from product name, one transliterated; conditioned on correctly spelled query finding the target in first 20; current test configuration', 'required_character': args.require_character, 'results': dict(counts)}
    fd = os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w', encoding='utf-8') as output:
        json.dump(report, output, ensure_ascii=False, indent=2)
        output.write('\n')
    print(json.dumps(report['results'], ensure_ascii=False), flush=True)


if __name__ == '__main__':
    main()
