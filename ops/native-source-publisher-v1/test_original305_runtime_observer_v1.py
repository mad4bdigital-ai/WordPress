"""Offline tests for autonomous, non-authoritative Original305 Host telemetry."""
from __future__ import annotations
from pathlib import Path
import hashlib,json,subprocess,sys,tempfile,types,unittest
from unittest import mock
import original305_runtime_observer_v1 as m

class RootOwnedTriageFixture:
 """Only tests simulate the root-owned stat; actual file bytes still SHA-pinned."""
 def __init__(self, actual, owner_uid=0):
  self.actual=actual
  self.owner_uid=owner_uid
 def is_file(self): return self.actual.is_file()
 def is_symlink(self): return self.actual.is_symlink()
 def read_bytes(self): return self.actual.read_bytes()
 def stat(self):
  real=self.actual.stat()
  return types.SimpleNamespace(st_uid=self.owner_uid, st_gid=real.st_gid,
                               st_mode=real.st_mode, st_nlink=real.st_nlink)

class ObserverTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory(prefix='original305-observer-test-')
  self.addCleanup(self.tmp.cleanup)
  d=Path(self.tmp.name)
  self.triage=d/'triage.py';self.triage.write_text('test-only-readonly-triage');
  self.reg=d/'index.json';self.reg.write_text('v8-pinned-test');
  self.hold=d/'hold.json';self.hold.write_text('test hold identity');
  self.root_triage=RootOwnedTriageFixture(self.triage)
  p=mock.patch.multiple(m,TRIAGE=self.root_triage,HOLD=self.hold,REGISTRY=self.reg,
       TRIAGE_SHA=hashlib.sha256(self.triage.read_bytes()).hexdigest(),
       REGISTRY_SHA=hashlib.sha256(self.reg.read_bytes()).hexdigest())
  p.start();self.addCleanup(p.stop)
  root=mock.patch.object(m.os,'geteuid',return_value=0)
  root.start();self.addCleanup(root.stop)
  self.safe={'state':'LEGACY_AMBIGUOUS_WRITE_HOLD_NEEDS_GOVERNED_RECONCILIATION',
   'signer_invoked':False,'wordpress_changed':False,'hold_cleared':False,
   'renewal_enabled_by_this_check':False,'host_release_authorized':False,
   'legacy_hold_present':True,'original_v8_registry_exact':True,
   'timer_state':'disabled','signed_readiness_admitted':False}

 def test_nonroot_triage_identity_never_bypasses_source_pin(self):
  self.root_triage.owner_uid=1001
  with self.assertRaisesRegex(m.ObserverDenied,'READONLY_TRIAGE_SOURCE_PIN_MISMATCH'):
   m.observe(clock=lambda:123456789,triage_reader=lambda:self.safe)

 def test_corrupt_triage_bytes_never_bypasses_source_pin(self):
  self.triage.write_text('modified-untrusted-test-only-triage')
  with self.assertRaisesRegex(m.ObserverDenied,'READONLY_TRIAGE_SOURCE_PIN_MISMATCH'):
   m.observe(clock=lambda:123456789,triage_reader=lambda:self.safe)

 def test_legitimate_hold_yields_telemetry_not_authority(self):
  value=m.observe(clock=lambda:123456789,triage_reader=lambda:self.safe)
  self.assertEqual(value['state'],'LEGACY_AMBIGUOUS_WRITE_HOLD_NEEDS_GOVERNED_RECONCILIATION')
  self.assertEqual(value['expires_epoch']-value['observation_epoch'],900)
  for k in ('host_release_authorized','signer_invoked','approval_created','incident_closed'):
   self.assertFalse(value[k])
  self.assertEqual(self.hold.read_text(),'test hold identity')

 def test_bad_original_v8_registry_fact_cannot_be_published(self):
  bad=dict(self.safe,original_v8_registry_exact=False)
  with self.assertRaisesRegex(m.ObserverDenied,'REGISTRY_TRIAGE_FACT_CONFLICT'):
   m.observe(clock=lambda:3,triage_reader=lambda:bad)

 def test_claim_of_host_action_is_rejected(self):
  for field in ('signer_invoked','wordpress_changed','hold_cleared','renewal_enabled_by_this_check','host_release_authorized'):
   with self.subTest(field=field):
    bad=dict(self.safe,**{field:True})
    with self.assertRaises(m.ObserverDenied):
     m.observe(clock=lambda:3,triage_reader=lambda:bad)

 def test_host_hold_modified_during_check_rejected(self):
  def change():
   self.hold.write_text('tampered')
   return self.safe
  with self.assertRaisesRegex(m.ObserverDenied,'HOLD_CHANGED'):
   m.observe(clock=lambda:3,triage_reader=change)

 def test_snapshot_php_publishes_only_telemetry_and_verifies_ack(self):
  snap=m.observe(clock=lambda:123456789,triage_reader=lambda:self.safe)
  fake=types.ModuleType('central_wpcli_runtime');fake.central_wp=lambda:['/usr/bin/wp']
  output=json.dumps({'state':'OBSERVER_TELEMETRY_STORED','contract':m.CONTRACT,
      'observation_epoch':snap['observation_epoch'],'host_release_authorized':False})
  runner=mock.Mock(return_value=subprocess.CompletedProcess([],0,output+'\n',''))
  with mock.patch.dict(sys.modules,{'central_wpcli_runtime':fake}):
   result=m.publish(snap,runner=runner)
  self.assertEqual(result['state'],'ORIGINAL305_OBSERVER_PUBLISHED')
  argv=runner.call_args.args[0]
  self.assertEqual(argv[:2],['/usr/bin/wp','eval'])
  self.assertIn("update_option($key,$payload,false)",argv[2])
  self.assertIn("mad4b_original305_runtime_observation_v1",argv[2])
  self.assertNotIn('mad4b_original_host_source_readiness_v1',argv[2])

 def test_wrong_wp_ack_never_reports_success(self):
  snap=m.observe(clock=lambda:123456789,triage_reader=lambda:self.safe)
  fake=types.ModuleType('central_wpcli_runtime');fake.central_wp=lambda:['wp']
  runner=lambda *a,**k:subprocess.CompletedProcess([],0,'{"state":"OBSERVER_REJECTED_PAYLOAD"}\n','')
  with mock.patch.dict(sys.modules,{'central_wpcli_runtime':fake}):
   with self.assertRaisesRegex(m.ObserverDenied,'READBACK_MISMATCH'):
    m.publish(snap,runner=runner)
if __name__=='__main__':unittest.main()
