import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('build_reviewed_hints', Path(__file__).resolve().parents[1] / 'tools/build_reviewed_hints.py')
hints = importlib.util.module_from_spec(spec)
spec.loader.exec_module(hints)


def row(locale='de-DE', query='ruhe', product='a' * 32, decision='ja', indexed='indexed'):
    return {'Sprache': locale, 'Suchbegriff': query, 'Shopware Produkt-ID': product, 'Indexstatus': indexed, 'Passt zur Suche?': decision}


class ReviewedHintsTest(unittest.TestCase):
    def test_only_explicit_approvals_are_exported_and_languages_stay_separate(self):
        result, count = hints.build([row(), row(), row('en-GB', 'calm'), row(query='ignored', decision=''), row(query='excluded', decision='nein')])
        self.assertEqual(count, 2)
        self.assertEqual(result, {'de-DE': {'a' * 32: ['ruhe']}, 'en-GB': {'a' * 32: ['calm']}})

    def test_unsafe_or_ambiguous_approvals_fail(self):
        for bad in (row(decision='maybe'), row(product='not-a-uuid'), row(indexed='not indexed'), row(query='x' * 81), row(query="'=1+1")):
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                hints.build([bad])


if __name__ == '__main__':
    unittest.main()
