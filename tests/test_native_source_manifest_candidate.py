"""Candidate manifest matches SHA-pinned deps; not enrolled or signed."""
import importlib.util
import json
from pathlib import Path
import subprocess
import unittest
ROOT=Path(__file__).resolve().parents[1]
H=ROOT/'ops/native-source-publisher-v1'
spec=importlib.util.spec_from_file_location('mad4b_manifest_builder',H/'build_enrollment_candidate.py')
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
class ManifestCandidate(unittest.TestCase):
 def test_deterministic_first_party_source_manifest(self):
  m=json.loads((H/'catalog/manifest.candidate.json').read_text())
  self.assertEqual(m,mod.build())
  self.assertEqual(m['operation_id'],'source.code.release')
  self.assertEqual(m['plan_contract'],'mad4b.company-code-release.plan.v1')
  self.assertEqual(m['command']['prefix'],['mad4b','native-source-publisher','apply'])
  self.assertEqual(len(m['dependencies']),2)
  self.assertEqual({x['path'] for x in m['dependencies']},
    {'native-source-publisher-v1/native_source_publisher.py',
     'company-code-release-v1/company_code_release.py'})
  self.assertEqual(m['evidence_schema'],'source_code_release.v1')
  schema=json.loads((H/'catalog/evidence-schema.json').read_text())
  self.assertEqual(set(schema['fields']),{'result_status','readback_sha256',
       'expected_readback_sha256','grant_id','transport_id',
       'production_mutation','target_sha256','file_count'})
  self.assertEqual(schema['success_state'],'HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313')
 def test_sibling_index_is_a_proposal_only(self):
  p=json.loads((H/'catalog/publisher-enrollment-proposal.json').read_text())
  for key in ('publisher_enabled','registry_enrolled','production_writes_authorized'):
   self.assertFalse(p[key])
  r=subprocess.run(['python3',str(H/'native_source_publisher.py'),'status'],
      capture_output=True,text=True,timeout=10,check=True)
  self.assertIn('SOURCE_PUBLISHER_NOT_ENROLLED',r.stdout)
if __name__=='__main__':
 unittest.main()
