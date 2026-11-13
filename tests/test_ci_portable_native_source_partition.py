"""Confirm CI source coverage is dynamic and never mints Host authority."""
from contextlib import redirect_stdout, redirect_stderr
from io import StringIO
import importlib.util
import json
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import patch
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'ops/native-source-publisher-v1/ci_portable_native_tests.py'
spec = importlib.util.spec_from_file_location('mad4b_ci_source_partition', SCRIPT)
partition = importlib.util.module_from_spec(spec)
spec.loader.exec_module(partition)

class NativeSourcePartitionContract(unittest.TestCase):
    def test_real_source_partition_is_total_and_never_approves_host(self):
        available = {p.name for p in (ROOT/'tests').glob('test_native_source*.py')}
        self.assertGreaterEqual(len(available), 24)
        self.assertEqual(len(available - set(partition.HOST_BOUND_TESTS)) +
                         len(partition.HOST_BOUND_TESTS), len(available))
        self.assertTrue(set(partition.HOST_BOUND_TESTS) <= available)
        self.assertTrue(all(x.strip() for x in partition.HOST_BOUND_TESTS.values()))
        code = SCRIPT.read_text()
        for claim in ('original_host_authority_verified',
                      'original313_production_receipt_verified', 'release_authorized'):
            self.assertIn("'" + claim + "': False", code)
        self.assertIn('SOURCE_ONLY__HOST_EVIDENCE_RELEASE_HOLD', code)

    def test_any_new_source_module_is_executed_without_allowlist_change(self):
        with TemporaryDirectory(prefix='mad4b-ci-native-partition-') as d:
            folder = Path(d)
            (folder/'test_native_source_future_provider.py').write_text(
                'import unittest\n'
                'class NewProvider(unittest.TestCase):\n'
                ' def test_refuses_unapproved_write(self): self.assertTrue(True)\n'
            )
            captured = StringIO()
            with patch.object(partition, 'TESTS', folder), \
                 patch.object(partition, 'HOST_BOUND_TESTS', {}), \
                 redirect_stdout(captured), redirect_stderr(StringIO()):
                self.assertEqual(partition.main(), 0)
            event = json.loads(captured.getvalue().splitlines()[0])
            self.assertEqual(event['portable_test_cases'], 1)
            self.assertIn('test_native_source_future_provider.py', event['portable_modules_tested'])
            self.assertFalse(event['release_authorized'])
            self.assertFalse(event['original_host_authority_verified'])

    def test_missing_host_test_module_is_rejected_instead_of_silently_skipped(self):
        with TemporaryDirectory(prefix='mad4b-ci-native-partition-missing-') as d:
            folder = Path(d)
            (folder/'test_native_source_safe.py').write_text('import unittest\n')
            with patch.object(partition, 'TESTS', folder), \
                 patch.object(partition, 'HOST_BOUND_TESTS',
                                   {'test_native_source_missing.py':'missing trust fixture'}):
                with self.assertRaisesRegex(RuntimeError, 'HOST_TEST_CONTRACT_FILE_MISSING'):
                    partition.main()

if __name__ == '__main__':
    unittest.main()
