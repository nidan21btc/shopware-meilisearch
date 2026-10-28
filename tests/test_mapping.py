import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('mapping', Path(__file__).resolve().parents[1] / 'tools/map_product_references.py')
mapping = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mapping)


class MappingTests(unittest.TestCase):
    def test_conflicting_ambiguous_url_is_not_masked_by_unique_url(self):
        benchmark = {'languages': {'de-DE': {'cases': [
            {'relevantProducts': {'seo-conflict': 3, 'number-conflict': 4, 'consistent': 2}},
        ], 'productReferences': {
            'seo-conflict': ['https://shop.example.test/de/unique', 'https://shop.example.test/de/reused'],
            'number-conflict': ['https://shop.example.test/de/unique', 'https://shop.example.test/de/DUPLICATE'],
            'consistent': ['https://shop.example.test/de/unique', 'https://shop.example.test/de/unique-again'],
        }}}}
        catalog = {'paths': {'de-DE': [['unique', 'id-a'], ['unique-again', 'id-a'], ['reused', 'id-a'], ['reused', 'id-b']]},
                   'numbers': [['DUPLICATE', 'id-a'], ['DUPLICATE', 'id-c']]}
        result = mapping.resolve(benchmark, catalog, {'shop.example.test'})
        case = result['languages']['de-DE']['cases'][0]
        self.assertEqual(case['relevantProducts'], {'id-a': 2})
        self.assertEqual(case['unmappedHistoricalClicks'], 7)
        self.assertEqual(result['referenceMapping']['languages']['de-DE']['mappedReferences'], 1)

    def test_same_language_paths_and_ambiguous_paths(self):
        benchmark = {'languages': {'de-DE': {'cases': [
            {'relevantProducts': {'external-a': 3, 'external-b': 2, 'external-c': 1, 'external-d': 4}},
        ], 'productReferences': {
            'external-a': ['https://shop.example.test/de/alt/rose'],
            'external-b': ['https://shop.example.test/en/alt/rose'],
            'external-c': ['https://shop.example.test/de/EL123/new'],
            'external-d': ['https://shop.example.test/de/ambiguous'],
        }}}}
        catalog = {'paths': {'de-DE': [['alt/rose', 'id-a'], ['ambiguous', 'id-a'], ['ambiguous', 'id-b']]},
                   'numbers': [['EL123', 'id-c']]}
        result = mapping.resolve(benchmark, catalog, {'shop.example.test'})
        case = result['languages']['de-DE']['cases'][0]
        self.assertEqual(case['relevantProducts'], {'id-a': 3, 'id-c': 1})
        self.assertEqual(case['unmappedHistoricalClicks'], 6)
        self.assertEqual(result['referenceMapping']['languages']['de-DE']['mappedReferences'], 2)


if __name__ == '__main__':
    unittest.main()
