import importlib.util
import base64
from pathlib import Path
import tempfile
import unittest
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey, Ed25519PublicKey
from cryptography.hazmat.primitives.serialization import (
    Encoding,PublicFormat)

SRC=Path(__file__).resolve().parents[2]/"ops/host-authority-v143/offline_owner_stepup.py"
spec=importlib.util.spec_from_file_location("offline_owner",SRC)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class DeviceSignerTests(unittest.TestCase):
 def test_encrypted_key_and_proof_are_not_enrollment(self):
    with tempfile.TemporaryDirectory() as t:
     p,fingerprint=m.create_device_key(Path(t),b"correct-horse-battery-xyz")
     text=p.read_text()
     self.assertIn("UNENROLLED_PENDING_FIRSTPARTY_OWNER_IDENTITY_REGISTRAR",text)
     pem=(Path(t)/m.PRIVATE_FILE).read_bytes()
     self.assertIn(b"ENCRYPTED PRIVATE KEY",pem)
     self.assertNotIn(b"-----BEGIN PRIVATE KEY-----",pem)
     self.assertEqual(len(fingerprint),64)
     self.assertEqual((Path(t)/m.PRIVATE_FILE).stat().st_mode & 0o777,0o600)
     with self.assertRaises(m.ProofRejected):
      m.create_device_key(Path(t),b"correct-horse-battery-xyz")
 def test_fake_offer_is_rejected_before_any_signature(self):
    signer=Ed25519PrivateKey.generate()
    base={"contract":m.OFFER_CONTRACT}
    with self.assertRaises(m.ProofRejected):
     m.sign_exact_intent(base,signer,"a"*64,now=1795300000)
 def test_independently_signed_wrong_key_is_rejected(self):
    # A synthetic *test* signer cannot replace the pinned Original313 public.
    fake313=Ed25519PrivateKey.generate()
    pub=Ed25519PrivateKey.generate().public_key().public_bytes(
         Encoding.Raw,PublicFormat.Raw)
    row=dict.fromkeys(m.OFFER_FIELDS)
    row.update({"contract":m.OFFER_CONTRACT,
      "company":"mad4b-central","environment":"staging",
      "site_uuid":m.SCOPE[2],"target_host_id":m.SCOPE[3],
      "request_id":"a"*32,"plan_sha256":"a"*64,
      "predecessor_sha256":"a"*64,"enrollment_sha256":"a"*64,
      "source_commit_sha":"a"*40,
      "owner_stepup_public_b64":base64.b64encode(pub).decode(),
      "owner_stepup_enrollment_sha256":"a"*64,
      "original305_issuer_public_b64":base64.b64encode(pub).decode(),
      "original305_issuer_enrollment_sha256":"a"*64,
      "identity_authority_confirmed":True,
      "monotonic_predecessor_verified":True,
      "issued_epoch":1795300000,"expires_epoch":1795300300,
      "state":"READY_FOR_OWNER_STEPUP"})
    row["signature"]=base64.b64encode(fake313.sign(
      m.canonical(row,m.OFFER_FIELDS,m.OFFER_DOMAIN))).decode()
    with self.assertRaises(m.ProofRejected):
     m.verify_original313_offer(row,key_public=pub,now=1795300030)
 def test_proof_uses_original305_exact_canonical_contract(self):
    fields=m.FIELDS
    self.assertEqual(fields,("contract","company","environment",
         "site_uuid","target_host_id","enrollment_sha256",
         "predecessor_sha256","source_commit_sha","operation_id",
         "request_id","actor_digest_sha256","intent_nonce","decision",
         "issued_epoch","expires_epoch"))
    self.assertEqual(m.DOMAIN,"MAD4B_ORIGINAL305_OWNER_V3_EXACT_STEPUP")
    self.assertEqual(len(m.ORIGINAL313_PUB),32)
 def test_no_remote_credentials_or_automatic_enrollment(self):
    src=SRC.read_text()
    for forbidden in ("requests.post(","urllib.request","wp option update",
                      "root-only-verifier-ed25519.key","SSH_PRIVATE_KEY",
                      "issue_approved_original305(", "site.code.atomic.install"):
     self.assertNotIn(forbidden,src)
if __name__=="__main__":unittest.main()

class ExactOwnerContractTests(unittest.TestCase):
 def test_source_has_no_hardcoded_real_owner_private_key(self):
    s=SRC.read_text()
    self.assertNotIn("-----BEGIN PRIVATE KEY-----",s)
    self.assertNotIn("-----BEGIN ENCRYPTED PRIVATE KEY-----",s)
    self.assertIn("getpass(",s)
    self.assertIn("OWNER_DEVICE_REQUIRED_DO_NOT_RUN_SIGNER_AS_ROOT",s)
    self.assertIn("BestAvailableEncryption(password)",s)
    self.assertIn('write_authorized":False',s)
 def test_valid_synthetic_test_offer_signs_original305_canonical_bytes(self):
    # This suite can only override the original313 public key in local test
    # memory. It never changes the installed Root first-party pins or records.
    fake313=Ed25519PrivateKey.generate()
    pub313=fake313.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
    old=m.ORIGINAL313_PUB
    try:
     m.ORIGINAL313_PUB=pub313
     owner=Ed25519PrivateKey.generate()
     owner_b64=base64.b64encode(m.public_bytes(owner)).decode()
     now=1795300000
     offer=dict.fromkeys(m.OFFER_FIELDS)
     offer.update(dict(contract=m.OFFER_CONTRACT,
      company=m.SCOPE[0],environment=m.SCOPE[1],
      site_uuid=m.SCOPE[2],target_host_id=m.SCOPE[3],
      request_id="a"*32,plan_sha256="e"*64,
      predecessor_sha256="b"*64,enrollment_sha256="c"*64,
      source_commit_sha="d"*40,owner_stepup_public_b64=owner_b64,
      owner_stepup_enrollment_sha256="e"*64,
      original305_issuer_public_b64=base64.b64encode(pub313).decode(),
      original305_issuer_enrollment_sha256="f"*64,
      identity_authority_confirmed=True,
      monotonic_predecessor_verified=True,
      issued_epoch=now-20,expires_epoch=now+140,
      state="READY_FOR_OWNER_STEPUP"))
     offer["signature"]=base64.b64encode(fake313.sign(
        m.canonical(offer,m.OFFER_FIELDS,m.OFFER_DOMAIN))).decode()
     result=m.sign_exact_intent(offer,owner,"f"*64,now=now,
                                source_commit="d"*40)
     self.assertEqual(set(result),set(m.FIELDS)|{"signature"})
     self.assertEqual(result["expires_epoch"],now+140)
     Ed25519PublicKey.from_public_bytes(m.public_bytes(owner)).verify(
       base64.b64decode(result["signature"]),
       m.canonical(result,m.FIELDS,m.DOMAIN))
     with self.assertRaises(m.ProofRejected):
      m.verify_original313_offer(dict(offer,source_commit_sha="bad"),
            key_public=m.public_bytes(owner),now=now)
     with self.assertRaises(m.ProofRejected):
      m.verify_original313_offer(offer,key_public=m.public_bytes(owner),
            now=now+141)
     other=Ed25519PrivateKey.generate()
     with self.assertRaises(m.ProofRejected):
      m.sign_exact_intent(offer,other,"f"*64,now=now)
    finally:
      m.ORIGINAL313_PUB=old
