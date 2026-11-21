"""Persistent default Write: revocable standing entitlement plus exact jobs."""
import base64
import copy
import sys
import unittest
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/"ops/host-authority-v128"))
import standing_write as s
import persistent_enrollment as p
import test_persistent_enrollment as fixture
from fleet_policy import SiteBinding
NOW=fixture.NOW

def pub(k):
    return k.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)

class PersistentStandingWriteTests(unittest.TestCase):
    def setUp(self):
        self.f=fixture.PersistentHostContractTests("test_default_mode_is_persistent")
        self.f.setUp()
        self.f.grant["action"]="site.code.atomic.install"
        self.f.grant=self.f.sign(
            {k:self.f.grant[k] for k in p.GRANT_FIELDS},
            p.GRANT_FIELDS,self.f.grant_key)
        self.f.activity["grant_sha256"]=p.digest(
            self.f.grant,p.GRANT_FIELDS,p.GRANT_CONTRACT)
        self.f.activity=self.f.sign(
            {k:self.f.activity[k] for k in p.ACTIVITY_FIELDS},
            p.ACTIVITY_FIELDS,self.f.activity_key)
        self.f.update_checkpoint()
        self.owner=Ed25519PrivateKey.generate()
        self.plan_signer=Ed25519PrivateKey.generate()
        self.binding=SiteBinding(
            company=self.f.enrollment["company"],
            environment=self.f.enrollment["environment"],
            site_uuid=self.f.enrollment["site_uuid"],
            host_id=self.f.enrollment["target_host_id"],
            ssh_principal=self.f.enrollment["ssh_principal"],
            host_key_sha256=self.f.enrollment["host_key_sha256"],
            policy_sha256=self.f.enrollment["policy_sha256"])
        self.capability=s.new_capability_proposal(self.binding,2,"4"*32,NOW-300)
        self.sign_capability()
        self.highwater={
            "contract":s.HIGHWATER_CONTRACT,
            "company":self.binding.company,
            "environment":self.binding.environment,
            "site_uuid":self.binding.site_uuid,
            "target_host_id":self.binding.host_id,
            "revision":2,
            "capability_sha256":s.digest(self.capability,s.CAPABILITY_FIELDS,
                                          s.CAPABILITY_DOMAIN),
            "state":"ACTIVE","observed_epoch":NOW,"nonce":"5"*32,
        }
        self.sign_highwater()
        self.plan={
            "contract":"mad4b.host-v129.atomic-group-execution-proposal.v1",
            "site_uuid":self.binding.site_uuid,
            "company":self.binding.company,
            "environment":self.binding.environment,
            "group_id":"original318",
            "paths":["wp-content/mu-plugins/410-mad4b-original318-v3-readback.php"],
            "current_sha256":"a"*64,
            "initial_current_sha256":"a"*64,
            "desired_sha256":"b"*64,
            "source_commit_sha":"c"*40,
            "candidate_exact":True,
            "previous":{
                "source":"independently_signed_actual_host_runner_backup",
                "snapshot_sha256":"a"*64,
                "group_id":"original318",
                "backup_receipt_id":"f"*32,
                "backup_bundle_sha256":"e"*64,
                "verified":True,
            },
            "executor_must_recheck_live_preimage_at_commit":True,
            "exact_owner_or_operator_signed_grant_required":True,
            "host_write_authorized":False,
            "operation_executed":False,
            "desired_promoted":False,
        }
        self.signed_plan={
            "contract":s.PLAN_CONTRACT,
            "company":self.binding.company,
            "environment":self.binding.environment,
            "site_uuid":self.binding.site_uuid,
            "target_host_id":self.binding.host_id,
            "operation_id":self.f.grant["operation_id"],
            "action":"site.code.atomic.install",
            "plan":self.plan,"observed_epoch":NOW,
            "nonce":"6"*32,
        }
        self.sign_plan()

    def sign(self,body,fields,domain,key):
        row={k:copy.deepcopy(body[k]) for k in fields}
        row["signature"]=base64.b64encode(
            key.sign(s.canonical(row,fields,domain))).decode()
        return row

    def sign_capability(self):
        self.capability=self.sign(self.capability,s.CAPABILITY_FIELDS,
                                  s.CAPABILITY_DOMAIN,self.owner)

    def sign_highwater(self):
        self.highwater=self.sign(self.highwater,s.HIGHWATER_FIELDS,
                                  s.HIGHWATER_DOMAIN,self.f.highwater_key)

    def sign_plan(self):
        self.signed_plan=self.sign(self.signed_plan,s.PLAN_FIELDS,
                                   s.PLAN_DOMAIN,self.plan_signer)

    def standing(self,*,now=NOW,production_receipt=None):
        return s.verify_standing_write(
            self.capability,self.highwater,self.binding,
            pub(self.owner),self.f.cp,now=now,
            production_receipt=production_receipt)

    def exact(self,*,now=NOW):
        return s.admit_exact_standing_write(
            self.capability,self.highwater,self.binding,
            pub(self.owner),self.f.cp,
            self.f.enrollment,self.f.checkpoint,self.f.wp,
            self.f.grant,self.f.activity,
            self.f.hp,self.f.gp,self.f.ap,
            self.signed_plan,pub(self.plan_signer),
            "site.code.atomic.install",now=now)

    def denied(self,callback,reason):
        with self.assertRaisesRegex(p.ConnectionDenied,reason):
            callback()

    def test_new_default_is_permanent_write_entitlement(self):
        default=s.default_policy()
        self.assertEqual(default["default_write_mode"],"persistent")
        self.assertTrue(default["default_write_enabled_after_signed_enrollment"])
        self.assertIsNone(default["write_entitlement_expires_epoch"])
        self.assertFalse(default["auto_activate_unsigned_grants"])

    def test_default_proposal_unsigned(self):
        prop=s.new_capability_proposal(self.binding,2,"a"*32,NOW)
        self.assertEqual(prop["write_mode"],"persistent")
        self.assertTrue(prop["enabled_by_default"])
        self.assertIsNone(prop["expires_epoch"])
        self.assertNotIn("signature",prop)

    def test_standing_entitlement_permanent_but_not_a_write_execution(self):
        status=self.standing()
        self.assertTrue(status["standing_capability_verified"])
        self.assertFalse(status["specific_write_authorized"])
        self.assertFalse(status["broad_host_write_authorized"])
        self.assertFalse(status["mutation_performed"])
        self.assertIsNone(status["entitlement_expires_epoch"])

    def test_permanent_entitlement_twenty_years_later_if_highwater_fresh(self):
        future=NOW+20*365*86400
        self.highwater["observed_epoch"]=future
        self.sign_highwater()
        self.assertTrue(self.standing(now=future)["standing_capability_verified"])

    def test_exact_write_admission_requires_all_signatures(self):
        accepted=self.exact()
        self.assertTrue(accepted["specific_write_authorized"])
        self.assertFalse(accepted["broad_host_write_authorized"])
        self.assertFalse(accepted["mutation_performed"])
        self.assertTrue(accepted["commit_time_cas_required"])
        self.assertTrue(accepted["postwrite_readback_required"])

    def test_revoked_standing_capability_denied(self):
        self.highwater["state"]="REVOKED"
        self.sign_highwater()
        self.denied(self.exact,"STANDING_WRITE_REVOKED_OR_STALE")

    def test_stale_independent_highwater_denied(self):
        self.highwater["observed_epoch"]=NOW-31
        self.sign_highwater()
        self.denied(self.exact,"STANDING_WRITE_REVOKED_OR_STALE")

    def test_wrong_site_policy_denied(self):
        self.capability["site_uuid"]="2"*36
        self.sign_capability()
        self.denied(self.exact,"STANDING_WRITE_SITE_BINDING_DRIFT")

    def test_forged_standing_approval_denied(self):
        self.capability["signature"]=base64.b64encode(b"x"*64).decode()
        self.denied(self.exact,"STANDING_WRITE_SIGNATURE_INVALID")

    def test_no_write_by_default_without_signed_capability(self):
        self.capability.pop("signature")
        self.denied(self.exact,"STANDING_WRITE_SIGNED_SHAPE_INVALID")

    def test_mismatched_owner_and_highwater_signer_denied(self):
        self.denied(lambda:s.verify_standing_write(
            self.capability,self.highwater,self.binding,
            self.f.cp,self.f.cp,now=NOW),"INDEPENDENT_SIGNERS_REQUIRED")

    def test_exact_operation_tamper_denied(self):
        self.f.grant["action"]="site.code.atomic.rollback"
        self.denied(self.exact,"SIGNED_SIGNATURE_INVALID")

    def test_tampered_activity_denied(self):
        self.f.activity["state"]="FAILED"
        self.denied(self.exact,"SIGNED_SIGNATURE_INVALID")

    def test_host_v2_incompatible_with_standing_v3(self):
        self.f.enrollment["contract"]="mad4b.company-host-authority-anchor.v2"
        self.denied(self.exact,"SIGNED_CONTRACT_INVALID")

    def test_unsigned_backup_booleans_are_not_sufficient(self):
        self.signed_plan["plan"]["previous"]["verified"]=False
        self.sign_plan()
        self.denied(self.exact,"STANDING_WRITE_VERIFIED_ATOMIC_PLAN_REQUIRED")

    def test_untrusted_plan_mutation_denied(self):
        self.signed_plan["plan"]["current_sha256"]="f"*64
        self.denied(self.exact,"STANDING_WRITE_SIGNATURE_INVALID")

    def test_wrong_operation_plan_signer_denied(self):
        self.denied(lambda:s.admit_exact_standing_write(
            self.capability,self.highwater,self.binding,
            pub(self.owner),self.f.cp,
            self.f.enrollment,self.f.checkpoint,self.f.wp,
            self.f.grant,self.f.activity,self.f.hp,self.f.gp,self.f.ap,
            self.signed_plan,pub(Ed25519PrivateKey.generate()),
            "site.code.atomic.install",now=NOW),
            "STANDING_WRITE_SIGNATURE_INVALID")

    def test_unknown_action_denied(self):
        self.denied(lambda:s.admit_exact_standing_write(
            self.capability,self.highwater,self.binding,
            pub(self.owner),self.f.cp,
            self.f.enrollment,self.f.checkpoint,self.f.wp,
            self.f.grant,self.f.activity,self.f.hp,self.f.gp,self.f.ap,
            self.signed_plan,pub(self.plan_signer),
            "server.shell.root",now=NOW),
            "STANDING_WRITE_ACTION_NOT_ALLOWED")

    def test_signed_plan_wrong_company_denied(self):
        self.signed_plan["company"]="wayfatours"
        self.sign_plan()
        self.denied(self.exact,"STANDING_WRITE_SIGNED_PLAN_SCOPE_OR_FRESHNESS_INVALID")

    def test_signed_plan_stale_denied(self):
        self.signed_plan["observed_epoch"]=NOW-31
        self.sign_plan()
        self.denied(self.exact,"STANDING_WRITE_SIGNED_PLAN_SCOPE_OR_FRESHNESS_INVALID")

    def test_operation_completed_blocks_standing_write(self):
        self.f.activity["state"]="COMPLETED"
        self.f.activity=self.f.sign(
            {k:self.f.activity[k] for k in p.ACTIVITY_FIELDS},
            p.ACTIVITY_FIELDS,self.f.activity_key)
        self.f.update_checkpoint()
        self.denied(self.exact,"OPERATION_TERMINAL_OR_NOT_RUNNING")

    def test_operation_idle_blocks_standing_write(self):
        self.f.activity["last_heartbeat_epoch"]=NOW-125
        self.f.activity=self.f.sign(
            {k:self.f.activity[k] for k in p.ACTIVITY_FIELDS},
            p.ACTIVITY_FIELDS,self.f.activity_key)
        self.f.update_checkpoint()
        self.denied(self.exact,"OPERATION_HEARTBEAT_LOST")

    def test_production_requires_separate_approval(self):
        self.binding=SiteBinding(**{
            **self.binding.__dict__,"environment":"production"})
        self.capability["environment"]="production"
        self.sign_capability()
        self.highwater["environment"]="production"
        self.highwater["capability_sha256"]=s.digest(
            self.capability,s.CAPABILITY_FIELDS,s.CAPABILITY_DOMAIN)
        self.sign_highwater()
        self.denied(self.standing,"PRODUCTION_WRITE_REQUIRES_SEPARATE_APPROVAL")
        production={
            "contract":s.PRODUCTION_CONTRACT,
            "company":self.binding.company,
            "environment":"production",
            "site_uuid":self.binding.site_uuid,
            "target_host_id":self.binding.host_id,
            "revision":2,
            "capability_sha256":s.digest(
                self.capability,s.CAPABILITY_FIELDS,s.CAPABILITY_DOMAIN),
            "approved_epoch":NOW-1,"nonce":"7"*32,"state":"APPROVED",
        }
        signed=self.sign(
            production,s.PRODUCTION_FIELDS,s.PRODUCTION_DOMAIN,self.owner)
        self.assertTrue(self.standing(
            production_receipt=signed)["standing_capability_verified"])
        signed["nonce"]="8"*32
        self.denied(lambda:self.standing(production_receipt=signed),
                    "STANDING_WRITE_SIGNATURE_INVALID")

    def test_capability_cannot_expire_timing_by_hidden_clock(self):
        self.capability["expires_epoch"]=NOW+3600
        self.sign_capability()
        self.denied(self.standing,"STANDING_WRITE_ENTITLEMENT_INVALID")

if __name__=="__main__":unittest.main()
