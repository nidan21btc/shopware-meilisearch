#!/usr/bin/env python3
"""Measure local index retrieval against historical positive click feedback.

This evaluates Meilisearch candidates, before Shopware visibility, variant,
customer-price and rendering rules. Clicks are incomplete relevance judgments;
this is not a direct comparison against Doofinder's original result rankings.
"""
import argparse
import json
import os
import urllib.parse
import urllib.request
from pathlib import Path



def measure(cases, retrieve, limit, max_cases, partition):
    selected = [case for case in cases if case['partition'] == partition]
    if max_cases:
        selected = selected[:max_cases]
    metrics = {'queries': 0, 'noResultQueries': 0, 'judgedQueries': 0,
               'queriesWithClickedProductInTopK': 0, 'historicalClicks': 0,
               'historicalClicksOnProductsInTopK': 0}
    misses = []
    for case in selected:
        ids = set(retrieve(case['query'], limit))
        relevant = case['relevantProducts']
        matched = ids.intersection(relevant)
        metrics['queries'] += 1
        metrics['noResultQueries'] += not bool(ids)
        if relevant:
            metrics['judgedQueries'] += 1
            metrics['queriesWithClickedProductInTopK'] += bool(matched)
            metrics['historicalClicks'] += sum(relevant.values())
            metrics['historicalClicksOnProductsInTopK'] += sum(relevant[identifier] for identifier in matched)
            if not matched:
                misses.append({'query': case['query'], 'searches': case['searches'], 'clicks': case['clicks']})
    metrics['queryHitRate'] = (metrics['queriesWithClickedProductInTopK'] / metrics['judgedQueries'] if metrics['judgedQueries'] else None)
    metrics['clickCoverage'] = (metrics['historicalClicksOnProductsInTopK'] / metrics['historicalClicks'] if metrics['historicalClicks'] else None)
    return metrics, misses


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--benchmark', required=True, type=Path)
    parser.add_argument('--index-map', required=True, type=Path, help='JSON object mapping each locale to its Meilisearch index')
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--url', default='http://127.0.0.1:7700')
    parser.add_argument('--key-env', default='SHOPWARE_MEILISEARCH_KEY')
    parser.add_argument('--partition', choices=['development', 'holdout'], default='development')
    parser.add_argument('--max-cases', type=int, default=100, help='Most frequent cases per language; 0 selects all')
    parser.add_argument('--limit', type=int, default=20)
    parser.add_argument('--matching-strategy', choices=['all', 'frequency', 'last'], default='frequency')
    args = parser.parse_args()
    if not 1 <= args.limit <= 100 or args.max_cases < 0:
        parser.error('limit must be 1–100 and max-cases must not be negative')
    from local_http import local_origin, opener as local_opener
    try:
        args.url = local_origin(args.url)
    except ValueError as error:
        parser.error(str(error))
    key = os.environ.get(args.key_env, '')
    if not key:
        parser.error('The configured key environment variable is empty')
    benchmark = json.loads(args.benchmark.read_text(encoding='utf-8'))
    indexes = json.loads(args.index_map.read_text(encoding='utf-8'))
    if set(indexes) != set(benchmark['languages']):
        parser.error('Index map must contain exactly the benchmark locales')
    opener = local_opener()
    results = {}
    for locale, language in benchmark['languages'].items():
        index = indexes[locale]
        if not isinstance(index, str) or not index.replace('_', '').replace('-', '').isalnum():
            parser.error('Invalid index name')
        def retrieve(query, limit):
            data = json.dumps({'q': query, 'limit': limit, 'attributesToRetrieve': ['id'], 'matchingStrategy': args.matching_strategy}).encode()
            request = urllib.request.Request(args.url.rstrip('/') + '/indexes/' + index + '/search', data=data,
                                             headers={'Authorization': 'Bearer ' + key, 'Content-Type': 'application/json'})
            with opener.open(request, timeout=10) as response:
                return [hit['id'] for hit in json.load(response)['hits']]
        metrics, misses = measure(language['cases'], retrieve, args.limit, args.max_cases, args.partition)
        results[locale] = {'metrics': metrics, 'misses': misses}
    output = {'scope': 'meilisearch_before_shopware_visibility_and_price_filters', 'partition': args.partition,
              'matchingStrategy': args.matching_strategy,
              'limit': args.limit, 'maxCasesPerLanguage': args.max_cases,
              'note': 'Click feedback is incomplete and is not a current Doofinder ranking.', 'languages': results}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    from private_output import write_private_text
    write_private_text(args.output, json.dumps(output, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({key: value['metrics'] for key, value in results.items()}, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
