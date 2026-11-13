import importlib.util
from pathlib import Path
import sys
import unittest

BASE=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(BASE/'integrations'))
import check_original_305_no_user_regression as guard

class Native305NoUserGuardTests(unittest.TestCase):
    def test_runtime_and_signed_native_contract_are_present(self):
        if not guard.LIVE.is_file():
            self.skipTest("Original live Tours.dev module is not mounted")
        result=guard.evaluate(guard.LIVE.read_text())
        self.assertEqual(result['state'],'PASS')
        self.assertTrue(result['no_wp_user_override_required'])
        self.assertTrue(result['original_rest_owner_protection_preserved'])

    def test_regression_if_blanket_cli_denial_is_reintroduced(self):
        if not guard.LIVE.is_file():
            self.skipTest("Original live Tours.dev module is not mounted")
        result=guard.evaluate(guard.LIVE.read_text()+"\nif (defined('WP_CLI') && WP_CLI) return false;\n")
        self.assertEqual(result['state'],'DENIED_REGRESSION')
        self.assertIn('blanket_wpcli_rejection',result['prohibited_patterns'])

    def test_regression_if_signature_or_native_enrollment_removed(self):
        if not guard.LIVE.is_file():
            self.skipTest("Original live Tours.dev module is not mounted")
        source=guard.LIVE.read_text()
        source=source.replace('sodium_crypto_sign_verify_detached(', 'not_verified(',1)
        source=source.replace('MAD4B_Company_Native_WPCLI_Enrollment_V1::readback(', 'unverified_slot(',1)
        result=guard.evaluate(source)
        self.assertEqual(result['state'],'DENIED_REGRESSION')
        self.assertIn('original_broker_signature',result['missing_checks'])
        self.assertIn('original_enrolled_company_grant',result['missing_checks'])

if __name__=='__main__':
    unittest.main()
