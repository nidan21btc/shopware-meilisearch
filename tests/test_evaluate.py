import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('evaluator', Path(__file__).resolve().parents[1] / 'tools/evaluate_meilisearch.py')
evaluator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(evaluator)


class EvaluateTests(unittest.TestCase):
    def test_click_weights_and_holdout_are_kept_separate(self):
        cases = [
            {'query': 'a', 'partition': 'development', 'relevantProducts': {'1': 3, '2': 1}, 'searches': 4, 'clicks': 4},
            {'query': 'b', 'partition': 'development', 'relevantProducts': {'3': 2}, 'searches': 2, 'clicks': 2},
            {'query': 'c', 'partition': 'holdout', 'relevantProducts': {'4': 1}, 'searches': 1, 'clicks': 1},
        ]
        metrics, misses = evaluator.measure(cases, lambda q, k: {'a': ['1'], 'b': [], 'c': ['4']}[q], 20, 0, 'development')
        self.assertEqual(metrics['queries'], 2)
        self.assertEqual(metrics['queryHitRate'], .5)
        self.assertEqual(metrics['clickCoverage'], .5)
        self.assertEqual([case['query'] for case in misses], ['b'])
        holdout, _ = evaluator.measure(cases, lambda q, k: ['4'], 20, 0, 'holdout')
        self.assertEqual(holdout['queryHitRate'], 1)


if __name__ == '__main__':
    unittest.main()
