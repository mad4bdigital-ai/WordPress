"""Original305 v3 exact signed Owner approval; all cryptographic keys ephemeral."""
import base64
import copy
import os
import sys
import tempfile
import unittest
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/"ops/host-authority-v128"))
import original305_v3_owner_provider as p305
import original312_v3_issuer as p312
import persistent_enrollment as v
import test_original312_v3_issuer as fixture

NOW=1800000000

def public(k):
    return k.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)

def signed(row,fields,contract,key):
    row=copy.deepcopy(row)
    row["signature"]=base64.b64encode(
        key.sign(v.canonical(row,fields,contract))).decode()
    return row

class Original305OwnerProviderTests(unittest.TestCase):
    def setUp(self):
        self.v312=fixture.Original312V3IssuerTests(
            "test_approved_expired_v2_revision_migrates_to_new_signed_v3")
        self.v312.setUp()
        self.k305=Ed25519PrivateKey.generate()
        self.stepup=Ed25519PrivateKey.generate()
        self.binding=self.v312.binding
        self.proposal=self.v312.proposal
        self.predecessor=self.v312.predecessor
        self.source="f"*40
        self.intent=p305.create_unsigned_owner_intent(
            self.proposal,self.predecessor,self.binding,
            pinned313=public(self.v312.k313),
            source_commit_sha=self.source,request_id="a"*32,
            actor_digest_sha256="b"*64,intent_nonce="c"*32,now=NOW)
        self.signed_intent=signed(
            self.intent,p305.INTENT_FIELDS,p305.INTENT_CONTRACT,self.stepup)

    def approve(self,*,signer=None,p305pub=None,stepup_pub=None,
                signed_stepup=None,now=NOW):
        return p305.issue_approved_original305(
            unsigned=self.proposal,predecessor=self.predecessor,
            binding=self.binding,
            signed_owner_stepup=self.signed_intent if signed_stepup is None else signed_stepup,
            pinned_stepup_public=public(self.stepup) if stepup_pub is None else stepup_pub,
            pinned_owner305_public=public(self.k305) if p305pub is None else p305pub,
            pinned_original312_public=public(self.v312.k312),
            pinned_original313_public=public(self.v312.k313),
            owner305_signer=self.k305 if signer is None else signer,
            approved_source_head=self.source,now=now)

    def denied(self,reason):
        with self.assertRaisesRegex(v.ConnectionDenied,reason):
            self.approve()

    def replace_intent(self,**changes):
        self.signed_intent=signed(
            dict(self.intent,**changes),p305.INTENT_FIELDS,p305.INTENT_CONTRACT,
            self.stepup)

    def test_unsigned_owner_intent_does_not_approve(self):
        self.assertEqual(self.intent["decision"],"APPROVE")
        self.assertNotIn("signature",self.intent)
        self.assertNotIn("write_authorized",self.intent)
        self.assertNotIn("owner305_approval",self.intent)

    def test_signed_owner_intent_and_independent_owner305_key(self):
        approval=self.approve()
        self.assertEqual(approval["contract"],p312.OWNER_CONTRACT)
        self.assertEqual(approval["operation_id"],p312.ISSUE_OP)
        self.assertEqual(approval["state"],"APPROVED")
        v.verify_signed(approval,p312.OWNER_FIELDS,p312.OWNER_CONTRACT,
                        public(self.k305))
        self.assertNotIn("write_authorized",approval)

    def test_exact_output_consumed_by_real_original312(self):
        approval=self.approve()
        enrolled=p312.issue_v3_enrollment(
            self.proposal,self.binding,approval,self.predecessor,
            public(self.k305),public(self.v312.k312),public(self.v312.k313),
            self.v312.k312,now=NOW)
        self.assertEqual(enrolled["mode"],"persistent")
        self.assertIsNone(enrolled["expires_epoch"])
        self.assertEqual(enrolled["revision"],2)
        v.verify_signed(enrolled,v.ENROLLMENT_FIELDS,v.ENROLLMENT_CONTRACT,
                        public(self.v312.k312))

    def test_intent_wrong_site(self):
        self.replace_intent(site_uuid="2"*8+"-2222-4222-8222-"+"2"*12)
        self.denied("OWNER305_EXACT_HUMAN_APPROVAL_INVALID")

    def test_intent_wrong_environment(self):
        self.replace_intent(environment="production")
        self.denied("OWNER305_EXACT_HUMAN_APPROVAL_INVALID")

    def test_intent_wrong_host(self):
        self.replace_intent(target_host_id="another-host")
        self.denied("OWNER305_EXACT_HUMAN_APPROVAL_INVALID")

    def test_intent_wrong_enrollment_hash(self):
        self.replace_intent(enrollment_sha256="f"*64)
        self.denied("OWNER305_EXACT_HUMAN_APPROVAL_INVALID")

    def test_intent_wrong_predecessor_hash(self):
        self.replace_intent(predecessor_sha256="f"*64)
        self.denied("OWNER305_EXACT_HUMAN_APPROVAL_INVALID")

    def test_intent_wrong_source_sha(self):
        self.replace_intent(source_commit_sha="e"*40)
        self.denied("OWNER305_EXACT_HUMAN_APPROVAL_INVALID")

    def test_intent_denied(self):
        self.replace_intent(decision="DENY")
        self.denied("OWNER305_EXACT_HUMAN_APPROVAL_INVALID")

    def test_owner_intent_without_signature(self):
        self.signed_intent=self.intent
        self.denied("SIGNED_ROW_SHAPE_INVALID")

    def test_owner_intent_tampered_signature(self):
        self.signed_intent["decision"]="DENY"
        self.denied("SIGNED_SIGNATURE_INVALID")

    def test_unpinned_stepup_public(self):
        with self.assertRaisesRegex(v.ConnectionDenied,"SIGNED_SIGNATURE_INVALID"):
            self.approve(stepup_pub=public(Ed25519PrivateKey.generate()))

    def test_owner305_unpinned_signer_rejected(self):
        with self.assertRaisesRegex(v.ConnectionDenied,"ORIGINAL305_REGISTERED_SIGNER_REQUIRED"):
            self.approve(signer=Ed25519PrivateKey.generate())

    def test_owner305_same_as_312_rejected(self):
        with self.assertRaisesRegex(v.ConnectionDenied,"INDEPENDENT_SIGNERS_REQUIRED"):
            self.approve(p305pub=public(self.v312.k312))

    def test_owner305_same_as_313_rejected(self):
        with self.assertRaisesRegex(v.ConnectionDenied,"INDEPENDENT_SIGNERS_REQUIRED"):
            self.approve(p305pub=public(self.v312.k313))

    def test_stepup_same_as_305_rejected(self):
        with self.assertRaisesRegex(v.ConnectionDenied,"INDEPENDENT_SIGNERS_REQUIRED"):
            self.approve(stepup_pub=public(self.k305))

    def test_outdated_signed_intent(self):
        self.replace_intent(issued_epoch=NOW-185,
                            expires_epoch=NOW-5)
        self.denied("OWNER305_EXACT_HUMAN_APPROVAL_INVALID")

    def test_future_signed_intent(self):
        self.replace_intent(issued_epoch=NOW+10,expires_epoch=NOW+180)
        self.denied("OWNER305_EXACT_HUMAN_APPROVAL_INVALID")

    def test_expired_signed_intent(self):
        self.replace_intent(expires_epoch=NOW)
        self.denied("OWNER305_EXACT_HUMAN_APPROVAL_INVALID")

    def test_malformed_owner_actor_hash(self):
        self.replace_intent(actor_digest_sha256="bad")
        self.denied("OWNER305_EXACT_HUMAN_APPROVAL_INVALID")

    def test_malformed_owner_nonce(self):
        self.replace_intent(intent_nonce="bad")
        self.denied("OWNER305_EXACT_HUMAN_APPROVAL_INVALID")

    def test_predecessor_drift(self):
        self.predecessor["prior_state"]="REVOKED"
        self.denied("SIGNED_SIGNATURE_INVALID")

    def test_predecessor_revocation_requires_exact_new_intent(self):
        self.predecessor["prior_state"]="REVOKED"
        self.predecessor=signed(
            {k:self.predecessor[k] for k in p312.PREDECESSOR_FIELDS},
            p312.PREDECESSOR_FIELDS,p312.PREDECESSOR_CONTRACT,self.v312.k313)
        self.denied("OWNER305_EXACT_HUMAN_APPROVAL_INVALID")

    def test_old_v2_enrollment_rejected(self):
        self.proposal["contract"]="mad4b.company-host-authority-anchor.v2"
        self.denied("OWNER305_EXACT_PERSISTENT_ENROLLMENT_REQUIRED")

    def test_wrong_revision_rejected(self):
        self.proposal["revision"]=4
        self.denied("OWNER305_EXACT_REVISION_OR_FRESHNESS_REQUIRED")

    def test_source_replay_without_signed_owner_fails(self):
        self.replace_intent(operation_id="host.source.release")
        self.denied("OWNER305_EXACT_HUMAN_APPROVAL_INVALID")

    @unittest.skipUnless(os.geteuid()==0,"Root-only replay journal")
    def test_ledger_rejects_claimed_unsigned_owner_approval(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)/"owner"
            root.mkdir(mode=0o700)
            with self.assertRaisesRegex(v.ConnectionDenied,"SIGNED_ROW_SHAPE_INVALID"):
                p305.persist_approved_owner_request(
                    root,issue_callback=lambda:{**{k:"x" for k in p312.OWNER_FIELDS},
                        "contract":p312.OWNER_CONTRACT,"request_id":"a"*32},
                    request_id="a"*32,signed_owner_stepup=self.signed_intent,
                pinned_owner_stepup_public=public(self.stepup),
                pinned_owner305_public=public(self.k305))
            self.assertFalse((root/("a"*32+".json")).exists())

    @unittest.skipUnless(os.geteuid()==0,"Root-only replay journal")
    def test_original305_approval_record_exact_once(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)/"owner"
            root.mkdir(mode=0o700)
            approval=p305.persist_approved_owner_request(
                root,issue_callback=self.approve,request_id="a"*32,
                signed_owner_stepup=self.signed_intent,
                pinned_owner_stepup_public=public(self.stepup),
                pinned_owner305_public=public(self.k305))
            self.assertEqual(approval["state"],"APPROVED")
            record=root/("a"*32+".json")
            self.assertTrue(record.is_file())
            self.assertEqual(record.stat().st_mode&0o777,0o600)
            called=[]
            with self.assertRaisesRegex(
                    v.ConnectionDenied,"ORIGINAL305_OWNER_REQUEST_ALREADY_CONSUMED"):
                p305.persist_approved_owner_request(
                    root,issue_callback=lambda:called.append(True),
                    request_id="a"*32,signed_owner_stepup=self.signed_intent,
                pinned_owner_stepup_public=public(self.stepup),
                pinned_owner305_public=public(self.k305))
            self.assertEqual(called,[])

    @unittest.skipUnless(os.geteuid()==0,"Root-only replay journal")
    def test_ledger_rejects_tampered_human_owner_proof(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)/"owner"
            root.mkdir(mode=0o700)
            forged=dict(self.signed_intent, decision="DENY")
            with self.assertRaisesRegex(v.ConnectionDenied,"SIGNED_SIGNATURE_INVALID"):
                p305.persist_approved_owner_request(
                    root,issue_callback=self.approve,request_id="a"*32,
                    signed_owner_stepup=forged,
                    pinned_owner_stepup_public=public(self.stepup),
                    pinned_owner305_public=public(self.k305))
            self.assertFalse((root/("a"*32+".json")).exists())

    @unittest.skipUnless(os.geteuid()==0,"Root-only replay journal")
    def test_world_readable_root_journal_denied(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=Path(tmp)/"unsafe"
            p.mkdir(mode=0o755)
            with self.assertRaisesRegex(
                    v.ConnectionDenied,"ORIGINAL305_PRIVATE_LEDGER_OWNER_INVALID"):
                p305.persist_approved_owner_request(
                    p,issue_callback=self.approve,request_id="a"*32,
                    signed_owner_stepup=self.signed_intent,
                pinned_owner_stepup_public=public(self.stepup),
                pinned_owner305_public=public(self.k305))

if __name__=="__main__":
    unittest.main()
