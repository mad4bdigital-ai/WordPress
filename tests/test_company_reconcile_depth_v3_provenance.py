import copy,json,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from verify_company_reconcile_depth_v3 import verify,Refused
DOC=json.loads((ROOT/'docs/release-coverage/company-reconcile-depth-v3-provenance-20261111.json').read_text())
class Provenance(unittest.TestCase):
 def test_source(self):
  got=verify(DOC);self.assertEqual(got['incident_playbooks'],19)
  self.assertFalse(got['signed_313'])
 def test_cannot_forge_release_or_target_write(self):
  for key in ['original_318_signed','original_313_signed',
              'reconcile_execution_authorized','company_grant_issued',
              'wayfa_wordpress_mutated','other_companies_touched']:
   x=copy.deepcopy(DOC);x[key]=True
   with self.subTest(key=key),self.assertRaises(Refused):verify(x)
 def test_site_scope(self):
  x=copy.deepcopy(DOC);x['site_scope'].append('allroyalegypt.com')
  with self.assertRaises(Refused):verify(x)
 def test_incident_total_and_dependency_count(self):
  for key in ['incident_count','dependency_edges']:
   x=copy.deepcopy(DOC);x[key]+=1
   with self.subTest(key=key),self.assertRaises(Refused):verify(x)
 def test_file_hashes(self):
  for i in range(3):
   x=copy.deepcopy(DOC);x['files'][i]['sha256']='0'*64
   with self.subTest(i=i),self.assertRaises(Refused):verify(x)
 def test_unauthorized_target(self):
  x=copy.deepcopy(DOC);x['files'][0]['installed']='../../etc/passwd'
  with self.assertRaises(Refused):verify(x)
if __name__=='__main__':unittest.main()
