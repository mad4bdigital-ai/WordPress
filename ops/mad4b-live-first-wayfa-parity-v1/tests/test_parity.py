import importlib.util
from pathlib import Path
import unittest
p=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("mad4b_live_first",p/"verify.py")
v=importlib.util.module_from_spec(spec);spec.loader.exec_module(v)
class LiveFirst(unittest.TestCase):
 def test_exact_package(self):
  self.assertEqual(v.verify("package")["files_checked"],7)
 def test_bound_to_two_distinct_sites(self):
  self.assertEqual(set(v.ROOTS),{"wayfa","toursdev"})
  self.assertNotEqual(v.ROOTS["wayfa"],v.ROOTS["toursdev"])
 def test_071_is_profile_certified_not_authorizing(self):
  d=v.verify("package")
  self.assertEqual(d["mcp_adapter"],"0.7.1")
  self.assertFalse(d["signed_release"])
