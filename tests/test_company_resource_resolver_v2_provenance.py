import copy,json,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from verify_company_resource_resolver_v2 import verify,Blocked,HISTORICAL_V2_COMMIT
DOC=json.loads((ROOT/'docs/release-coverage/company-resource-resolver-v2-provenance-20261111.json').read_text())
def historical_v2(doc):
 return verify(doc,historical_revision=HISTORICAL_V2_COMMIT)
class ResourceProvenance(unittest.TestCase):
 def test_exact_source_verified(self):
  result=historical_v2(DOC);self.assertFalse(result['original_313_signed'])
  self.assertTrue(result['policy_unchanged'])
  self.assertEqual(result['historical_commit_verified'],HISTORICAL_V2_COMMIT)
 def test_old_manifest_fails_against_new_current_sources(self):
  with self.assertRaises(Blocked):verify(DOC)
 def test_no_forged_original_authority(self):
  for name in ('native_305_job_issued','original_312_signed','original_313_signed',
    'original_318_signed_deployment','central_exact_resource_job_created'):
   row=copy.deepcopy(DOC);row[name]=True
   with self.subTest(name=name),self.assertRaises(Blocked):historical_v2(row)
 def test_no_extra_company(self):
  row=copy.deepcopy(DOC);row['target_observed']='othercompany.example'
  with self.assertRaises(Blocked):historical_v2(row)
 def test_source_files_hash(self):
  for index in range(4):
   row=copy.deepcopy(DOC);row['files'][index]['sha256']='0'*64
   with self.subTest(index=index),self.assertRaises(Blocked):historical_v2(row)
 def test_no_unreported_resource_job(self):
  row=copy.deepcopy(DOC);row['target_resource_child_journals']=1
  with self.assertRaises(Blocked):historical_v2(row)
 def test_no_false_provider_readiness(self):
  row=copy.deepcopy(DOC);row['target_resource_preview_state']='EXACT_RESOURCE_OBSERVED_UNSIGNED'
  with self.assertRaises(Blocked):historical_v2(row)
 def test_no_false_pagination(self):
  row=copy.deepcopy(DOC);row['resource_index_paged_at_scale']=True
  with self.assertRaises(Blocked):historical_v2(row)
if __name__=='__main__':unittest.main()
