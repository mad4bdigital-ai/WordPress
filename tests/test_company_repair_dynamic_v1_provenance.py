import copy,json,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from verify_company_repair_dynamic_v1 import verify,Blocked
DOC=json.loads((ROOT/'docs/release-coverage/company-repair-jobs-dynamic-v1-provenance-20261111.json').read_text())
class RepairProvenance(unittest.TestCase):
 def test_sourced(self):
  out=verify(DOC)
  self.assertFalse(out['original_313_signed'])
  self.assertEqual(out['source_files'],5)
 def test_unauthorized_claims(self):
  keys=['scope_other_companies_touched','wayfa_target_wordpress_mutated',
   'full_clone_executed','native_305_job_submitted','original_313_exact_receipt_verified',
   'original_318_signed_release','native_grant_created']
  for field in keys:
   row=copy.deepcopy(DOC);row[field]=True
   with self.subTest(field=field),self.assertRaises(Blocked):verify(row)
 def test_site_identity(self):
  row=copy.deepcopy(DOC);row['wayfa_production_site_uuid']='unknown'
  with self.assertRaises(Blocked):verify(row)
 def test_source_hash(self):
  for i in range(5):
   row=copy.deepcopy(DOC);row['files'][i]['sha256']='0'*64
   with self.subTest(i=i),self.assertRaises(Blocked):verify(row)
 def test_fileset(self):
  row=copy.deepcopy(DOC);row['files'].pop()
  with self.assertRaises(Blocked):verify(row)
 def test_fake_provider(self):
  row=copy.deepcopy(DOC);row['pilot_provider_candidates']=1
  with self.assertRaises(Blocked):verify(row)
 def test_durable_counts(self):
  row=copy.deepcopy(DOC);row['repair_queue_not_created']=0
  with self.assertRaises(Blocked):verify(row)
if __name__=='__main__':unittest.main()
