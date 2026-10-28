#!/usr/bin/env python3
"""Build opt-in, language-specific keyed query/product click signals locally.

Input contains customer search text. Output contains keyed fingerprints rather
than plaintext queries, but remains sensitive and must be protected. The local
search key must match the key used by the Shopware plugin.
"""
import argparse
import collections
import hashlib
import hmac
import json
import os
import re
import secrets
import unicodedata
from pathlib import Path

LOCALE = re.compile(r'^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})+$')
PRODUCT = re.compile(r'^[a-f0-9]{32}$')

def key_check(secret):
    if not secret: raise ValueError('A non-empty local key is required')
    return hmac.new(secret.encode('utf-8'), b'shopware-meilisearch/query-click-feedback/v1', hashlib.sha256).hexdigest()

def fingerprint(locale, query, secret):
    if not LOCALE.fullmatch(locale) or not secret:
        raise ValueError('A valid locale and non-empty local key are required')
    text = unicodedata.normalize('NFC', query).strip(' \t\n\r\0\x0b').lower()
    text = re.sub(r'\s+', ' ', text)
    if not text or len(text) > 150:
        raise ValueError('Invalid query')
    return hmac.new(secret.encode('utf-8'), (locale + '\0' + text).encode('utf-8'), hashlib.sha256).hexdigest()

def build(benchmark, secret, partition='development', min_searches=3, min_clicks=2):
    result = {}
    for locale, language in benchmark['languages'].items():
        if not LOCALE.fullmatch(locale): raise ValueError('Invalid locale')
        queries = collections.defaultdict(collections.Counter)
        for case in language['cases']:
            if partition != 'all' and case['partition'] != partition: continue
            if case['searches'] < min_searches: continue
            query = case['query']
            if not isinstance(query, str) or not query.strip() or len(query) > 150: continue
            selected = {pid: clicks for pid, clicks in case['relevantProducts'].items()
                        if isinstance(pid, str) and PRODUCT.fullmatch(pid) and isinstance(clicks, int) and not isinstance(clicks, bool) and clicks >= min_clicks}
            if not selected: continue
            try:
                digest = fingerprint(locale, query, secret)
            except ValueError:
                continue
            queries[digest].update(selected)
        if len(queries) > 20000: raise ValueError('More than 20000 query signals in a locale')
        result[locale] = {digest: {pid: min(count, 1000000) for pid, count in sorted(products.items(), key=lambda item: (-item[1], item[0]))[:5]}
                          for digest, products in sorted(queries.items())}
    return result

def summarize(benchmark, signals, secret):
    """Count historical coverage without exposing queries or fingerprints."""
    result = {}
    for locale, language in benchmark['languages'].items():
        lookup = signals.get(locale, {})
        summary = {'signal_queries': len(lookup),
                   'signal_products': sum(len(products) for products in lookup.values()),
                   'historical_searches': 0, 'historical_searches_with_signal': 0}
        for case in language['cases']:
            searches = case.get('searches', 0)
            query = case.get('query')
            if not isinstance(searches, int) or isinstance(searches, bool) or searches < 0:
                raise ValueError('Invalid historical search count')
            summary['historical_searches'] += searches
            if isinstance(query, str) and query.strip() and len(query) <= 150:
                try:
                    digest = fingerprint(locale, query, secret)
                except ValueError:
                    continue
                if digest in lookup:
                    summary['historical_searches_with_signal'] += searches
        result[locale] = summary
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--benchmark', required=True, type=Path, help='Mapped, locally reviewed benchmark JSON')
    output = parser.add_mutually_exclusive_group(required=True)
    output.add_argument('--output', type=Path, help='New private JSON file')
    output.add_argument('--dry-run', action='store_true', help='Show aggregate historical coverage without writing a signal file')
    parser.add_argument('--key-env', default='SHOPWARE_MEILISEARCH_KEY')
    parser.add_argument('--partition', choices=['development', 'all'], default='development')
    parser.add_argument('--min-searches', type=int, default=3)
    parser.add_argument('--min-clicks', type=int, default=2)
    args = parser.parse_args()
    if args.min_searches < 1 or args.min_clicks < 2: parser.error('Thresholds must be positive; at least two clicks are required')
    secret = secrets.token_hex(32) if args.dry_run else os.environ.get(args.key_env, '')
    if not secret: parser.error('The local search key environment variable is empty')
    benchmark = json.loads(args.benchmark.read_text(encoding='utf-8'))
    data = build(benchmark, secret, args.partition, args.min_searches, args.min_clicks)
    if args.dry_run:
        print(json.dumps(summarize(benchmark, data, secret)))
        return
    encoded = (json.dumps({'version': 1, 'keyCheck': key_check(secret), 'locales': data}, ensure_ascii=False, separators=(',', ':')) + '\n').encode('utf-8')
    if len(encoded) > 20 * 1024 * 1024: raise ValueError('Query feedback exceeds 20 MB')
    fd = os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'wb') as target: target.write(encoded)
    print(json.dumps({locale: len(queries) for locale, queries in data.items()}))

if __name__ == '__main__': main()
