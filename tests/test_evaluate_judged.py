import copy
import math
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
from evaluate_judged import evaluate


class JudgedEvaluationTest(unittest.TestCase):
    def test_weighted_ranking_and_empty(self):
        cases = [
            {'locale': 'de-DE', 'weight': 3, 'judgments': {'a': 3, 'b': 1}, 'reference': ['a', 'b'], 'candidate': ['b', 'a']},
            {'locale': 'de-DE', 'weight': 1, 'judgments': {'a': 3}, 'reference': ['a'], 'candidate': []},
            {'locale': 'fr-FR', 'weight': 2, 'judgments': {'c': 0}, 'reference': ['c'], 'candidate': []},
        ]
        result = evaluate(cases)
        ideal = 7 + 1 / math.log2(3)
        candidate = (1 + 7 / math.log2(3)) / ideal
        self.assertAlmostEqual(result['de-DE']['candidateNdcgAt10'], 3 * candidate / 4)
        self.assertEqual(result['de-DE']['referenceNdcgAt10'], 1)
        self.assertEqual(result['de-DE']['candidateHitAt5'], .75)
        self.assertEqual(result['de-DE']['candidateEmptyRate'], .25)
        self.assertEqual(result['de-DE']['candidateEmptyRateWhenRelevantKnown'], .25)
        self.assertIsNone(result['fr-FR']['candidateEmptyRateWhenRelevantKnown'])
        self.assertIsNone(result['fr-FR']['candidateNdcgAt10'])
        self.assertEqual(result['fr-FR']['noKnownRelevantCases'], 1)

    def test_partially_relevant_is_not_a_matching_product(self):
        cases = [
            {'locale': 'de-DE', 'weight': 2, 'judgments': {'partial': 1, 'matching': 2}, 'reference': ['matching'], 'candidate': ['partial']},
            {'locale': 'de-DE', 'weight': 3, 'judgments': {'partial': 1}, 'reference': ['partial'], 'candidate': []},
        ]
        result = evaluate(cases)['de-DE']
        self.assertEqual(result['matchingEligibleWeight'], 2)
        self.assertEqual(result['noKnownMatchingCases'], 1)
        self.assertEqual(result['referenceHitAt5'], 1)
        self.assertEqual(result['candidateHitAt5'], 0)
        self.assertEqual(result['candidateEmptyRateWhenRelevantKnown'], 0)
        self.assertEqual(result['candidateEmptyRate'], 3 / 5)
        self.assertGreater(result['candidateNdcgAt10'], 0)

    def test_unjudged_and_bad_input_rejected(self):
        case = {'locale': 'de-DE', 'weight': 1, 'judgments': {'a': 1}, 'reference': ['a'], 'candidate': ['a']}
        for change in ({'candidate': ['b']}, {'candidate': ['a', 'a']}, {'weight': float('nan')},
                       {'weight': True}, {'judgments': {'a': True}}, {'locale': 'de\nDE'}):
            modified = copy.deepcopy(case)
            modified.update(change)
            with self.subTest(change=change), self.assertRaises(ValueError):
                evaluate([modified])
        huge = dict(case, weight=1e308)
        with self.assertRaises(ValueError):
            evaluate([huge, huge])


if __name__ == '__main__':
    unittest.main()
