import importlib.util,json,tempfile,unittest
from pathlib import Path
p=Path(__file__).with_name('integration.py')
spec=importlib.util.spec_from_file_location('governance_candidate',p)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class CoverageTests(unittest.TestCase):
 def test_all_sources_registered(self):
  self.assertEqual(len(m.SOURCES),10)
  self.assertEqual(len(set(m.SOURCES)),10)
 def test_original_broker_blocker_is_dependency(self):
  for code in ('NO_CURRENT_EXACT_ELIGIBLE_JOB','REMOTE_EXECUTOR_NOT_ENROLLED_FOR_TARGET'):
   d=m.classify(code)
   self.assertEqual(d['category'],'ORIGINAL_DEPENDENCY')
   self.assertNotIn('REPLAN',d.get('action',''))
 def test_denied_grant_never_replans(self):
  self.assertEqual(m.classify('ORIGINAL_GRANT_REVOKED_OR_CHANGED')['category'],'ORIGINAL_AUTHORITY_DENIAL')
 def test_unclassified_malformed_state(self):
  self.assertEqual(m.classify('not safe text')['category'],'UNCLASSIFIED')
 def test_after_attempt_transport_requires_receipt(self):
  self.assertEqual(m.classify('REMOTE_TRANSPORT_FAILED',True)['category'],'EFFECT_UNCERTAIN')
 def test_lifecycle_states_are_observed_without_execution(self):
  with tempfile.TemporaryDirectory() as td:
   old=m.BASE;m.BASE=Path(td)
   try:
    p=Path(td)/'state.json'
    p.write_text(json.dumps({'contract':'lifecycle','states':{
       'a':{'state':'VERIFIED','execution_attempted':True},
       'b':{'state':'WAITING_CENTRAL_GRANT','execution_attempted':False}}}))
    d=m.source('state.json','lifecycle')
    self.assertEqual(d['snapshot'],'READABLE')
    self.assertEqual(len(d['original_outcomes']),2)
    self.assertTrue(d['original_outcomes'][0]['execution_attempted'])
    self.assertFalse(d['execution_proven_by_snapshot'])
   finally:m.BASE=old
 def test_broker_blockers_dont_grant_execution(self):
  with tempfile.TemporaryDirectory() as td:
   old=m.BASE;m.BASE=Path(td)
   try:
    p=Path(td)/'broker.json'
    p.write_text(json.dumps({'contract':'broker','state':'WAITING_FOR_VERIFIED_HOST_AUTHORITY',
      'blockers':['NO_CURRENT_EXACT_ELIGIBLE_JOB','REMOTE_EXECUTOR_NOT_ENROLLED_FOR_TARGET'],
      'registry_ssh':{'targets':{'hosting-2':{'state':'READY'}}}}))
    d=m.source('broker.json','broker')
    self.assertEqual(len(d['original_outcomes']),4)
    self.assertFalse(d['execution_proven_by_snapshot'])
    self.assertEqual(d['original_outcomes'][1]['category'],'ORIGINAL_DEPENDENCY')
   finally:m.BASE=old
 def test_missing_source_is_not_server_denial(self):
  with tempfile.TemporaryDirectory() as td:
   old=m.BASE;m.BASE=Path(td)
   try:self.assertEqual(m.source('missing','missing')['snapshot'],'NOT_PRESENT')
   finally:m.BASE=old
 def test_report_never_calls_original_executor(self):
  report=m.report()
  self.assertEqual(report['alternate_execution_channels_started'],0)
  self.assertEqual(report['grants_created_by_this_component'],0)
  self.assertEqual(report['authority_overrides_by_this_component'],0)
 def test_current_sources_are_verified(self):
  report=m.report()
  self.assertEqual(set(report['sources']),set(m.SOURCES))
  self.assertEqual({v['snapshot'] for v in report['sources'].values()},{'READABLE'})
if __name__=='__main__':unittest.main()
