import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('review_zero_results', Path(__file__).resolve().parents[1] / 'tools/review_zero_results.py')
review = importlib.util.module_from_spec(spec)
spec.loader.exec_module(review)


class ReviewSafetyTest(unittest.TestCase):
    def test_only_local_origins_are_accepted(self):
        self.assertEqual(review.local_origin('http://127.0.0.1:7700/'), 'http://127.0.0.1:7700')
        for url in ('https://example.org', 'http://127.0.0.1.example.org', 'http://127.0.0.1/?q=secret', 'http://user@127.0.0.1'):
            with self.subTest(url=url), self.assertRaises(ValueError):
                review.local_origin(url)

    def test_customer_text_is_not_a_spreadsheet_formula(self):
        for text in ('=1+1', '  +cmd', '-1', '@sum', '\tformula'):
            with self.subTest(text=text):
                self.assertTrue(review.spreadsheet_text(text).startswith("'"))
        self.assertEqual(review.spreadsheet_text('Blüten'), 'Blüten')


if __name__ == '__main__':
    unittest.main()
