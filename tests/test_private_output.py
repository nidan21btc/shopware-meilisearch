import importlib.util
import os
import stat
import tempfile
import unittest
from pathlib import Path

module_path = Path(__file__).resolve().parents[1] / 'tools/private_output.py'
spec = importlib.util.spec_from_file_location('private_output', module_path)
private = importlib.util.module_from_spec(spec)
spec.loader.exec_module(private)


class PrivateOutputTests(unittest.TestCase):
    def test_owner_only_even_with_permissive_umask(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'report.json'
            old = os.umask(0)
            try:
                private.write_private_text(path, 'local search data')
            finally:
                os.umask(old)
            self.assertEqual(path.read_text(), 'local search data')
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)

    def test_existing_file_and_symlink_are_never_replaced(self):
        with tempfile.TemporaryDirectory() as directory:
            existing = Path(directory) / 'existing'
            existing.write_text('original')
            with self.assertRaises(FileExistsError):
                private.write_private_text(existing, 'new')
            link = Path(directory) / 'link'
            link.symlink_to(existing)
            with self.assertRaises(FileExistsError):
                private.write_private_text(link, 'new')
            self.assertEqual(existing.read_text(), 'original')

if __name__ == '__main__':
    unittest.main()
