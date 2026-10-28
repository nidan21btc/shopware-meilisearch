import csv
import importlib.util
import stat
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('translation_impact', Path(__file__).resolve().parents[1] / 'tools/translation_impact.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class TranslationImpactTest(unittest.TestCase):
    def test_clicks_only_count_in_affected_language(self):
        report = [{'locale': 'fr-FR', 'productId': 'x'}, {'locale': 'fr-FR', 'productId': 'x'}]
        benchmark = {'languages': {
            'fr-FR': {'cases': [{'relevantProducts': {'x': 4, 'y': 2}}, {'relevantProducts': {}}]},
            'de-DE': {'cases': [{'relevantProducts': {'x': 3}}]},
        }}
        results = module.summarize(report, benchmark)
        self.assertEqual(results['fr-FR'], {'queries': 2, 'mappedClicks': 6, 'affectedClicks': 4, 'queriesWithAffectedClicks': 1})
        self.assertEqual(results['de-DE']['affectedClicks'], 0)


    def test_priority_is_language_scoped_deduplicated_and_safe_for_spreadsheets(self):
        report = [
            {'locale': 'en-GB', 'salesChannelId': 'channel', 'productId': 'a', 'productNumber': '=FORMULA'},
            {'locale': 'en-GB', 'salesChannelId': 'channel', 'productId': 'a', 'productNumber': '=FORMULA'},
            {'locale': 'en-GB', 'salesChannelId': 'channel', 'productId': 'b', 'productNumber': '002'},
            {'locale': 'fr-FR', 'salesChannelId': 'channel', 'productId': 'a', 'productNumber': '=FORMULA'},
        ]
        benchmark = {'languages': {'en-GB': {'cases': [
            {'query': 'private query', 'relevantProducts': {'a': 3, 'b': 1}},
            {'query': 'other query', 'relevantProducts': {'a': 2}},
        ]}, 'fr-FR': {'cases': []}}}
        rows = module.prioritize(report, benchmark)
        self.assertEqual([(r['locale'], r['productId'], r['mappedClicks'], r['queriesWithClicks']) for r in rows],
                         [('en-GB', 'a', 5, 2), ('en-GB', 'b', 1, 1), ('fr-FR', 'a', 0, 0)])
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'priorities.csv'
            module.write_priority(path, rows)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
            content = path.read_text(encoding='utf-8-sig')
            self.assertNotIn('private query', content)
            values = list(csv.reader(content.splitlines(), delimiter=';'))
            self.assertEqual(values[1][3], "'=FORMULA")
            with self.assertRaises(FileExistsError):
                module.write_priority(path, rows)



if __name__ == '__main__':
    unittest.main()
