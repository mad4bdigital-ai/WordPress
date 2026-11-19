#!/usr/bin/env python3
"""Hermetic tests of live Original306 claim window and legacy remote safety."""
import hashlib
import importlib.util
import time
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
WORKER=Path('/opt/mad4b-company-transport/native-313-independent/claimed-job-worker.py')
PATCH=ROOT/'ops/host-anchor-v98/patch_claim_window.py'
def load_source(path,name,source=None):
    spec=importlib.util.spec_from_file_location(name,path)
    module=importlib.util.module_from_spec(spec)
    if source is None:
        spec.loader.exec_module(module)
    else:
        exec(compile(source,str(path),'exec'),module.__dict__)
    return module

patcher=load_source(PATCH,'patcher_v98')
BACKUP=Path('/opt/mad4b-company-provisioning/reports/'
            'host-v98-original306-first-anchor-ttl-live-20261118/'
            'claimed-job-worker-before-v98.py')
WORKER_V98_SHA='35947c92db959f4ed0a1416b96b10bd65e7cbb3dd4baf8d6af3f303e6ceef5b3'
PREIMAGE=(
    WORKER.read_bytes() if WORKER.exists() and
    hashlib.sha256(WORKER.read_bytes()).hexdigest()==patcher.PREIMAGE_SHA256
    else BACKUP.read_bytes() if BACKUP.exists() else None
)
CANDIDATE=patcher.render(PREIMAGE) if PREIMAGE is not None else None
worker=load_source(WORKER,'original306_worker_v98_test',CANDIDATE) if CANDIDATE else None
SITE='49c562d1-8f2f-456f-b454-26816c6ba4cb'
ISSUE='company.host-authority.anchor.issue'

