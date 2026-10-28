import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('popularity', Path(__file__).resolve().parents[1] / 'tools/build_popularity.py')
popularity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(popularity)


class PopularityTests(unittest.TestCase):
    def test_language_and_holdout_isolation(self):
        benchmark = {'languages': {
            'de-DE': {'cases': [{'partition': 'development', 'query': 'private query', 'relevantProducts': {'p1': 3}},
                                {'partition': 'holdout', 'query': 'not training', 'relevantProducts': {'p1': 8}}]},
            'en-GB': {'cases': [{'partition': 'development', 'query': 'different', 'relevantProducts': {'p1': 1}}]},
        }}
        self.assertEqual(popularity.build(benchmark, 'development'), {'de-DE': {'p1': 3}, 'en-GB': {'p1': 1}})
        self.assertEqual(popularity.build(benchmark, 'all')['de-DE']['p1'], 11)


if __name__ == '__main__':
    unittest.main()
