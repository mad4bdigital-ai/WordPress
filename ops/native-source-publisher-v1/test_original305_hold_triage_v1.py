"""Offline status-only regression tests; never execute the first-party signer."""
from __future__ import annotations
import json
import tempfile
import types
import stat
from pathlib import Path
import unittest
from unittest import mock
import original305_hold_triage_v1 as t

class RootOwnedHoldFixture:
 """Model Original305's real root-owned HOLD without chown on CI runners.

 The underlying regular file, mode, content and link count remain real;
 only simulated owner identity is controlled by this hermetic test.
 """
 def __init__(self,path,owner=0,group=0):
  self.path=path;self.owner=owner;self.group=group
 def exists(self):return self.path.exists()
 def is_symlink(self):return self.path.is_symlink()
 def read_bytes(self):return self.path.read_bytes()
 def lstat(self):
  real=self.path.lstat()
  return types.SimpleNamespace(
    st_uid=self.owner,st_gid=self.group,st_mode=real.st_mode,
    st_nlink=real.st_nlink,st_mtime=real.st_mtime)

class Original305IncidentTriage(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='original305-triage-fixture-')
        self.addCleanup(self.tmp.cleanup)
        folder=Path(self.tmp.name)
        self.registry=folder/'index.json';self.registry.write_bytes(b'fixed-registry-v6-fixture')
        self.hold=folder/'hold.json'
        self.root_hold=RootOwnedHoldFixture(self.hold)
        p=mock.patch.multiple(t,REGISTRY=self.registry,HOLD=self.root_hold,
                             EXPECTED_REGISTRY=__import__('hashlib').sha256(self.registry.read_bytes()).hexdigest())
        p.start();self.addCleanup(p.stop)
        self.root=mock.patch.object(t.os,'geteuid',return_value=0)
        self.root.start();self.addCleanup(self.root.stop)
        self.timer=mock.patch.object(t,'timer_state',return_value='disabled')
        self.timer.start();self.addCleanup(self.timer.stop)
        self.lock=mock.patch.object(t,'lock_is_busy',return_value=False)
        self.lock.start();self.addCleanup(self.lock.stop)

    def test_nonroot_hold_file_cannot_be_admitted(self):
        self.hold.write_text(json.dumps({'reason_code':'ISSUER_SIGNED_RESULT_UNCONFIRMED'}))
        self.hold.chmod(0o600)
        self.root_hold.owner=1001
        report=t.check(wp_reader=lambda:{'state':'SIGNED_LEASE_CURRENT','signed_readiness_admitted':True})
        self.assertEqual(report['state'],'INCIDENT_EVIDENCE_INVALID_OR_UNAVAILABLE')
        self.assertFalse(report['host_release_authorized'])

    def test_wrong_permission_hold_cannot_be_admitted(self):
        self.hold.write_text(json.dumps({'reason_code':'ISSUER_SIGNED_RESULT_UNCONFIRMED'}))
        self.hold.chmod(0o644)
        report=t.check(wp_reader=lambda:{'state':'SIGNED_LEASE_CURRENT','signed_readiness_admitted':True})
        self.assertEqual(report['state'],'INCIDENT_EVIDENCE_INVALID_OR_UNAVAILABLE')
        self.assertFalse(report['host_release_authorized'])

    def test_expired_lease_and_hold_are_separate_facts(self):
        self.hold.write_text(json.dumps({'reason_code':'ISSUER_SIGNED_RESULT_UNCONFIRMED'}))
        proof={'state':'SIGNED_LEASE_EXPIRED_OR_UNADMITTED','signed_readiness_admitted':False,
               'signed_issued_epoch':100,'signed_expires_epoch':280,'seconds_until_expiry':-100}
        self.hold.chmod(0o600)
        result=t.check(wp_reader=lambda:proof)
        self.assertEqual(result['state'],'LEGACY_AMBIGUOUS_WRITE_HOLD_NEEDS_GOVERNED_RECONCILIATION')
        self.assertTrue(self.hold.exists())
        self.assertFalse(result['host_release_authorized'])
        self.assertEqual(result['next_authorized_step'],'ORIGINAL_FIRSTPARTY_HOLD_INCIDENT_REVIEW')

    def test_no_hold_and_current_wordpress_is_observed_not_published(self):
        result=t.check(wp_reader=lambda:{'state':'SIGNED_LEASE_CURRENT','signed_readiness_admitted':True})
        self.assertEqual(result['state'],'SIGNED_HOST_READINESS_ACCEPTED')
        self.assertFalse(result['host_release_authorized'])
        self.assertFalse(result['signer_invoked'])

    def test_stale_registry_pin_fails_closed(self):
        self.registry.write_bytes(b'changed-and-untrusted')
        result=t.check(wp_reader=lambda:{'state':'SIGNED_LEASE_CURRENT','signed_readiness_admitted':True})
        self.assertEqual(result['state'],'ORIGINAL_REGISTRY_CHANGED_HOLD')
        self.assertFalse(result['original_v8_registry_exact'])

    def test_wordpress_unavailable_not_misreported_as_signed(self):
        result=t.check(wp_reader=lambda:{'state':'WPCLI_READ_FAILED','signed_readiness_admitted':False})
        self.assertEqual(result['state'],'ORIGINAL305_WORDPRESS_READBACK_UNAVAILABLE')
        self.assertFalse(result['host_release_authorized'])

    def test_external_transport_failure_remains_readonly(self):
        result=t.check(wp_reader=lambda: (_ for _ in ()).throw(OSError('test-only')))
        self.assertEqual(result['state'],'INCIDENT_EVIDENCE_INVALID_OR_UNAVAILABLE')
        self.assertFalse(result['wordpress_changed'])

if __name__=='__main__':unittest.main()
