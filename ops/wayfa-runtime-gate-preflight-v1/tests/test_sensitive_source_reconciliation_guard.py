"""Source-only guard: do not turn the current Wayfatours live conflicts into grants."""
import json,unittest,hashlib
from pathlib import Path

ROOT=Path(__file__).resolve().parents[3]
PLUGIN=ROOT/'wp-content/plugins/mad4b-site-control-plane/includes'
REVIEW=ROOT/'ops/wayfa-runtime-gate-preflight-v1/evidence/sensitive-source-reconciliation-review-20261119.json'

class SensitiveSourceReview(unittest.TestCase):
    def test_exact_non_authorizing_review(self):
        d=json.loads(REVIEW.read_text())
        self.assertEqual(d['original_conflict_count'],21)
        self.assertEqual(d['remaining_full_live_conflicts_uncertified'],21)
        self.assertFalse(d['production_authorized'])
        self.assertFalse(d['production_mutated'])
        self.assertTrue(d['requires_exact_host_preflight_before_release'])
        self.assertEqual(len(d['rows']),4)
        for row in d['rows']:
            self.assertFalse(row['production_authorized'])
            self.assertFalse(row['automatic_merge'])
            self.assertFalse(row['apply_live_over_candidate'])
            self.assertEqual(hashlib.sha256((PLUGIN/Path(row['path']).name).read_bytes()).hexdigest(),row['candidate_sha256'])

    def test_site_profile_does_not_trust_requested_uuid_without_host_attestation(self):
        src=(PLUGIN/'class-mad4b-scp-site-profile.php').read_text()
        for token in ('mad4b_scp_authorized_reserved_site_uuid','mad4b_reserved_site_uuid_not_attested',
                      'mad4b_reserved_site_uuid_identity_conflict','wp_generate_uuid4()'):
            self.assertIn(token,src)

    def test_agent_grants_keep_exact_capability_and_identity(self):
        src=(PLUGIN/'class-mad4b-scp-agent-grant-lifecycle.php').read_text()
        for token in ('exact_db_number','origin_enrolled()', 'site_urls_match_enrollment()',
                      'verified_bearer_active()', 'wp_verify_nonce(', 'mad4b_agent_grant_inventory_unverified'):
            self.assertIn(token,src)

    def test_context_title_only_classification_and_reindex(self):
        src=(PLUGIN/'class-mad4b-scp-context-authority.php').read_text()
        a=src.index('public static function classify_asset(')
        b=src.index('public static function score_asset(',a)
        block=src[a:b]
        self.assertIn('Folder paths and body references are provenance/context',block)
        self.assertIn('$title_path = $haystack;',block)
        self.assertNotIn("trim( (string) $name . ' ' . (string) $path",block)
        self.assertIn('mad4b_context_auto_classification_stale_requires_reindex',src)

    def test_host_signed_release_preflight_is_an_explicit_remaining_blocker(self):
        d=json.loads(REVIEW.read_text())
        row=next(e for e in d['rows'] if e['path'].endswith('class-mad4b-scp-self-update.php'))
        self.assertEqual(row['decision'],'blocked_host_preflight_dependency')
        self.assertTrue(d['requires_exact_host_preflight_before_release'])
        src=(PLUGIN/'class-mad4b-scp-self-update.php').read_text()
        self.assertIn('private static function apply_verified_archive(',src)
        self.assertNotIn('MAD4B_Release_Source_Admission::require_for_release(',src)
        self.assertFalse(d['production_authorized'])

if __name__=='__main__':
    unittest.main()
