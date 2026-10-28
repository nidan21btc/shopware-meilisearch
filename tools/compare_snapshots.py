#!/usr/bin/env python3
"""Compare two observed result sets against mapped historical click feedback.

Snapshots map locale to [{"query": ..., "ids": [...]}]. Reference IDs are
Doofinder IDs; candidate IDs are Shopware UUIDs. Observed clicks are positive
feedback, not complete judgments, and tiny samples cannot prove overall parity.
"""
import argparse
import json
import math
from pathlib import Path


def compare(raw, mapped, reference, candidate, families=None):
    if families is not None and not isinstance(families, dict):
        raise ValueError('Product families must be an object')
    output = {}
    for locale, observations in reference.items():
        candidate_queries = {case['query']: case for case in candidate[locale]}
        raw_cases = {case['query']: case for case in raw['languages'][locale]['cases']}
        references = mapped['languages'][locale]['referenceMap']
        summary = {'queries': 0, 'judgedQueries': 0, 'referenceHits': 0, 'candidateHits': 0,
                   'mappedHistoricalClicks': 0, 'referenceClickCoverage': 0, 'candidateClickCoverage': 0,
                   'referenceDiscountedClicks': 0.0, 'candidateDiscountedClicks': 0.0,
                   'unmappedHistoricalClicks': 0}
        if families is not None:
            summary.update({'candidateOtherVariantClickWeight': 0, 'candidateFamilyUnknownClickWeight': 0})
        details = []
        for old in observations:
            query = old['query']
            if query not in candidate_queries or query not in raw_cases:
                raise ValueError(f'No matching case for locale {locale}')
            expected = raw_cases[query]['relevantProducts']
            mapped_expected = {external_id: count for external_id, count in expected.items() if external_id in references}
            old_ids, new_ids = set(old['ids'][:20]), set(candidate_queries[query]['ids'][:20])
            if families is not None:
                visible_families = {families[pid] for pid in new_ids if pid in families}
                unknown_visible = any(pid not in families for pid in new_ids)
                for external_id, count in mapped_expected.items():
                    product_id = references[external_id]
                    if external_id not in old_ids or product_id in new_ids:
                        continue
                    family = families.get(product_id)
                    if family is not None and family in visible_families:
                        summary['candidateOtherVariantClickWeight'] += count
                    elif family is None or unknown_visible:
                        summary['candidateFamilyUnknownClickWeight'] += count
            old_ranks = {identifier: rank for rank, identifier in reversed(list(enumerate(old['ids'][:20], 1)))}
            new_ranks = {identifier: rank for rank, identifier in reversed(list(enumerate(candidate_queries[query]['ids'][:20], 1)))}
            reference_clicks = sum(count for external_id, count in mapped_expected.items() if external_id in old_ids)
            candidate_clicks = sum(count for external_id, count in mapped_expected.items() if references[external_id] in new_ids)
            reference_discounted = sum(count / math.log2(old_ranks[external_id] + 1) for external_id, count in mapped_expected.items() if external_id in old_ranks)
            candidate_discounted = sum(count / math.log2(new_ranks[references[external_id]] + 1) for external_id, count in mapped_expected.items() if references[external_id] in new_ranks)
            summary['queries'] += 1
            summary['unmappedHistoricalClicks'] += sum(expected.values()) - sum(mapped_expected.values())
            if mapped_expected:
                summary['judgedQueries'] += 1
                summary['referenceHits'] += reference_clicks > 0
                summary['candidateHits'] += candidate_clicks > 0
                summary['mappedHistoricalClicks'] += sum(mapped_expected.values())
                summary['referenceClickCoverage'] += reference_clicks
                summary['candidateClickCoverage'] += candidate_clicks
                summary['referenceDiscountedClicks'] += reference_discounted
                summary['candidateDiscountedClicks'] += candidate_discounted
            details.append({'query': query, 'referenceClicksInTop20': reference_clicks, 'candidateClicksInTop20': candidate_clicks,
                            'referenceDiscountedClicks': reference_discounted, 'candidateDiscountedClicks': candidate_discounted,
                            'mappedHistoricalClicks': sum(mapped_expected.values())})
        output[locale] = {'summary': summary, 'queries': details}
    return output


def main():
    p = argparse.ArgumentParser(description=__doc__)
    for argument in ['raw', 'mapped', 'reference', 'candidate', 'output']:
        p.add_argument('--' + argument, required=True, type=Path)
    p.add_argument('--catalog', type=Path, help='Optional private catalog export with product families')
    a = p.parse_args()
    load = lambda path: json.loads(path.read_text(encoding='utf-8'))
    families = None
    if a.catalog is not None:
        families = load(a.catalog).get('families')
        if not isinstance(families, dict):
            raise ValueError('Catalog must contain product families')
    result = {'note': 'Historical clicks on mapped products; a small sample is not proof of overall search parity. Another variant does not prove the same product is suitable.',
              'languages': compare(load(a.raw), load(a.mapped), load(a.reference), load(a.candidate), families)}
    from private_output import write_private_text
    write_private_text(a.output, json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({locale: data['summary'] for locale, data in result['languages'].items()}, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
