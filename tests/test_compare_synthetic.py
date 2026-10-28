import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('compare_synthetic', Path(__file__).resolve().parents[1] / 'tools/compare_synthetic.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class CompareSyntheticTest(unittest.TestCase):
    def test_url_mapping_remains_in_its_language(self):
        paths = {'item': {'product'}}
        hosts = {'shop.example.test'}
        self.assertEqual(module.candidate_from_url('https://shop.example.test/fr/item', 'fr-FR', paths, {}, hosts), 'product')
        self.assertIsNone(module.candidate_from_url('https://shop.example.test/de/item', 'fr-FR', paths, {}, hosts))
        self.assertIsNone(module.candidate_from_url('https://example.com/fr/item', 'fr-FR', paths, {}, hosts))
        self.assertIsNone(module.candidate_from_url('https://shop.example.test/fr/item', 'fr-FR', {'item': {'one', 'two'}}, {}, hosts))

    def test_unmapped_reference_is_not_a_definite_miss(self):
        ref = {'fr-FR': [{'query': 'example', 'productId': 'p', 'cards': [{'id': 'x', 'href': ''}]}]}
        local = {'fr-FR': [{'query': 'example', 'ids': ['p']}]}
        catalog = {'numbers': [], 'paths': {'fr-FR': []}}
        benchmark = {'languages': {'fr-FR': {'referenceMap': {}}}}
        metrics = module.compare(ref, local, catalog, benchmark, {'shop.example.test'})['fr-FR']
        self.assertEqual(metrics['referenceTargetUnknown'], 1)
        self.assertEqual(metrics['referenceTargetFound'], 0)
        self.assertEqual(metrics['localTargetFound'], 1)
        local['fr-FR'][0]['query'] = 'different'
        with self.assertRaises(ValueError):
            module.compare(ref, local, catalog, benchmark, {'shop.example.test'})


    def test_family_comparison_separates_alternative_variant_and_unknown(self):
        ref = {'fr-FR': [
            {'query': 'q1', 'productId': 'target', 'cards': [{'id': 'variant', 'href': ''}]},
            {'query': 'q2', 'productId': 'target', 'cards': [{'id': 'unknown', 'href': ''}]},
            {'query': 'q3', 'productId': 'target', 'cards': [{'id': 'other', 'href': ''}]},
        ]}
        local = {'fr-FR': [{'query': 'q1', 'ids': ['target']}, {'query': 'q2', 'ids': []}, {'query': 'q3', 'ids': []}]}
        catalog = {'numbers': [], 'paths': {'fr-FR': []}, 'families': {'target': 'family', 'sibling': 'family', 'other': 'other'}}
        benchmark = {'languages': {'fr-FR': {'referenceMap': {'variant': 'sibling', 'other': 'other'}}}}
        metrics = module.compare(ref, local, catalog, benchmark, {'shop.example.test'})['fr-FR']
        self.assertEqual(metrics['referenceFamilyFound'], 1)
        self.assertEqual(metrics['referenceOtherVariantOnly'], 1)
        self.assertEqual(metrics['referenceFamilyUnknown'], 1)
        self.assertEqual(metrics['referenceFamilyNotIdentified'], 1)
        self.assertEqual(metrics['referenceTargetFound'], 0)



if __name__ == '__main__':
    unittest.main()
