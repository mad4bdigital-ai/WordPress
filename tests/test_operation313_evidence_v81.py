#!/usr/bin/env python3
import base64
import importlib.util
import pathlib
import sys
import time
import unittest
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives import serialization

f=pathlib.Path(__file__).resolve().parents[1]/"deploy/host-authority/v81/operation313_evidence.py"
spec=importlib.util.spec_from_file_location("evidence81",f)
m=importlib.util.module_from_spec(spec);sys.modules[spec.name]=m;spec.loader.exec_module(m)

class Test(unittest.TestCase):
    def setUp(self):
        now=int(time.time())
        self.sk=Ed25519PrivateKey.generate()
        self.pk=self.sk.public_key().public_bytes(
            encoding=serialization.Encoding.Raw,
            format=serialization.PublicFormat.Raw)
        self.job=dict(job_id="a"*32,operation_id=m.ISSUE,
            company="all-royal-egypt",environment="production",
            site_uuid="0384b6ce-4004-4b4e-b701-7d77cb6f6ff0",
            origin="https://www.allroyalegypt.com",
            target_host_id="hosting-2",ssh_principal="u948953904",
            command_sha256="b"*64,plan_sha256="c"*64,
            job_type="signed_remote_hostinger_wpcli_v2",
            principal_type="signed_host_principal_v2",executed=False)
        self.proof={k:self.job[k] for k in ("job_id","operation_id",
            "company","environment","site_uuid","origin","target_host_id",
            "ssh_principal","command_sha256","plan_sha256")}
        self.proof.update(contract=m.CONTRACT,grant_revision=2,
            anchor_revision_before=3,anchor_revision_after=4,
            anchor_nonce="d"*32,reserved_job_id=self.job["job_id"],
            result_state=m.EXPECTED[m.ISSUE],
            original318_snapshot_sha256="e"*64,revision_event_sha256="f"*64,
            issued_epoch=now,expires_epoch=now+90)
        self.original312=dict(contract="mad4b.original312.host-principal.exact-job.v2",
            job_id=self.job["job_id"],command_sha256=self.job["command_sha256"],
            signature_verified=True,independent_authority_readback_matched=True,
            grant_revision=2)
        self.readback=dict(source="independent_original318_and_central_revision_ledger",
            fresh_wp_readback=True,fresh_central_highwater_verified=True,
            original_reserved_job_id=self.job["job_id"],
            original318_snapshot_sha256="e"*64,revision_event_sha256="f"*64,
            anchor_revision=4,anchor_nonce="d"*32,
            company=self.job["company"],environment=self.job["environment"],
            site_uuid=self.job["site_uuid"],
            anchor_signature_verified=True,anchor_current=True,
            anchor_revoked=False,prior_grants_ineligible=False)
        self.sign()
    def sign(self):
        self.proof["signature"]=base64.b64encode(self.sk.sign(
            m.canonical({k:self.proof[k] for k in m.FIELDS}))).decode()
    def verify(self):
        return m.verify_independent_operation_proof(
            self.proof,self.pk,self.job,self.original312,self.readback)
    def denied(self):
        with self.assertRaises(m.EvidenceDenied):self.verify()
    def test_valid_issue_proof_not_central_receipt(self):
        result=self.verify()
        self.assertTrue(result["signed_independent_evidence_verified"])
        self.assertFalse(result["central_receipt_created"])
        self.assertFalse(result["original313_certified"])
    def test_wrong_signer(self):
        other=Ed25519PrivateKey.generate()
        self.pk=other.public_key().public_bytes(
            encoding=serialization.Encoding.Raw,
            format=serialization.PublicFormat.Raw)
        self.denied()
    def test_tamper_signed_job(self):
        self.proof["plan_sha256"]="1"*64;self.denied()
    def test_same_signed_proof_wrong_job(self):
        self.job["company"]="another-company";self.denied()
    def test_legacy_job_not_eligible(self):
        self.job["job_type"]="signed_vps_wpcli";self.denied()
    def test_no_original312_sig(self):
        self.original312["signature_verified"]=False;self.denied()
    def test_original312_grant_drift(self):
        self.original312["grant_revision"]=3;self.denied()
    def test_ledger_missing(self):
        self.readback["fresh_central_highwater_verified"]=False;self.denied()
    def test_stale_anchor(self):
        self.readback["anchor_revision"]=3;self.denied()
    def test_signed_handler_success_not_enough(self):
        self.readback["source"]="handler_report_only";self.denied()
    def test_issue_revoked(self):
        self.readback["anchor_revoked"]=True;self.denied()
    def test_valid_revoke_proof_not_receipt(self):
        self.proof["operation_id"]=m.REVOKE
        self.proof["result_state"]=m.EXPECTED[m.REVOKE]
        self.job["operation_id"]=m.REVOKE
        self.readback.update(anchor_current=False,anchor_revoked=True,
                             prior_grants_ineligible=True)
        self.sign()
        self.assertFalse(self.verify()["central_receipt_created"])
    def test_revoke_without_prior_grant_invalidation(self):
        self.proof["operation_id"]=m.REVOKE
        self.proof["result_state"]=m.EXPECTED[m.REVOKE]
        self.job["operation_id"]=m.REVOKE
        self.readback.update(anchor_current=False,anchor_revoked=True,
                             prior_grants_ineligible=False)
        self.sign();self.denied()
    def test_first_anchor_bootstrap_purpose(self):
        self.proof.update(grant_revision=0,anchor_revision_before=0,
                          anchor_revision_after=1)
        self.readback["anchor_revision"]=1
        self.original312.update(contract="mad4b.original312.host-principal.bootstrap-issue.v1",
                                bootstrap_signature_verified=True)
        self.sign()
        self.assertTrue(self.verify()["signed_independent_evidence_verified"])
    def test_bootstrap_revoke_forbidden(self):
        self.proof.update(grant_revision=0,anchor_revision_before=0,
                          anchor_revision_after=1,operation_id=m.REVOKE,
                          result_state=m.EXPECTED[m.REVOKE])
        self.job["operation_id"]=m.REVOKE
        self.readback.update(anchor_current=False,anchor_revoked=True,
                             prior_grants_ineligible=True,anchor_revision=1)
        self.original312.update(contract="mad4b.original312.host-principal.bootstrap-issue.v1",
                                bootstrap_signature_verified=True)
        self.sign();self.denied()
    def test_skipped_revision_rejected(self):
        self.proof["anchor_revision_after"]=5;self.sign();self.denied()
    def test_expired_signature(self):
        self.proof["issued_epoch"]-=300;self.proof["expires_epoch"]-=300
        self.sign();self.denied()
if __name__=="__main__":unittest.main(verbosity=2)
