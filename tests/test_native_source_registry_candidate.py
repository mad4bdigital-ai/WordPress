"""Original first-party Registry validator accepts the source-only manifest.

All files live in an ephemeral /tmp tree. Never updates installed registry.
"""
import base64
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import sys
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
H=ROOT/'ops/native-source-publisher-v1'
CORE=ROOT/'ops/company-code-release-v1/company_code_release.py'
REGISTRY=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled/registry.py')
spec=importlib.util.spec_from_file_location('_mad4b_registered_validator_offline',REGISTRY)
validator=importlib.util.module_from_spec(spec)
sys.modules[spec.name]=validator
spec.loader.exec_module(validator)

class RegistryCandidate(unittest.TestCase):
 def test_manifest_eligible_with_original_validator_and_isolated_index(self):
  with tempfile.TemporaryDirectory(prefix='mad4b-source-registry-fixture-') as tmp:
   base=Path(tmp)
   src={
    'native-source-publisher-v1/original_313_host_handler.py':
      H/'original_313_host_handler.py',
    'native-source-publisher-v1/original_313_independent_readback.py':
      H/'original_313_independent_readback.py',
    'native-source-publisher-v1/original_306_preclaim.py':
      H/'original_306_preclaim.py',
    'native-source-publisher-v1/original_313_source_finalizer.py':
      H/'original_313_source_finalizer.py',
    'executor-time-cas-v1/preclaim.py':
      ROOT/'ops/dynamic-operation-registry-v1/executor-time-cas-v1/preclaim.py',
    'native-source-publisher-v1/native_source_publisher.py':
      H/'native_source_publisher.py',
    'native-source-publisher-v1/operation_plan_association.py':
      H/'operation_plan_association.py',
    'company-code-release-v1/company_code_release.py':CORE,
    'dynamic-operation-registry-v1/providers/source.code.release/evidence-schema.json':
      H/'catalog/evidence-schema.json',
    'dynamic-operation-registry-v1/providers/source.code.release/manifest.json':
      H/'catalog/generated/source.code.release.manifest.json'
   }
   for rel,source in src.items():
    dst=base/rel
    dst.parent.mkdir(parents=True,exist_ok=True)
    shutil.copyfile(source,dst)
   manifest=base/'dynamic-operation-registry-v1/providers/source.code.release/manifest.json'
   index={
    'contract':'mad4b.dynamic-operation-index.v1',
    'installed_by_first_party':True,
    'independent_verifier_public_key_b64':base64.b64encode(b'\x08'*32).decode(),
    'providers':[{
      'manifest':'dynamic-operation-registry-v1/providers/source.code.release/manifest.json',
      'sha256':hashlib.sha256(manifest.read_bytes()).hexdigest()
    }]
   }
   (base/'index.json').write_text(json.dumps(index))
   reg=validator.Registry(base)
   provider=reg.resolve('source.code.release')
   self.assertEqual(provider['operation_id'],'source.code.release')
   self.assertEqual(provider['version'],1)
   self.assertEqual(reg.command_argv('source.code.release','a'*64),
     ['mad4b','native-source-publisher','apply','--plan_sha256='+'a'*64])
   self.assertEqual(reg.evidence_definition('source.code.release')['schema_id'],
                    'source_code_release.v1')
   # The fixture's claimed first-party status is sandbox only. No enrollment.
   self.assertNotEqual(base,
    Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled'))
if __name__=='__main__':
 unittest.main()
