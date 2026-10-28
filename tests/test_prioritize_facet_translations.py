import csv
import importlib.util
import stat
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('prioritize_facets', Path(__file__).resolve().parents[1] / 'tools/prioritize_facet_translations.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
A, B, C = 'a' * 32, 'b' * 32, 'c' * 32


def item(kind, count, identifier, locale='it-IT', name='Safe'):
    return {'salesChannelId': A, 'languageId': B, 'locale': locale, 'kind': kind,
            'id': identifier, 'affectedProducts': count,
            'referenceOnly': {'locale': 'de-DE', 'name': name}}


class FacetPriorityTest(unittest.TestCase):
    def test_orders_by_locale_and_coverage_without_reusing_reference_as_translation(self):
        rows = [item('manufacturer', 5, C, name='=1+1'), item('category', 100, B), item('manufacturer', 3, C, locale='fr-FR')]
        rows[2]['languageId'] = C
        ranked = module.validate(rows)
        self.assertEqual([(r['locale'], r['kind'], r['affectedProducts']) for r in ranked],
                         [('fr-FR', 'manufacturer', 3), ('it-IT', 'category', 100), ('it-IT', 'manufacturer', 5)])
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'priority.csv'
            module.write_csv(path, ranked)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
            with path.open(encoding='utf-8-sig', newline='') as f:
                content = list(csv.DictReader(f, delimiter=';'))
            self.assertEqual(content[-1]['Referenzname (nur zur Prüfung)'], "'=1+1")
            with self.assertRaises(FileExistsError):
                module.write_csv(path, ranked)

    def test_rejects_duplicate_and_invalid_records(self):
        row = item('manufacturer', 5, C)
        with self.assertRaises(ValueError):
            module.validate([row, row])
        with self.assertRaises(ValueError):
            module.validate([dict(row, affectedProducts=-1)])
        with self.assertRaises(ValueError):
            module.validate([dict(row, id='not-an-id')])


if __name__ == '__main__':
    unittest.main()
