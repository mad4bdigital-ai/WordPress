"""The monitor cannot infer signed identity from WordPress or historical silence."""
import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[2]
SRC=ROOT/"ops/host-authority-v142/single_owner_readiness.py"
spec=importlib.util.spec_from_file_location("single_owner_root_monitor",SRC)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

def evidence():
    a={"contract":"mad4b.original305.host-v3-root-preflight.v1",
       "company":"mad4b-central","environment":"staging","site_uuid":m.SITE,
       "approved_write":False,"original305_v8_manifest_pin_verified":True,
       "original305_legacy_ambiguous_write_hold_preserved":True,
       "original305_legacy_hold_reason":"ISSUER_SIGNED_RESULT_UNCONFIRMED",
       "original313_predecessor_v3_signed":False,
       "original305_v3_independent_owner_key_registered":False,
       "original305_v3_approved_owner_stepup_registered":False,
       "original305_v3_exact_approval_signed":False,
       "original312_v3_distinct_signer_enrollment_verified":False}
    b={"contract":"mad4b.original313.host-v134-private-archive-census.v1",
       "target_site_uuid":m.SITE,"v2_signed_current_pairs_verified":1,
       "root_archive_sha256":"a"*64,"complete_cross_generation_history_verified":False,
       "complete_original313_predecessor_for_target":"NOT_ESTABLISHED"}
    c={"contract":"mad4b.host-v133.verified-original-v3-source-standby.v1",
       "company":"mad4b-central","environment":"staging","site_uuid":m.SITE,
       "activation_state":"VERIFIED_ROOT_PRIVATE_SOURCE_STANDBY_NOT_EXECUTABLE"}
    d={"signed_current_verified":True,"write_authorized":False,"snapshot_sha256":"b"*64}
    return [a,b,c,d]

def check(e):
    a,b,c,d=e
    return m.evaluate(original305=a,archive313=b,standby=c,current=d,now=1795300000)

class Monitor(unittest.TestCase):
    def test_missing_owner_identity_and_original313_remain_blocked(self):
        x=check(evidence())
        self.assertTrue(x["single_owner_model_requested"])
        self.assertFalse(x["single_owner_identity_cryptographically_enrolled"])
        self.assertEqual(x["next_state"],"WAITING_FOR_INDEPENDENT_FIRSTPARTY_EVIDENCE")
        self.assertIn("complete_original313_history",x["blockers"])
        self.assertIn("independent_owner_signer_enrolled",x["blockers"])
        self.assertFalse(x["write_authorized"])
        self.assertEqual(x["owner_action"],
                         "NONE_UNTIL_SIGNED_ORIGINAL313_OFFER_AND_ENROLLED_SIGNER")
    def test_remaining_issuer_gates_are_independent(self):
        e=evidence()
        e[1]["complete_cross_generation_history_verified"]=True
        e[1]["complete_original313_predecessor_for_target"]="VERIFIED"
        e[0]["original313_predecessor_v3_signed"]=True
        x=check(e)
        self.assertNotIn("complete_original313_history",x["blockers"])
        self.assertIn("independent_owner_signer_enrolled",x["blockers"])
        self.assertFalse(x["write_authorized"])
    def test_even_all_five_prerequisites_do_not_grant_write(self):
        e=evidence()
        e[1]["complete_cross_generation_history_verified"]=True
        e[1]["complete_original313_predecessor_for_target"]="VERIFIED"
        for key in ("original313_predecessor_v3_signed",
            "original305_v3_independent_owner_key_registered",
            "original305_v3_approved_owner_stepup_registered",
            "original305_v3_exact_approval_signed",
            "original312_v3_distinct_signer_enrollment_verified"):
            e[0][key]=True
        x=check(e)
        self.assertEqual(x["blockers"],[])
        self.assertEqual(x["next_state"],"FIRSTPARTY_ISSUANCE_READBACK_REQUIRED")
        self.assertFalse(x["write_authorized"])
    def test_deny_mismatched_company_or_unpinned_broker(self):
        for group,key,value in (
          (0,"company","all-royal-egypt"),(1,"target_site_uuid","wrong"),
          (2,"environment","production"),(0,"original305_v8_manifest_pin_verified",False),
          (0,"original305_legacy_ambiguous_write_hold_preserved",False),
          (3,"signed_current_verified",False),(3,"write_authorized",True),
          (0,"approved_write",True),
          (2,"activation_state","ISSUER_LIVE_REGISTERED")):
            with self.subTest(group=group,key=key):
                e=evidence();e[group][key]=value
                with self.assertRaises(m.UntrustedReadiness):check(e)
    def test_never_write_anywhere_else(self):
        with self.assertRaises(m.UntrustedReadiness):
            m.publish_private({},Path("/tmp/unsafe.json"))
    def test_no_grant_or_private_signer_calls(self):
        source=SRC.read_text()
        self.assertNotIn("Ed25519PrivateKey",source)
        for invalid in ("wp option update","register_rest_route(", "sodium_crypto_sign",
                        "original305_v3_owner_provider.issue_approved_original305",
                        "original312_v3_issuer.issue","chattr","systemctl start",
                        "write_authorized\":True"):
            self.assertNotIn(invalid,source)

if __name__=="__main__":unittest.main()
