"""Root re-verifies signed Owner consent/Original313 offer, never issues grants."""
import base64
import copy
import sys
import unittest
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/"ops/host-authority-v128"))
import original305_remote_intake_v135 as remote
from original305_v3_owner_provider import INTENT_CONTRACT,INTENT_FIELDS
from persistent_enrollment import canonical,ConnectionDenied

NOW=1800000000
def pub(key):return key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
def b64(byte):return base64.b64encode(byte).decode()
def sign(row,fields,contract,key):
    o=copy.deepcopy(row)
    o["signature"]=b64(key.sign(canonical(o,fields,contract)))
    return o

class Original305RemoteRootIntakeTests(unittest.TestCase):
    def setUp(self):
        self.master=Ed25519PrivateKey.generate()
        self.resource312=Ed25519PrivateKey.generate()
        self.independent313=Ed25519PrivateKey.generate()
        self.stepup=Ed25519PrivateKey.generate()
        self.source="e"*40
        self.plan="b"*64
        self.review={
            "contract":"mad4b.original305.remote-review-request.v1",
            "state":"AWAITING_INDEPENDENT_ORIGINAL313_PREDECESSOR",
            "site_uuid":remote.SITE_UUID,
            "company":remote.COMPANY,"environment":remote.ENV,
            "target_host_id":remote.HOST,
            "request_id":"a"*32,"plan_sha256":self.plan,
            "requested_by_user_id":7,
            "created_epoch":NOW-60,"expires_epoch":NOW+1800,
            "owner_signature_verified":False,
            "predecessor_signature_verified":False,
            "original305_approval_signed":False,
            "original312_enrollment_signed":False,
            "standing_host_write_authorized":False,
        }
        self.index={
            "contract":"mad4b.dynamic-operation-index.v1",
            "installed_by_first_party":True,
            "host_resource_312_public_key_b64":b64(pub(self.resource312)),
            "independent_verifier_public_key_b64":b64(pub(self.independent313))
        }
        self.policy={
            "contract":"mad4b.native-source-publisher-policy.v2",
            "enabled":True,
            "master_approval_public_key_b64":b64(pub(self.master)),
        }
        self.offer=sign({
            "contract":remote.HANDOFF_CONTRACT,
            "company":remote.COMPANY,
            "environment":remote.ENV,"site_uuid":remote.SITE_UUID,
            "target_host_id":remote.HOST,
            "request_id":self.review["request_id"],"plan_sha256":self.plan,
            "predecessor_sha256":"c"*64,"enrollment_sha256":"d"*64,
            "source_commit_sha":self.source,
            "owner_stepup_public_b64":b64(pub(self.stepup)),
            "owner_stepup_enrollment_sha256":"f"*64,
            "original305_issuer_public_b64":b64(pub(self.master)),
            "original305_issuer_enrollment_sha256":"6"*64,
            "identity_authority_confirmed":True,
            "monotonic_predecessor_verified":True,
            "issued_epoch":NOW-5,"expires_epoch":NOW+240,
            "state":"READY_FOR_OWNER_STEPUP",
        },remote.HANDOFF_FIELDS,remote.HANDOFF_CONTRACT,self.independent313)
        self.intent=sign({
            "contract":INTENT_CONTRACT,"company":remote.COMPANY,
            "environment":remote.ENV,"site_uuid":remote.SITE_UUID,
            "target_host_id":remote.HOST,
            "enrollment_sha256":"d"*64,"predecessor_sha256":"c"*64,
            "source_commit_sha":self.source,
            "operation_id":"company.host-authority.enrollment.v3.issue",
            "request_id":self.review["request_id"],
            "actor_digest_sha256":"1"*64,"intent_nonce":"2"*32,
            "decision":"APPROVE","issued_epoch":NOW-2,
            "expires_epoch":NOW+100,
        },INTENT_FIELDS,INTENT_CONTRACT,self.stepup)

    def call(self):
        return remote.verify_owner_remote_transport(
            review=self.review,
            original313_offer=self.offer,
            signed_owner_intent=self.intent,
            firstparty_index=self.index,
            firstparty_master_policy=self.policy,
            now=NOW,expected_source_commit=self.source,
            expected_plan_sha256=self.plan)

    def denied(self,code):
        with self.assertRaisesRegex(ConnectionDenied,code):
            self.call()

    def resign_offer(self,**update):
        fields={k:v for k,v in self.offer.items() if k!="signature"}
        fields.update(update)
        self.offer=sign(fields,remote.HANDOFF_FIELDS,
                        remote.HANDOFF_CONTRACT,self.independent313)

    def resign_intent(self,**update):
        fields={k:v for k,v in self.intent.items() if k!="signature"}
        fields.update(update)
        self.intent=sign(fields,INTENT_FIELDS,INTENT_CONTRACT,self.stepup)

    def test_valid_both_signatures_do_not_issue_approval(self):
        data=self.call()
        self.assertEqual(data["state"],
           "VERIFIED_TRANSPORT_ONLY_PENDING_ROOT_SIGNED_PREDECESSOR")
        self.assertTrue(data["independent_firstparty_original313_full_ledger_read_required"])
        self.assertTrue(data["owner_identity_enrollment_independently_revalidate_required"])
        self.assertFalse(data["signed_original305_approval_issued"])
        self.assertFalse(data["signed_original312_v3_enrollment_issued"])
        self.assertFalse(data["host_write_authorized"])

    def test_unsigned_offer_denied(self):
        self.offer.pop("signature")
        self.denied("SIGNED_ROW_SHAPE_INVALID")

    def test_offer_signature_tampered_denied(self):
        self.offer["state"]="REVOKED"
        self.denied("SIGNED_SIGNATURE_INVALID")

    def test_offer_wrong_issuer_key_denied(self):
        self.resign_offer(original305_issuer_public_b64=b64(pub(Ed25519PrivateKey.generate())))
        self.denied("OWNER_REMOTE_ORIGINAL305_ROOT_SIGNER_MISMATCH")

    def test_owner_signer_cannot_be_original313(self):
        self.resign_offer(owner_stepup_public_b64=b64(pub(self.independent313)))
        self.denied("INDEPENDENT_SIGNERS_REQUIRED")

    def test_owner_signer_cannot_be_original305(self):
        self.resign_offer(owner_stepup_public_b64=b64(pub(self.master)))
        self.denied("INDEPENDENT_SIGNERS_REQUIRED")

    def test_original313_and_original312_collusion_denied(self):
        self.index["host_resource_312_public_key_b64"]=b64(pub(self.independent313))
        self.denied("INDEPENDENT_SIGNERS_REQUIRED")

    def test_wrong_company_denied(self):
        self.resign_offer(company="other-company")
        self.denied("OWNER_REMOTE_SIGNED_OFFER_NOT_CURRENT")

    def test_wrong_request_id_denied(self):
        self.resign_offer(request_id="0"*32)
        self.denied("OWNER_REMOTE_SIGNED_OFFER_NOT_CURRENT")

    def test_wrong_revision_source_denied(self):
        self.resign_offer(source_commit_sha="0"*40)
        self.denied("OWNER_REMOTE_SIGNED_OFFER_NOT_CURRENT")

    def test_claimed_full_history_false_denied(self):
        self.resign_offer(monotonic_predecessor_verified=False)
        self.denied("OWNER_REMOTE_SIGNED_OFFER_NOT_CURRENT")

    def test_unregistered_owner_identity_denied(self):
        self.resign_offer(identity_authority_confirmed=False)
        self.denied("OWNER_REMOTE_SIGNED_OFFER_NOT_CURRENT")

    def test_owner_fake_signature_denied(self):
        self.intent["signature"]=b64(bytes(64))
        self.denied("SIGNED_SIGNATURE_INVALID")

    def test_signed_owner_deny_denied(self):
        self.resign_intent(decision="DENY")
        self.denied("OWNER_REMOTE_SIGNED_INTENT_CHANGED")

    def test_signed_owner_wrong_enrollment_denied(self):
        self.resign_intent(enrollment_sha256="0"*64)
        self.denied("OWNER_REMOTE_SIGNED_INTENT_CHANGED")

    def test_signed_owner_wrong_site_denied(self):
        self.resign_intent(site_uuid="f"*36)
        self.denied("OWNER_REMOTE_SIGNED_INTENT_CHANGED")

    def test_expired_owner_intent_denied(self):
        self.resign_intent(expires_epoch=NOW-1)
        self.denied("OWNER_REMOTE_SIGNED_INTENT_CHANGED")

    def test_expired_root_offer_denied(self):
        self.resign_offer(expires_epoch=NOW-1)
        self.denied("OWNER_REMOTE_SIGNED_OFFER_NOT_CURRENT")

    def test_expired_browser_review_denied(self):
        self.review["expires_epoch"]=NOW-1
        self.denied("OWNER_REMOTE_REQUEST_NOT_CURRENT")

    def test_unsigned_original_root_index_denied(self):
        self.index["installed_by_first_party"]=False
        self.denied("OWNER_REMOTE_FIRSTPARTY_PINS_INVALID")

    def test_plan_digest_changed_denied(self):
        self.plan="f"*64
        self.denied("OWNER_REMOTE_SIGNED_OFFER_NOT_CURRENT")

    def test_issuer_another_public_not_from_firstparty_denied(self):
        self.policy["master_approval_public_key_b64"]=b64(pub(Ed25519PrivateKey.generate()))
        self.denied("OWNER_REMOTE_ORIGINAL305_ROOT_SIGNER_MISMATCH")

if __name__=="__main__":unittest.main()
