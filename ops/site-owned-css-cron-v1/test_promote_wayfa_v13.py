#!/usr/bin/env python3
"""Release preflight and rollback regression tests; never modify live sites."""
import importlib.util, json, pathlib, unittest
from unittest import mock
S=pathlib.Path(__file__).with_name('promote_wayfa_v13.py')
spec=importlib.util.spec_from_file_location('wayfa_v13_release',S)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class ReleaseGuards(unittest.TestCase):
 def test_real_environment_read_only_admission(self):
  before=m.sha(m.PLUGIN),m.sha(m.PROFILE),m.sha(m.BASE/'site-copy/wp-content/mad4b-critical-css-v1/manifest.json')
  report=m.preflight()
  self.assertEqual(report['contract'],'mad4b.css.wayfa-v13-admission.v1')
  self.assertFalse(report['live_changed'])
  self.assertEqual(before,(m.sha(m.PLUGIN),m.sha(m.PROFILE),m.sha(m.BASE/'site-copy/wp-content/mad4b-critical-css-v1/manifest.json')))
  self.assertIn('holds',report)
 def test_oom_blocks_even_under_normal_load(self):
  with mock.patch.object(m.os,'getloadavg',return_value=(1.,2.,3.)),\
       mock.patch.object(m,'oom_recent',return_value=True),\
       mock.patch.object(m,'healthy_http',return_value=True):
   report=m.preflight()
  self.assertFalse(report['admitted'])
  self.assertIn('kernel_oom_within_10_minutes',report['holds'])
 def test_cpu_budget_block(self):
  with mock.patch.object(m.os,'getloadavg',return_value=(17.,32.,4.)),\
       mock.patch.object(m,'oom_recent',return_value=False),\
       mock.patch.object(m,'healthy_http',return_value=True):
   report=m.preflight()
  self.assertFalse(report['admitted'])
  self.assertIn('host_load_above_budgets',report['holds'])
 def test_invalid_profile_blocks(self):
  with mock.patch.object(m.os,'getloadavg',return_value=(1.,2.,3.)),\
       mock.patch.object(m,'oom_recent',return_value=False),\
       mock.patch.object(m,'healthy_http',return_value=True),\
       mock.patch.object(m.PROFILE.__class__,'read_text',return_value=json.dumps({'site_id':'other','environment':'production'})):
   report=m.preflight()
  self.assertFalse(report['admitted'])
  self.assertIn('site_identity_mismatch',report['holds'])
 def test_rollback_not_allowed_without_release(self):
  # Rollback must reject before release; after release do not mutate live sites in a unit test.
  if m.sha(m.PLUGIN)!=m.OLD_PHP:self.skipTest('release already active')
  with self.assertRaises(ValueError):m.rollback_release()
 def test_consumer_hashes_pinned(self):
  self.assertIn(m.sha(m.PLUGIN),(m.OLD_PHP,m.NEW_PHP))
  self.assertEqual(m.sha(m.CANDIDATE),m.NEW_PHP)

if __name__=='__main__':unittest.main(verbosity=2)
