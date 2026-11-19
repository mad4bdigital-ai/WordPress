"""Original v5-aware Worker checks independent Host 313 before dynamic 306.

Only read-only checks. No Job is issued, claimed, or executed.
"""
from pathlib import Path
import importlib.util
import unittest
from unittest import mock

SOURCE=Path(__file__).resolve().parents[1]/'integrations/claimed-job-worker-private-v5-hostreadiness.py'

class HostDynamic313ReadinessTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not Path('/opt/mad4b-company-transport/native-313-independent/root-only-verifier-ed25519.key').exists():
            raise unittest.SkipTest('First-party independent VPS Host is not mounted')
        spec=importlib.util.spec_from_file_location('firstparty_host313_candidate_test',SOURCE)
        cls.mod=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.mod)

    def test_registered_original_operations_preserved(self):
        ops=self.mod.operation_digests()
        self.assertGreaterEqual(len(ops),6)
        self.assertIn('dynamic:company.code.release',ops.values())
        self.assertTrue(callable(self.mod.private_draft_original_313_v5_ready))

    def test_live_independent_host_readiness(self):
        self.assertTrue(self.mod.dynamic_host_313_components_ready())
        self.assertFalse(self.mod.dynamic_release_grant_ready('a'*32,'b'*64))

    def test_untrusted_signer_denied(self):
        with mock.patch.object(self.mod,'DYNAMIC_313_SIGNER',Path('/tmp/does-not-exist-key')):
            self.assertFalse(self.mod.dynamic_host_313_components_ready())

    def test_unregistered_original_resumer_denied(self):
        with mock.patch.object(self.mod,'DYNAMIC_313_RESUMER',Path('/tmp/does-not-exist-original-resumer')):
            self.assertFalse(self.mod.dynamic_host_313_components_ready())

    def test_inactive_worker_timer_denied(self):
        with mock.patch.object(self.mod,'DYNAMIC_313_TIMERS',('does-not-exist.timer',)):
            self.assertFalse(self.mod.dynamic_host_313_components_ready())

    def test_source_guard_pins_latest_original_v5_worker(self):
        from pathlib import Path
        import hashlib,importlib.util
        script=SOURCE.parents[1]/'integrations/guard_original_313_worker_hostreadiness_release.py'
        src=script.read_text()
        digest=hashlib.sha256(SOURCE.read_bytes()).hexdigest()
        self.assertIn('guard.EXPECTED_NEW="'+digest+'"',src)
        self.assertIn('f9828ce5e744f114b7a057fe8ca41634176b1a3b1ee7c4b012370dec80ebd4f6',src)
        self.assertIn('claimed-job-worker-private-v5-hostreadiness.py',src)

    def test_original_central_not_treated_as_host_grant(self):
        with mock.patch.object(self.mod,'dynamic_host_313_components_ready',return_value=False):
            with mock.patch.object(self.mod,'cli_json',side_effect=AssertionError('NOT_CALLED')):
                self.assertFalse(self.mod.dynamic_313_original_central_ready('1'))

if __name__=='__main__':
    unittest.main()
