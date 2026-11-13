"""Source-only non-mutating negative tests for the enrolled remote read lane."""
import importlib.util
import time
from pathlib import Path
from unittest import TestCase, mock

WORKER = Path(__file__).resolve().parents[1]/'integrations/claimed-job-worker-postmeta-v6-code-release-merged.py'
SPEC = importlib.util.spec_from_file_location('reviewed_original_313_worker',WORKER)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)

class Remote306ClaimSourceScopeTests(TestCase):
    def setUp(self):
        self.job='a'*32
        self.site_uuid='11111111-2222-3333-4444-555555555555'
        self.scope='all-royal-egypt:staging'
        self.target={'transport_kind':'hostinger_ssh_wpcli',
                     'target_host_id':'hosting-3','site_uuid':self.site_uuid}
        self.entry={'company':'all-royal-egypt','environment':'staging',
                    'job_id':self.job,'broker_host_id':'srv2030908',
                    'target_host_id':'hosting-3','site_uuid':self.site_uuid,
                    'expires_epoch':int(time.time())+700}
        self.existing={'operation':'company.remote.wpcli.full',
                       'target_scope':'wordpress','command_format':'wpcli.argv.json.v1',
                       'job_type':'signed_remote_hostinger_wpcli',
                       'site_uuid':self.site_uuid,'state':'QUEUED_FOR_VPS_BROKER_CLAIM',
                       'command_sha256':MODULE.DIGEST,'sealed_argv_sha256':'b'*64}

    def run_claim(self,entry=None,existing=None,target=None):
        requested=self.entry if entry is None else entry
        job=self.existing if existing is None else existing
        binding=self.target if target is None else target
        with mock.patch.object(MODULE,'exact_host_targets',return_value={self.scope:binding}),\
             mock.patch.object(MODULE,'operation_digests',return_value={}),\
             mock.patch.object(MODULE,'cli_json',side_effect=[
                {'contract':'mad4b.central-vps-broker.v1','pending':[requested]},job]) as cli,\
             mock.patch.object(MODULE,'sign_existing_original_306_claim',return_value=self.job) as signer:
            result=MODULE.claim_authorized_job(17)
            return result,signer.call_count,cli.call_count

    def test_only_exact_remote_signed_read_can_request_original_claim(self):
        result,signs,calls=self.run_claim()
        self.assertEqual(result,self.job)
        self.assertEqual(signs,1)
        self.assertEqual(calls,2)

    def test_remote_writer_or_wrong_digest_never_signs(self):
        for key,value in (
            ('command_sha256','0'*64),
            ('job_type','signed_vps_wpcli'),
            ('sealed_argv_sha256','x'*64),
            ('sealed_argv_sha256',None),
            ('operation','content.write'),
            ('target_scope','host')):
            with self.subTest(key=key,value=value):
                result,signs,_=self.run_claim(existing={**self.existing,key:value})
                self.assertIsNone(result)
                self.assertEqual(signs,0)

    def test_unknown_target_or_short_lease_never_signs(self):
        cases=[
          ({**self.entry,'target_host_id':'hosting-9'},None),
          ({**self.entry,'expires_epoch':int(time.time())+100},None),
          ({**self.entry,'site_uuid':'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'},None),
          (self.entry,{**self.target,'target_host_id':'hosting-9'}),
        ]
        for entry,target in cases:
            with self.subTest(entry=entry,target=target):
                result,signs,_=self.run_claim(entry=entry,target=target)
                self.assertIsNone(result)
                self.assertEqual(signs,0)

    def test_claimed_remote_execution_lane_is_read_only_and_pinned(self):
        text=WORKER.read_text()
        for clause in (
            "if target_id not in ('srv2030908','hosting-2','hosting-3'):continue",
            "and v.get('transport_kind')=='hostinger_ssh_wpcli'",
            "and v.get('target_host_id')==target_id",
            "row.get('command_sha256')!=DIGEST",
            "(ROOT/'reserved'/(job+'.reserved')).exists()",
            "work.append((job,'read'))"):
            self.assertIn(clause,text)
