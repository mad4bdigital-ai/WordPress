"""Pure Original312 v3 signer admission tests; ephemeral keys only."""
import base64
import sys
from pathlib import Path
import unittest

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/"ops/host-authority-v128"))
import original312_v3_issuer as issuer
import persistent_enrollment as v
from fleet_policy import SiteBinding
import test_persistent_enrollment as fixtures

NOW=1800000000

def pub(k):
    return k.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)

def sign(row, fields, key, contract):
    r=dict(row)
    r["signature"]=base64.b64encode(key.sign(v.canonical(r,fields,contract))).decode()
    return r

class Original312V3IssuerTests(unittest.TestCase):
    def setUp(self):
        f=fixtures.PersistentHostContractTests("test_default_mode_is_persistent")
        f.setUp()
        self.k312=f.host_key
        self.k313=f.highwater_key
        self.k305=Ed25519PrivateKey.generate()
        self.binding=SiteBinding(
            company=f.enrollment["company"],
            environment=f.enrollment["environment"],
            site_uuid=f.enrollment["site_uuid"],
            host_id=f.enrollment["target_host_id"],
            ssh_principal=f.enrollment["ssh_principal"],
            host_key_sha256=f.enrollment["host_key_sha256"],
            policy_sha256=f.enrollment["policy_sha256"],
        )
        self.proposal={k:f.enrollment[k] for k in v.ENROLLMENT_FIELDS}
        # A historic established connection is not a fresh issuance proposal.
        self.proposal["issued_epoch"]=NOW-3
        self.predecessor=sign({
            "contract":issuer.PREDECESSOR_CONTRACT,
            "company":self.binding.company,
            "environment":self.binding.environment,
            "site_uuid":self.binding.site_uuid,
            "target_host_id":self.binding.host_id,
            "prior_revision":1,
            "prior_state":"EXPIRED",
            "prior_event_sha256":"a"*64,
            "observed_epoch":NOW-2,
        },issuer.PREDECESSOR_FIELDS,self.k313,issuer.PREDECESSOR_CONTRACT)
        self.owner=self.owner_signed()

    def owner_signed(self):
        return sign({
            "contract":issuer.OWNER_CONTRACT,
            "company":self.binding.company,
            "environment":self.binding.environment,
            "site_uuid":self.binding.site_uuid,
            "target_host_id":self.binding.host_id,
            "enrollment_sha256":v.digest(
                self.proposal,v.ENROLLMENT_FIELDS,v.ENROLLMENT_CONTRACT),
            "predecessor_sha256":v.digest(
                self.predecessor,issuer.PREDECESSOR_FIELDS,
                issuer.PREDECESSOR_CONTRACT),
            "source_commit_sha":"1"*40,
            "operation_id":issuer.ISSUE_OP,
            "request_id":"4"*32,
            "approved_epoch":NOW-1,
            "state":"APPROVED",
        },issuer.OWNER_FIELDS,self.k305,issuer.OWNER_CONTRACT)

    def issue(self):
        return issuer.issue_v3_enrollment(
            self.proposal,self.binding,self.owner,self.predecessor,
            pub(self.k305),pub(self.k312),pub(self.k313),self.k312,now=NOW)

    def denied(self,reason):
        with self.assertRaisesRegex(v.ConnectionDenied,reason):
            self.issue()

    def test_approved_expired_v2_revision_migrates_to_new_signed_v3(self):
        signed=self.issue()
        self.assertEqual(signed["revision"],2)
        self.assertEqual(signed["mode"],"persistent")
        self.assertIsNone(signed["expires_epoch"])
        v.verify_signed(signed,v.ENROLLMENT_FIELDS,v.ENROLLMENT_CONTRACT,
                        pub(self.k312))
        self.assertNotEqual(signed["signature"],self.predecessor["signature"])

    def test_stale_predecessor_is_rejected_even_if_signature_valid(self):
        self.predecessor["observed_epoch"]=NOW-50
        self.predecessor=sign(
            {k:self.predecessor[k] for k in issuer.PREDECESSOR_FIELDS},
            issuer.PREDECESSOR_FIELDS,self.k313,issuer.PREDECESSOR_CONTRACT)
        self.owner=self.owner_signed()
        self.denied("CURRENT_PREDECESSOR_PROOF_INVALID")

    def test_wrong_next_revision_rejected(self):
        self.proposal["revision"]=4
        self.owner=self.owner_signed()
        self.denied("NEW_ENROLLMENT_REVISION_NOT_NEXT")

    def test_tampered_owner_consent_rejected(self):
        self.owner["state"]="APPROVED_BY_UNTRUSTED"
        self.denied("SIGNED_SIGNATURE_INVALID")

    def test_owner_consent_for_another_site_rejected(self):
        self.owner["site_uuid"]="2"*8+"-2222-4222-8222-"+"2"*12
        self.owner=sign(
            {k:self.owner[k] for k in issuer.OWNER_FIELDS},
            issuer.OWNER_FIELDS,self.k305,issuer.OWNER_CONTRACT)
        self.denied("ORIGINAL305_OWNER_APPROVAL_INVALID")

    def test_stale_owner_permission_rejected(self):
        self.owner["approved_epoch"]=NOW-1000
        self.owner=sign(
            {k:self.owner[k] for k in issuer.OWNER_FIELDS},
            issuer.OWNER_FIELDS,self.k305,issuer.OWNER_CONTRACT)
        self.denied("ORIGINAL305_OWNER_APPROVAL_INVALID")

    def test_unapproved_owner_state_rejected(self):
        self.owner["state"]="PENDING"
        self.owner=sign(
            {k:self.owner[k] for k in issuer.OWNER_FIELDS},
            issuer.OWNER_FIELDS,self.k305,issuer.OWNER_CONTRACT)
        self.denied("ORIGINAL305_OWNER_APPROVAL_INVALID")

    def test_wrong_312_private_key_rejected(self):
        fake=Ed25519PrivateKey.generate()
        with self.assertRaisesRegex(v.ConnectionDenied,"ORIGINAL312_SIGNER_KEY_MISMATCH"):
            issuer.issue_v3_enrollment(
                self.proposal,self.binding,self.owner,self.predecessor,
                pub(self.k305),pub(self.k312),pub(self.k313),fake,now=NOW)

    def test_reused_signer_key_rejected(self):
        with self.assertRaisesRegex(v.ConnectionDenied,"INDEPENDENT_SIGNERS_REQUIRED"):
            issuer.issue_v3_enrollment(
                self.proposal,self.binding,self.owner,self.predecessor,
                pub(self.k305),pub(self.k312),pub(self.k312),self.k312,now=NOW)

    def test_predecessor_revocation_does_not_auto_renew(self):
        self.predecessor["prior_state"]="REVOKED"
        self.predecessor=sign(
            {k:self.predecessor[k] for k in issuer.PREDECESSOR_FIELDS},
            issuer.PREDECESSOR_FIELDS,self.k313,issuer.PREDECESSOR_CONTRACT)
        self.owner=self.owner_signed()
        signed=self.issue()
        self.assertEqual(signed["revision"],2)
        self.assertEqual(signed["state"],"ACTIVE")
        # This is a NEW signed authority, not an existing revoked grant.
        self.assertFalse("write_authorized" in signed)

    def test_unsigned_old_v2_cannot_be_submitted(self):
        self.proposal["contract"]="mad4b.company-host-authority-anchor.v2"
        self.denied("NEW_V3_ENROLLMENT_EXACT_SHAPE_REQUIRED")

    def test_untrusted_site_binding_rejected(self):
        self.binding=SiteBinding(
            **{**self.binding.__dict__,"host_id":"hosting-3"})
        self.denied("ENROLLMENT_TRUSTED_SITE_BINDING_MISMATCH")

if __name__=="__main__": unittest.main()
