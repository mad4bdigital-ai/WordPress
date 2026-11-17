#!/usr/bin/env python3
import copy,importlib.util,unittest,pathlib,tempfile,json,hashlib
p=pathlib.Path(__file__).with_name('company-reconcile-host-asset-observer.py')
s=importlib.util.spec_from_file_location('host_asset_observer',p)
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class Tests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();root=pathlib.Path(self.tmp.name)
  self.src=root/'source';self.dst=root/'tenant';
  for r in (self.src,self.dst):r.mkdir()
  self.id={'origin':'https://tours.dev.mad4b.com','site_uuid':'e5e64bca-ed62-48d2-b2b3-dff693cd702a','document_root':str(self.src)}
  self.site={'origin':'https://wayfatours.com','site_uuid':'a3a7cc36-db7e-4a14-8456-b41857def05c','document_root':str(self.dst),'company_code':'wayfatours','environment':'production'}
  self.policy={'contract':'mad4b.company-fx-distribution.v1','source':self.id,'targets':[self.site]}
  self.file='wp-content/plugins/mad4b-commerce/modules/presentation/my-asset.css'
  for root in (self.src,self.dst):
   p=root/self.file;p.parent.mkdir(parents=True);p.write_text('a')
 def tearDown(self):self.tmp.cleanup()
 def test_same_files(self):
  x=m.snapshot(self.policy,(self.file,));self.assertEqual(x['different_files'],0);self.assertFalse(x['full_wpcli_inventory_certified']);self.assertFalse(x['tenant_mutated'])
 def test_changed_file(self):
  (self.dst/self.file).write_text('b');x=m.snapshot(self.policy,(self.file,));self.assertEqual(x['different_files'],1)
 def test_missing_target_file(self):
  (self.dst/self.file).unlink();self.assertIsNone(m.snapshot(self.policy,(self.file,))['items'][0]['target_sha256'])
 def test_missing_source_fails(self):
  (self.src/self.file).unlink()
  with self.assertRaises(RuntimeError):m.snapshot(self.policy,(self.file,))
 def test_wrong_origin_fails(self):
  bad=copy.deepcopy(self.policy);bad['targets'][0]['origin']='https://other.example'
  with self.assertRaises(RuntimeError):m.snapshot(bad,(self.file,))
 def test_wrong_uuid_fails(self):
  bad=copy.deepcopy(self.policy);bad['source']['site_uuid']='bad'
  with self.assertRaises(RuntimeError):m.snapshot(bad,(self.file,))
 def test_missing_enrollment_fails(self):
  bad=copy.deepcopy(self.policy);bad['targets']=[]
  with self.assertRaises(RuntimeError):m.snapshot(bad,(self.file,))
 def test_duplicate_enrollment_fails(self):
  bad=copy.deepcopy(self.policy);bad['targets']=[self.site,self.site]
  with self.assertRaises(RuntimeError):m.snapshot(bad,(self.file,))
 def test_symlink_target_fails(self):
  dst=self.dst/self.file;dst.unlink();dst.symlink_to(self.src/self.file)
  with self.assertRaises(RuntimeError):m.snapshot(self.policy,(self.file,))
 def test_report_persists_no_authorization(self):
  report=pathlib.Path(self.tmp.name)/'output.json';data=m.snapshot(self.policy,(self.file,));m.publish(data,report)
  x=json.loads(report.read_text());self.assertEqual(x['jobs_created'],0);self.assertFalse(x['full_wpcli_inventory_certified'])
if __name__=='__main__':unittest.main(verbosity=2)
