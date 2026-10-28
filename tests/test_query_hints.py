import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('hints', Path(__file__).resolve().parents[1] / 'tools/build_query_hints.py')
hints = importlib.util.module_from_spec(spec)
spec.loader.exec_module(hints)


class HintTests(unittest.TestCase):
    def test_only_repeated_clicked_queries_and_selected_partition(self):
        cases = [
            {'partition': 'development', 'query': 'orchid', 'searches': 4, 'relevantProducts': {'p1': 2, 'p2': 1}},
            {'partition': 'development', 'query': 'test@example.com', 'searches': 20, 'relevantProducts': {'p1': 4}},
            {'partition': 'holdout', 'query': 'secret', 'searches': 10, 'relevantProducts': {'p1': 3}},
        ]
        output = hints.build({'languages': {'en-GB': {'cases': cases}, 'de-DE': {'cases': []}}}, 'development')
        self.assertEqual(output, {'en-GB': {'p1': ['orchid']}, 'de-DE': {}})
        self.assertFalse(hints.eligible('0123456789'))
        self.assertFalse(hints.eligible('https://example.com'))


if __name__ == '__main__':
    unittest.main()
