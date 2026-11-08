import importlib.util,unittest
from pathlib import Path
P=Path(__file__).resolve().parent.parent/'bin/company-redis-flush-provenance-v2.py'
if not P.is_file():
 P=Path('/opt/mad4b-company-provisioning/bin/company-redis-flush-provenance-v2.py')
spec=importlib.util.spec_from_file_location('incident',P)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
def stats(count=99,runid='abcdef012345'):
 return {'run_id':runid,'flushdb_calls':count,'flushall_calls':0}
def event(script='property-fix.php'):
 return {'timestamp_utc':'2026-11-08T03:00:00Z','script_basename':script,
 'caller_basename':'unknown','workflow':'unassigned','execution':'wpcli',
 'command':'eval-file','event_id':'a'*24,'successful':True}
def old(s):return {'sites':{'tours-dev':s}}
class IncidentLedgerTests(unittest.TestCase):
 def test_pending_reconciles_in_next_poll(self):
  one=m.reconcile(old(stats()),'tours-dev',stats(100),[])
  self.assertEqual(one['status'],'PENDING_ATTRIBUTION')
  two=m.reconcile(old(one),'tours-dev',stats(100),[event()])
  self.assertEqual(two['status'],'PHP_FLUSH_CORRELATED_WITH_REDIS_COUNTER')
  self.assertEqual(two['confirmed_unattributed_count'],0)
 def test_unexplained_promoted_to_persistent_alert_after_one_poll(self):
  one=m.reconcile(old(stats()),'tours-dev',stats(100),[])
  two=m.reconcile(old(one),'tours-dev',stats(100),[])
  self.assertEqual(two['status'],'UNATTRIBUTED_FLUSH_ALERT')
  self.assertEqual(two['pending_unattributed_delta'],0)
  self.assertEqual(two['confirmed_unattributed_count'],1)
 def test_legacy_alert_is_migrated_without_accidental_clearing(self):
  previous={**stats(102),'pending_unattributed_delta':3,
    'pending_successful_events':[],'status':'UNATTRIBUTED_FLUSH_ALERT'}
  result=m.reconcile(old(previous),'tours-dev',stats(102),[])
  self.assertEqual(result['confirmed_unattributed_count'],3)
  self.assertEqual(result['status'],'UNATTRIBUTED_FLUSH_ALERT')
  later=m.reconcile(old(result),'tours-dev',stats(102),[event()])
  self.assertEqual(later['confirmed_unattributed_count'],3)
  self.assertEqual(later['status'],'UNATTRIBUTED_FLUSH_ALERT')
  self.assertEqual(later['latest_activity'],'PENDING_COUNTER_CONFIRMATION')
 def test_new_named_counter_never_rewrites_old_incident(self):
  prior={**stats(102),'confirmed_unattributed_count':3,
    'pending_unattributed_delta':0,'pending_attribution_polls':0}
  after=m.reconcile(old(prior),'tours-dev',stats(103),[event()])
  self.assertEqual(after['confirmed_unattributed_count'],3)
  self.assertEqual(after['latest_activity'],'PHP_FLUSH_CORRELATED_WITH_REDIS_COUNTER')
  self.assertEqual(after['status'],'UNATTRIBUTED_FLUSH_ALERT')
 def test_alert_survives_redis_restart(self):
  prior={**stats(102),'confirmed_unattributed_count':3,
    'pending_unattributed_delta':0,'pending_attribution_polls':0}
  r=m.reconcile(old(prior),'tours-dev',stats(0,'different_runid'),[])
  self.assertEqual(r['status'],'UNATTRIBUTED_FLUSH_ALERT')
  self.assertEqual(r['confirmed_unattributed_count'],3)
 def test_event_before_counter_window(self):
  first=m.reconcile(old(stats()),'tours-dev',stats(),[event()])
  self.assertEqual(first['latest_activity'],'PENDING_COUNTER_CONFIRMATION')
  second=m.reconcile(old(first),'tours-dev',stats(100),[])
  self.assertEqual(second['status'],'PHP_FLUSH_CORRELATED_WITH_REDIS_COUNTER')
 def test_untagged_stdin_remains_an_alert(self):
  r=m.reconcile(old(stats()),'tours-dev',stats(100),[event('stdin')])
  self.assertEqual(r['status'],'FLUSH_SOURCE_UNRESOLVED_ALERT')
if __name__=='__main__':unittest.main()
