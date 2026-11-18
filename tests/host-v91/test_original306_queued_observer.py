"""Exact root Registry pending Host Anchor tests: no Broker Claim or signer."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import sys
import time
import unittest

ROOT=Path(__file__).resolve().parents[2]
SRC=ROOT/'ops/host-anchor-v91/original306_queued_observer.py'
RUNTIME=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
spec=importlib.util.spec_from_file_location('host_v91_queued',SRC)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
sys.path.insert(0,str(RUNTIME))
import registry

class HostQueuedTests(unittest.TestCase):
    def setUp(self):
        if not RUNTIME.is_dir():self.skipTest('Only an authorized root runtime has original Registry')
        self.index=registry.Registry(RUNTIME)
        self.operation=m.ISSUE
        gate=RUNTIME/'host-anchor-v2/operation_plan_contract.py'
        spec=importlib.util.spec_from_file_location('synthetic_v91_plan',gate)
        planner=importlib.util.module_from_spec(spec);spec.loader.exec_module(planner)
        self.plan={
            'contract':'mad4b.host-authority.anchor-issue.plan.v2',
            'operation_id':self.operation,'authority_mode':'bootstrap',
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
        self.plan['plan_sha256']=hashlib.sha256(planner.canonical(self.plan)).hexdigest()
        self.job={
            'contract':'mad4b.central-vps-broker.v1',
            'job_id':'3'*32,
            'company':self.plan['company'],
            'environment':self.plan['environment'],
            'site_uuid':self.plan['site_uuid'],
            'origin':self.plan['origin'],
            'target_host_id':self.plan['target_host_id'],
            'target_ssh_principal':self.plan['ssh_principal'],
            'broker_host_id':'srv2030908','operation':self.operation,
            'operation_id':self.operation,
            'job_type':'signed_remote_hostinger_wpcli_v2',
            'principal_type':'signed_host_principal_v2',
            'target_scope':'host','command_format':'host.anchor.ref.v2',
            'state':'QUEUED_FOR_VPS_BROKER_CLAIM',
            'executed':False,
            'expires_epoch':int(time.time())+600,
            'plan_sha256':self.plan['plan_sha256'],
            'command_sha256':self.index.command_sha256(
                self.operation,self.plan['plan_sha256']),
        }
        self.pending={k:self.job[k] for k in (
            'job_id','company','environment','site_uuid',
            'target_host_id','broker_host_id','expires_epoch')}

    def test_valid_queued_observed_but_never_claimed(self):
        result=m.inspect(self.index,self.pending,self.job,self.plan)
        self.assertEqual(result['job_id'],self.job['job_id'])
        self.assertTrue(result['root_provider_registered'])
        self.assertFalse(result['manifest_execution_enabled'])
        self.assertFalse(result['eligible_to_claim'])
        self.assertFalse(result['execution_authorized'])

    def test_tampered_broker_job_rejected(self):
        for field,change in (
            ('operation_id',m.REVOKE),
            ('operation',m.REVOKE),
            ('job_type','signed_remote_hostinger_wpcli'),
            ('principal_type','signed_hostinger_ssh_wpcli'),
            ('target_scope','wordpress'),
            ('command_format','wpcli.argv.json.v1'),
            ('command_sha256','f'*64),
            ('plan_sha256','f'*64),
            ('site_uuid','0'*36),
            ('origin','https://example.com'),
            ('target_host_id','hosting-3'),
            ('target_ssh_principal','root'),
            ('broker_host_id','hosting-2'),
            ('expires_epoch',int(time.time())+3),
            ('executed',True),
            ('state','BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'),
        ):
            with self.subTest(field=field):
                row=copy.deepcopy(self.job)
                row[field]=change
                with self.assertRaises(m.QueuedV2Denied):
                    m.inspect(self.index,self.pending,row,self.plan)

    def test_forged_pending_and_plan_rejected(self):
        for field,change in (
            ('job_id','4'*32),('company','egypt-tour-gates'),
            ('environment','production'),('site_uuid','5'*36),
            ('target_host_id','hosting-3'),
            ('expires_epoch',int(time.time())+1),
        ):
            with self.subTest(pending=field):
                pending=dict(self.pending);pending[field]=change
                with self.assertRaises(m.QueuedV2Denied):
                    m.inspect(self.index,pending,self.job,self.plan)
        for field,change in (
            ('operation_id',m.REVOKE),
            ('authority_mode','operational'),
            ('requested_anchor_revision',2),
            ('plan_sha256','f'*64),
            ('enrollment_nonce','x'*32),
        ):
            with self.subTest(plan=field):
                plan=dict(self.plan);plan[field]=change
                with self.assertRaises(ValueError):
                    m.inspect(self.index,self.pending,self.job,plan)

    def test_none_or_fake_registry_rejected(self):
        for fake in (None,{},object()):
            with self.subTest(fake=str(type(fake))):
                with self.assertRaises(m.QueuedV2Denied):
                    m.inspect(fake,self.pending,self.job,self.plan)

    def test_read_only_no_wp_or_key_write(self):
        code=SRC.read_text()
        for forbidden in ('sodium_crypto_sign_detached','Ed25519PrivateKey',
                          'update_option(','add_option(','sign_existing_original_306_claim(',
                          'subprocess.run(','os.system(','requests.post('):
            self.assertNotIn(forbidden,code)

if __name__=='__main__':unittest.main()
