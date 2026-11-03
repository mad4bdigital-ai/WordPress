import copy
import importlib.util
from pathlib import Path
import unittest

path = Path(__file__).with_name('mad4b_full_enforcement_shadow_gate.py')
spec = importlib.util.spec_from_file_location('shadow_gate', path)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
SHA = '3c68e4f4ac9a789f3e5b5f3325eb4fe093f3805c'


def fixture():
    return {
        'contract': mod.CONTRACT, 'source_master_sha': SHA,
        'environment': 'staging', 'site': 'https://tours.dev.mad4b.com',
        'database': 'etg_commerce_staging', 'feature_mode': 'certified_only',
        'full_enforce_execution_performed': False, 'legacy_locked': True,
        'original_legacy_count': 20, 'original_task_count': 322,
        'original_legacy_digest': 'f' * 64, 'active_total': 2,
        'classifications': [
            {'operation': 'review_canonical_identity', 'state': 'WAITING_EXECUTOR',
             'reason': 'mad4b_hub_flight_legacy_unlinked_origin_requires_review', 'count': 1},
            {'operation': 'workflow_inspect', 'state': 'QUEUED', 'reason': 'ELIGIBLE', 'count': 1},
        ],
    }


class ShadowGateTests(unittest.TestCase):
    def test_blocks_unproven_original_work(self):
        r = mod.evaluate(fixture(), SHA)
        self.assertEqual(r['active_requires_original_proof'], 1)
        self.assertEqual(r['active_plan_eligible'], 1)
        self.assertFalse(r['enable_full_enforce_authorized'])
        self.assertTrue(r['fail_closed'])

    def test_empty_evidence_does_not_grant_full_enforce(self):
        j = fixture()
        j['active_total'] = 0
        j['classifications'] = []
        self.assertFalse(mod.evaluate(j, SHA)['enable_full_enforce_authorized'])

    def test_invalid_source_rejected(self):
        with self.assertRaisesRegex(ValueError, 'exact_source_mismatch'):
            mod.evaluate(fixture(), '0' * 40)

    def test_no_production_scope(self):
        j = fixture()
        j['environment'] = 'production'
        with self.assertRaisesRegex(ValueError, 'environment_not_staging'):
            mod.evaluate(j, SHA)

    def test_mode_change_rejected(self):
        j = fixture()
        j['feature_mode'] = 'enforce'
        with self.assertRaisesRegex(ValueError, 'unexpected_effective_mode'):
            mod.evaluate(j, SHA)

    def test_mutation_claim_rejected(self):
        j = fixture()
        j['full_enforce_execution_performed'] = True
        with self.assertRaisesRegex(ValueError, 'unexpected_mutation'):
            mod.evaluate(j, SHA)

    def test_legacy_lock_mandatory(self):
        j = fixture()
        j['legacy_locked'] = False
        with self.assertRaisesRegex(ValueError, 'legacy_not_locked'):
            mod.evaluate(j, SHA)

    def test_no_partial_count(self):
        j = fixture()
        j['active_total'] = 3
        with self.assertRaisesRegex(ValueError, 'active_count_mismatch'):
            mod.evaluate(j, SHA)

    def test_terminal_record_not_accepted_as_active(self):
        j = fixture()
        j['classifications'][0]['state'] = 'COMPLETED'
        with self.assertRaisesRegex(ValueError, 'not_active_state'):
            mod.evaluate(j, SHA)

    def test_forged_reason_does_not_grant_authority(self):
        j = fixture()
        j['classifications'][0]['reason'] = 'anything_else'
        r = mod.evaluate(j, SHA)
        self.assertEqual(r['required_remediation']['unclassified_original_provider_review_required'], 1)
        self.assertFalse(r['enable_full_enforce_authorized'])


if __name__ == '__main__':
    unittest.main()