class ClaimWindowTests(unittest.TestCase):
    def setUp(self):
        if worker is None:
            self.skipTest('Live first-party Worker preimage unavailable in CI; '
                          'Root live-first checks and patch safety remain mandatory')
        self.now=int(time.time())
        self.entry={
            'job_id':'1'*32,'company':'all-royal-egypt','environment':'staging',
            'site_uuid':SITE,'broker_host_id':'srv2030908',
            'target_host_id':'hosting-2',
            'expires_epoch':self.now+300,
        }
        self.job={
            'operation':ISSUE,'operation_id':ISSUE,
            'target_scope':'host','command_format':'host.anchor.ref.v2',
            'job_type':'signed_remote_hostinger_wpcli_v2',
            'site_uuid':SITE,'state':'QUEUED_FOR_VPS_BROKER_CLAIM',
            'command_sha256':'b'*64,
        }
        self.targets={'all-royal-egypt:staging':{
            'site_uuid':SITE,
            'transport_kind':'hostinger_ssh_wpcli',
            'target_host_id':'hosting-2'
        }}
        self.claimed=[]
        self.reads=[]
        self.owner_ready=True
        def mock_cli(argv,**kwargs):
            if 'pending' in argv:
                return {'contract':'mad4b.central-vps-broker.v1',
                        'pending':[dict(self.entry)]}
            if 'eval' in argv:
                self.reads.append(list(argv))
                return dict(self.job)
            raise AssertionError('Unexpected original306 CLI call')
        self.mock_cli=mock_cli

    def run_queue(self):
        with (
            patch.object(worker,'cli_json',side_effect=self.mock_cli),
            patch.object(worker,'exact_host_targets',return_value=self.targets),
            patch.object(worker,'operation_digests',return_value={}),
            patch.object(worker,'firstparty_host_v95_first_claim_only',
                         side_effect=lambda actor,entry:self.owner_ready),
            patch.object(worker,'firstparty_host_v2_pending_observation',
                         side_effect=lambda *args:{'state':'HELD'}),
            patch.object(worker,'sign_existing_original_306_claim',
                         side_effect=lambda jid,entry:self.claimed.append(jid) or jid)
        ):
            return worker.claim_authorized_job(59)

    def test_first_staging_anchor_300s_can_reach_signed_claim_only(self):
        self.assertEqual(self.run_queue(),'1'*32)
        self.assertEqual(self.claimed,['1'*32])
        self.assertEqual(len(self.reads),1)
    def test_owner_328_plan_not_ready_still_never_claims(self):
        self.owner_ready=False
        self.assertIsNone(self.run_queue())
        self.assertEqual(self.claimed,[])
    def test_anchor_under_120s_rejected_before_read(self):
        self.entry['expires_epoch']=self.now+90
        self.assertIsNone(self.run_queue())
        self.assertEqual(self.reads,[])
    def test_remote_generic_300s_still_requires_360(self):
        self.job.update(operation='company.remote.wpcli.full',
                        target_scope='wordpress',
                        command_format='wpcli.argv.json.v1',
                        job_type='signed_remote_hostinger_wpcli',
                        command_sha256=worker.DIGEST,
                        sealed_argv_sha256='e'*64)
        self.assertIsNone(self.run_queue())
        self.assertEqual(self.claimed,[])
    def test_remote_generic_420s_keeps_legacy_claim(self):
        self.job.update(operation='company.remote.wpcli.full',
                        target_scope='wordpress',
                        command_format='wpcli.argv.json.v1',
                        job_type='signed_remote_hostinger_wpcli',
                        command_sha256=worker.DIGEST,
                        sealed_argv_sha256='e'*64)
        self.entry['expires_epoch']=self.now+420
        self.assertEqual(self.run_queue(),'1'*32)
        self.assertEqual(len(self.claimed),1)
    def test_other_company_scope_cannot_get_first_anchor_window(self):
        self.entry.update(company='egypt-tour-gates',environment='staging')
        self.targets={'egypt-tour-gates:staging':dict(self.targets['all-royal-egypt:staging'])}
        self.assertIsNone(self.run_queue())
        self.assertEqual(self.claimed,[])
    def test_wrong_original_host_and_site_never_claims(self):
        for field,changed in (('target_host_id','hosting-3'),('site_uuid','0'*36)):
            prior=self.entry[field];self.entry[field]=changed
            try:
                self.assertIsNone(self.run_queue())
                self.assertEqual(self.claimed,[])
            finally:self.entry[field]=prior
    def test_expected_exact_cas_and_no_execution_enable(self):
        self.assertEqual(hashlib.sha256(PREIMAGE).hexdigest(),patcher.PREIMAGE_SHA256)
        if WORKER.exists():
            live_sha=hashlib.sha256(WORKER.read_bytes()).hexdigest()
            self.assertIn(live_sha,(patcher.PREIMAGE_SHA256,WORKER_V98_SHA))
        self.assertIn(b'first_anchor_scope',CANDIDATE)
        self.assertIn(b'if int(entry.get(\'expires_epoch\') or 0)<time.time()+360:continue',CANDIDATE)
        with self.assertRaisesRegex(ValueError,'PREIMAGE_CHANGED'):
            patcher.render(b'drifted')
        self.assertNotIn(b'ORIGINAL312_ISSUER_COMMISSIONED=True',CANDIDATE)

class SourcePatchContractTests(unittest.TestCase):
    def test_source_contract_usable_without_live_vps(self):
        # This unit runs in CI without private Root Worker files.
        self.assertEqual(len(patcher.PREIMAGE_SHA256),64)
        self.assertIn("first_anchor_scope",patcher.AFTER)
        self.assertIn("min_lifetime=(120 if first_anchor_scope",patcher.AFTER)
        self.assertIn("time.time()+360:continue",patcher.AFTER_REMOTE)
        self.assertIn("if first_anchor_scope and firstparty_host_v95",patcher.AFTER_ANCHOR)
        self.assertNotIn("execution_enabled=True",patcher.AFTER)
        with self.assertRaisesRegex(ValueError,'PREIMAGE_CHANGED'):
            patcher.render(b'not the enrolled Original306 worker')

if __name__=='__main__':
    unittest.main(verbosity=2)
