#!/usr/bin/env python3
"""Original305 exact owner -> Original306 signed claim only. No live writes."""
from __future__ import annotations
import copy
import hashlib
import importlib.util
from pathlib import Path
import time
import unittest
from unittest.mock import patch
import tempfile
import sys

ROOT=Path(__file__).resolve().parents[2]
RUNTIME=Path('/opt/mad4b-company-transport/native-313-independent')
REGISTRY=RUNTIME/'dynamic-operation-registry-v1/enrolled'
SOURCE=ROOT/'ops/host-anchor-v95/original306_claim_only.py'
PATCHER=ROOT/'ops/host-anchor-v95/patch_root_worker.py'
def load(path,name):
    sp=importlib.util.spec_from_file_location(name,path)
    module=importlib.util.module_from_spec(sp)
    sp.loader.exec_module(module)
    return module

m=load(SOURCE,'v95_claim_only')
p=load(PATCHER,'v95_worker_patch')

class FirstClaimTests(unittest.TestCase):
    def setUp(self):
        if not REGISTRY.exists(): self.skipTest('Requires root runtime')
        sys.path.insert(0,str(REGISTRY))
        import registry
        self.index=registry.Registry(REGISTRY)
        verifier=load(REGISTRY/'host-anchor-v2/operation_plan_contract.py',
                      'v95_source_plan')
        self.plan={
            'contract':'mad4b.host-authority.anchor-issue.plan.v2',
            'operation_id':m.ISSUE,'authority_mode':'bootstrap',
            'company':'all-royal-egypt','environment':'staging',
            'site_uuid':'49c562d1-8f2f-456f-b454-26816c6ba4cb',
            'origin':'https://staging.allroyalegypt.com',
            'target_host_id':'hosting-2','ssh_principal':'u948953904',
            'policy_sha256':'a'*64,'profile_digest':'b'*64,
            'host_key_sha256':'c'*64,'ssh_root_sha256':'d'*64,
            'registry_sha256':'e'*64,'grant_revision':0,
            'anchor_revision':0,'requested_anchor_revision':1,
            'enrollment_id':'1'*32,'enrollment_nonce':'2'*32,
        }
        self.plan['plan_sha256']=hashlib.sha256(
             verifier.canonical(self.plan)).hexdigest()
        self.job={
            'contract':'mad4b.central-vps-broker.v1',
            'job_id':'3'*32,
            'company':self.plan['company'],'environment':self.plan['environment'],
            'site_uuid':self.plan['site_uuid'],'origin':self.plan['origin'],
            'target_host_id':self.plan['target_host_id'],
            'target_ssh_principal':self.plan['ssh_principal'],
            'broker_host_id':'srv2030908','operation':m.ISSUE,'operation_id':m.ISSUE,
            'job_type':'signed_remote_hostinger_wpcli_v2',
            'principal_type':'signed_host_principal_v2',
            'target_scope':'host','command_format':'host.anchor.ref.v2',
            'state':'QUEUED_FOR_VPS_BROKER_CLAIM','executed':False,
            'expires_epoch':int(time.time())+600,
            'plan_sha256':self.plan['plan_sha256'],
            'command_sha256':self.index.command_sha256(
                  m.ISSUE,self.plan['plan_sha256']),
        }
        self.pending={k:self.job[k] for k in (
            'job_id','company','environment','site_uuid',
            'target_host_id','broker_host_id','expires_epoch')}
        self.job.update({
           'central_principal_id':59,
           'grant_id':'4'*32,
           'transport_id':'5'*32,
           'grant_mode':'persistent',
           'host_v2_bootstrap_only':True,
           'authority_mode':'bootstrap',
        })
        self.request={
           k:self.job[k] for k in (
            'company','environment','site_uuid','target_host_id',
            'target_ssh_principal','command_sha256','grant_id',
            'transport_id','central_principal_id','grant_mode',
            'operation','command_format')
        }
        self.request.update({
           'request_id':self.job['job_id'],
           'status':'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY',
           'execution_performed':False,
           'target_scope':'host',
           'host_v2_bootstrap_only':True,
           'host_anchor_identity':{
              'authority_mode':'bootstrap',
              'operation_id':m.ISSUE,
              'plan_sha256':self.job['plan_sha256'],
              'site_uuid':self.job['site_uuid'],
              'target_host_id':'hosting-2',
           }
        })
        self.grant={
           'grant_id':self.job['grant_id'],
           'state':'CENTRAL_OPERATION_SCOPE_GRANTED',
           'operation':m.ISSUE,
           'company':self.job['company'],
           'environment':self.job['environment'],
           'site_uuid':self.job['site_uuid'],
           'central_principal_id':59,
           'transport_id':self.job['transport_id'],
           'target_host_id':'hosting-2',
           'grant_mode':'persistent',
           'principal_type':'signed_host_principal_v2',
           'host_v2_bootstrap_only':True,
           'host_v2_execution_authorized':False,
           'original328_parent_grant_id':'6'*32,
        }
        self.parent={
           'grant_id':'6'*32,
           'company':self.job['company'],
           'environment':self.job['environment'],
           'site_uuid':self.job['site_uuid'],
           'central_principal_id':59,
           'transport_id':self.job['transport_id'],
           'operation':'company.remote.wpcli.full',
           'state':'CENTRAL_OPERATION_SCOPE_GRANTED'
        }
        self.transport={
           'transport_id':self.job['transport_id'],
           'company':self.job['company'],
           'environment':self.job['environment'],
           'site_uuid':self.job['site_uuid'],
           'host_id':'hosting-2',
           'state':'REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED',
        }
        self.site={
            'original328_active':True,
            'anchor_option_present':False,
            'anchor_readback':{
              'contract':'mad4b.original318.host-anchor-current-readback.v2',
              'source':'original318_fresh_wp_readback',
              'company':'all-royal-egypt','environment':'staging',
              'state':'MISSING_OR_INVALID','revision':0,
              'readback_verified':False,'write_authorized':False
            }
        }

    def approved(self):
        return m.verify_against_originals(
           self.index,self.pending,self.job,self.plan,self.request,
           self.grant,self.parent,self.transport,self.site)

    def test_exact_first_claim_is_non_authorizing(self):
        out=self.approved()
        self.assertEqual(out['job_id'],self.job['job_id'])
        self.assertTrue(out['non_authorizing_signed_broker_claim_allowed'])
        self.assertFalse(out['execution_authorized'])
        self.assertFalse(out['anchor_issued'])
        self.assertFalse(out['original313_certified'])

    def test_mutated_original_job_rejected(self):
        cases=(
          ('company','wayfatours'),('environment','production'),
          ('target_host_id','hosting-3'),('operation_id','company.host-authority.anchor.revoke'),
          ('operation','company.host-authority.anchor.revoke'),
          ('authority_mode','operational'),('host_v2_bootstrap_only',False),
          ('state','BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'),
          ('executed',True),('central_principal_id',0),
          ('command_sha256','f'*64),('job_id','9'*32),
          ('plan_sha256','e'*64),('site_uuid','a'*36),
        )
        for field,value in cases:
            with self.subTest(job=field):
                original=self.job[field]
                self.job[field]=value
                try:
                    with self.assertRaises((m.FirstClaimHold, ValueError)):
                        self.approved()
                finally:self.job[field]=original

    def test_owner_grant_and_parent_tamper_rejected(self):
        for obj,cases in (
          (self.request,{
              'request_id':'7'*32,'status':'REVOKED',
              'execution_performed':True,'host_v2_bootstrap_only':False,
              'target_scope':'wordpress','central_principal_id':32}),
          (self.grant,{
              'host_v2_execution_authorized':True,
              'host_v2_bootstrap_only':False,
              'original328_parent_grant_id':'8'*32,
              'central_principal_id':33,'operation':'company.remote.wpcli.full',
              'state':'REVOKED'}),
          (self.parent,{
              'operation':'maintenance.shell','state':'REVOKED',
              'site_uuid':'x'*36,
              'central_principal_id':33,'grant_id':'7'*32}),
          (self.transport,{
              'state':'PENDING_INDEPENDENT_SSH_VERIFICATION',
              'host_id':'hosting-3','site_uuid':'x'*36}),
        ):
            for field,changed in cases.items():
                with self.subTest(field=field):
                    before=obj[field];obj[field]=changed
                    try:
                        with self.assertRaises((m.FirstClaimHold, ValueError)):
                            self.approved()
                    finally:obj[field]=before

    def test_wp_anchor_or_revoked_transport_denies(self):
        for field,value in (
          ('original328_active',False),
          ('anchor_option_present',True),
        ):
            old=self.site[field]
            self.site[field]=value
            try:
                with self.assertRaises(m.FirstClaimHold):self.approved()
            finally:self.site[field]=old
        for field,value in (
          ('state','ACTIVE'),('revision',1),
          ('readback_verified',True),('write_authorized',True),
        ):
            readback=self.site['anchor_readback']
            old=readback[field];readback[field]=value
            try:
                with self.assertRaises(m.FirstClaimHold):self.approved()
            finally:readback[field]=old

    def test_existing_root_revision_enrollment_or_reservation_rejects(self):
        with tempfile.TemporaryDirectory(prefix='v95-root-') as d:
            root=Path(d)
            rev=root/'revisions';rev.mkdir()
            cp=root/'checkpoints';cp.mkdir()
            en=root/'enrollments';en.mkdir()
            rs=root/'reserved';rs.mkdir()
            with patch.object(m,'REVISION_ROOT',rev),patch.object(m,'CHECKPOINT_ROOT',cp),patch.object(m,'ENROLLMENT_ROOT',en),patch.object(m,'RESERVED_ROOT',rs):
                self.approved()
                for directory,path in (
                  (rev,'all-royal-egypt--staging.json'),
                  (cp,'all-royal-egypt--staging.json'),
                  (en,'all-royal-egypt--staging.json'),
                  (rs,self.job['job_id']+'.reserved'),
                ):
                    (directory/path).write_text('occupied')
                    with self.subTest(dir=directory):
                        with self.assertRaisesRegex(m.FirstClaimHold,
                            'ROOT_AUTHORITY_ALREADY_EXISTS'):
                            self.approved()
                    (directory/path).unlink()

    def test_worker_exact_preimage_cas_and_non_signer(self):
        worker=RUNTIME/'claimed-job-worker.py'
        before=worker.read_bytes()
        if hashlib.sha256(before).hexdigest()!=p.PREIMAGE_SHA256:
            # Live-first deployment preserves the byte-exact old Worker
            # under a root-only rollback artifact. Never patch v95 twice.
            backup=(Path('/opt/mad4b-company-provisioning/reports/'
                         'host-v95-original306-claim-only-live-20261118/'
                         'original306-worker-before-v95.py'))
            if not backup.exists():
                self.skipTest('Worker already migrated; preimage not available in CI')
            before=backup.read_bytes()
        self.assertEqual(hashlib.sha256(before).hexdigest(),p.PREIMAGE_SHA256)
        out=p.render(before)
        compile(out,'claimed-job-worker-v95.py','exec')
        self.assertEqual(out.count(b'firstparty_host_v95_first_claim_only'),2)
        self.assertIn(b'return sign_existing_original_306_claim(job,entry)',out)
        self.assertEqual(hashlib.sha256(SOURCE.read_bytes()).hexdigest(),
                         p.ROOT_MODULE_SHA256)
        self.assertNotIn(b'anchor_issued=True',out)
        with self.assertRaisesRegex(ValueError,'PREIMAGE_CHANGED'):
            p.render(b'injected arbitrary worker')

if __name__=='__main__':
    unittest.main()
