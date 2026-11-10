import base64
import json
from pathlib import Path
import sys
import time
import unittest
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import registry as r
import evidence as e
import test_registry


class EvidenceContractTests(unittest.TestCase):
    def setUp(self):
        self.fixture=test_registry.RegistryTests()
        self.fixture.setUp()
        self.index=r.Registry(self.fixture.root)
        self.key=self.fixture.verifier_key
        self.public=self.fixture.verifier_pub
        now=int(time.time())
        self.proof={
            "contract":e.CONTRACT,
            "job_id":"a"*32,
            "operation_id":"company.code.release",
            "provider_version":1,
            "evidence_schema":"code_release.v2",
            "company":"wayfatours","environment":"production",
            "site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c",
            "origin":"https://wayfatours.com",
            "target_id":"srv2030908",
            "command_sha256":self.index.command_sha256("company.code.release","1"*64),
            "plan_sha256":"1"*64,
            "payload":{"file_count":2,"readback_sha256":"b"*64,
                       "native_receipt_sha256":"d"*64,
                       "output_sha256":"e"*64,
                       "expected_readback_sha256":"b"*64,
                       "grant_id":"1"*32,"transport_id":"2"*32,
                       "production_mutation":True,
                       "result_status":"HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313"},
            "issued_epoch":now,
            "expires_epoch":now+65,
            "nonce":"c"*32,
        }
        self.proof["evidence_sha256"]=r.sha(r.canonical(self.proof["payload"]))
        self.job={**{key:self.proof[key] for key in
                     ("job_id","company","environment","site_uuid","origin","command_sha256")},
                  "operation":"company.remote.wpcli.full",
                  "job_type":"signed_vps_wpcli",
                  "target_scope":"wordpress","target_host_id":"srv2030908"}
        self.original={**{key:self.proof[key] for key in
                          ("job_id","company","environment","site_uuid","origin","command_sha256","target_id")},
                       "contract":"mad4b.broker-exact-ssh-authority.v1",
                       "verified":True,"eligible":True,"owner_enrolled_wpcli":True,
                       "central_scope_only":True,"shell_executed":False,
                       "expires_epoch":now+65,
            "grant_id":"1"*32,"transport_id":"2"*32}
        self.sign()

    def tearDown(self):self.fixture.tearDown()

    def sign(self):
        self.proof["signature"]=base64.b64encode(
            self.key.sign(r.canonical({k:v for k,v in self.proof.items()
                                       if k!="signature"}))).decode()

    def validate(self):
        return e.verify_signed(self.index,self.proof,self.job,self.original)

    def test_dynamic_schema_independent_signature_verified(self):
        result=self.validate()
        self.assertEqual(result["state"],"SIGNED_INDEPENDENT_EVIDENCE_VERIFIED")
        self.assertFalse(result["original_313_certified"])
        self.assertFalse(result["central_receipt_created"])

    def test_untrusted_public_key_cannot_be_supplied_by_caller(self):
        with self.assertRaises(TypeError):
            e.verify_signed(self.index,self.proof,self.job,self.original,
                            "ATTACKER_CONTROLLED_PUBLIC_KEY")

    def test_signed_evidence_wrong_native_grant_denied(self):
        self.proof["payload"]["grant_id"]="3"*32
        self.proof["evidence_sha256"]=r.sha(r.canonical(self.proof["payload"]))
        self.sign()
        with self.assertRaisesRegex(r.RegistryDenied,"RECEIPT_BINDING_MISMATCH"):
            self.validate()

    def test_signed_evidence_wrong_postcondition_denied(self):
        self.proof["payload"]["expected_readback_sha256"]="0"*64
        self.proof["evidence_sha256"]=r.sha(r.canonical(self.proof["payload"]))
        self.sign()
        with self.assertRaisesRegex(r.RegistryDenied,"RECEIPT_BINDING_MISMATCH"):
            self.validate()

    def test_modified_payload_denied(self):
        self.proof["payload"]["file_count"]=5
        with self.assertRaisesRegex(r.RegistryDenied,"HASH_MISMATCH"):
            self.validate()

    def test_untrusted_signature_denied(self):
        self.proof["nonce"]="d"*32
        with self.assertRaisesRegex(r.RegistryDenied,"SIGNATURE_INVALID"):
            self.validate()

    def test_false_original_312_eligibility_denied(self):
        self.original["eligible"]=False
        with self.assertRaisesRegex(r.RegistryDenied,"JOB_SCOPE_NOT_VERIFIED"):
            self.validate()

    def test_different_environment_denied(self):
        self.original["environment"]="qa"
        with self.assertRaisesRegex(r.RegistryDenied,"JOB_SCOPE_NOT_VERIFIED"):
            self.validate()

    def test_false_result_status_denied(self):
        self.proof["payload"]["result_status"]="APPLIED"
        self.proof["evidence_sha256"]=r.sha(r.canonical(self.proof["payload"]))
        self.sign()
        with self.assertRaisesRegex(r.RegistryDenied,"RESULT_UNVERIFIED"):
            self.validate()

    def test_custom_provider_success_state_from_schema(self):
        schema_path=self.fixture.root/"code"/"schema.json"
        definition=json.loads(schema_path.read_text())
        definition["success_state"]="MY_PROVIDER_SUCCESS"
        schema_path.write_text(json.dumps(definition)+"\n")
        self.fixture.manifest["evidence"]["sha256"]=r.sha(schema_path.read_bytes())
        self.fixture.write_manifest()
        self.index=r.Registry(self.fixture.root)
        self.proof["payload"]["result_status"]="MY_PROVIDER_SUCCESS"
        self.proof["evidence_sha256"]=r.sha(r.canonical(self.proof["payload"]))
        self.sign()
        self.assertEqual(self.validate()["state"],"SIGNED_INDEPENDENT_EVIDENCE_VERIFIED")

    def test_unknown_evidence_field_denied(self):
        self.proof["payload"]["shell_command"]="rm -rf"
        self.proof["evidence_sha256"]=r.sha(r.canonical(self.proof["payload"]))
        self.sign()
        with self.assertRaisesRegex(r.RegistryDenied,"FIELDS_MISMATCH"):
            self.validate()

    def test_revoked_provider_digest_denied(self):
        (self.fixture.root/"code"/"schema.json").write_text("{}\n")
        with self.assertRaisesRegex(r.RegistryDenied,"SCHEMA_DIGEST_DRIFT"):
            self.validate()

    def test_proof_expired_denied(self):
        self.proof["issued_epoch"]-=250
        self.proof["expires_epoch"]-=250
        self.sign()
        with self.assertRaisesRegex(r.RegistryDenied,"PROOF_EXPIRED"):
            self.validate()

    def test_job_command_digest_mismatch_denied(self):
        self.job["command_sha256"]="f"*64
        with self.assertRaisesRegex(r.RegistryDenied,"JOB_SCOPE_NOT_VERIFIED"):
            self.validate()
