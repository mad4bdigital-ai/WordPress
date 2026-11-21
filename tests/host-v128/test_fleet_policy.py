"""Cross-company isolation and global optional release qualification tests."""
import json
from pathlib import Path
import sys
import unittest
SRC = Path(__file__).resolve().parents[2] / "ops" / "host-authority-v128"
sys.path.insert(0,str(SRC))
import fleet_policy as f
import persistent_enrollment as p
import test_persistent_enrollment as fixture_module
ROOT = Path(__file__).resolve().parents[2]

def item(company,env,uuid,host,key):
    return {
        "company":company,"environment":env,"site_uuid":uuid,
        "host_id":host,"ssh_principal":"u123456789",
        "host_key_sha256":key*64,"policy_sha256":"b"*64,
        "enabled":True
    }

class FleetPolicyTests(unittest.TestCase):
    def setUp(self):
        self.registry = {"contract":f.REGISTRY_CONTRACT,"sites":[
            item("all-royal-egypt","staging","11111111-1111-4111-8111-111111111111","hosting-2","a"),
            item("wayfatours","production","22222222-2222-4222-8222-222222222222","hosting-3","c"),
            item("egypt-tour-gates","staging","33333333-3333-4333-8333-333333333333","hosting-3","d"),
        ]}
        self.policy=json.loads((SRC/"release_qualification_policy.json").read_text())
        self.global_release_policy=json.loads(
            (ROOT/".github/mad4b-release-qualification-mode.json").read_text())
        self.binding=f.SiteBinding.from_trusted_registry(
            self.registry,"all-royal-egypt","staging")
        self.effective=f.resolve_qualification(self.policy,self.binding,self.global_release_policy)

    def test_three_companies_share_policy_without_colliding(self):
        for row in self.registry["sites"]:
            binding=f.SiteBinding.from_trusted_registry(
                self.registry,row["company"],row["environment"])
            effective=f.resolve_qualification(self.policy,binding,self.global_release_policy)
            self.assertEqual(effective["connection_mode"],"persistent")
            self.assertEqual(effective["release_qualification"],"optional")
            self.assertFalse(effective["host_write_authorized"])

    def test_mode_override_only_one_site(self):
        self.policy["site_overrides"]={
            "wayfatours:production":{
                "connection_mode":"operation_scoped",
                "release_qualification":"required"}}
        for row in self.registry["sites"]:
            binding=f.SiteBinding.from_trusted_registry(
                self.registry,row["company"],row["environment"])
            effective=f.resolve_qualification(self.policy,binding,self.global_release_policy)
            if row["company"]=="wayfatours":
                self.assertEqual(effective["connection_mode"],"operation_scoped")
                self.assertEqual(effective["release_qualification"],"required")
            else:
                self.assertEqual(effective["connection_mode"],"persistent")
                self.assertEqual(effective["release_qualification"],"optional")

    def test_unsigned_new_plan_never_issues_write(self):
        planned=f.plan_new_site_enrollment(self.binding,self.effective,
            revision=2,nonce="1"*32,issued_epoch=1800000000)
        self.assertEqual(planned["unsigned_enrollment"]["mode"],"persistent")
        self.assertNotIn("signature",planned["unsigned_enrollment"])
        self.assertFalse(planned["original318_write_authorized"])
        self.assertEqual(planned["standing_write_mode_default"],"persistent")
        self.assertTrue(planned["unsigned_standing_write_capability"]["enabled_by_default"])
        self.assertIsNone(planned["unsigned_standing_write_capability"]["expires_epoch"])
        self.assertNotIn("signature",planned["unsigned_standing_write_capability"])
        self.assertTrue(planned["standing_write_owner305_signature_required"])
        self.assertTrue(planned["standing_write_independent313_highwater_required"])
        self.assertTrue(planned["standing_write_exact_operation_grant_required"])
        self.assertTrue(planned["current_signed_revision_preflight_required"])
        self.assertFalse(planned["legacy_v2_auto_migration_allowed"])
        self.assertTrue(planned["issuer_312_signature_required"])
        self.assertTrue(planned["independent_313_signature_required"])

    def test_forged_trusted_site_binding_rejected(self):
        fixture=fixture_module.PersistentHostContractTests("test_default_mode_is_persistent");fixture.setUp()
        wrong_binding=f.SiteBinding.from_trusted_registry(
            self.registry,"wayfatours","production")
        with self.assertRaisesRegex(p.ConnectionDenied,"ENROLLMENT_TRUSTED_SITE_BINDING_MISMATCH"):
            f.read_site_connection(wrong_binding,fixture.enrollment,fixture.checkpoint,
                                   fixture.wp,fixture.hp,fixture.cp,now=1800000000)

    def test_matching_site_binding_verifies(self):
        fixture=fixture_module.PersistentHostContractTests("test_default_mode_is_persistent");fixture.setUp()
        result=f.read_site_connection(self.binding,fixture.enrollment,
                 fixture.checkpoint,fixture.wp,fixture.hp,fixture.cp,
                 now=1800000000)
        self.assertTrue(result["connection_verified"])
        self.assertFalse(result["host_write_authorized"])

    def test_registry_duplicate_site_uuid_denied(self):
        self.registry["sites"][1]["site_uuid"]=self.registry["sites"][0]["site_uuid"]
        with self.assertRaisesRegex(p.ConnectionDenied,"DUPLICATE_OR_INVALID_SITE_UUID"):
            f.SiteBinding.from_trusted_registry(self.registry,
                                               "all-royal-egypt","staging")

    def test_registry_duplicate_scope_denied(self):
        self.registry["sites"][1]["company"]="all-royal-egypt"
        self.registry["sites"][1]["environment"]="staging"
        with self.assertRaisesRegex(p.ConnectionDenied,"DUPLICATE_HOST_REGISTRY_SCOPE"):
            f.SiteBinding.from_trusted_registry(self.registry,
                                               "all-royal-egypt","staging")

    def test_disabled_site_denied(self):
        self.registry["sites"][0]["enabled"]=False
        with self.assertRaisesRegex(p.ConnectionDenied,"HOST_SITE_NOT_ENROLLED_OR_DISABLED"):
            f.SiteBinding.from_trusted_registry(self.registry,
                                               "all-royal-egypt","staging")

    def test_unknown_site_denied(self):
        with self.assertRaisesRegex(p.ConnectionDenied,"HOST_SITE_NOT_ENROLLED_OR_DISABLED"):
            f.SiteBinding.from_trusted_registry(self.registry,
                                               "unknown-site","staging")

    def test_registry_missing_contract_denied(self):
        self.registry["contract"]="untrusted"
        with self.assertRaisesRegex(p.ConnectionDenied,"TRUSTED_HOST_REGISTRY_REQUIRED"):
            f.SiteBinding.from_trusted_registry(self.registry,
                                               "all-royal-egypt","staging")

    def test_release_policy_cannot_erase_boundaries(self):
        self.policy["mandatory_boundaries"].remove("owner_or_operator_authority")
        with self.assertRaisesRegex(p.ConnectionDenied,"MANDATORY_SECURITY_BOUNDARIES_CHANGED"):
            f.resolve_qualification(self.policy,self.binding,self.global_release_policy)

    def test_invalid_policy_override_denied(self):
        self.policy["site_overrides"]={"wayfatours:production":{
            "release_qualification":"unrestricted"}}
        with self.assertRaisesRegex(p.ConnectionDenied,"RELEASE_POLICY_OVERRIDE_INVALID"):
            f.resolve_qualification(self.policy,self.binding,self.global_release_policy)

    def test_effective_policy_cross_site_reuse_denied(self):
        other=f.SiteBinding.from_trusted_registry(
            self.registry,"wayfatours","production")
        with self.assertRaisesRegex(p.ConnectionDenied,"EFFECTIVE_POLICY_SITE_DRIFT"):
            f.plan_new_site_enrollment(other,self.effective,
                revision=2,nonce="1"*32,issued_epoch=1800000000)

if __name__=="__main__": unittest.main()
