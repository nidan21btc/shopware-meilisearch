#!/usr/bin/env python3
"""Build optional per-product query hints from repeated historical click signals.

The output contains search terms. Review before use; this is not anonymization
and cannot automatically recognize all personal data. Only local use is intended.
"""
import argparse
import collections
import json
import re
from pathlib import Path


def eligible(query):
    # Avoid obvious identifiers/URLs and unusual inputs. This cannot identify
    # names or other private information; manual review is still required.
    if not 3 <= len(query) <= 80 or any(c.isdigit() for c in query):
        return False
    return all(c.isalpha() or c in " '-" for c in query) and len(query.split()) <= 8


def build(benchmark, partition, min_searches=3, min_clicks=2, max_hints=20):
    output = {}
    for locale, language in benchmark['languages'].items():
        product_terms = collections.defaultdict(list)
        for case in language['cases']:
            if partition != 'all' and case['partition'] != partition:
                continue
            if case['searches'] < min_searches or not eligible(case['query']):
                continue
            for product, clicks in case['relevantProducts'].items():
                if clicks >= min_clicks:
                    product_terms[product].append((clicks, case['query']))
        output[locale] = {product: [term for _, term in sorted(terms, key=lambda row: (-row[0], row[1]))[:max_hints]]
                          for product, terms in sorted(product_terms.items())}
    return output


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--benchmark', required=True, type=Path)
    p.add_argument('--output', required=True, type=Path)
    p.add_argument('--partition', choices=['development', 'all'], default='development')
    p.add_argument('--min-searches', type=int, default=3)
    p.add_argument('--min-clicks', type=int, default=2)
    p.add_argument('--max-hints', type=int, default=20)
    a = p.parse_args()
    if a.min_searches < 1 or a.min_clicks < 1 or not 1 <= a.max_hints <= 50:
        p.error('Thresholds must be positive and max-hints must be 1–50')
    result = build(json.loads(a.benchmark.read_text(encoding='utf-8')), a.partition, a.min_searches, a.min_clicks, a.max_hints)
    from private_output import write_private_text
    write_private_text(a.output, json.dumps(result, ensure_ascii=False, separators=(',', ':')) + '\n')
    print(json.dumps({locale: len(products) for locale, products in result.items()}))


if __name__ == '__main__':
    main()
