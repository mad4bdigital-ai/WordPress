#!/usr/bin/env python3
"""Pure read-only priority admission tests; no WordPress/bootstrap/DB mutation."""
import importlib.util,json,subprocess,time,types,unittest
from pathlib import Path
from unittest.mock import patch
BASE=Path(__file__).resolve().parent

def load(name,file):
 spec=importlib.util.spec_from_file_location(name,BASE/file)
 module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
 return module

class PriorityAdmission(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.priority=load('original_signed_priority_test','wpcli-signed-priority.py')
  cls.scheduler=load('original_wpcli_scheduler_test','wpcli-site-scheduler.py')
 @staticmethod
 def serialized(job='a'*32,state='QUEUED_FOR_VPS_BROKER_CLAIM',expiry=2200,created=1000,**overrides):
  fields={'job_id':job,'state':state,'operation':'company.remote.wpcli.full',
    'target_scope':'wordpress','target_host_id':'srv2030908','broker_host_id':'srv2030908',
    'company':'wayfatours','site_uuid':'a3a7cc36-db7e-4a14-8456-b41857def05c',
    'command_sha256':'a'*64,'created_epoch':created,'expires_epoch':expiry}
  fields.update(overrides)
  out='a:'+str(len(fields))+':{'
  for key,value in fields.items():
   out+=f's:{len(key.encode())}:"{key}";'
   out+=(f'i:{value};' if type(value) is int else f's:{len(str(value).encode())}:"{value}";')
  return (out+'}').encode()
 def classify(self,blob,now=1200,host='srv2030908',job='a'*32):
  return self.priority.classify(blob,self.priority.PREFIX+job,now,host)
 def test_queued_original_job_reserves_priority(self):
  self.assertEqual(self.classify(self.serialized())['state'],self.priority.STATE_QUEUED)
 def test_claimed_original_job_reserves_priority(self):
  self.assertEqual(self.classify(self.serialized(state=self.priority.STATE_CLAIMED))['state'],self.priority.STATE_CLAIMED)
 def test_job_near_expiry_does_not_block_background(self):
  self.assertIsNone(self.classify(self.serialized(expiry=1550,state=self.priority.STATE_CLAIMED)))
 def test_expired_or_completed_cannot_block_background(self):
  self.assertIsNone(self.classify(self.serialized(expiry=1199)))
  self.assertIsNone(self.classify(self.serialized(state='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED')))
 def test_wrong_host_or_target_or_site_fails_closed(self):
  self.assertIsNone(self.classify(self.serialized(target_host_id='different-host')))
  self.assertIsNone(self.classify(self.serialized(site_uuid='invalid')))
  self.assertIsNone(self.classify(self.serialized(target_scope='shell')))
 def test_no_bogus_job_or_hash_deferral(self):
  self.assertIsNone(self.classify(self.serialized(job='b'*32)))
  self.assertIsNone(self.classify(self.serialized(command_sha256='not-a-sha')))
 def test_huge_or_invalid_window_not_deferred(self):
  self.assertIsNone(self.classify(self.serialized(expiry=6000)))
  self.assertIsNone(self.classify(self.serialized(created=1500)))
 def test_serialized_values_not_evaled(self):
  self.assertIsNone(self.classify(b'malicious-python-code'))
  self.assertIsNone(self.classify(self.serialized().replace(b's:13:"expires_epoch";i:2200;',b's:13:"expires_epoch";O:4:"Test":0:{};')))
 def test_scheduler_defers_before_running_subprocess(self):
  with patch.object(self.scheduler,'record') as report:
   self.assertTrue(self.scheduler.defer_if_signed_priority('mad4b-company-reconcile-inventory-refresh',probe=lambda:{'defer_optional':True,'jobs_detected':1}))
   self.assertEqual(report.call_args.kwargs['state'],'DEFERRED_FOR_ORIGINAL_SIGNED_WPCLI')
   self.assertFalse(self.scheduler.defer_if_signed_priority('mad4b-company-reconcile-inventory-refresh',probe=lambda:{'defer_optional':False}))
 def test_probe_fails_open_when_db_signal_unavailable(self):
  with patch.object(self.scheduler.subprocess,'run',side_effect=subprocess.TimeoutExpired('priority',18)):
   self.assertFalse(self.scheduler.priority_probe()['defer_optional'])
 def test_live_jobs_read_only_without_wordpress_bootstrap(self):
  blob=self.serialized().hex().upper()
  def fake_run(argv,**kwargs):
   if argv[0]=='/usr/bin/hostname':return types.SimpleNamespace(returncode=0,stdout='srv2030908\n',stderr='')
   self.assertEqual(argv[:3],['/usr/bin/docker','exec','db-test'])
   self.assertIn('SELECT option_name,HEX(option_value)',argv[-1])
   self.assertNotIn('/usr/local/bin/wp',' '.join(argv))
   return types.SimpleNamespace(returncode=0,stdout=self.priority.PREFIX+'a'*32+'\t'+blob+'\n',stderr='')
  with patch.object(self.priority,'enrolled_database',return_value='db-test'):
   result=self.priority.live_jobs(now=1200,run=fake_run)
  self.assertEqual(len(result),1)
 def test_optional_telemetry_gate_blocks_only_while_job_eligible(self):
  import io,contextlib,sys
  with patch.object(self.priority.os,'geteuid',return_value=0):
   with patch.object(sys,'argv',['priority','optional-admit']),patch.object(self.priority,'live_jobs',return_value=[{'job_id':'a'*32}]),contextlib.redirect_stdout(io.StringIO()):
    self.assertEqual(self.priority.main(),1)
   with patch.object(sys,'argv',['priority','optional-admit']),patch.object(self.priority,'live_jobs',return_value=[]),contextlib.redirect_stdout(io.StringIO()):
    self.assertEqual(self.priority.main(),0)
 def test_optional_telemetry_db_outage_does_not_suspend_monitoring(self):
  import io,contextlib,sys
  with patch.object(self.priority.os,'geteuid',return_value=0),patch.object(sys,'argv',['priority','optional-admit']),patch.object(self.priority,'live_jobs',side_effect=RuntimeError('CENTRAL_JOB_PRIORITY_DB_READ_UNAVAILABLE')),contextlib.redirect_stdout(io.StringIO()) as out:
   self.assertEqual(self.priority.main(),0)
   self.assertIn('PRIORITY_PROBE_UNAVAILABLE',out.getvalue())
 def test_root_bounded_job_scan(self):
  self.assertLessEqual(self.priority.MAX_JOBS,64)
  self.assertIn('expires_epoch',self.priority.classify.__code__.co_consts)

if __name__=='__main__':unittest.main()
