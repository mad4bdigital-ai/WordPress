import copy,json,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from verify_company_reconcile_start_v2 import verify,Refused
DOC=json.loads((ROOT/'docs/release-coverage/company-reconcile-start-v2-20261111.json').read_text())
class ContractTests(unittest.TestCase):
 def test_source(self):
  r=verify(DOC);self.assertEqual(r['files'],6);self.assertFalse(r['original_313_signed'])
 def test_no_forged_authority(self):
  for field in ('original_313_postcondition_signed','original_318_release_signed',
                'native_grant_issued','full_clone_executed','company_resource_created','other_companies_modified'):
   row=copy.deepcopy(DOC);row[field]=True
   with self.subTest(field=field),self.assertRaises(Refused):verify(row)
 def test_scope(self):
  row=copy.deepcopy(DOC);row['verified_runtime_target']='other.example'
  with self.assertRaises(Refused):verify(row)
 def test_digest_tamper(self):
  for i in range(6):
   row=copy.deepcopy(DOC);row['files'][i]['sha256']='0'*64
   with self.subTest(i=i),self.assertRaises(Refused):verify(row)
 def test_missing_file(self):
  row=copy.deepcopy(DOC);row['files'].pop()
  with self.assertRaises(Refused):verify(row)
if __name__=='__main__':unittest.main()
