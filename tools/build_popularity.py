#!/usr/bin/env python3
"""Aggregate mapped historical clicks by locale/product; omit all queries."""
import argparse
import collections
import json
from pathlib import Path


def build(benchmark, partition):
    output = {}
    for locale, language in benchmark['languages'].items():
        totals = collections.Counter()
        for case in language['cases']:
            if partition != 'all' and case['partition'] != partition:
                continue
            totals.update(case['relevantProducts'])
        output[locale] = {product_id: min(clicks, 1000000) for product_id, clicks in sorted(totals.items()) if clicks > 0}
    return output


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--benchmark', required=True, type=Path)
    p.add_argument('--output', required=True, type=Path)
    p.add_argument('--partition', choices=['development', 'all'], default='development')
    a = p.parse_args()
    result = build(json.loads(a.benchmark.read_text(encoding='utf-8')), a.partition)
    from private_output import write_private_text
    write_private_text(a.output, json.dumps(result, ensure_ascii=False, separators=(',', ':')) + '\n')
    print(json.dumps({locale: len(products) for locale, products in result.items()}))


if __name__ == '__main__':
    main()
