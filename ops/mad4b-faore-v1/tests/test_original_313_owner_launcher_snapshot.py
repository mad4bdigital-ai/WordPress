"""The original Central owner-only REST launcher is preserved as evidence.

Tests are static, do not call owner REST, and never create Broker Jobs.
"""
import hashlib
import json
import unittest
from pathlib import Path

EVIDENCE=Path(__file__).resolve().parents[1]/'original-provider-evidence'
SOURCE=EVIDENCE/'332-mad4b-original-313-owner-launcher.php'
MANIFEST=EVIDENCE/'manifest.json'


class OwnerLauncherSnapshot(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.meta=json.loads(MANIFEST.read_text())
        cls.bytes=SOURCE.read_bytes()
        cls.text=cls.bytes.decode('utf-8')

    def test_source_sha_matches_manifest(self):
        self.assertEqual(self.meta['sha256'],hashlib.sha256(self.bytes).hexdigest())
        self.assertEqual(self.meta['snapshot_file'],SOURCE.name)
        self.assertEqual(self.meta['deployment_policy'],'inventory_snapshot_only_no_auto_install')

    def test_actual_original_rest_and_admin_hooks(self):
        for token in (
            'final class MAD4B_Original_313_Owner_Launcher_V1',
            "const REST='/native-313/readonly-canary'",
            "'methods'=>'POST'",
            "'methods'=>'GET'",
            "add_management_page('MAD4B Native 313'",
            'public static function launch(', 'public static function job_status(',
        ):
            with self.subTest(token=token):
                self.assertIn(token,self.text)

    def test_owner_rest_session_is_part_of_original_contract(self):
        for token in ('current_user_can(\'manage_options\')',
                      'MAD4B_Central_SSH_Command_Intake_V1::permitted($r)',
                      "get_header('X-WP-Nonce')",
                      "('POST','/mad4b/v1/central-execution-lanes/'",
                      "MAD4B_Central_VPS_Broker_V1::dispatch($dispatch)"):
            with self.subTest(token=token):
                self.assertIn(token,self.text)
        self.assertNotIn('wp_set_current_user(',self.text)
        self.assertTrue(self.meta['authenticated_owner_rest_required'])
        self.assertFalse(self.meta['wpcli_user_override_allowed'])

    def test_313_receipt_is_not_inferred_from_306(self):
        for token in ("const COMMAND=['option','get','home']",
                      "'state'=>'ORIGINAL_306_QUEUED'",
                      "'original_313_certified'=>false",
                      "'original_313_certified'=>$verified",
                      "'independent_readback_verified'",
                      "'native_executor_certified'"):
            with self.subTest(token=token):
                self.assertIn(token,self.text)
        self.assertFalse(self.meta['original_313_certified'])


if __name__=='__main__':
    unittest.main()
