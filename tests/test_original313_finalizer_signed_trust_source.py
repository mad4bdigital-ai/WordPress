"""Static fail-closed checks for Central receipt-only Finalizer review candidate.

No live signing, WordPress writes, or private key reads.
"""
import re
import unittest
from pathlib import Path

SOURCE=Path(__file__).resolve().parents[1]/'wp-content/plugins/mad4b-platform/modules/authority/dynamic313/class-mad4b-original313-receipt-only-finalizer.php'

class CentralFinalizerTrustTests(unittest.TestCase):
    def setUp(self):
        self.s=SOURCE.read_text()
        self.trust=self.s.split('private static function trust():array {',1)[1].split('public static function status()',1)[0]

    def test_requires_enrollment_without_boolean_only_authority(self):
        self.assertIn("get_option(self::TRUST_OPTION,false)",self.trust)
        self.assertIn('sodium_crypto_sign_verify_detached($signature,$message,$key)',self.trust)
        self.assertLess(self.trust.index('sodium_crypto_sign_verify_detached($signature,$message,$key)'),self.trust.index('return $proof;'))
        self.assertIn("'issuer'",self.trust)

    def test_matches_existing_original_broker_and_independent_public_keys(self):
        self.assertIn('MAD4B_Central_VPS_Broker_V1::PUBKEY',self.trust)
        self.assertIn('MAD4B_Native_WPCLI_Receipt_Verifier_V1::PUBLIC_KEY_B64',self.trust)
        self.assertIn('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES',self.trust)
        self.assertIn('SODIUM_CRYPTO_SIGN_BYTES',self.trust)
        self.assertIn("mad4b.original313.receipt-only-first-party-trust.v1",self.trust)
        self.assertNotIn('PRIVATE_KEY',self.trust)

    def test_binds_exact_file_registry_and_adapter(self):
        for key in ['finalizer_file_sha256','provider_manifest_sha256','worker_adapter_sha256']:
            self.assertIn(key,self.trust)
        self.assertIn("hash_file('sha256',__FILE__)",self.trust)

    def test_central_scope_not_staging_only(self):
        for key in ['MAD4B_SCP_Site_Profile::status()',
                    'deployment_binding_match','origin_match','environment_match',
                    'central_site_uuid','central_environment','central_origin',
                    'untrailingslashit(home_url(\'/\'))']:
            self.assertIn(key,self.trust)
        self.assertNotIn("wp_get_environment_type()==='staging'",self.trust)

    def test_unsigned_source_install_does_not_activate(self):
        self.assertIn('$ready=self::trust()!==[];',self.s)
        self.assertIn("'operational_ready'=>$ready",self.s)
        self.assertIn("if(self::trust()===[])return self::deny('RECOVERY_FIRST_PARTY_ENROLLMENT_REQUIRED');",self.s)

    def test_recovery_not_confused_with_original_timely_apply(self):
        self.assertIn("'original_313_timely_apply_certified'=>false",self.s)
        self.assertIn("'historical_reconciliation_only'=>true",self.s)
        self.assertIn("'schema_ddl_performed'=>false",self.s)
        self.assertIn("'code_reapplied'=>false",self.s)

if __name__=='__main__':unittest.main()
