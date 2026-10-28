import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('typo_eval', Path(__file__).resolve().parents[1] / 'tools/evaluate_typo_tolerance.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class TypoMutationsTest(unittest.TestCase):
    def test_three_distinct_one_character_errors(self):
        self.assertEqual(module.mutations('abcdef'), {
            'transposition': 'abcedf', 'deletion': 'abcef', 'substitution': 'abcxef',
        })

    def test_repeated_letters_do_not_produce_unchanged_transposition(self):
        self.assertNotIn('transposition', module.mutations('abcddf'))
        self.assertEqual(module.mutations('short'), {})


if __name__ == '__main__':
    unittest.main()
