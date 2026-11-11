"""Source-fidelity canary for recovered runtime modules: never publish."""
from pathlib import Path
from copy import deepcopy
import importlib.util,json,hashlib,unittest
ROOT=Path(__file__).resolve().parents[1]
path=ROOT/'tools/verify_unrepresented_live_source.py'
spec=importlib.util.spec_from_file_location('mad4b_live_modules_snapshot_verifier',path)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
DATA=json.loads((ROOT/'docs/release-coverage/missing-live-code-source-snapshot-20261111.json').read_text())

class SnapshotIntegrity(unittest.TestCase):
    def test_all_file_digests_and_source_only(self):
        result=m.verify(DATA)
        self.assertEqual(result['files_verified'],533)
        self.assertFalse(result['company_deployment_authorized'])
        self.assertFalse(result['source_complete_for_global_release'])
    def test_manifest_cannot_claim_release(self):
        x=deepcopy(DATA);x['allow_release']=True
        with self.assertRaisesRegex(ValueError,'INVALID_RELEASE_CLAIM'):m.verify(x)
    def test_reject_missing_path(self):
        x=deepcopy(DATA);x['files'][0]['path']='wp-content/plugins/mad4b-platform/does-not-exist.php'
        with self.assertRaises(ValueError):m.verify(x)
    def test_reject_changed_digest(self):
        x=deepcopy(DATA);x['files'][0]['sha256']='0'*64
        with self.assertRaises(ValueError):m.verify(x)
    def test_reject_traversal(self):
        x=deepcopy(DATA);x['files'][0]['path']='../../etc/passwd'
        with self.assertRaises(ValueError):m.verify(x)
    def test_reject_widened_scope(self):
        x=deepcopy(DATA);x['files'][0]['path']='wp-content/plugins/other/plugin.php'
        with self.assertRaises(ValueError):m.verify(x)
    def test_recorded_original318_is_historical_not_v82(self):
        authority=ROOT/'wp-content/plugins/mad4b-platform/modules/authority/318-mad4b-native-host-exact-grant.php'
        self.assertTrue(authority.is_file())
        self.assertEqual(hashlib.sha256(authority.read_bytes()).hexdigest(),
          'ecfefbee1a60f52431c4688f7b5858ea1df3633812483c9794f36515c418af55')
        self.assertFalse(DATA['original318_v82_not_automatically_substituted'] is False)
if __name__=='__main__':unittest.main()
