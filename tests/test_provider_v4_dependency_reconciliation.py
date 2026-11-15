"""Hermetic-candidate tests against first-party installed v3; never writes it.

Optional live-registry fixture: CI without the original root enrollment skips.
"""
import importlib.util
import json
import shutil
import tempfile
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
INSTALLED=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
SOURCE=ROOT/'ops'
SCRIPT=ROOT/'ops/dynamic-operation-registry-v1/integrations/plan_provider_v4_dependency_reconciliation.py'
spec=importlib.util.spec_from_file_location('mad4b_provider_v4_candidate',SCRIPT)
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

@unittest.skipUnless((INSTALLED/'index.json').exists(),'No first-party enrolled root Registry fixture on this runner')
class CandidateUpgradeTests(unittest.TestCase):
    def setUp(self):
        m=json.loads((INSTALLED/'dynamic-operation-registry-v1/providers/company.code.release/manifest.json').read_text())
        if m['version']!=3:self.skipTest('Live original no longer on v3; do not test a downgrade')
        self.tmp=tempfile.TemporaryDirectory(prefix='mad4b-v4-plan-contract-')
        self.addCleanup(self.tmp.cleanup)
        self.directory=Path(self.tmp.name)
        self.before=mod.sha((INSTALLED/'index.json').read_bytes())
        self.candidate=self.directory/'candidate'

    def assert_live_unchanged(self):
        self.assertEqual(self.before,mod.sha((INSTALLED/'index.json').read_bytes()))
        self.assertEqual(json.loads((INSTALLED/'dynamic-operation-registry-v1/providers/company.code.release/manifest.json').read_text())['version'],3)

    def test_firstparty_sibling_repin_is_compatible_and_readonly(self):
        p=mod.generate(INSTALLED,SOURCE,self.candidate)
        self.assertEqual(p['state'],'CANDIDATE_REGISTRY_VALIDATED_NOT_ENROLLED')
        self.assertEqual(p['operations_changed'],['company.code.release','host.source.release'])
        self.assertEqual(p['operations_in_candidate'],sorted(p['operations_in_candidate']))
        self.assertEqual(len(p['sibling_dependency_repin']),1)
        self.assertIn('company-code-release-v1/company_code_release.py',p['sibling_dependency_repin'][0]['path'])
        self.assertFalse(p['grant_issued'])
        self.assertTrue(p['original_313_key_unchanged'])
        self.assertTrue(p['original_host312_key_unchanged'])
        self.assert_live_unchanged()

    def test_refuses_candidate_overwrite(self):
        self.candidate.mkdir()
        with self.assertRaisesRegex(RuntimeError,'CANDIDATE_EXISTS_DONT_OVERWRITE'):
            mod.generate(INSTALLED,SOURCE,self.candidate)
        self.assert_live_unchanged()

    def test_refuses_unpinned_changed_source_dependency(self):
        src=self.directory/'src';src.mkdir()
        mrel='dynamic-operation-registry-v1/providers/company.code.release/manifest.json'
        m=json.loads((SOURCE/mrel).read_text())
        for rel in [mrel,*[x['path'] for _,x in mod.components(m)]]:
            dest=src/rel;dest.parent.mkdir(parents=True,exist_ok=True)
            shutil.copy2(SOURCE/rel,dest)
        target=src/'company-code-release-v1/company_code_release.py'
        target.write_bytes(target.read_bytes()+b'\n# unreviewed drift\n')
        with self.assertRaisesRegex(RuntimeError,'SOURCE_V4_COMPONENT_UNPINNED'):
            mod.generate(INSTALLED,src,self.candidate)
        self.assertFalse(self.candidate.exists())
        self.assert_live_unchanged()

    def test_refuses_untrusted_pinned_manifest(self):
        src=self.directory/'src';src.mkdir()
        mrel='dynamic-operation-registry-v1/providers/company.code.release/manifest.json'
        dest=src/mrel;dest.parent.mkdir(parents=True,exist_ok=True)
        shutil.copy2(SOURCE/mrel,dest)
        dest.chmod(0o666)
        with self.assertRaisesRegex(RuntimeError,'SOURCE_NOT_ROOT_TRUSTED'):
            mod.generate(INSTALLED,src,self.candidate)
        self.assertFalse(self.candidate.exists())
        self.assert_live_unchanged()

if __name__=='__main__':unittest.main(verbosity=2)
