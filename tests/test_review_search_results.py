import importlib.util
import csv
import json
import os
import stat
import sys
import tempfile
import unittest
from unittest.mock import patch
from pathlib import Path

TOOLS = Path(__file__).resolve().parents[1] / 'tools'
sys.path.insert(0, str(TOOLS))
spec = importlib.util.spec_from_file_location('review_search_results', TOOLS / 'review_search_results.py')
review = importlib.util.module_from_spec(spec)
spec.loader.exec_module(review)
A, B = 'a' * 32, 'b' * 32


class ReviewSheetTests(unittest.TestCase):
    def test_cards_keep_id_and_visible_name_together(self):
        parser = review.ProductCards()
        parser.feed('<section data-el-result-status="2"><article class="el-search__card" data-product-id="' + A
                    + '"><span class="el-search__name">A &amp; B</span></article>'
                    + '<article class="el-search__card" data-product-id="' + B
                    + '"><span class="el-search__name">Other</span></article></section>')
        self.assertTrue(parser.status)
        self.assertEqual(parser.cards, [{'id': A, 'name': 'A & B'}, {'id': B, 'name': 'Other'}])

    def test_invalid_product_card_is_rejected(self):
        parser = review.ProductCards()
        with self.assertRaises(ValueError):
            parser.feed('<article class="el-search__card" data-product-id="invalid"></article>')

    def test_selection_is_deterministic_and_language_local(self):
        def case(query, partition, pid):
            return {'query': query, 'partition': partition, 'searches': 3, 'relevantProducts': {pid: 2}}
        sample = {'languages': {'de-DE': {'cases': [case('first', 'holdout', A), case('second', 'holdout', B), case('dev', 'development', A)]},
                                'en-GB': {'cases': [case('english', 'holdout', B)]}}}
        first = review.choose_cases(sample, 'holdout', 1)
        self.assertEqual(first, review.choose_cases(sample, 'holdout', 1))
        self.assertEqual(len(first['de-DE']), 1)
        self.assertEqual(first['en-GB'][0]['query'], 'english')
        self.assertNotEqual(first['de-DE'][0]['query'], 'dev')

    def test_spreadsheet_text_and_remote_origins_are_rejected(self):
        self.assertEqual(review.spreadsheet_text('  =1+1'), "'  =1+1")
        with self.assertRaises(ValueError):
            review.local_origin('https://example.com')

    def test_review_sheet_records_configuration_and_is_private(self):
        with tempfile.TemporaryDirectory() as directory:
            benchmark = Path(directory) / 'benchmark.json'
            output = Path(directory) / 'review.csv'
            benchmark.write_text(json.dumps({'languages': {'de-DE': {'cases': []}}}))
            row = ['de-DE', '=2+2', 3, 'Lokales Ergebnis', 1, A, 'Test', 2, '']
            args = ['review_search_results.py', '--benchmark', str(benchmark), '--shop', 'http://127.0.0.1:1234',
                    '--host', 'test.local', '--configuration-label', 'Standard', '--output', str(output)]
            with patch.object(sys, 'argv', args), patch.object(review, 'collect', return_value=iter([row])):
                review.main()
            self.assertEqual(stat.S_IMODE(os.stat(output).st_mode), 0o600)
            with output.open(encoding='utf-8-sig', newline='') as source:
                data = list(csv.DictReader(source, delimiter=';'))
            self.assertEqual(data[0]['Konfiguration'], 'Standard')
            self.assertEqual(data[0]['Suchbegriff'], "'=2+2")
            with patch.object(sys, 'argv', args), patch.object(review, 'collect', return_value=iter([row])):
                with self.assertRaises(FileExistsError):
                    review.main()


if __name__ == '__main__':
    unittest.main()
