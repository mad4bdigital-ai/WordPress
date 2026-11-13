"""Source-only dynamic operation manifest generator; no code-defined operation list."""
from copy import deepcopy
import json
from pathlib import Path
import tempfile
import unittest
ROOT=Path(__file__).resolve().parents[1]
BASE=ROOT/'ops/native-source-publisher-v1'
import importlib.util
spec=importlib.util.spec_from_file_location('mad4b_dynamic_catalog_builder',BASE/'build_enrollment_candidate.py')
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)

class DynamicSourceManifest(unittest.TestCase):
 def test_first_source_is_canonical_dynamic_descriptor(self):
  candidates=mod.build_all()
  self.assertEqual(len(candidates),1)
  manifest=next(iter(candidates.values()))
  file=BASE/'catalog/generated'/f"{manifest['operation_id']}.manifest.json"
  self.assertEqual(json.loads(file.read_text()),manifest)
  self.assertEqual(manifest['source_family'],'mad4b.native-source-release.v2')
  self.assertEqual(manifest['approval_ability'],'mad4b/native-source-release')
  self.assertEqual(manifest['plan_contract'],'mad4b.company-code-release.plan.v1')
  self.assertEqual(manifest['hooks']['operation_association']['entrypoint'],
                   'exclusive_operation')
  self.assertEqual(manifest['hooks']['operation_association']['path'],
                   'native-source-publisher-v1/operation_plan_association.py')
  self.assertEqual({x['path'] for x in manifest['dependencies']},{
    'native-source-publisher-v1/native_source_publisher.py',
    'company-code-release-v1/company_code_release.py',
    'executor-time-cas-v1/preclaim.py'})
  self.assertEqual(manifest['preclaim']['path'],
                   'native-source-publisher-v1/original_306_preclaim.py')
  self.assertEqual(manifest['finalizer']['path'],
                   'native-source-publisher-v1/original_313_source_finalizer.py')
 def test_second_operation_registered_without_modifying_executable_code(self):
  with tempfile.TemporaryDirectory(prefix='mad4b-operation-discovery-') as tmp:
   folder=Path(tmp)
   original=json.loads((BASE/'catalog/operations/source.code.release.json').read_text())
   (folder/(original['operation_id']+'.json')).write_text(json.dumps(original))
   another=deepcopy(original)
   another['operation_id']='partner.content.deploy'
   another['provider']='partner-content-provider'
   another['approval_ability']='mad4b/partner-content-deploy'
   (folder/(another['operation_id']+'.json')).write_text(json.dumps(another))
   result=mod.build_all(folder)
   self.assertEqual(set(result),{original['operation_id'],another['operation_id']})
   self.assertEqual(result[another['operation_id']]['provider'],another['provider'])
   self.assertEqual(result[another['operation_id']]['command']['bound_operation_id'],
                    another['operation_id'])
 def test_malformed_and_name_mismatch_denied(self):
  with tempfile.TemporaryDirectory(prefix='mad4b-invalid-operation-') as tmp:
   folder=Path(tmp)
   bad=json.loads((BASE/'catalog/operations/source.code.release.json').read_text())
   (folder/'wrong.file.json').write_text(json.dumps(bad))
   with self.assertRaises(ValueError):mod.build_all(folder)
   (folder/'wrong.file.json').unlink()
   bad['command']['prefix']=['sh','-c','whoami']
   (folder/(bad['operation_id']+'.json')).write_text(json.dumps(bad))
   with self.assertRaises(ValueError):mod.build_all(folder)
 def test_evidence_schema_matches_first_party_contract(self):
  schema=json.loads((BASE/'catalog/evidence-schema.json').read_text())
  self.assertEqual(schema['schema_id'],'source_code_release.v1')
  self.assertTrue(schema['success_state'].startswith('HOST_READBACK_VERIFIED'))
  self.assertEqual(set(schema['fields']),{'result_status','readback_sha256',
    'expected_readback_sha256','grant_id','transport_id','production_mutation',
    'output_sha256',
    'target_sha256','file_count'})
 def test_no_registry_install_or_approval(self):
  props=json.loads((BASE/'catalog/publisher-enrollment-proposal.json').read_text())
  self.assertFalse(props['publisher_enabled'])
  self.assertFalse(props['registry_enrolled'])
  self.assertFalse(props['production_writes_authorized'])

if __name__=='__main__':unittest.main()
