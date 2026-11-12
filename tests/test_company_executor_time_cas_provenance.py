import copy
import json
from pathlib import Path
import sys
import unittest

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from verify_company_executor_time_cas_v1 import check,Hold
DATA=json.loads((ROOT/'docs/release-coverage/company-executor-time-cas-v1-provenance-20261112.json').read_text())

class CASProvenance(unittest.TestCase):
 def test_source_verified_and_not_certified(self):
  row=check(DATA)
  self.assertFalse(row['original_313_certified'])
  self.assertEqual(row['target_files_verified'],2)
 def test_no_fabricated_original_authority(self):
  for name in ('original_dynamic_cas_provider_enrolled',
               'signed_specific_release_grant_created',
               'original_305_job_created_for_resource',
               'original_313_independent_signature_issued',
               'original_318_signed_release_certified'):
   with self.subTest(name=name),self.assertRaises(Hold):
    row=copy.deepcopy(DATA)
    row[name]=True
    check(row)
 def test_wrong_target_rejected(self):
  row=copy.deepcopy(DATA)
  row['tenant']='other.company'
  with self.assertRaises(Hold):check(row)
 def test_no_undocumented_extra_files(self):
  row=copy.deepcopy(DATA)
  row['files'].append(copy.deepcopy(row['files'][0]))
  with self.assertRaises(Hold):check(row)
 def test_each_component_digest_enforced(self):
  for idx in range(6):
   with self.subTest(index=idx),self.assertRaises(Hold):
    row=copy.deepcopy(DATA)
    row['files'][idx]['sha256']='0'*64
    check(row)
 def test_source_only_provider_not_confused_with_live(self):
  row=copy.deepcopy(DATA)
  row['files'][2]['deployed_to']=['central']
  with self.assertRaises(Hold):check(row)
 def test_no_commercial_field_sneaks_into_source(self):
  row=copy.deepcopy(DATA)
  row['tests']['new_cas_target_php']=999
  with self.assertRaises(Hold):check(row)

if __name__=='__main__':
 unittest.main()
