import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

SPEC=importlib.util.spec_from_file_location(
 "provenance",str(Path(__file__).resolve().parent.parent/'bin/company-redis-flush-provenance-v2.py'))
mod=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(mod)

def event(**changes):
 r={
  'contract':'mad4b.redis-flush-provenance.v2',
  'event_id':'a'*24,'timestamp_utc':'2026-11-08T02:40:00Z',
  'site':'tours-dev','execution':'wpcli','command':'eval-file',
  'script_basename':'mad4b-property-type-fix.php','caller_basename':'unknown','workflow':'unassigned',
  'successful':True,'frames':['object.lib.php:wp_cache_flush']
 }
 return r|changes

class ProvenanceTests(unittest.TestCase):
 def test_named_script_correlates_without_claiming_unique_causality(self):
  self.assertEqual(mod.attribution([event()],1,True,False),'PHP_FLUSH_CORRELATED_WITH_REDIS_COUNTER')
 def test_unexplained_flush_alerts(self):
  self.assertEqual(mod.attribution([],1,True,False),'UNATTRIBUTED_FLUSH_ALERT')
 def test_stdin_without_tag_alerts(self):
  self.assertEqual(mod.attribution([event(script_basename='stdin')],1,True,False),'FLUSH_SOURCE_UNRESOLVED_ALERT')
 def test_stdin_with_broker_workflow_correlates(self):
  self.assertEqual(mod.attribution([event(script_basename='stdin',workflow='content-bridge-verify')],1,True,False),'PHP_FLUSH_CORRELATED_WITH_REDIS_COUNTER')
 def test_partial_correlation_alerts(self):
  self.assertEqual(mod.attribution([event()],2,True,False),'UNATTRIBUTED_FLUSH_ALERT')
 def test_server_restart_rebaseline(self):
  self.assertEqual(mod.attribution([],None,True,True),'REDIS_RESTART_REBASELINE')
 def test_retains_origin_on_zero_counter_change(self):
  self.assertEqual(mod.attribution([event()],0,True,False),'PHP_FLUSH_REPORTED_WITHOUT_REDIS_DELTA')
 def test_never_archive_sensitive_values(self):
  r=mod.validate_event(event(secret='should_not_be_stored'), 'tours-dev')
  self.assertNotIn('secret',r)
  self.assertNotIn('should_not_be_stored',json.dumps(r))
 def test_rejects_foreign_site(self):
  self.assertRaises(ValueError,mod.validate_event,event(site='wayfatours'),'tours-dev')
 def test_rejects_frame_containing_full_path_or_token(self):
  self.assertRaises(ValueError,mod.validate_event,event(frames=['/home/admin/password=secret']),'tours-dev')
 def test_rejects_long_or_malformed_event(self):
  self.assertRaises(ValueError,mod.validate_event,event(event_id='bad'),'tours-dev')
 def test_atomic_archival_and_truncation(self):
  with tempfile.TemporaryDirectory() as tmp:
   base=Path(tmp);eventfile=base/'log.jsonl';eventfile.write_text(json.dumps(event())+'\n')
   original_sites=mod.SITES;original_dir=mod.DIR
   try:
    mod.SITES={'tours-dev':{'file':str(eventfile),'redis':'dummy'}}
    mod.DIR=base/'private-reports'
    got=mod.collect_events('tours-dev')
    self.assertEqual(len(got),1)
    self.assertEqual(eventfile.read_text(),'')
    archives=list((base/'private-reports/events').glob('*.jsonl'))
    self.assertEqual(len(archives),1)
    self.assertIn('mad4b-property-type-fix.php',archives[0].read_text())
   finally:
    mod.SITES=original_sites;mod.DIR=original_dir
 def test_invalid_log_not_truncated(self):
  with tempfile.TemporaryDirectory() as tmp:
   path=Path(tmp)/'bad.jsonl';path.write_text('{"raw_password":"hello"}\n')
   original_sites=mod.SITES
   try:
    mod.SITES={'tours-dev':{'file':str(path),'redis':'dummy'}}
    self.assertRaises(ValueError,mod.collect_events,'tours-dev')
    self.assertIn('raw_password',path.read_text())
   finally:mod.SITES=original_sites

if __name__=='__main__': unittest.main()
