#!/usr/bin/env python3
"""v119 hermetic hold-first root admission and crash/replay classification.

No installed signer, no real WordPress writes, no Owner Request and
no PrivateStore mutations. Broker-row fixture is synthetic and NEVER
represents independent first-party row provenance.
"""
from __future__ import annotations
import copy, importlib.util, os,sys,time,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
def load(path,name):
    spec=importlib.util.spec_from_file_location(name,path)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    return module
v119=load(ROOT/'ops/host-anchor-v119/first_anchor_runner.py','v119_gates')
fixture=load(ROOT/'tests/host-v95/test_original306_first_claim.py','v119_v95_fixture')
v118=load(ROOT/'ops/host-anchor-v118/queued_original_capture.py','v119_capture_fixture')

class FirstAnchorRootGateTests(unittest.TestCase):
    def setUp(self):
        self.t=int(time.time())
        self.fx=fixture.FirstClaimTests(
            methodName='test_exact_first_claim_is_non_authorizing')
        self.fx.setUp()
        self.fx.job['host_anchor_issuer_commissioned']=False
        self.fx.job['expires_epoch']=self.t+500
        self.fx.pending['expires_epoch']=self.t+500
        self.fx.request['expires_epoch']=self.t+500
        self.fx.site['anchor_readback'].update({
            'observed_epoch':self.t,'original313_certified':False,
            'readback_verified':False,'write_authorized':False
        })
        historical={
            'queued':copy.deepcopy(self.fx.job),
            'pending':[copy.deepcopy(self.fx.pending)],
            'request':copy.deepcopy(self.fx.request),
            'grant':copy.deepcopy(self.fx.grant),
            'parent':copy.deepcopy(self.fx.parent),
            'transport':copy.deepcopy(self.fx.transport),
            'site':copy.deepcopy(self.fx.site)
        }
        self.preimage={'contract':v118.CONTRACT,
            'job_id':self.fx.job['job_id'],
            'company':'all-royal-egypt','environment':'staging',
            'plan_sha256':self.fx.plan['plan_sha256'],
            'captured_epoch':self.t-5,
            'originals':historical,'issuer_execution_authorized':False,
            'anchor_issued':False}
        self.claimed=copy.deepcopy(self.fx.job)
        self.claimed.update({
           'state':'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
           'claimed_epoch':self.t-1,
           'independent_host_permission_verified':False
        })
        self.claim={
            'contract':'mad4b.central-vps-broker.v1',
            'job_id':self.claimed['job_id'],'host_id':'srv2030908',
            'nonce':'9'*32,'claimed_epoch':self.t-1,
            'state':self.claimed['state'],
            'production_mutation_performed':False,
        }
        self.broker_row={
            'job_id':self.claimed['job_id'],
            'command_sha256':self.claimed['command_sha256'],
            'target_id':'hosting-2','broker_host_id':'srv2030908',
            'company':'all-royal-egypt','environment':'staging',
            'site_uuid':self.claimed['site_uuid']
        }
        self.current={
            'job':copy.deepcopy(self.claimed),
            'claim':copy.deepcopy(self.claim),
            'request':copy.deepcopy(self.fx.request),
            'grant':copy.deepcopy(self.fx.grant),
            'parent':copy.deepcopy(self.fx.parent),
            'transport':copy.deepcopy(self.fx.transport),
            'site':copy.deepcopy(self.fx.site),
        }
    def verified(self):
        return v119.validate_readiness(
            self.fx.index,self.preimage,self.claimed,
            self.claim,self.broker_row,self.fx.plan,self.current,now=self.t)
    def test_exact_original305_306_readback_gates_without_signing(self):
        result=self.verified()
        self.assertEqual(result['state'],'CLAIM_READY_FOR_ROOT_313_ORIGINALS')
        self.assertTrue(result['original306_claim_matched'])
        self.assertFalse(result['anchor_issued'])
        self.assertFalse(result['execution_authorized'])
        self.assertFalse(result['issuer_commissioned'])
    def test_queued_cannot_be_faked_from_claimed(self):
        self.preimage['originals']['queued']['state']=self.claimed['state']
        with self.assertRaises((ValueError,TypeError)):
            self.verified()
    def test_historical_job_exactly_immutable_except_three_claim_fields(self):
        self.claimed['command_sha256']='a'*64
        self.current['job']=copy.deepcopy(self.claimed)
        with self.assertRaisesRegex(v119.FirstAnchorHeld,'MUTATED'):
            self.verified()
    def test_missing_independent_broker_row_never_signs(self):
        self.broker_row=None
        with self.assertRaisesRegex(v119.FirstAnchorHeld,'BROKER_ROW_MISSING'):
            self.verified()
    def test_wrong_broker_row_rejected_by_original_v86(self):
        self.broker_row['company']='wayfatours'
        with self.assertRaises(ValueError):
            self.verified()
    def test_expired_claim_and_owner_request_rejected(self):
        self.claim['claimed_epoch']=self.t-100
        self.claimed['claimed_epoch']=self.t-100
        self.current['job']=copy.deepcopy(self.claimed)
        self.current['claim']=copy.deepcopy(self.claim)
        with self.assertRaisesRegex(v119.FirstAnchorHeld,'NOT_CURRENT'):
            self.verified()
        self.claim['claimed_epoch']=self.t-1
        self.claimed['claimed_epoch']=self.t-1
        self.current['job']=copy.deepcopy(self.claimed)
        self.current['claim']=copy.deepcopy(self.claim)
        self.current['request']['expires_epoch']=self.t+2
        self.preimage['originals']['request']['expires_epoch']=self.t+2
        with self.assertRaisesRegex(v119.FirstAnchorHeld,'REQUEST_EXPIRED'):
            self.verified()
    def test_original328_and_original318_pristine_mandatory(self):
        self.current['site']['original328_active']=False
        with self.assertRaisesRegex(v119.FirstAnchorHeld,'PRISTINE'):
            self.verified()
        self.current['site']['original328_active']=True
        self.current['site']['anchor_readback']['revision']=1
        with self.assertRaisesRegex(v119.FirstAnchorHeld,'PRISTINE'):
            self.verified()
    def test_recovery_is_strictly_read_only_with_no_repeat_write(self):
        proof={
            'contract':v119.CONTRACT,
            'execution_authorized':False,
            'anchor_issued':False
        }
        state={k:False for k in (
            'enrollment','prewrite','signed_docs','revision','checkpoint','receipt')}
        live={'revision':0,'state':'MISSING_OR_INVALID',
              'verified':False,'write_authorized':False}
        self.assertEqual(v119.classify_recovery(proof,state,live),
            'READ_ONLY_READY_NO_ORIGINAL313_ENROLLMENT')
        state.update(enrollment=True,signed_docs=True,prewrite=True)
        self.assertEqual(v119.classify_recovery(proof,state,live),
            'PREWRITE_PERSISTED_REVERIFY_SIGNATURE_AND_EXPIRY_BEFORE_SINGLE_WRITE')
        live.update(revision=1,state='ACTIVE',verified=True)
        self.assertEqual(v119.classify_recovery(proof,state,live),
            'POSTWRITE_RECOVERY_ORIGINAL313_FINALIZE_ONLY_NO_REPEAT_WP_WRITE')
        state.update(revision=True,checkpoint=True,receipt=True)
        self.assertEqual(v119.classify_recovery(proof,state,live),
            'FINAL_RECEIPT_PRESENT_REQUIRE_INDEPENDENT_SIGNED_READBACK')
    def test_recovery_does_not_repair_missing_signed_prewrite(self):
        proof={'contract':v119.CONTRACT,'execution_authorized':False}
        state={k:False for k in (
            'enrollment','prewrite','signed_docs','revision','checkpoint','receipt')}
        live={'revision':1,'state':'ACTIVE','verified':True,'write_authorized':False}
        self.assertEqual(v119.classify_recovery(proof,state,live),
            'HOLD_ORIGINAL318_WRITE_WITHOUT_PRIOR_SIGNED_PREWRITE')
        live['write_authorized']=True
        self.assertEqual(v119.classify_recovery(proof,state,live),
            'HOLD_UNEXPECTED_WRITE_AUTHORITY')
    def test_current_live_entrypoint_only_inspects(self):
        original_src=(ROOT/'ops/host-anchor-v119/first_anchor_runner.py').read_text()
        self.assertNotIn('Ed25519PrivateKey',original_src)
        self.assertNotIn('anchor-reconcile-signed',original_src)
        self.assertNotIn('update_option(',original_src)
        with patch.object(v119,'pin',side_effect=v119.FirstAnchorHeld(
            'V119_SOURCE_PIN_DRIFT')):
            with self.assertRaisesRegex(v119.FirstAnchorHeld,'PIN_DRIFT'):
                v119.read_only_status(self.claimed['job_id'])

if __name__=='__main__':
    unittest.main(verbosity=2)
