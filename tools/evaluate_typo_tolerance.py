#!/usr/bin/env python3
"""Measure synthetic product-name typo recovery against local Meilisearch.

This is a technical smoke test, not a Doofinder comparison or a relevance
judgment. A product may reasonably rank below the first page for a common word.
Output contains aggregate counts only; no product names or search terms.
"""
import argparse
import json
import os
import random
import re
import urllib.parse
import urllib.request
from pathlib import Path



def mutations(word):
    """Deterministic single-character errors, restricted to nontrivial words."""
    if len(word) < 6:
        return {}
    i = len(word) // 2
    swapped = word[:i] + word[i + 1] + word[i] + word[i + 2:]
    replacement = 'x' if word[i].lower() != 'x' else 'z'
    return {kind: value for kind, value in {
        'transposition': swapped,
        'deletion': word[:i] + word[i + 1:],
        'substitution': word[:i] + replacement + word[i + 1:],
    }.items() if value.casefold() != word.casefold()}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--index-map', type=Path, required=True, help='JSON object mapping locales to index names')
    parser.add_argument('--url', default='http://127.0.0.1:7700')
    parser.add_argument('--key-env', default='SHOPWARE_MEILISEARCH_KEY')
    parser.add_argument('--sample', type=int, default=100, help='Maximum product-name cases per language')
    parser.add_argument('--seed', type=int, default=20261027)
    parser.add_argument('--limit', type=int, default=20)
    args = parser.parse_args()
    from local_http import local_origin, opener as local_opener
    try:
        args.url = local_origin(args.url)
    except ValueError as error:
        parser.error(str(error))
    if not 1 <= args.sample <= 10000 or not 1 <= args.limit <= 100:
        parser.error('sample must be 1–10000 and limit must be 1–100')
    key = os.environ.get(args.key_env, '')
    if not key:
        parser.error('The configured key environment variable is empty')
    indexes = json.loads(args.index_map.read_text(encoding='utf-8'))
    if not isinstance(indexes, dict) or not indexes:
        parser.error('Index map must be a non-empty object')
    opener = local_opener()

    def request(path, data=None):
        body = json.dumps(data).encode() if data is not None else None
        req = urllib.request.Request(args.url.rstrip('/') + path, data=body,
                                     headers={'Authorization': 'Bearer ' + key, 'Content-Type': 'application/json'})
        with opener.open(req, timeout=15) as response:
            return json.load(response)

    output = {}
    for locale, index in sorted(indexes.items()):
        if not isinstance(locale, str) or not isinstance(index, str) or not re.fullmatch(r'[A-Za-z0-9_-]+', index):
            parser.error('Invalid locale or index name')
        documents = []
        offset = 0
        while True:
            page = request(f'/indexes/{index}/documents?limit=1000&offset={offset}&fields=id,name')['results']
            documents.extend(page)
            if len(page) < 1000:
                break
            offset += len(page)
        random.Random(args.seed).shuffle(documents)
        cases = []
        for document in documents:
            name, product_id = document.get('name'), document.get('id')
            if not isinstance(name, str) or not isinstance(product_id, str):
                continue
            words = re.findall(r'[^\W\d_]{6,}', name, flags=re.UNICODE)
            if not words:
                continue
            word = max(words, key=len)
            variants = mutations(word)
            if len(variants) == 3:
                cases.append((product_id, word, variants))
            if len(cases) >= args.sample:
                break
        metrics = {'cases': len(cases), 'exactTopK': 0, 'typoTopK': {k: 0 for k in ('transposition', 'deletion', 'substitution')},
                   'typoTopKWhenExactTopK': {k: 0 for k in ('transposition', 'deletion', 'substitution')}}
        def contains(product_id, query):
            result = request(f'/indexes/{index}/search', {'q': query, 'limit': args.limit,
                             'attributesToRetrieve': ['id'], 'matchingStrategy': 'frequency'})
            return any(hit.get('id') == product_id for hit in result['hits'])
        for product_id, word, variants in cases:
            exact = contains(product_id, word)
            metrics['exactTopK'] += exact
            for kind, typo in variants.items():
                hit = contains(product_id, typo)
                metrics['typoTopK'][kind] += hit
                metrics['typoTopKWhenExactTopK'][kind] += hit and exact
        output[locale] = metrics
    print(json.dumps({'scope': 'synthetic_single_word_meilisearch_candidates', 'limit': args.limit,
                      'samplePerLanguage': args.sample, 'seed': args.seed, 'languages': output}, indent=2))


if __name__ == '__main__':
    main()
