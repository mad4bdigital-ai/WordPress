"""V2 source/live gates never infer Original certification from a PHP deploy."""
from __future__ import annotations
import copy,json,sys,unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from verify_company_original_cas_306313_v2 import verify,Refused
DATA=json.loads((ROOT/'docs/release-coverage/company-original306313-cas-v2-provenance-20261112.json').read_text())


class CASV2Provenance(unittest.TestCase):
 def test_exact_source_provenance_passes_unenrolled(self):
  result=verify(DATA)
  self.assertFalse(result['cas_provider_enrolled'])
  self.assertEqual(result['original_provider_source_files_verified'],12)

 def test_no_fabricated_original_signing_or_mutation(self):
  for field in (
    'live_central_cas_finalizer_ready',
    'cas_provider_original_registry_enrolled',
    'original306_live_worker_modified',
    'original313_host_finalizer_modified',
    'original318_signed_release_certified',
    'original313_cas_certificate_issued',
    'original305_cas_job_issued',
    'scoped_cas_grant_created',
    'other_companies_modified',
    'wayfatours_data_mutated'):
   with self.subTest(field=field),self.assertRaises(Refused):
    row=copy.deepcopy(DATA)
    row[field]=True
    verify(row)

 def test_missing_source_component_fails_closed(self):
  row=copy.deepcopy(DATA)
  row['source_files']=row['source_files'][:-1]
  with self.assertRaises(Refused):verify(row)

 def test_byte_digest_mismatch_fails(self):
  row=copy.deepcopy(DATA)
  row['source_files'][0]['sha256']='0'*64
  with self.assertRaises(Refused):verify(row)

 def test_forged_live_central_readback_fails(self):
  row=copy.deepcopy(DATA)
  row['legacy_code_release_central_operational_after']=False
  with self.assertRaises(Refused):verify(row)

 def test_source_cannot_claim_original_registry_upgrade_installed(self):
  row=copy.deepcopy(DATA)
  row['current_enrolled_operations']=4
  with self.assertRaises(Refused):verify(row)

if __name__=='__main__':
 unittest.main()
