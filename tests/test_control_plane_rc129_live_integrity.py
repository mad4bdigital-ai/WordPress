"""Source-only review of verified RC128 live deltas over RC129 staging code.

No live WordPress read/write, no Owner approval, no package/signature issuance.
Only the exact review manifest's SHA-pinned candidate bytes can pass.
"""
import collections
import hashlib
import json
from pathlib import Path
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[1]
PLUGIN=ROOT/'wp-content/plugins/mad4b-site-control-plane'
REVIEW=ROOT/'docs/release-coverage/control-plane-rc128-live-rc129-review-candidate-20261113.json'
BRIDGE=ROOT/'docs/release-coverage/google-measurement-bridge-gtm-live-parity-20261113.json'
MU=ROOT/'docs/release-coverage/gtm-original-mu-source-restore-20261113.json'
SHA=lambda data:hashlib.sha256(data).hexdigest()


class Rc129LiveReview(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.review=json.loads(REVIEW.read_text())

    def test_exact_51_path_review_without_auto_release(self):
        doc=self.review
        self.assertEqual(doc['review_count'],51)
        self.assertEqual(len({x['path'] for x in doc['reviewed_paths']}),51)
        self.assertFalse(doc['original_site_files_modified'])
        self.assertFalse(doc['owner_signed_release_approved'])
        self.assertFalse(doc['production_mutation_authorized'])
        counts=collections.Counter(x['action'] for x in doc['reviewed_paths'])
        self.assertEqual(counts['fast_forward_reviewed_live_delta_from_rc128'],32)
        self.assertEqual(counts['reviewed_live_plus_rc129_diagnostic_compatibility'],1)
        self.assertEqual(counts['clean_original_3way_merge_review'],6)
        self.assertEqual(counts['reviewed_photo_story_nile_alert_merge'],1)
        self.assertEqual(counts['keep_rc129_stricter_site_authority'],2)
        self.assertEqual(counts['preserve_live_only_source_module_or_test'],3)

    def test_all_candidate_files_match_reviewed_sha(self):
        for row in self.review['reviewed_paths']:
            with self.subTest(path=row['path']):
                file=PLUGIN/row['path']
                if row['action']=='exclude_generated_rc128_provenance_from_rc129_source':
                    self.assertFalse(file.exists())
                    continue
                self.assertTrue(file.is_file() and not file.is_symlink())
                if row['path']=='mad4b-site-control-plane.php':
                    # Original verified RC128->RC129 preimage is preserved in
                    # Git. A separately SHA-pinned V29 readonly-only boot
                    # overlay is reviewed without altering old source proof.
                    overlay=json.loads((ROOT/'docs/release-coverage/control-plane-rc129-v29-readonly-overlay-20261113.json').read_text())
                    # The standalone overlay test reconstructs the exact
                    # preimage by reversing its three pinned additive hooks.
                    # This integrity check therefore works on a remote
                    # review branch even if local-only commits are absent.
                    from test_control_plane_rc129_v29_readonly_overlay import assert_bootstrap_overlay_is_exact
                    assert_bootstrap_overlay_is_exact()
                    self.assertEqual(SHA(file.read_bytes()),overlay['main_candidate_sha256'])
                else:
                    self.assertEqual(SHA(file.read_bytes()),row['candidate_after_sha256'])

    def test_live_advisory_alerts_and_stricter_site_authority(self):
        alert=(PLUGIN/'includes/class-mad4b-scp-content-readiness-alerts.php').read_text()
        for needle in ('MAD4B_Tour_Photo_Quality_V1::state_sha',
                       'MAD4B_Story_Observability_V2::accepts_issue',
                       'MAD4B_Nile_Intelligent_Inheritance_V1::accepts_issue',
                       '||$tourPhotoQuality||$storyMonitor||$nileInheritance'):
            self.assertIn(needle,alert)
        structural=(PLUGIN/'includes/class-mad4b-scp-contentjob-structural-cwa.php').read_text()
        self.assertIn("MAD4B_SCP_Site_Profile::current_environment()!=='staging'",structural)
        inventory=(PLUGIN/'includes/class-mad4b-scp-company-local-wpcli.php').read_text()
        self.assertIn("in_array($current_env,array('staging','production'),true)",inventory)
        self.assertNotIn('<<<<<<<',alert)

    def test_no_control_plane_downgrade_or_unreviewed_release(self):
        main=(PLUGIN/'mad4b-site-control-plane.php').read_text()
        self.assertIn('Version: 0.4.0-rc.129',main)
        source=(PLUGIN/'includes/class-mad4b-scp-self-update.php').read_text()
        self.assertIn('native_release_target_ancestor_or_older',source)
        self.assertIn('native_source_relationship(',source)
        self.assertFalse(self.review['production_mutation_authorized'])

    def test_google_bridge_and_original_mu_parity_with_owner_gate(self):
        bridge=json.loads(BRIDGE.read_text());mu=json.loads(MU.read_text())
        self.assertEqual(len(bridge['reviewed_files']),5)
        self.assertFalse(bridge['independent_owner_release_approved'])
        self.assertFalse(mu['source_release_approved'])
        for row in bridge['reviewed_files']:
            with self.subTest(path=row['path']):
                f=ROOT/'wp-content/plugins/mad4b-google-measurement-bridge'/row['path']
                self.assertEqual(SHA(f.read_bytes()),row['candidate_sha256'])
        src=ROOT/mu['source_path']
        self.assertEqual(SHA(src.read_bytes()),mu['sha256'])
        self.assertTrue(mu['matched_live_sha256'])

if __name__=='__main__':unittest.main()
