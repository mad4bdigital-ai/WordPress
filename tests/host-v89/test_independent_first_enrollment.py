#!/usr/bin/env python3
"""Original first-anchor issuer: synthetic crypto only, no live original keys."""
from __future__ import annotations
import base64
import copy
import importlib.util
from pathlib import Path
import time
import unittest
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path(__file__).resolve().parents[2]
SOURCE=ROOT/"ops/host-anchor-v89/first_anchor_original312.py"
spec=importlib.util.spec_from_file_location("original_v89_first",SOURCE)
v89=importlib.util.module_from_spec(spec);spec.loader.exec_module(v89)


def pub(key):
    return key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)


class OriginalFirstAnchorTests(unittest.TestCase):
    def setUp(self):
        self.now=int(time.time())
        self.broker=Ed25519PrivateKey.generate()
        self.independent=Ed25519PrivateKey.generate()
        self.host_source=Ed25519PrivateKey.generate()
        self.op=v89.OPER
        self.job={
            "operation_id":self.op,
            "job_id":"1"*32,
            "company":"all-royal-egypt",
            "environment":"staging",
            "site_uuid":"49c562d1-8f2f-456f-b454-26816c6ba4cb",
            "origin":"https://staging.allroyalegypt.com",
            "target_host_id":"hosting-2",
            "target_ssh_principal":"u948953904",
            "job_type":"signed_remote_hostinger_wpcli_v2",
            "principal_type":"signed_host_principal_v2",
            "state":"BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY",
            "executed":False,
            "plan_sha256":"a"*64,
            "command_sha256":"b"*64,
            "expires_epoch":self.now+110,
        }
        self.document={
            "contract":v89.CONTRACT,
            "source":v89.SOURCE,
            "company":self.job["company"],
            "environment":self.job["environment"],
            "site_uuid":self.job["site_uuid"],
            "origin":self.job["origin"],
            "target_host_id":self.job["target_host_id"],
            "ssh_principal":self.job["target_ssh_principal"],
            "enrollment_id":"2"*32,
            "nonce":"3"*32,
            "original306_job_id":self.job["job_id"],
            "plan_sha256":self.job["plan_sha256"],
            "command_sha256":self.job["command_sha256"],
            "current_anchor_revision":0,
            "current_anchor_state":"NOT_ENROLLED",
            "nonce_unspent":True,
            "signed_site_identity_verified":True,
            "remote_host_identity_verified":True,
            "independent_issuer_verified":True,
            "owner_scope_authorized":True,
            "enrollment_revoked":False,
            "issued_epoch":self.now-5,
            "expires_epoch":self.now+90,
        }
        self.sign(self.document)
        self.plan={
            "operation_id":self.op,
            "authority_mode":"bootstrap",
            "plan_sha256":self.job["plan_sha256"],
            "grant_revision":0,
            "anchor_revision":0,
            "requested_anchor_revision":1,
            "enrollment_id":self.document["enrollment_id"],
            "enrollment_nonce":self.document["nonce"],
        }
        class Index:pass
        self.index=Index()
        self.index.host_anchor_312_public_key=pub(self.broker)
        self.index.independent_verifier_public_key=pub(self.independent)
        self.index.host_resource_312_public_key=pub(self.host_source)

    def sign(self,doc):
        doc['signature']=base64.b64encode(
            self.independent.sign(v89.enrollment_message(doc))
        ).decode()

    def authorized(self,doc=None,job=None):
        return v89.verify_enrollment(
            self.document if doc is None else doc,
            self.index.independent_verifier_public_key,
            self.job if job is None else job,now=self.now)

    def test_valid_independent_313_signed_first_enrollment(self):
        verified=self.authorized()
        self.assertTrue(verified['independent_enrollment_signature_verified'])
        self.assertTrue(verified['owner_scope_authorized'])
        self.assertEqual(verified['current_anchor_revision'],0)

    def test_first_312_bootstrap_signature_is_real_but_nonauthorizing(self):
        verified=self.authorized()
        proof=v89.assemble_signed_bootstrap(
            self.index,self.job,self.plan,verified,self.broker,now=self.now)
        self.assertEqual(proof['requested_anchor_revision'],1)
        self.assertEqual(proof['contract'],
                         'mad4b.original312.host-principal.bootstrap-issue.v1')
        self.assertEqual(proof['job_id'],self.job['job_id'])
        self.assertIn('signature',proof)
        self.assertNotIn('grant_created',proof)
        self.assertNotIn('original313_certified',proof)

    def test_enrollment_claim_field_tampering_rejected(self):
        cases=(
            ('company','egypt-tour-gates'),
            ('site_uuid','1'*36),
            ('nonce','4'*32),
            ('original306_job_id','5'*32),
            ('plan_sha256','e'*64),
            ('command_sha256','f'*64),
            ('owner_scope_authorized',False),
            ('independent_issuer_verified',False),
            ('enrollment_revoked',True),
            ('current_anchor_revision',2),
            ('current_anchor_state','ACTIVE'),
            ('nonce_unspent',False),
            ('issued_epoch',self.now-500),
            ('expires_epoch',self.now-1),
        )
        for field,changed in cases:
            with self.subTest(field=field):
                doc=copy.deepcopy(self.document)
                doc[field]=changed
                with self.assertRaises(v89.BootstrapIssuerHold):
                    self.authorized(doc=doc)

    def test_forged_broker_signature_rejected(self):
        doc=copy.deepcopy(self.document)
        doc['signature']=base64.b64encode(
            self.broker.sign(v89.enrollment_message(doc))).decode()
        with self.assertRaisesRegex(v89.BootstrapIssuerHold,
                                    'INDEPENDENT313_SIGNATURE_INVALID'):
            self.authorized(doc=doc)

    def test_signed_but_wrong_original_job_rejected(self):
        doc=copy.deepcopy(self.document)
        doc['company']='egypt-tour-gates'
        self.sign(doc)
        with self.assertRaisesRegex(v89.BootstrapIssuerHold,
                                    'ORIGINAL_JOB_SCOPE_CHANGED'):
            self.authorized(doc=doc)

    def test_independent_313_key_not_original312(self):
        e=self.authorized()
        self.index.host_anchor_312_public_key=pub(self.independent)
        with self.assertRaisesRegex(v89.BootstrapIssuerHold,
                                    'SIGNER_NOT_THE_PINNED_BROKER'):
            v89.assemble_signed_bootstrap(
                self.index,self.job,self.plan,e,self.broker,now=self.now)

    def test_host_source_signer_cannot_issue_first_anchor(self):
        e=self.authorized()
        self.index.host_anchor_312_public_key=pub(self.host_source)
        with self.assertRaisesRegex(v89.BootstrapIssuerHold,
                                    'SIGNER_NOT_THE_PINNED_BROKER'):
            v89.assemble_signed_bootstrap(
                self.index,self.job,self.plan,e,self.broker,now=self.now)

    def test_first_anchor_cannot_be_revoke_or_rotation(self):
        e=self.authorized()
        for field,value in (
            ('authority_mode','operational'),
            ('anchor_revision',1),
            ('grant_revision',1),
            ('requested_anchor_revision',2),
            ('enrollment_id','4'*32),
            ('enrollment_nonce','4'*32),
        ):
            plan=dict(self.plan)
            plan[field]=value
            with self.subTest(field=field), self.assertRaises(v89.BootstrapIssuerHold):
                v89.assemble_signed_bootstrap(
                    self.index,self.job,plan,e,self.broker,now=self.now)

    def test_existing_independent_revision_blocks_first_bootstrap_replay(self):
        from tempfile import TemporaryDirectory
        from unittest.mock import patch
        with TemporaryDirectory(prefix='original-host-v89-replay-') as raw:
            root=Path(raw)
            rev=root/'revisions';rev.mkdir(mode=0o700)
            reserved=root/'reserved';reserved.mkdir(mode=0o700)
            rev.chmod(0o700)
            with patch.object(v89,'REVISION_ROOT',rev), patch.object(
                    v89,'ROOT_RESERVED',reserved):
                v89._original_first_anchor_unspent(self.job)
                (rev/'all-royal-egypt--staging.json').write_text('{"revision":1}')
                with self.assertRaisesRegex(v89.BootstrapIssuerHold,
                                             'ALREADY_HAS_INDEPENDENT_REVISION'):
                    v89._original_first_anchor_unspent(self.job)
                (rev/'all-royal-egypt--staging.json').unlink()
                (reserved/(self.job['job_id']+'.reserved')).write_text('reserved')
                with self.assertRaisesRegex(v89.BootstrapIssuerHold,
                                             'ALREADY_RESERVED'):
                    v89._original_first_anchor_unspent(self.job)

    def test_code_source_pins_are_live_independent_and_fixed(self):
        import hashlib
        for filename, pin in (
            (v89.CLASSIFIER_SOURCE,v89.CLASSIFIER_SHA256),
            (v89.BOOTSTRAP_SOURCE,v89.BOOTSTRAP_SHA256),
            (v89.ORIGINAL_SIGNER_SOURCE,v89.ORIGINAL_SIGNER_SHA256),
        ):
            if not filename.is_file():
                self.skipTest('Only root runtime has installed signer sources')
            self.assertEqual(hashlib.sha256(filename.read_bytes()).hexdigest(),pin)

    def test_no_live_enrollment_can_be_inferred_from_registry(self):
        import sys
        sys.path.insert(0,str(Path(
            '/opt/mad4b-company-transport/native-313-independent/'
            'dynamic-operation-registry-v1/enrolled')))
        import registry
        real=Path('/opt/mad4b-company-transport/native-313-independent/'
                  'dynamic-operation-registry-v1/enrolled')
        if not real.exists():self.skipTest('No live root runtime in isolated CI')
        index=registry.Registry(real)
        with self.assertRaises(v89.BootstrapIssuerHold):
            v89.read_first_party_enrollment(index,self.job)


if __name__=='__main__':
    unittest.main()
