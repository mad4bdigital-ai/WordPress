import importlib.util
from pathlib import Path
import unittest
P=Path(__file__).resolve().parent.parent
spec=importlib.util.spec_from_file_location('patcher',P/'tools/patch_legacy_noop_guards.py')
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
class PatchTests(unittest.TestCase):
 def test_only_targeted_noop_calls_guarded(self):
  for name,(old,new) in mod.RULES.items():
   self.assertIn('wp_cache_flush()',old)
   self.assertIn('wp_cache_flush()',new)
   self.assertNotEqual(old,new)
   self.assertTrue(new.count('wp_cache_flush()')==1)
 def test_all_rules_are_idempotent(self):
  for name,(old,new) in mod.RULES.items():
   text='<?php\n'+old
   self.assertEqual(text.count(old),1)
   self.assertEqual(text.replace(old,new).count(new),1)
 def test_no_dynamic_shell(self):
  s=(P/'tools/patch_legacy_noop_guards.py').read_text()
  self.assertNotIn('subprocess',s)
  self.assertNotIn('eval(',s)
if __name__=='__main__':unittest.main()
