from tour_highlights_bootstrap_overlay import original_bootstrap_bytes
from control_plane_pr808_historical_successor import assert_source_successor
"""Review-only, non-authorizing V2 projection and exact portable Skill parity."""
from pathlib import Path
import hashlib
import json
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[1]
REVIEW=ROOT/'docs/release-coverage/pr808-developer-policy-sidecar-v2-review-20261113.json'
SKILL_RECORD=ROOT/'docs/release-coverage/pr808-portable-skills-provenance-20261113.json'
PLUGIN=ROOT/'wp-content/plugins/mad4b-site-control-plane'
SHA=lambda raw:hashlib.sha256(raw).hexdigest()

# A PR808 signed-era hash can only be checked against its immutable Git
# ancestor. The later seed-v31 successor is independently bound to the
# append-only, non-authorizing Source Readiness exact-blob review ledger.
PR808_FROZEN_COMMIT='46c0c77b937b6d14b3e5fc9f1df35f1a8654368f'
SEEDER_PATH='wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-skill-seeder.php'
MANIFEST_PATH='wp-content/plugins/mad4b-site-control-plane/config/skill-seed-manifest.json'
NEW_OPERATIONAL_RUNBOOKS=frozenset({
 'master-admin-operations','skills-coverage-and-recovery',
 'role-and-authority-audit','booking-to-cash-reconciliation',
 'pricing-catalog-readiness','localization-ui-parity',
 'incident-and-release-recovery','team-workload-and-sla',
})

def immutable_pr808_bytes(path):
    return subprocess.check_output(
        ['git','-C',str(ROOT),'show',PR808_FROZEN_COMMIT+':'+path],
        timeout=20,
    )

def reviewed_current_successor(path):
    source=ROOT/path
    assert source.is_file() and not source.is_symlink(),path
    result=subprocess.run(['git','-C',str(ROOT),'rev-parse','HEAD:'+path],
                          check=True,capture_output=True,text=True,timeout=20)
    committed=result.stdout.strip()
    raw=source.read_bytes()
    computed=hashlib.sha1(b'blob '+str(len(raw)).encode('ascii')+bytes([0])+raw).hexdigest()
    assert committed==computed and len(committed)==40,(path,'Git source drift')
    ledger=json.loads((ROOT/'governance/production-readiness/capability-coverage.json').read_text())
    assert ledger['contract']=='mad4b.readiness-capability-coverage.v1'
    assert ledger['authorizing'] is False and ledger['production_promotion_from_ci'] is False
    reviewed=[row for row in ledger['reviewed_changes']
              if row.get('path')==path and row.get('source_blob_sha')==committed]
    assert len(reviewed)==1,('unreviewed exact successor',path)
    row=reviewed[0]
    assert row['status']=='reviewed_for_ci_only'
    assert row['production_authorized'] is False and row['live_evidence_required'] is True
    assert {'AUTOMATION-01','PERFORMANCE-01'}<=set(row['control_ids'])
    assert isinstance(row['review_rationale'],str) and len(row['review_rationale'])>70
    assert isinstance(row['regression_tests'],list) and row['regression_tests']
    for regression in row['regression_tests']:
        assert (ROOT/regression).is_file() and not (ROOT/regression).is_symlink()
    return row


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
        # PR808's frozen V1 pin belongs to its original Git ancestor.
        # Check the immutable old blob and separately reviewed current
        # guarded successor. Never rewrite the original signed-era record.
        assert_source_successor(
            'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-developer-authority.php',
            d['legacy_v1_developer_source_sha256_unchanged'])
        for name,digest in d['source_after_sha256'].items():
            with self.subTest(name=name):
                p=ROOT/name
                self.assertTrue(p.is_file() and not p.is_symlink())
                current=p.read_bytes()
                if name=='wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php':
                    current=original_bootstrap_bytes(current)
                if name == SEEDER_PATH:
                    self.assertEqual(SHA(immutable_pr808_bytes(name)),digest)
                    reviewed_current_successor(name)
                else:
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
        historical=json.loads(immutable_pr808_bytes(MANIFEST_PATH))
        old_names={item['name'] for item in historical['skills']}
        self.assertEqual(historical['seed_version'],30)
        self.assertEqual(len(old_names),21)
        self.assertEqual(old_names & names,old_names)
        self.assertEqual(names-old_names,NEW_OPERATIONAL_RUNBOOKS)
        self.assertEqual(manifest['seed_version'],31)
        self.assertEqual(len(names),29)
        reviewed_current_successor(MANIFEST_PATH)
        self.assertTrue(record['git_object_verified'])
        self.assertFalse(record['live_site_modified'])
        self.assertEqual({x['skill'] for x in record['source_git_objects']},
                         {x for x in old_names if x.startswith('nile-') or x.startswith('tour-optional-') or x in ('wordpress-cache-performance-governor','wordpress-mobile-css-delivery','wordpress-performance-intelligence-v2')})
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
