"""Original public role-pins, no signers or production mutations."""
import base64
import json
import sys
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/"ops/host-authority-v128"))
import original_v3_root_public_pin_migration as v
SITE="e5e64bca-ed62-48d2-b2b3-dff693cd702a"
ORIGIN="https://tours.dev.mad4b.com"
DIGEST="f"*64

def pub(k):return base64.b64encode(bytes([k])*32).decode()
class PinsTests(unittest.TestCase):
    def setUp(self):
        self.index={
          "contract":"mad4b.dynamic-operation-index.v1",
          "installed_by_first_party":True,
          "host_anchor_312_public_key_b64":pub(1),
          "host_resource_312_public_key_b64":pub(2),
          "independent_verifier_public_key_b64":pub(3),
        }
        self.policy={
          "contract":"mad4b.native-source-publisher-policy.v2",
          "enabled":True,"central_approval_scope":"tours-central:staging",
          "master_approval_public_key_b64":pub(4),
          "targets":{"tours-central:staging":{"site_uuid":SITE,"origin":ORIGIN}},
        }
        self.profile={
          "contract":"mad4b.site-profile.v2","version":2,
          "environment":"staging","site_uuid":SITE,
          "canonical_origin":ORIGIN,"deployment_binding_digest":DIGEST,
        }
        self.root_php=(
           '<?php\nif (!defined("MAD4B_HOST_V3_ROOT_TRUST_COLLISION")) return;\n'
           f'"site_uuid" => "{SITE}", "origin" => "{ORIGIN}";\n'
           f'define("MAD4B_HOST_V3_CENTRAL_DEPLOYMENT_BINDING_SHA256","{DIGEST}");\n'
           +v.OLD_BINDING%pub(1)+'\n'
           +v.ORIGINAL313%pub(3)+'\n'
        ).encode()

    def plan(self):
        return v.prepare_public_roles(
          self.root_php,
          json.dumps(self.index).encode(),
          json.dumps(self.policy).encode(),
          json.dumps(self.profile).encode(),
          expected_current_sha256=v.sha(self.root_php),
          expected_site_uuid=SITE,expected_origin=ORIGIN,
          expected_broker_sha256=v.sha(bytes([1])*32),
          expected_public313_sha256=v.sha(bytes([3])*32),
          expected_site_deployment_digest=DIGEST)

    def denied(self,reason):
        with self.assertRaisesRegex(v.PinMigrationDenied,reason):self.plan()

    def test_public_pin_migration_corrects_only_312_role(self):
        result=self.plan()
        self.assertNotEqual(result.before_sha256,result.after_sha256)
        self.assertIn((v.OLD_BINDING%pub(2)).encode(),result.php)
        self.assertNotIn((v.OLD_BINDING%pub(1)).encode(),result.php)
        self.assertIn((v.ORIGINAL313%pub(3)).encode(),result.php)
        self.assertTrue(result.public_role_registry["four_pinned_public_signers_distinct"])
        self.assertFalse(result.public_role_registry["standing_host_write_authorized"])
        self.assertFalse(result.public_role_registry["original312_v3_issuer_enabled"])
        self.assertFalse(result.public_role_registry["human_owner_v3_stepup_signed"])

    def test_live_preimage_drifts(self):
        self.root_php+=b"changed"
        with self.assertRaisesRegex(v.PinMigrationDenied,"V133_LIVE_CURRENT_PREIMAGE_CHANGED"):
            v.prepare_public_roles(self.root_php,json.dumps(self.index).encode(),
              json.dumps(self.policy).encode(),json.dumps(self.profile).encode(),
              expected_current_sha256=v.sha(self.root_php[:-7]),
              expected_site_uuid=SITE,expected_origin=ORIGIN,
              expected_broker_sha256=v.sha(bytes([1])*32),
              expected_public313_sha256=v.sha(bytes([3])*32),
              expected_site_deployment_digest=DIGEST)

    def test_original_registry_must_be_firstparty(self):
        self.index["installed_by_first_party"]=False
        self.denied("V133_ORIGINAL_REGISTRY_NOT_FIRST_PARTY")

    def test_production_identity_cannot_inherit_staging_pins(self):
        self.profile["environment"]="production"
        self.denied("V133_CENTRAL_SITE_PROFILE_DRIFT")

    def test_master_policy_must_target_same_uuid(self):
        self.policy["targets"]["tours-central:staging"]["site_uuid"]="a"*36
        self.denied("V133_ORIGINAL_PUBLISHER_SITE_CHANGED")

    def test_master_cannot_be_original312(self):
        self.policy["master_approval_public_key_b64"]=pub(2)
        self.denied("V133_ORIGINAL_INDEPENDENT_SIGNER_PINS_INVALID")

    def test_independent313_cannot_equal_original312(self):
        self.index["independent_verifier_public_key_b64"]=pub(2)
        self.denied("V133_ORIGINAL_INDEPENDENT_SIGNER_PINS_INVALID")

    def test_old_broker_312_binding_must_be_exactly_present(self):
        self.root_php=self.root_php.replace(
            (v.OLD_BINDING%pub(1)).encode(),(v.OLD_BINDING%pub(2)).encode())
        self.denied("V133_INSTALLED_LEGACY_BROKER_PUBLIC_BINDING_CHANGED")

    def test_313_wp_verifier_must_match_independent_index(self):
        self.root_php=self.root_php.replace(
            (v.ORIGINAL313%pub(3)).encode(),(v.ORIGINAL313%pub(4)).encode())
        self.denied("V133_INSTALLED_LEGACY_BROKER_PUBLIC_BINDING_CHANGED")

    def test_bad_public_base64_rejected(self):
        self.policy["master_approval_public_key_b64"]="INVALID"
        self.denied("V133_PUBLIC_KEY_ENCODING_INVALID")

    def test_unsigned_role_mapping_cannot_approve_write(self):
        result=self.plan().public_role_registry
        self.assertFalse(result["new_v3_owner_role_operationally_authorized"])
        self.assertFalse(result["human_owner_v3_stepup_signed"])
        self.assertFalse(result["original313_v3_predecessor_signed"])
        self.assertFalse(result["original318_v3_signed_enrollment_installed"])
        self.assertFalse(result["production_write_authorized"])

if __name__=="__main__":unittest.main()
