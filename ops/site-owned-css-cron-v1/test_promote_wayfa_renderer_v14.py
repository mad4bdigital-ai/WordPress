#!/usr/bin/env python3
"""Read-only v14 release regression tests; no WordPress job execution."""
import contextlib, importlib.util, json, pathlib, unittest
from unittest import mock
spec=importlib.util.spec_from_file_location('css_renderer_v14',pathlib.Path(__file__).with_name('promote_wayfa_renderer_v14.py'))
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class ReleaseV14(unittest.TestCase):
 def test_source_pins_and_renderer_fingerprints(self):
  info=m.validate_pins()
  self.assertEqual(info['site_id'],'wayfa')
  self.assertEqual(m.tree_digest(m.ACTIVE),m.OLD)
  self.assertEqual(m.tree_digest(m.STAGE),m.NEW)
  self.assertEqual(m.sha(m.CONSUMER),m.PHP)
 def test_read_only_preflight_does_not_change_certificate(self):
  before=(m.sha(m.CSS_MANIFEST),m.sha(m.PROFILE),m.tree_digest(m.ACTIVE))
  report=m.preflight()
  self.assertEqual(report['contract'],'mad4b.css.wayfa-v14-admission.v1')
  self.assertFalse(report['live_changed'])
  self.assertEqual(before,(m.sha(m.CSS_MANIFEST),m.sha(m.PROFILE),m.tree_digest(m.ACTIVE)))
 def test_oom_requires_holding_release(self):
  with mock.patch.object(m.os,'getloadavg',return_value=(2.,3.,4.)),mock.patch.object(m,'recent_oom',return_value=True),mock.patch.object(m,'healthy_site',return_value=True):
   report=m.preflight()
  self.assertFalse(report['admitted']);self.assertIn('kernel_oom_last_10_minutes',report['holds'])
 def test_overloaded_host_requires_holding_release(self):
  with mock.patch.object(m.os,'getloadavg',return_value=(16.,33.,4.)),mock.patch.object(m,'recent_oom',return_value=False),mock.patch.object(m,'healthy_site',return_value=True):
   report=m.preflight()
  self.assertFalse(report['admitted']);self.assertIn('cpu_load_threshold_exceeded',report['holds'])
 def test_website_not_healthy_requires_holding_release(self):
  with mock.patch.object(m.os,'getloadavg',return_value=(2.,3.,4.)),mock.patch.object(m,'recent_oom',return_value=False),mock.patch.object(m,'healthy_site',return_value=False):
   report=m.preflight()
  self.assertFalse(report['admitted']);self.assertIn('site_https_readback_failed',report['holds'])
 def test_mismatched_wayfa_php_pin_is_denied(self):
  with mock.patch.object(m,'sha',side_effect=lambda x: '0'*64 if x==m.CONSUMER else m.PHP):
   with self.assertRaises(ValueError):m.validate_pins()
 def test_rollback_is_refused_before_activation(self):
  if m.tree_digest(m.ACTIVE)!=m.OLD:self.skipTest('v14 already installed')
  with mock.patch.object(m,'release_locks',return_value=contextlib.nullcontext()):
   with self.assertRaises(ValueError):m.rollback()
if __name__=='__main__':unittest.main(verbosity=2)
