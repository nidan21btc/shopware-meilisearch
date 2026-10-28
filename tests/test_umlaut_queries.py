import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
from evaluate_umlaut_queries import transliterate, words_for_name


class UmlautQueryTest(unittest.TestCase):
    def test_selects_sharp_s_and_normalizes_combining_diacritics(self):
        self.assertEqual(words_for_name('GRÜSSE Größe Blu\u0308ten'), ['grüsse', 'größe', 'blüten'])

    def test_transliterates_without_discarding_remaining_characters(self):
        self.assertEqual(transliterate('größe'), 'groesse')
        self.assertEqual(transliterate('blüten'), 'blueten')


if __name__ == '__main__':
    unittest.main()
