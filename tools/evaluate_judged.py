#!/usr/bin/env python3
"""Compare two rankings using independent product relevance judgments.

Input is confidential JSON. Each case has locale, positive query weight,
judgments mapping product IDs to relevance grades 0..3, and reference and
candidate lists of product IDs. Every product in the first ten positions of
both rankings must have a judgment; no unjudged product is silently assumed
irrelevant. Judgments should cover the pooled results of both systems, plus
any other known relevant products. The ideal ranking is limited to that pool.
Only aggregate results are printed; no query text is required or emitted.
"""
import argparse
import json
import math
from collections import defaultdict
from pathlib import Path


def evaluate(cases):
    if not isinstance(cases, list) or not cases:
        raise ValueError('Expected a nonempty list of cases')
    totals = defaultdict(lambda: {'cases': 0, 'weight': 0, 'judgedWeight': 0, 'matchingWeight': 0,
                                  'referenceNdcg': 0, 'candidateNdcg': 0,
                                  'referenceHit': 0, 'candidateHit': 0,
                                  'referenceEmpty': 0, 'candidateEmpty': 0,
                                  'referenceRelevantEmpty': 0, 'candidateRelevantEmpty': 0,
                                  'noKnownRelevantCases': 0, 'noKnownMatchingCases': 0})
    for case in cases:
        if not isinstance(case, dict):
            raise ValueError('Each case must be an object')
        locale, weight, judgments = case.get('locale'), case.get('weight'), case.get('judgments')
        if not isinstance(locale, str) or not locale or len(locale) > 35 or any(not (c.isascii() and (c.isalnum() or c == '-')) for c in locale):
            raise ValueError('Invalid locale')
        if isinstance(weight, bool) or not isinstance(weight, (int, float)) or not math.isfinite(weight) or weight <= 0:
            raise ValueError('Weight must be a positive finite number')
        if not isinstance(judgments, dict) or any(not isinstance(k, str) or not k or isinstance(v, bool) or not isinstance(v, int) or v not in range(4) for k, v in judgments.items()):
            raise ValueError('Judgments must map product IDs to integer grades 0..3')
        rankings = []
        for name in ('reference', 'candidate'):
            ranking = case.get(name)
            if not isinstance(ranking, list) or any(not isinstance(i, str) or not i for i in ranking) or len(ranking) != len(set(ranking)):
                raise ValueError('Rankings must be lists of unique product IDs')
            if any(i not in judgments for i in ranking[:10]):
                raise ValueError('Every product in the first ten positions must be judged')
            rankings.append(ranking)
        total = totals[locale]
        total['cases'] += 1
        total['weight'] += weight
        if not math.isfinite(total['weight']):
            raise ValueError('Total weight is too large')
        for name, ranking in zip(('reference', 'candidate'), rankings):
            total[name + 'Empty'] += weight * (not ranking)
        ideal = sum((2 ** grade - 1) / math.log2(rank + 1)
                    for rank, grade in enumerate(sorted(judgments.values(), reverse=True)[:10], 1))
        matching = any(grade >= 2 for grade in judgments.values())
        if matching:
            total['matchingWeight'] += weight
        else:
            total['noKnownMatchingCases'] += 1
        if ideal == 0:
            total['noKnownRelevantCases'] += 1
        else:
            total['judgedWeight'] += weight
        for name, ranking in zip(('reference', 'candidate'), rankings):
            if matching:
                total[name + 'RelevantEmpty'] += weight * (not ranking)
                total[name + 'Hit'] += weight * any(judgments[identifier] >= 2 for identifier in ranking[:5])
            if ideal:
                dcg = sum((2 ** judgments[identifier] - 1) / math.log2(rank + 1)
                          for rank, identifier in enumerate(ranking[:10], 1))
                total[name + 'Ndcg'] += weight * (dcg / ideal)
    result = {}
    for locale, t in sorted(totals.items()):
        d = t['judgedWeight']
        m = t['matchingWeight']
        result[locale] = {'cases': t['cases'], 'weight': t['weight'], 'relevanceEligibleWeight': d,
                          'noKnownRelevantCases': t['noKnownRelevantCases'],
                          'matchingEligibleWeight': m, 'noKnownMatchingCases': t['noKnownMatchingCases'],
                          'referenceNdcgAt10': t['referenceNdcg'] / d if d else None,
                          'candidateNdcgAt10': t['candidateNdcg'] / d if d else None,
                          'referenceHitAt5': t['referenceHit'] / m if m else None,
                          'candidateHitAt5': t['candidateHit'] / m if m else None,
                          'referenceEmptyRateWhenRelevantKnown': t['referenceRelevantEmpty'] / m if m else None,
                          'candidateEmptyRateWhenRelevantKnown': t['candidateRelevantEmpty'] / m if m else None,
                          'referenceEmptyRate': t['referenceEmpty'] / t['weight'],
                          'candidateEmptyRate': t['candidateEmpty'] / t['weight']}
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('input', type=Path, help='Confidential JSON with a cases array')
    args = parser.parse_args()
    try:
        data = json.loads(args.input.read_text(encoding='utf-8'))
        result = evaluate(data['cases'])
    except (OSError, ValueError, TypeError, KeyError, OverflowError):
        parser.exit(2, 'Invalid input or unable to read the confidential input file.\n')
    print(json.dumps({'note': 'Pooled judgments are incomplete by nature; no confidence interval or automatic acceptance is implied.', 'languages': result}, indent=2))


if __name__ == '__main__':
    main()
