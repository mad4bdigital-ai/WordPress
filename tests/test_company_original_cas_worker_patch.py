"""Original 306 delta is eight *additive* lines, never runtime auto-install."""
from __future__ import annotations
import ast
import difflib
import importlib.util
from pathlib import Path
import unittest

SOURCE=Path(__file__).resolve().parents[1]/"ops/dynamic-operation-registry-v1/executor-time-cas-v1/integrations/patch_original306_provider_preclaim.py"
spec=importlib.util.spec_from_file_location("first_party_cas_worker_patcher",SOURCE)
patcher=importlib.util.module_from_spec(spec)
spec.loader.exec_module(patcher)
BASELINE="""
def dynamic_release_grant_ready(job,command_sha):
    try:
        if operation=='company.code.release':
            return company_code_release_grant_ready_legacy(job,command_sha)
        if operation!='plugin.license.activate':return False
        manifest=index.resolve(operation)
        return bool(row.get('vendor_entitlement_independently_verified',False))
    except Exception:
        return False
"""

class Original306WorkerPatch(unittest.TestCase):
 def test_exact_one_additive_hunk_only(self):
  before=BASELINE.splitlines(keepends=True)
  after=patcher.transform(BASELINE).splitlines(keepends=True)
  changes=[x for x in difflib.SequenceMatcher(
      a=before,b=after,autojunk=False).get_opcodes() if x[0]!='equal']
  self.assertEqual(len(changes),1)
  tag,i,j,k,l=changes[0]
  self.assertEqual(tag,'insert')
  self.assertEqual(l-k,8)
  self.assertEqual(after[:k]+after[l:],before)
  ast.parse(''.join(after))

 def test_no_known_static_new_operation_condition(self):
  after=patcher.transform(BASELINE)
  self.assertNotIn("if operation=='company.resource.cas.apply'",after)
  self.assertIn("manifest.get('preclaim')",after)

 def test_legacy_code_release_and_plugin_license_still_present(self):
  after=patcher.transform(BASELINE)
  self.assertIn("company_code_release_grant_ready_legacy",after)
  self.assertIn("vendor_entitlement_independently_verified",after)

 def test_previously_patched_source_refuses_repeat(self):
  with self.assertRaisesRegex(ValueError,"ALREADY_PATCHED"):
   patcher.transform(patcher.transform(BASELINE))

 def test_nonmatching_worker_source_refuses_without_fallback(self):
  with self.assertRaisesRegex(ValueError,"ANCHOR_NOT_FOUND"):
   patcher.transform("def dynamic_release_grant_ready():return False")

 def test_script_requires_pinned_input_and_output_path(self):
  source=SOURCE.read_text()
  self.assertIn("expected-sha256",source)
  self.assertIn("OUTPUT_ALREADY_EXISTS",source)
  self.assertNotIn("os.replace",source)

if __name__=="__main__":
 unittest.main()
