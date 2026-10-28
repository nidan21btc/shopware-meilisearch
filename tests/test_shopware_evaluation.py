import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('shop_eval', Path(__file__).resolve().parents[1] / 'tools/evaluate_shopware.py')
evaluation = importlib.util.module_from_spec(spec)
spec.loader.exec_module(evaluation)


class ShopEvaluationTests(unittest.TestCase):
    def test_only_mapped_clicks_with_known_positions_are_comparable(self):
        cases = [{'partition': 'holdout', 'query': 'x', 'relevantProducts': {'e1': 3, 'e2': 2, 'e3': 5},
                  'historicalPositions': {'e1': 2.5, 'e2': 25}}]
        raw = {'languages': {'de-DE': {'cases': cases}}}
        mapped = {'languages': {'de-DE': {'referenceMap': {'e1': 's1', 'e2': 's2', 'e3': 's3'}}}}
        stats = evaluation.measure(raw, mapped, lambda locale, query: ['s2'], 'holdout', 0)['de-DE']
        self.assertEqual(stats['historicalClicksWithPositionAndMapping'], 5)
        self.assertEqual(stats['historicalClicksAvgPositionAtMost20'], 3)
        self.assertEqual(stats['candidateClicksTop20'], 2)
        self.assertEqual(stats['clicksWithoutPositionOrMapping'], 5)


if __name__ == '__main__':
    unittest.main()
