import csv
import importlib.util
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('importer', Path(__file__).resolve().parents[1] / 'tools/import_doofinder.py')
importer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(importer)


class ImportTests(unittest.TestCase):
    def setUp(self):
        importer.ENGINES = {'00000000000000000000000000100035': 'de-DE', '00000000000000000000000000100036': 'en-GB'}

    def test_cross_language_product_rejected(self):
        with self.assertRaises(ValueError):
            importer.product_id('00000000000000000000000000100036@product@' + 'a' * 32, '00000000000000000000000000100035')

    def test_duplicate_headers_preserved(self):
        header = ['hashid', 'search_term', 'total_searches', 'total_clicks', 'query_CTR', 'query_conversion_rate', 'query_avg_click_position', 'query_revenue', 'clicked_product_names', 'clicked_product_names', 'clicked_product_link', 'product_clicks', 'product_clicks']
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'terms.csv'
            with path.open('w', newline='') as stream:
                writer = csv.writer(stream)
                writer.writerow(header)
                writer.writerow(['00000000000000000000000000100035', 'query', '1', '1', '100', '0', '2', '0', 'id', 'name', 'url', '1', '2'])
            row = list(importer.read_rows(path, 'terms'))[0]
            self.assertEqual(row[8], 'id')
            self.assertEqual(row[9], 'name')
            self.assertEqual(row[11], '1')
            self.assertEqual(row[12], '2')

    def test_unrecognized_engine_rejected(self):
        with self.assertRaises(ValueError):
            importer.product_id('not-an-id', 'a' * 32)


if __name__ == '__main__':
    unittest.main()
