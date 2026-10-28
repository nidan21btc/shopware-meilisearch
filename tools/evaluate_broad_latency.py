#!/usr/bin/env python3
"""Measure local storefront response time for frequent catalog-name words.

The report contains only timings and document counts. It is an indicative
single-client measurement, not a load test or a production SLA.
"""
import argparse
import collections
import json
import os
import re
import statistics
import time
import urllib.parse
import urllib.request
from pathlib import Path

from local_http import local_origin, opener


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--shop', required=True)
    parser.add_argument('--host', required=True)
    parser.add_argument('--engine', required=True)
    parser.add_argument('--index-map', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    if not re.fullmatch(r'[A-Za-z0-9.-]+(?::[0-9]+)?', args.host):
        parser.error('Invalid host')
    shop, engine = local_origin(args.shop), local_origin(args.engine)
    key = os.environ.get('SHOPWARE_MEILISEARCH_KEY')
    if not key:
        parser.error('SHOPWARE_MEILISEARCH_KEY is required')
    indexes = json.loads(args.index_map.read_text(encoding='utf-8'))
    if set(indexes) != {'de-DE', 'en-GB', 'es-ES', 'fr-FR', 'it-IT'}:
        parser.error('Expected exactly the five test locales')
    http = opener()
    results = {}
    for locale, index in indexes.items():
        if not re.fullmatch(r'[A-Za-z0-9_-]+', index):
            raise ValueError('Invalid index name')
        req = urllib.request.Request(engine + '/indexes/' + index + '/documents?limit=10000', headers={'Authorization': 'Bearer ' + key})
        with http.open(req, timeout=60) as response:
            payload = json.load(response)
        docs = payload['results']
        if payload['total'] != len(docs):
            raise ValueError('Index exceeds evaluation limit of 10000 documents')
        terms = collections.Counter()
        for document in docs:
            name = document.get('name') or ''
            terms.update(set(word.casefold() for word in re.findall(r'[^\W\d_]+', name) if len(word) >= 5))
        chosen = sorted(terms, key=lambda term: (-terms[term], term))[:5]
        if len(chosen) != 5:
            raise ValueError('Not enough catalog words in ' + locale)
        timings = []
        for term in chosen:
            for _ in range(2):
                path = '/' + locale[:2] + '/shopware-meilisearch?' + urllib.parse.urlencode({'q': term})
                request = urllib.request.Request(shop + path, headers={'Host': args.host, 'X-Requested-With': 'XMLHttpRequest'})
                started = time.monotonic()
                try:
                    with http.open(request, timeout=90) as response:
                        body = response.read().decode('utf-8')
                except Exception:
                    raise RuntimeError('Local storefront request failed for ' + locale) from None
                if 'data-el-result-status' not in body:
                    raise RuntimeError('Unexpected storefront response for ' + locale)
                timings.append(round((time.monotonic() - started) * 1000))
        ordered = sorted(timings)
        results[locale] = {'requests': len(timings), 'median_ms': round(statistics.median(timings)),
                           'p90_ms': ordered[8], 'max_ms': max(timings),
                           'top_word_document_counts': [terms[term] for term in chosen]}
        print(locale, json.dumps(results[locale]), flush=True)
    fd = os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w', encoding='utf-8') as output:
        json.dump({'method': 'Single client, five most common catalog-name words of at least five letters per locale, twice each; configuration is that of the test shop', 'results': results}, output, indent=2)
        output.write('\n')


if __name__ == '__main__':
    main()
