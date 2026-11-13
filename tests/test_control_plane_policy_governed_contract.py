"""Second overlay is a verified source delta, never a signed Owner release."""
import hashlib
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REL = 'wp-content/plugins/mad4b-site-control-plane/'
REVIEW = ROOT / 'docs/release-coverage/pr808-policy-governed-operator-review-20261113.json'
SHA = lambda value: hashlib.sha256(value).hexdigest()

class RegisteredGovernanceSourceReview(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.review=json.loads(REVIEW.read_text())

    def test_exact_source_digests_and_zero_premature_authority(self):
        record=self.review
        self.assertEqual(len(record['reviewed_source_sha256']),8)
        self.assertFalse(record['signed_owner_release_approved'])
        self.assertFalse(record['site_changed'])
        self.assertFalse(record['production_changed'])
        self.assertFalse(record['registry_admission_is_grant'])
        self.assertFalse(record['policy_allow_is_write_authority'])
        self.assertFalse(record['original312_313_receipts_issued'])
        for name,expected in record['reviewed_source_sha256'].items():
            with self.subTest(path=name):
                path=ROOT/name
                self.assertTrue(path.is_file() and not path.is_symlink())
                self.assertEqual(SHA(path.read_bytes()),expected)

    def test_no_hardcoded_operator_environment_blacklist(self):
        op=(ROOT/REL/'includes/class-mad4b-scp-operator-grant-convergence.php').read_text()
        gate=(ROOT/REL/'includes/class-mad4b-scp-registered-write-admission.php').read_text()
        self.assertNotIn("nonproduction_governed( 'write' )",op)
        self.assertNotIn('APPROVE FIVE STAGING OPERATOR GRANTS',op)
        self.assertIn("Registered_Write_Admission::preflight( self::ABILITY )",op)
        self.assertIn("'production_mutation_allowed' => 'policy_governed'",op)
        self.assertIn("'governed_operation_policy' => 'registered_write_policy_v1'",op)
        self.assertIn("MAD4B_SCP_Operation_Registry::registered_write_policy( $ability_name )",gate)
        self.assertIn("MAD4B_SCP_Policy_Resolution::environment_allowed( $environment )",gate)
        self.assertIn('MAD4B_SCP_Governed_Runtime_Gates::production_mutation_enabled()',gate)
        self.assertIn("'authorization_issued' => false",gate)
        self.assertIn("'mutation_performed' => false",gate)

    def test_first_party_registry_has_exact_declarative_scope(self):
        raw=json.loads((ROOT/REL/'config/operation-registry.json').read_text())
        matches=[x for x in raw['operations'] if x.get('planner')==self.review['ability_name']
                 and x.get('executor')==self.review['ability_name']]
        self.assertEqual(len(matches),1)
        row=matches[0]
        self.assertEqual(row['id'],self.review['registered_operation_id'])
        self.assertFalse(row['generic_mutation_dispatch'])
        self.assertTrue(row['owner_confirmation_required'])
        self.assertEqual(row['write_admission']['policy_effect'],'preflight_only')
        self.assertEqual(row['write_admission']['production_mode'],'runtime_governance_policy')

    def test_broker_revalidates_current_request_and_digests(self):
        dispatch=(ROOT/REL/'includes/class-mad4b-scp-enrollment-dispatch.php').read_text()
        census=(ROOT/REL/'includes/class-mad4b-scp-governed-ability-census.php').read_text()
        self.assertIn('self::can_execute( $input )',dispatch)
        parent=(ROOT/REL/'includes/class-mad4b-scp-abilities.php').read_text()
        self.assertIn('registered_policy_classification( $input )',parent)
        self.assertIn("'registered_governance' === ( $classification['policy_class'] ?? '' )",parent)
        self.assertIn('MAD4B_SCP_Remote_Operation_Parity::can_execute( $params )',parent)
        self.assertIn('self::can_execute( $input )',dispatch)
        self.assertIn("'registered_policy_sha256'",dispatch)
        self.assertIn('Registered_Write_Admission::preflight( $ability_name )',dispatch)
        self.assertIn('MAD4B_SCP_Operation_Registry::registered_write_policy( $name )',census)
        self.assertIn('callback_pair_digest( $ability )',census)
        self.assertIn('callback_identity_sha256',census)

if __name__=='__main__': unittest.main()
