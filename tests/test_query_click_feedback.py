import importlib.util
import json
import os
import stat
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

TOOL = Path(__file__).resolve().parents[1] / 'tools/build_query_click_feedback.py'
spec = importlib.util.spec_from_file_location('query_click_feedback', TOOL)
feedback = importlib.util.module_from_spec(spec); spec.loader.exec_module(feedback)
A, B = 'a' * 32, 'b' * 32

def case(query, searches, products, partition='development'):
    return {'query': query, 'searches': searches, 'relevantProducts': products, 'partition': partition}

class QueryClickFeedbackTests(unittest.TestCase):
    def test_fingerprint_matches_php_and_keeps_locales_separate(self):
        query = '  BLA\u0308TTER\tTest '
        self.assertEqual(feedback.fingerprint('de-DE', query, 'local-test-key'), '10093bd0c0686b5e3b7ba4619354bf8be861c5614059710f2127e2283406d0f8')
        self.assertNotEqual(feedback.fingerprint('de-DE', query, 'local-test-key'), feedback.fingerprint('en-GB', query, 'local-test-key'))
        self.assertNotEqual(feedback.fingerprint('de-DE', query, 'local-test-key'), feedback.fingerprint('de-DE', query, 'another-key'))
        self.assertEqual(feedback.key_check('local-test-key'), 'f7a0cc182218696c297fc4c83120b95b7b227636d5365c37fd0fb12451b69823')

    def test_only_repeated_mapped_clicks_are_included_per_locale(self):
        sample = {'languages': {'de-DE': {'cases': [case(' Ruhe ', 3, {A: 3, B: 1}), case('RUHE', 4, {B: 2}), case('hidden', 5, {A: 3}, 'holdout')]},
                                'en-GB': {'cases': [case('Ruhe', 3, {B: 4})]}}}
        output = feedback.build(sample, 'local-test-key')
        digest = feedback.fingerprint('de-DE', 'ruhe', 'local-test-key')
        self.assertEqual(output['de-DE'][digest], {A: 3, B: 2})
        self.assertEqual(len(output['de-DE']), 1)
        self.assertNotIn(digest, output['en-GB'])
        self.assertNotIn('ruhe', json.dumps(output).lower())
        self.assertEqual(len(feedback.build(sample, 'local-test-key', 'all')['de-DE']), 2)

    def test_dry_run_counts_coverage_without_writing_or_exposing_queries(self):
        sample = {'languages': {'de-DE': {'cases': [case('Private example', 4, {A: 3}),
                                                    case('PRIVATE EXAMPLE', 1, {}),
                                                    case('Unmatched query', 2, {})]},
                                'en-GB': {'cases': [case('Private example', 3, {B: 4})]}}}
        with tempfile.TemporaryDirectory() as directory:
            src = Path(directory) / 'source.json'
            src.write_text(json.dumps(sample), encoding='utf-8')
            env = os.environ.copy(); env.pop('SHOPWARE_MEILISEARCH_KEY', None)
            process = subprocess.run([sys.executable, str(TOOL), '--benchmark', str(src), '--dry-run'],
                                     env=env, check=True, text=True, capture_output=True)
            self.assertNotIn('Private example', process.stdout)
            self.assertEqual(sorted(p.name for p in Path(directory).iterdir()), ['source.json'])
            result = json.loads(process.stdout)
            self.assertEqual(result['de-DE'], {'signal_queries': 1, 'signal_products': 1,
                                               'historical_searches': 7, 'historical_searches_with_signal': 5})
            self.assertEqual(result['en-GB']['historical_searches_with_signal'], 3)

    def test_output_is_private_and_never_overwrites(self):
        sample = {'languages': {'de-DE': {'cases': [case('Private example', 4, {A: 3})]}}}
        with tempfile.TemporaryDirectory() as directory:
            src = Path(directory) / 'source.json'; target = Path(directory) / 'signals.json'
            src.write_text(json.dumps(sample), encoding='utf-8')
            env = os.environ.copy(); env['SHOPWARE_MEILISEARCH_KEY'] = 'local-test-key'
            command = [sys.executable, str(TOOL), '--benchmark', str(src), '--output', str(target)]
            subprocess.run(command, env=env, check=True, capture_output=True)
            data = target.read_text(encoding='utf-8')
            self.assertNotIn('Private example', data); self.assertNotIn('local-test-key', data)
            self.assertEqual(json.loads(data)['version'], 1)
            self.assertEqual(json.loads(data)['keyCheck'], feedback.key_check('local-test-key'))
            self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o600)
            second = subprocess.run(command, env=env, capture_output=True)
            self.assertNotEqual(second.returncode, 0); self.assertEqual(target.read_text(encoding='utf-8'), data)

if __name__ == '__main__': unittest.main()
