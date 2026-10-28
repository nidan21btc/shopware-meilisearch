import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
from evaluate_catalog_recall import partial, typo


class CatalogRecallQueriesTest(unittest.TestCase):
    def test_partial_keeps_original_order_and_ignores_duplicate_words(self):
        self.assertEqual(partial('Rose Kräuteressenz Rose Blütenmischung'), 'Kräuteressenz Blütenmischung')

    def test_partial_requires_two_distinct_words(self):
        self.assertIsNone(partial('Rose ROSE Öl'))

    def test_typo_deletes_an_interior_letter(self):
        self.assertEqual(typo('Lavendel Blüten'), 'Lavedel Blüten')
        self.assertIsNone(typo('Rose Öl'))


if __name__ == '__main__':
    unittest.main()
