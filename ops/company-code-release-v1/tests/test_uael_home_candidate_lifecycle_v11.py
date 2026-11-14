#!/usr/bin/env python3
"""Hermetic negative/positive admission tests for native UAEL proof lifecycle."""
import builtins,datetime,hashlib,importlib.util,io,json,os,pathlib,sys,tempfile,time,unittest
from unittest import mock
SOURCE=pathlib.Path(__file__).resolve().parents[1]/'tools/uael-home-candidate-cycle-v1.py'
spec=importlib.util.spec_from_file_location('uael_cycle_v1',SOURCE);runner=importlib.util.module_from_spec(spec);spec.loader.exec_module(runner)
NOW=time.time();cases=0
class CycleSafety(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);p=pathlib.Path(self.tmp.name)
  self.root=p;self.old={x:getattr(runner,x)for x in ['M','C','B','LOCK','REPORT','CURRENT_SHA','run']}
  runner.M=p/'manifest.json';runner.C=p/'candidate.json';runner.B=p/'builder.php';runner.LOCK=str(p/'native.lock');runner.REPORT=p/'report.json';runner.B.write_text('native-builder-v1');runner.CURRENT_SHA=hashlib.sha256(runner.B.read_bytes()).hexdigest()
  self.options={'host':'https://wayfatours.com','mode':'on','epoch':'fresh','uael':'1.44.3','popup':41878}
  self.oldOpen=builtins.open
  def mocked_open(path,*args,**kwargs):
   if str(path)=='/proc/meminfo':return io.StringIO('MemAvailable: 12582912 kB\n')
   return self.oldOpen(path,*args,**kwargs)
  def stub(argv,timeout=65):
   if 'eval' in argv:return self.options
   if 'readback' in ' '.join(argv):return {'status':'READY','template_count':43}
   if 'eval-file' in argv:
    return {'ready':True,'candidate_only':True}
   raise AssertionError(('Unexpected command',argv))
  runner.run=stub
  self.patchers=[mock.patch('os.getloadavg',return_value=(0,0,0)),mock.patch('builtins.open',side_effect=mocked_open),mock.patch.object(sys,'argv',['script.py'])]
  for a in self.patchers:a.start()
  self.addCleanup(lambda:[a.stop()for a in reversed(self.patchers)])
  self.addCleanup(lambda:[setattr(runner,k,v)for k,v in self.old.items()]);self.count=0
 def document(self,epoch,age):return {'contract':'mad4b.uael-home.manifest.v1','site_home':'https://wayfatours.com','epoch':epoch,'uael_version':'1.44.3','generated_at':datetime.datetime.fromtimestamp(NOW-age*86400,datetime.timezone.utc).isoformat(),'ready':True,'issues':[],'checked_templates':43,'home_ids':{'en':35138,'es':43775},'popup_id':41878,'uael_css_size':751778,'uael_css_mtime':1788767549,'tree':[{'id':35138,'sha256':'real-graph'}]}
 def dump(self,live,candidate):
  runner.M.write_text(json.dumps(live));runner.C.write_text(json.dumps(candidate))
 def test_epoch_drift_never_promotes(self):
  self.dump(self.document('old',2),self.document('fresh',0));before=runner.M.read_bytes();self.assertEqual(runner.main(),0);self.assertEqual(runner.M.read_bytes(),before);self.assertEqual(json.loads(runner.REPORT.read_text())['state'],'candidate_ready_css_requalification_hold')
 def test_identical_expiring_proof_renews_before_deadline(self):
  base=self.document('fresh',5.8);candidate={**base,'generated_at':datetime.datetime.fromtimestamp(NOW,datetime.timezone.utc).isoformat()};self.dump(base,candidate);self.assertEqual(runner.main(),0);self.assertEqual(runner.M.read_bytes(),runner.C.read_bytes());self.assertEqual(json.loads(runner.REPORT.read_text())['state'],'ttl_proof_renewed_identical_graph')
 def test_expired_proof_never_autopromotes(self):
  base=self.document('fresh',7.25);candidate={**base,'generated_at':datetime.datetime.fromtimestamp(NOW,datetime.timezone.utc).isoformat()};self.dump(base,candidate);before=runner.M.read_bytes();self.assertEqual(runner.main(),0);self.assertEqual(runner.M.read_bytes(),before);self.assertEqual(json.loads(runner.REPORT.read_text())['state'],'candidate_ready_no_safe_promotion')
 def test_changed_graph_same_epoch_never_promotes(self):
  base=self.document('fresh',5.8);candidate=self.document('fresh',0);candidate['tree']=[{'id':35138,'sha256':'new-graph'}];self.dump(base,candidate);before=runner.M.read_bytes();self.assertEqual(runner.main(),0);self.assertEqual(runner.M.read_bytes(),before);self.assertEqual(json.loads(runner.REPORT.read_text())['state'],'candidate_ready_css_requalification_hold')
 def test_wrong_builder_hash_rejected(self):
  base=self.document('fresh',2);self.dump(base,self.document('fresh',0));runner.B.write_text('drift');before=runner.M.read_bytes()
  with self.assertRaisesRegex(RuntimeError,'BUILDER_SHA_DRIFT'):runner.main()
  self.assertEqual(runner.M.read_bytes(),before)
 def test_foreign_company_rejected(self):
  base=self.document('fresh',6);self.dump(base,self.document('fresh',0));self.options['host']='https://other.example';before=runner.M.read_bytes()
  with self.assertRaisesRegex(RuntimeError,'COMPANY_POLICY_NOT_ADMITTED'):runner.main()
  self.assertEqual(runner.M.read_bytes(),before)
if __name__=='__main__':unittest.main(verbosity=2)
