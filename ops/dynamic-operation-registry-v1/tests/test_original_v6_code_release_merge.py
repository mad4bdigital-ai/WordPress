from pathlib import Path
import hashlib, importlib.util, unittest
from unittest import mock
SOURCE=Path(__file__).resolve().parents[1]/'integrations/claimed-job-worker-postmeta-v6-code-release-merged.py'
GUARD=SOURCE.with_name('guard_original_313_worker_postmeta_v6_code_release_merged.py')
class OriginalWorkerPostmetaV6MergeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        spec=importlib.util.spec_from_file_location('original_306_v6_merged_source',SOURCE)
        cls.m=importlib.util.module_from_spec(spec);spec.loader.exec_module(cls.m)
    def test_v6_and_v5_features_retained(self):
        s=SOURCE.read_text()
        for part in ('def postmeta_v6_ready(actor):',
                     'def postmeta_claimed_ready(job,actor,digest):',
                     'def private_draft_original_313_v5_ready(actor):',
                     'def dynamic_host_313_components_ready():',
                     "'$c=get_option(MAD4B_Central_VPS_Broker_V1::CLAIM.$id,false);'"):
            self.assertIn(part,s)
        self.assertNotIn("'$p=get_option(MAD4B_Broker_Remote_SSH_Receipt_V1::WPCLI_PREFIX.$id,false);'",s)
    def test_guard_pins_merged_source(self):
        digest=hashlib.sha256(SOURCE.read_bytes()).hexdigest()
        self.assertIn('guard.EXPECTED_NEW="'+digest+'"',GUARD.read_text())
        self.assertIn('bb505a2fb0b3e0834ce815b4c17b380e2fdde1193d8cdd6424b785223f315c6f',GUARD.read_text())
    def test_dynamic_host_ready_only_with_real_signer(self):
        if not Path('/opt/mad4b-company-transport/native-313-independent/root-only-verifier-ed25519.key').is_file():
            self.skipTest('Host key not mounted')
        self.assertTrue(self.m.dynamic_host_313_components_ready())
        with mock.patch.object(self.m,'DYNAMIC_313_SIGNER',Path('/tmp/missing-signing-key')):
            self.assertFalse(self.m.dynamic_host_313_components_ready())
    def test_no_release_grant_for_random_job(self):
        self.assertFalse(self.m.dynamic_release_grant_ready('b'*32,'c'*64))
