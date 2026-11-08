import importlib.util
from pathlib import Path
import unittest
LOCAL=Path(__file__).resolve().parent.parent/'bin/company-redis-flush-provenance-v2.py'
if not LOCAL.is_file():
 LOCAL=Path('/opt/mad4b-company-provisioning/bin/company-redis-flush-provenance-v2.py')
spec=importlib.util.spec_from_file_location('m',LOCAL)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
def event(kind='purge_intent',successful=False):
 return {'contract':'mad4b.redis-flush-provenance.v2','event_id':'b'*24,
 'timestamp_utc':'2026-11-08T03:10:00Z','site':'tours-dev',
 'execution':'wpcli','command':'cache','script_basename':'unknown',
 'caller_basename':'purge.cls.php','workflow':'unassigned',
 'successful':successful,'event_kind':kind,'frames':['purge.cls.php:_purge_all_object']}
def stats(n):return {'run_id':'abcdef012345','flushdb_calls':n,'flushall_calls':0}
class DirectPurgeIntentTests(unittest.TestCase):
 def test_purge_intent_is_not_mislabeled_success(self):
  row=m.validate_event(event(), 'tours-dev')
  self.assertEqual(row['event_kind'],'purge_intent')
  self.assertFalse(row['successful'])
 def test_purge_intent_with_success_claim_rejected(self):
  with self.assertRaises(ValueError):m.validate_event(event(successful=True),'tours-dev')
 def test_unknown_event_kind_rejected(self):
  with self.assertRaises(ValueError):m.validate_event(event(kind='arbitrary'),'tours-dev')
 def test_direct_purge_intent_never_settles_unattributed_counter(self):
  old={'sites':{'tours-dev':stats(99)}}
  result=m.reconcile(old,'tours-dev',stats(100),[m.validate_event(event(),'tours-dev')])
  self.assertEqual(result['latest_activity'],'PENDING_ATTRIBUTION')
  self.assertEqual(result['purge_intent_count'],1)
  self.assertEqual(result['successful_php_flush_count'],0)
  next_=m.reconcile({'sites':{'tours-dev':result}},'tours-dev',stats(100),[])
  self.assertEqual(next_['status'],'UNATTRIBUTED_FLUSH_ALERT')
  self.assertEqual(next_['confirmed_unattributed_count'],1)
 def test_legacy_payload_still_accepted(self):
  raw=event(kind='cache_flush_result',successful=True);raw.pop('event_kind')
  self.assertEqual(m.validate_event(raw,'tours-dev')['event_kind'],'cache_flush_result')
if __name__=='__main__':unittest.main()
