"""Independent Original313 root-only signing tests, with synthetic first-party readers."""
import sys
from pathlib import Path
import unittest
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/"ops/host-authority-v128"))
import persistent_enrollment as v
import original313_v3_checkpoint as verifier313
import original312_v3_issuer as issuer312
import test_original312_v3_issuer as fixtures

NOW=1800000000

def pub(key):
    return key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)

class Original313V3CheckpointTests(unittest.TestCase):
    def setUp(self):
        self.f=fixtures.Original312V3IssuerTests("test_approved_expired_v2_revision_migrates_to_new_signed_v3")
        self.f.setUp()
        self.enrollment=self.f.issue()
        self.ehash=v.digest(self.enrollment,v.ENROLLMENT_FIELDS,v.ENROLLMENT_CONTRACT)
        self.installed = {
            "source":verifier313.ROOT_INSTALL_SNAPSHOT,
            "company":self.f.binding.company,
            "environment":self.f.binding.environment,
            "site_uuid":self.f.binding.site_uuid,
            "target_host_id":self.f.binding.host_id,
            "observed_epoch":NOW-1,"read_only":True,
            "original_signatures_verified":True,
            "original312_signature_verified":True,
            "enrollment_sha256":self.ehash,
            "revision":self.enrollment["revision"],
            "write_authorized":False,
        }
        self.ledger={
            "source":verifier313.ROOT_LEDGER_SNAPSHOT,
            "company":self.f.binding.company,
            "environment":self.f.binding.environment,
            "site_uuid":self.f.binding.site_uuid,
            "target_host_id":self.f.binding.host_id,
            "observed_epoch":NOW-1,"read_only":True,
            "original_signatures_verified":True,
            "monotonic_chain_verified":True,
            "enrollment_sha256":self.ehash,
            "revision":self.enrollment["revision"],
            "state":"ACTIVE",
        }

    def sign(self):
        return verifier313.sign_installed_checkpoint(
            self.enrollment,self.f.binding,self.f.owner,self.f.predecessor,
            lambda binding:self.installed,
            lambda binding:self.ledger,
            self.f.k313,
            pub(self.f.k305),pub(self.f.k312),pub(self.f.k313),
            now=NOW
        )

    def denied(self,code):
        with self.assertRaisesRegex(v.ConnectionDenied,code):
            self.sign()

    def test_sign_independent_checkpoint_after_exact_installed_readback(self):
        checkpoint=self.sign()
        self.assertEqual(checkpoint["revision"],2)
        self.assertEqual(checkpoint["state"],"ACTIVE")
        self.assertIsNone(checkpoint["operation_id"])
        self.assertIsNone(checkpoint["activity_sha256"])
        v.verify_signed(checkpoint,v.CHECKPOINT_FIELDS,v.CHECKPOINT_CONTRACT,
                        pub(self.f.k313))
        self.assertEqual(checkpoint["enrollment_sha256"],self.ehash)

    def test_313_cannot_proclaim_mutation_or_generic_host_write(self):
        checkpoint=self.sign()
        self.assertNotIn("write_authorized",checkpoint)
        self.assertNotIn("grant_id",checkpoint)
        self.assertIsNone(checkpoint["operation_id"])

    def test_uninstalled_wp_enrollment_denied(self):
        self.installed["enrollment_sha256"]="b"*64
        self.denied("ORIGINAL318_INSTALLED_V3_READBACK_INVALID")

    def test_wp_signer_not_verified_denied(self):
        self.installed["original312_signature_verified"]=False
        self.denied("ORIGINAL318_INSTALLED_V3_READBACK_INVALID")

    def test_root_ledger_behind_wp_denied(self):
        self.ledger["revision"]=1
        self.denied("ORIGINAL313_HIGHWATER_NOT_CURRENT")

    def test_root_revoked_while_preparing_denied(self):
        self.ledger["state"]="REVOKED"
        self.denied("ORIGINAL313_HIGHWATER_NOT_CURRENT")

    def test_live_root_reader_must_be_fresh(self):
        self.ledger["observed_epoch"]=NOW-60
        self.denied("ROOT_CURRENT_READER_INVALID")

    def test_no_self_reported_source_can_replace_318(self):
        self.installed["source"]="wordpress_option_self_claim"
        self.denied("ROOT_CURRENT_READER_INVALID")

    def test_owner_consent_changed_denied(self):
        self.f.owner["state"]="REVOKED"
        self.denied("SIGNED_SIGNATURE_INVALID")

    def test_stale_predecessor_denied(self):
        self.f.predecessor["observed_epoch"]=NOW-50
        self.f.predecessor=fixtures.sign(
            {k:self.f.predecessor[k] for k in issuer312.PREDECESSOR_FIELDS},
            issuer312.PREDECESSOR_FIELDS,self.f.k313,issuer312.PREDECESSOR_CONTRACT)
        self.denied("CURRENT_PREDECESSOR_PROOF_INVALID")

    def test_root_signer_key_substitution_denied(self):
        self.f.k313=self.f.k312
        with self.assertRaisesRegex(v.ConnectionDenied,"INDEPENDENT_SIGNERS_REQUIRED"):
            self.sign()

    def test_original312_313_318_real_signed_end_to_end_parity(self):
        # Signed owner + predecessor -> 312 enrollment -> 313 current
        # certificate -> PHP Original318 readback -> independent Python read.
        import test_php318_v3_contract as php_fixture
        checkpoint=self.sign()
        t=php_fixture.Original318PHPv3Tests(
            "test_php_reader_exactly_matches_independent_python_verified_shape")
        t.setUp()
        t.fixture.enrollment=self.enrollment
        t.fixture.checkpoint=checkpoint
        t.fixture.hp=pub(self.f.k312)
        t.fixture.cp=pub(self.f.k313)
        t.site={k:self.enrollment[k] for k in (
            "company","environment","site_uuid","target_host_id",
            "ssh_principal","host_key_sha256","policy_sha256")}
        current=t.php_readback()
        self.assertTrue(current["readback_verified"])
        self.assertFalse(current["write_authorized"])
        evidence=v.verify_connection(self.enrollment,checkpoint,current,
                                     pub(self.f.k312),pub(self.f.k313),now=NOW)
        self.assertEqual(evidence["state"],"PERSISTENT_CONNECTED")
        self.assertFalse(evidence["execution_performed"])

    def test_predecessor_issued_only_from_verified_highwater_root(self):
        prior_reader=lambda binding:{
            **{k:self.ledger[k] for k in
               ("company","environment","site_uuid","target_host_id","observed_epoch",
                "read_only","original_signatures_verified","monotonic_chain_verified")},
            "source":verifier313.ROOT_PREDECESSOR_SNAPSHOT,
            "revision":1,"state":"EXPIRED","event_sha256":"a"*64,
        }
        cert=verifier313.sign_current_predecessor(
            self.f.binding,prior_reader,self.f.k313,pub(self.f.k313),now=NOW)
        issuer312.validate_predecessor(cert,self.f.binding,pub(self.f.k313),NOW)

    def test_predecessor_cannot_rely_on_wp_option_only(self):
        reader=lambda binding:{
            **{k:self.ledger[k] for k in
               ("company","environment","site_uuid","target_host_id","observed_epoch",
                "read_only","original_signatures_verified","monotonic_chain_verified")},
            "source":"self_reported_wp_option",
            "revision":1,"state":"EXPIRED","event_sha256":"a"*64,
        }
        with self.assertRaisesRegex(v.ConnectionDenied,"ROOT_CURRENT_READER_INVALID"):
            verifier313.sign_current_predecessor(
                self.f.binding,reader,self.f.k313,pub(self.f.k313),now=NOW)

if __name__=="__main__": unittest.main()
