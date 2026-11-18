#!/usr/bin/env python3
"""Synthetic cryptographic regressions; never touches original keys or Jobs."""
from __future__ import annotations
import base64
import copy
import importlib.util
from pathlib import Path
import shutil
import sys
import tempfile
import time

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path(__file__).resolve().parents[2]
src=ROOT/'ops/dynamic-operation-registry-v1'
sys.path.insert(0,str(src))
import evidence
import registry

issuer=Ed25519PrivateKey.generate()
public=issuer.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
now=int(time.time())
testroot=Path(tempfile.mkdtemp(prefix='mad4b-original313-proof-test-'))
try:
    testroot.chmod(0o700)
    source=ROOT/'deploy/host-authority/v81/operation313_evidence.py'
    dest=testroot/'operation313_evidence.py'
    shutil.copyfile(source,dest)
    dest.chmod(0o600)
    evidence.HOST_V2_PROOF_VERIFIER=str(dest)
    gate=evidence._original_host_v2_proof_module()
    assert gate.CONTRACT=='mad4b.original313.host-anchor-operation-proof.v1'
    def gen(operation,*,rev_before=0,grant_revision=0):
        issue=operation==gate.ISSUE
        job={
          'operation_id':operation,'job_id':'1'*32,
          'company':'all-royal-egypt','environment':'staging',
          'site_uuid':'49c562d1-8f2f-456f-b454-26816c6ba4cb',
          'origin':'https://staging.allroyalegypt.com',
          'target_host_id':'hosting-2','ssh_principal':'u948953904',
          'command_sha256':'a'*64,'plan_sha256':'b'*64,
          'job_type':'signed_remote_hostinger_wpcli_v2',
          'principal_type':'signed_host_principal_v2',
          'state':'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
          'executed':False,
        }
        proof={
          'contract':gate.CONTRACT,'operation_id':operation,'job_id':job['job_id'],
          'company':job['company'],'environment':job['environment'],
          'site_uuid':job['site_uuid'],'origin':job['origin'],
          'target_host_id':job['target_host_id'],'ssh_principal':job['ssh_principal'],
          'command_sha256':job['command_sha256'],'plan_sha256':job['plan_sha256'],
          'grant_revision':grant_revision,'anchor_revision_before':rev_before,
          'anchor_revision_after':rev_before+1,'anchor_nonce':'2'*32,
          'reserved_job_id':job['job_id'],
          'result_state':gate.EXPECTED[operation],
          'original318_snapshot_sha256':'c'*64,'revision_event_sha256':'d'*64,
          'issued_epoch':now-1,'expires_epoch':now+60
        }
        proof['signature']=base64.b64encode(issuer.sign(
            gate.canonical({k:proof[k] for k in gate.FIELDS}))).decode()
        original312={
          'contract':(
            'mad4b.original312.host-principal.bootstrap-issue.v1' if rev_before==0 else
            'mad4b.original312.host-principal.exact-job.v2'),
          'job_id':job['job_id'],
          'command_sha256':job['command_sha256'],
          'signature_verified':True,
          'bootstrap_signature_verified':rev_before==0,
          'independent_authority_readback_matched':rev_before>0,
          'grant_revision':grant_revision,
        }
        readback={
          'source':'independent_original318_and_central_revision_ledger',
          'fresh_wp_readback':True,'fresh_central_highwater_verified':True,
          'original_reserved_job_id':job['job_id'],
          'original318_snapshot_sha256':proof['original318_snapshot_sha256'],
          'revision_event_sha256':proof['revision_event_sha256'],
          'anchor_revision':proof['anchor_revision_after'],
          'anchor_nonce':proof['anchor_nonce'],
          'company':job['company'],'environment':job['environment'],
          'site_uuid':job['site_uuid'],
          'anchor_signature_verified':issue,
          'anchor_current':issue,'anchor_revoked':not issue,
          'prior_grants_ineligible':not issue,
        }
        return proof,job,original312,readback
    tests=0
    def accept(p,j,o,r):
        global tests
        result=evidence._verify_host_v2_signed_operation_precondition(
            p,j,o,r,public)
        assert result['state']=='SIGNED_INDEPENDENT_HOST_OPERATION_PROOF_VERIFIED'
        assert result['original313_certified'] is False
        assert result['central_receipt_created'] is False
        assert result['execution_authorized'] is False
        tests+=1
    def deny(p,j,o,r):
        global tests
        try:evidence._verify_host_v2_signed_operation_precondition(p,j,o,r,public)
        except (ValueError,registry.RegistryDenied):
            tests+=1
        else:raise AssertionError('Expected evidence refusal')
    p,j,o,r=gen(gate.ISSUE)
    accept(p,j,o,r)
    q=copy.deepcopy(p);q['signature']='AA';deny(q,j,o,r)
    q=copy.deepcopy(p);q['result_state']='ANCHOR_REVOKED_AND_PRIOR_GRANTS_INELIGIBLE';deny(q,j,o,r)
    q=copy.deepcopy(p);q['plan_sha256']='e'*64;deny(q,j,o,r)
    q=copy.deepcopy(j);q['site_uuid']='f'*36;deny(p,q,o,r)
    q=copy.deepcopy(o);q['bootstrap_signature_verified']=False;deny(p,j,q,r)
    q=copy.deepcopy(r);q['fresh_central_highwater_verified']=False;deny(p,j,o,q)
    q=copy.deepcopy(r);q['anchor_signature_verified']=False;deny(p,j,o,q)
    q=copy.deepcopy(r);q['original_reserved_job_id']='0'*32;deny(p,j,o,q)
    q=copy.deepcopy(p);q['issued_epoch']=now-200;deny(q,j,o,r)
    p2,j2,o2,r2=gen(gate.REVOKE,rev_before=1,grant_revision=3)
    accept(p2,j2,o2,r2)
    q=copy.deepcopy(r2);q['prior_grants_ineligible']=False;deny(p2,j2,o2,q)
    q=copy.deepcopy(o2);q['independent_authority_readback_matched']=False;deny(p2,j2,q,r2)
    q=copy.deepcopy(p2);q['anchor_revision_after']=3;deny(q,j2,o2,r2)
    # The public wrapper must reject fabricated Registry wrappers.
    try:evidence.verify_host_v2_signed_operation(object(),p,j,o,r)
    except registry.RegistryDenied:tests+=1
    else:raise AssertionError('Synthetic Registry unexpectedly accepted')
    print({'state':'PASS','tests':tests,'signed_claimed_job_created':False,
           'original313_receipt_created':False,'production_mutated':False})
finally:
    shutil.rmtree(testroot)
