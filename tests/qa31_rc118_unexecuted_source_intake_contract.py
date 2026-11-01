#!/usr/bin/env python3
"""CI-only, exact-hash verification of staged unexecuted QA31 source candidates."""
import hashlib
import json
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
INBOX=ROOT/'ops/qa31/rc118-source-intake'
BASE=ROOT/'wp-content/plugins/mad4b-site-control-plane'
NAMES=(
    'class-mad4b-scp-company-tenant-migration.php',
    'class-mad4b-scp-migration-decision-catalog.php',
    'class-mad4b-scp-company-migration-decisions.php',
)
HASHES=(
    '06447093ff420e76af963d07285922972ff29be17ca1cb5d2072738d9c41bbf9',
    '2321244f43113cc998887c03ad923ab8dfc50510c33a73d435a4127afc787b8d',
    '408febe1cea99ce389f84e9035f91dce1930843ec2f535e9cbc9d987198e2488',
)

class SourceIntakeContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.data=json.loads((INBOX/'intake.json').read_text(encoding='utf-8'))

    def test_contract_and_pending_governance(self):
        a=self.data
        self.assertEqual(a['contract'],'mad4b.qa31.rc118-staging-source-intake.v1')
        self.assertEqual(a['baseline_github_commit_sha'],'a6beb2d0b4d235169183ba4985e61c21f3caf131')
        self.assertEqual(a['staging_control_plane_version'],'0.4.0-rc.118')
        self.assertEqual(a['baseline_github_control_plane_version'],'0.4.0-rc.104')
        self.assertTrue(a['non_executable_source_intake'])
        self.assertTrue(a['source_candidate_only'])
        self.assertFalse(a['previous_manifest_lists_new_modules'])
        for name in ('authorizing','staging_install_authorized','production_install_authorized',
                     'provider_certified','staging_mutation_performed'):
            self.assertIs(a[name],False)
        self.assertGreaterEqual(len(a['remaining_gates']),6)

    def test_exact_three_candidate_paths_and_sha256(self):
        rows=self.data['files']
        self.assertEqual(len(rows),3)
        self.assertEqual([x['candidate_path'] for x in rows],['source/'+n for n in NAMES])
        for row, expected in zip(rows,HASHES):
            p=INBOX/row['candidate_path']
            self.assertFalse(p.is_symlink())
            self.assertTrue(p.is_file())
            self.assertEqual(hashlib.sha256(p.read_bytes()).hexdigest(),expected)
            self.assertEqual(row['sha256'],expected)
            self.assertEqual(row['bytes'],p.stat().st_size)
            self.assertEqual(row['review_status'],'SOURCE_CANDIDATE_UNREVIEWED')
            self.assertTrue(row['php_lint_required'])

    def test_source_never_implicitly_installed_into_live_plugin(self):
        for name in NAMES:
            self.assertFalse((BASE/'includes'/name).exists(),
                             'Unreviewed runtime source must stay out of the active plugin tree')
        loader=(BASE/'mad4b-site-control-plane.php').read_text(encoding='utf-8')
        for name in NAMES:
            self.assertNotIn("require_once MAD4B_SCP_DIR . 'includes/"+name+"'",loader)

    def test_three_lines_and_old_sha_are_observation_only(self):
        self.assertEqual(self.data['previous_manifest_entries'],877)
        self.assertEqual(self.data['previous_manifest_matching_entries'],876)
        self.assertEqual(self.data['loader_post_manifest_lines'],3)
        self.assertEqual(self.data['loader_bytes_added'],266)
        self.assertEqual(self.data['loader_original_manifest_sha256'],
            '32d3aa84b15b0fa549c6dd8ffc131c2c292640e5b63991dab1a94f3f0e97cc00')
        self.assertEqual(self.data['loader_current_runtime_sha256'],
            'b67a6be43d4057d2061b980cc3abb0e2b2605c954a4fccabf38d6daa682ef4e3')

    def test_registration_safety_requires_review(self):
        code=[(INBOX/'source'/name).read_text() for name in NAMES]
        self.assertIn('::register();',code[0])
        self.assertIn('::boot();',code[2])
        self.assertIn('APPROVE COMPANY ROLE MIGRATION ON STAGING',code[0])
        self.assertIn('APPROVE COMPANY MIGRATION DECISION',code[2])
        self.assertIn('requires_company_specific_review',code[1])

if __name__=='__main__':
    unittest.main()
