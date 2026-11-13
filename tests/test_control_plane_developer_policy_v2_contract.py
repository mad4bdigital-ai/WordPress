from tour_highlights_bootstrap_overlay import original_bootstrap_bytes
"""Review-only, non-authorizing V2 projection and exact portable Skill parity."""
from pathlib import Path
import hashlib
import json
import unittest

ROOT=Path(__file__).resolve().parents[1]
REVIEW=ROOT/'docs/release-coverage/pr808-developer-policy-sidecar-v2-review-20261113.json'
SKILL_RECORD=ROOT/'docs/release-coverage/pr808-portable-skills-provenance-20261113.json'
PLUGIN=ROOT/'wp-content/plugins/mad4b-site-control-plane'
SHA=lambda raw:hashlib.sha256(raw).hexdigest()

class DeveloperPolicyV2Review(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.review=json.loads(REVIEW.read_text())

    def test_historical_v1_plan_and_exact_source_are_immutable(self):
        d=self.review
        self.assertFalse(d['legacy_canonical_plan_schema_changed'])
        self.assertFalse(d['legacy_plan_sha_algorithm_changed'])
        self.assertFalse(d['legacy_plan_production_allowed_field_changed'])
        self.assertFalse(d['historical_signed_plan_approvals_changed'])
        self.assertFalse(d['live_grants_issued'])
        self.assertFalse(d['live_deployment_performed'])
        self.assertFalse(d['original312_313_receipts_issued'])
        original=PLUGIN/'includes/class-mad4b-scp-developer-authority.php'
        self.assertEqual(SHA(original.read_bytes()),d['legacy_v1_developer_source_sha256_unchanged'])
        for name,digest in d['source_after_sha256'].items():
            with self.subTest(name=name):
                p=ROOT/name
                self.assertTrue(p.is_file() and not p.is_symlink())
                current=p.read_bytes()
                if name=='wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php':
                    current=original_bootstrap_bytes(current)
                self.assertEqual(SHA(current),digest)
        for name,digest in d['prior_source_sha256'].items():
            self.assertRegex(digest,r'^[a-f0-9]{64}$')

    def test_v2_is_separate_and_never_mints_grants(self):
        s=(PLUGIN/'includes/class-mad4b-scp-developer-authority-policy-v2.php').read_text()
        op=(PLUGIN/'includes/class-mad4b-scp-operator-grant-convergence.php').read_text()
        self.assertIn("const LEGACY_PLAN_CONTRACT = 'mad4b.developer-authority.v1'",s)
        self.assertIn("hash_equals( $sha, hash( 'sha256', $json ) )",s)
        self.assertIn('canonical_plan_modified',s)
        self.assertIn("'authorization_issued' => false",s)
        self.assertIn("'grant_issued' => false",s)
        self.assertIn("'v2_view_is_authorizing' => false",s)
        self.assertIn('Developer_Authority_Policy_V2::project(',op)
        self.assertIn("'developer_policy_v2' => $policy_v2",op)
        self.assertIn('MAD4B_SCP_Developer_Authority::apply( $canonical )',op)

    def test_missing_portable_skills_are_exact_git_source_reconciliations(self):
        record=json.loads(SKILL_RECORD.read_text())
        manifest=json.loads((PLUGIN/'config/skill-seed-manifest.json').read_text())
        names={x['name'] for x in manifest['skills']}
        self.assertEqual(record['entry_count'],8)
        self.assertEqual(record['canonical_manifest_count'],21)
        self.assertEqual(len(names),21)
        self.assertTrue(record['git_object_verified'])
        self.assertFalse(record['live_site_modified'])
        self.assertEqual({x['skill'] for x in record['source_git_objects']},
                         {x for x in names if x.startswith('nile-') or x.startswith('tour-optional-') or x in ('wordpress-cache-performance-governor','wordpress-mobile-css-delivery','wordpress-performance-intelligence-v2')})
        for name in names:
            canonical=PLUGIN/'skill-seeds'/name/'SKILL.md'
            portable=ROOT/'plugins/mad4b-wordpress/skills'/name/'SKILL.md'
            with self.subTest(skill=name):
                self.assertTrue(canonical.is_file() and portable.is_file())
                self.assertFalse(canonical.is_symlink() or portable.is_symlink())
                self.assertEqual(canonical.read_bytes(),portable.read_bytes())
        for entry in record['source_git_objects']:
            p=PLUGIN/'skill-seeds'/entry['skill']/'SKILL.md'
            self.assertEqual(SHA(p.read_bytes()),entry['sha256'])
            self.assertEqual(entry['copied_to'],2)
            self.assertFalse(entry['installed_from_source'])
            self.assertRegex(entry['git_sha'],r'^[a-f0-9]{40}$')

if __name__=='__main__':unittest.main()
