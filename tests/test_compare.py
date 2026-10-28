import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('comparison', Path(__file__).resolve().parents[1] / 'tools/compare_snapshots.py')
comparison = importlib.util.module_from_spec(spec)
spec.loader.exec_module(comparison)


class CompareTests(unittest.TestCase):
    def test_same_mapped_clicks_on_both_sides(self):
        raw = {'languages': {'de-DE': {'cases': [{'query': 'x', 'relevantProducts': {'external1': 3, 'external2': 2, 'missing': 10}}]}}}
        mapped = {'languages': {'de-DE': {'referenceMap': {'external1': 'local1', 'external2': 'local2'}}}}
        reference = {'de-DE': [{'query': 'x', 'ids': ['external1']}]}
        candidate = {'de-DE': [{'query': 'x', 'ids': ['local2']}]}
        result = comparison.compare(raw, mapped, reference, candidate)['de-DE']['summary']
        self.assertEqual(result['referenceClickCoverage'], 3)
        self.assertEqual(result['candidateClickCoverage'], 2)
        self.assertEqual(result['mappedHistoricalClicks'], 5)
        self.assertEqual(result['unmappedHistoricalClicks'], 10)
        self.assertEqual(result['referenceDiscountedClicks'], 3)
        self.assertEqual(result['candidateDiscountedClicks'], 2)

    def test_other_variant_is_reported_without_counting_as_exact_click(self):
        raw = {'languages': {'de-DE': {'cases': [{'query': 'x', 'relevantProducts': {'a': 3, 'b': 2, 'c': 5}}]}}}
        mapped = {'languages': {'de-DE': {'referenceMap': {'a': 'a1', 'b': 'b1', 'c': 'c1'}}}}
        reference = {'de-DE': [{'query': 'x', 'ids': ['a', 'b']}]}
        candidate = {'de-DE': [{'query': 'x', 'ids': ['a2', 'unknown']}]}
        families = {'a1': 'family-a', 'a2': 'family-a', 'b1': 'family-b', 'c1': 'family-a'}
        summary = comparison.compare(raw, mapped, reference, candidate, families)['de-DE']['summary']
        self.assertEqual(summary['candidateClickCoverage'], 0)
        self.assertEqual(summary['candidateOtherVariantClickWeight'], 3)
        self.assertEqual(summary['candidateFamilyUnknownClickWeight'], 2)

    def test_missing_family_of_target_is_unknown(self):
        raw = {'languages': {'en-GB': {'cases': [{'query': 'x', 'relevantProducts': {'a': 4}}]}}}
        mapped = {'languages': {'en-GB': {'referenceMap': {'a': 'a1'}}}}
        reference = {'en-GB': [{'query': 'x', 'ids': ['a']}]}
        candidate = {'en-GB': [{'query': 'x', 'ids': ['b1']}]}
        summary = comparison.compare(raw, mapped, reference, candidate, {'b1': 'family-b'})['en-GB']['summary']
        self.assertEqual(summary['candidateOtherVariantClickWeight'], 0)
        self.assertEqual(summary['candidateFamilyUnknownClickWeight'], 4)

    def test_mismatched_query_is_not_exposed_in_error(self):
        raw = {'languages': {'de-DE': {'cases': []}}}
        mapped = {'languages': {'de-DE': {'referenceMap': {}}}}
        reference = {'de-DE': [{'query': 'private-test-query', 'ids': []}]}
        with self.assertRaises(ValueError) as error:
            comparison.compare(raw, mapped, reference, {'de-DE': []})
        self.assertNotIn('private-test-query', str(error.exception))


if __name__ == '__main__':
    unittest.main()
