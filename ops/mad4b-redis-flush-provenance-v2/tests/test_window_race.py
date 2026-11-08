import importlib.util
from pathlib import Path
import unittest

spec=importlib.util.spec_from_file_location('provenance',str(Path(__file__).resolve().parent.parent/'bin/company-redis-flush-provenance-v2.py'))
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
def stats(count=99,runid='abc123'):
 return {'run_id':runid,'flushdb_calls':count,'flushall_calls':0}
def event(script='mad4b-property-type-fix.php'):
 return {'timestamp_utc':'2026-11-08T03:00:00Z',
  'script_basename':script,'caller_basename':'unknown','workflow':'unassigned',
  'execution':'wpcli','command':'eval-file',
  'event_id':'a'*24,'successful':True}
def old(s):
 return {'sites':{'tours-dev':s}}

class RaceWindowTests(unittest.TestCase):
 def test_counter_precedes_delayed_provenance_event(self):
  first=m.reconcile(old(stats()),'tours-dev',stats(100),[])
  self.assertEqual(first['status'],'PENDING_ATTRIBUTION')
  next_=m.reconcile(old(first),'tours-dev',stats(100),[event()])
  self.assertEqual(next_['status'],'PHP_FLUSH_CORRELATED_WITH_REDIS_COUNTER')
  self.assertEqual(next_['pending_unattributed_delta'],0)
 def test_event_precedes_delayed_redis_counter(self):
  first=m.reconcile(old(stats()),'tours-dev',stats(),[event()])
  self.assertEqual(first['status'],'PENDING_COUNTER_CONFIRMATION')
  next_=m.reconcile(old(first),'tours-dev',stats(100),[])
  self.assertEqual(next_['status'],'PHP_FLUSH_CORRELATED_WITH_REDIS_COUNTER')
 def test_real_unattributed_flush_alerts_next_poll(self):
  first=m.reconcile(old(stats()),'tours-dev',stats(100),[])
  next_=m.reconcile(old(first),'tours-dev',stats(100),[])
  self.assertEqual(next_['status'],'UNATTRIBUTED_FLUSH_ALERT')
 def test_stdin_source_still_alerts_after_counter_match(self):
  r=m.reconcile(old(stats()),'tours-dev',stats(100),[event('stdin')])
  self.assertEqual(r['status'],'FLUSH_SOURCE_UNRESOLVED_ALERT')
 def test_restart_rebaselines_instead_of_mixing_events(self):
  first=m.reconcile(old(stats()),'tours-dev',stats(1,'new123'),[])
  self.assertEqual(first['status'],'REDIS_RESTART_REBASELINE')
 def test_matching_event_no_pending(self):
  r=m.reconcile(old(stats()),'tours-dev',stats(100),[event()])
  self.assertEqual(r['status'],'PHP_FLUSH_CORRELATED_WITH_REDIS_COUNTER')
  self.assertEqual(r['pending_successful_events'],[])
if __name__=='__main__': unittest.main()
