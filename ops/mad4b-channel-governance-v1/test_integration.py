import importlib.util, json, os, tempfile, unittest
from pathlib import Path
from datetime import datetime,timezone
SOURCE=Path(__file__).with_name('integration.py')
spec=importlib.util.spec_from_file_location('mad4b_channel_governance_integration',SOURCE)
c=importlib.util.module_from_spec(spec);spec.loader.exec_module(c)
class TestChannelGovernance(unittest.TestCase):
 def test_exact_source_inventory(self):
  self.assertEqual(len(c.SOURCES),10)
  self.assertIn('broker_native_ssh',c.SOURCES)
 def test_external_tool_denial_not_server_denial(self):
  self.assertEqual(c.classify('TOOL_INVOCATION_BLOCKED')['category'],'EXTERNAL_TOOL_POLICY')
 def test_original_denial_no_channel_fallback(self):
  self.assertIn('STOP_NO_CROSS_CHANNEL_RETRY',c.classify('ORIGINAL_AUTHORITY_DENIED')['action'])
 def test_grant_denial_immutable(self):
  self.assertEqual(c.classify('ORIGINAL_GRANT_REVOKED_OR_CHANGED')['category'],'ORIGINAL_AUTHORITY_DENIAL')
 def test_transport_before_attempt_only(self):
  self.assertEqual(c.classify('PROVIDER_UNAVAILABLE')['category'],'PRE_DISPATCH_TRANSPORT')
 def test_transport_after_attempt_uncertain(self):
  self.assertEqual(c.classify('PROVIDER_UNAVAILABLE',True)['category'],'EFFECT_UNCERTAIN')
 def test_uncertain_requires_receipt(self):
  self.assertEqual(c.classify('RECONCILIATION_REQUIRED')['action'],'ORIGINAL_RECEIPT_RECONCILE_NO_REPLAY')
 def test_waiting_not_security_denial(self):
  self.assertEqual(c.classify('WAITING_VERIFIED_SITE_BINDING')['category'],'ORIGINAL_DEPENDENCY')
 def test_unknown_fails_closed(self):
  self.assertEqual(c.classify('random_bad_code')['category'],'UNCLASSIFIED')
 def test_ready_never_proves_execution(self):
  self.assertEqual(c.classify('READY')['category'],'REPORTED_STATE_ONLY')
 def test_no_non_authorizing_flags(self):
  d=c.report()
  self.assertEqual(d['authority_overrides_by_this_component'],0)
  self.assertEqual(d['grants_created_by_this_component'],0)
  self.assertEqual(d['alternate_execution_channels_started'],0)
 def test_invalid_contract_does_not_promote(self):
  with tempfile.TemporaryDirectory() as td:
   old=c.BASE;c.BASE=Path(td)
   try:
    (c.BASE/'source.json').write_text(json.dumps({'contract':'wrong'}))
    self.assertEqual(c.source('source.json','wanted')['snapshot'],'CONTRACT_MISMATCH')
   finally:c.BASE=old
 def test_unknown_source_is_not_execution_denial(self):
  with tempfile.TemporaryDirectory() as td:
   old=c.BASE;c.BASE=Path(td)
   try:self.assertEqual(c.source('missing.json','wanted')['snapshot'],'NOT_PRESENT')
   finally:c.BASE=old
 def test_component_state_does_not_disable_owner(self):
  with tempfile.TemporaryDirectory() as td:
   old=c.BASE;c.BASE=Path(td)
   try:
    (c.BASE/'source.json').write_text(json.dumps({'contract':'expected','state':'WAITING_FOR_VERIFIED_HOST_AUTHORITY','grants_created':0}))
    d=c.source('source.json','expected')
    self.assertEqual(d['original_outcomes'][0]['category'],'ORIGINAL_DEPENDENCY')
    self.assertFalse(d['execution_proven_by_snapshot'])
   finally:c.BASE=old
if __name__=='__main__':unittest.main()
