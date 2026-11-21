"""Offline Master review binding tests; test-only signer never touches VPS key."""
from __future__ import annotations
import base64
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest import mock
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives import serialization
import original305_incident_signed_review_v1 as m

class IncidentMasterReviewTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='original305-test-only-review-')
        self.addCleanup(self.tmp.cleanup)
        d=Path(self.tmp.name)
        self.hold=d/'hold.json'
        self.hold.write_text(json.dumps({'contract':'mad4b.original305-enrolled-readiness-supervisor.v1',
                     'state':'AMBIGUOUS_SIGNED_WRITE_HOLD',
                     'reason_code':'ISSUER_SIGNED_RESULT_UNCONFIRMED',
                     'automatic_retry_allowed':False}))
        self.hold.chmod(0o600)
        self.index=d/'index.json';self.index.write_text('{}');self.index.chmod(0o600)
        self.supervisor=d/'supervisor.py';self.supervisor.write_text('TEST ONLY');self.supervisor.chmod(0o600)
        self.patches=mock.patch.multiple(m,HOLD=self.hold,INDEX=self.index,SUPERVISOR=self.supervisor,
         INDEX_PIN=hashlib.sha256(self.index.read_bytes()).hexdigest(),
         SUPERVISOR_PIN=hashlib.sha256(self.supervisor.read_bytes()).hexdigest())
        self.patches.start();self.addCleanup(self.patches.stop)
        # The actual first-party root_read() still uses O_NOFOLLOW, fstat,
        # mode, nlink, length and SHA checks. Only the root-owner bits of
        # TEST FIXTURE descriptors are simulated on unprivileged CI runners.
        # No production file, signing key or real owner evidence is used.
        originals=m.os.fstat
        owned_paths={str(p.resolve()) for p in (
            self.hold,self.index,self.supervisor )}
        self.simulated_owner_uid=0
        self.simulated_owner_gid=0
        def fixture_fstat(fd):
            real=originals(fd)
            try:
                name=__import__('os').readlink('/proc/self/fd/'+str(fd))
            except OSError:
                return real
            if name not in owned_paths:return real
            fields=list(real)
            fields[4]=self.simulated_owner_uid
            fields[5]=self.simulated_owner_gid
            return m.os.stat_result(fields)
        fstat_guard=mock.patch.object(m.os,'fstat',side_effect=fixture_fstat)
        fstat_guard.start();self.addCleanup(fstat_guard.stop)

        root=mock.patch.object(m.os,'geteuid',return_value=0)
        root.start();self.addCleanup(root.stop)
        self.request=m.plan()
        self.private=Ed25519PrivateKey.generate()
        self.public=self.private.public_key().public_bytes(
            encoding=serialization.Encoding.Raw,
            format=serialization.PublicFormat.Raw)
        self.now=1800000000
        self.fields={
            'contract':m.APPROVAL_CONTRACT,
            'request_sha256':self.request['request_sha256'],
            'legacy_hold_sha256':self.request['legacy_hold_sha256'],
            'registry_sha256':self.request['registry_sha256'],
            'supervisor_sha256':self.request['supervisor_sha256'],
            'issuer':'mad4b-master-admin',
            'approval_ability':m.ABILITY,
            'action':m.ACTION,
            'issued_epoch':self.now-10,
            'expires_epoch':self.now+120,
            'ticket_id':'test-ticket-01',
            'reviewed_by':'test-reviewer',
        }
    def sign(self,data=None):
        d=dict(data or self.fields)
        d['signature']=base64.b64encode(self.private.sign(
            (m.APPROVAL_CONTRACT+'\n').encode()+m.canonical(d))).decode()
        return d
    def test_untrusted_fixture_owner_is_denied_by_original_root_read(self):
        self.simulated_owner_uid=1001
        with self.assertRaisesRegex(m.IncidentReviewDenied,'INCIDENT_ROOT_FILE_UNTRUSTED'):
            m.plan()

    def test_untrusted_fixture_group_is_denied_by_original_root_read(self):
        self.simulated_owner_gid=1001
        with self.assertRaisesRegex(m.IncidentReviewDenied,'INCIDENT_ROOT_FILE_UNTRUSTED'):
            m.plan()

    def test_plan_is_exact_and_readonly(self):
        result=m.plan()
        self.assertEqual(result['request_sha256'],self.request['request_sha256'])
        self.assertEqual(result['legacy_hold_sha256'],hashlib.sha256(self.hold.read_bytes()).hexdigest())
        self.assertTrue(self.hold.exists())
        self.assertFalse(result['host_release_authorized'])
    def test_valid_test_only_master_signature_verifies_no_mutation(self):
        response=m.verify_approval(self.sign(),self.request,self.public,now=self.now)
        self.assertTrue(response['signature_verified'])
        self.assertTrue(response['review_verified_not_executed'])
        self.assertFalse(response['incident_marker_changed'])
        self.assertFalse(response['timer_enabled'])
        self.assertFalse(response['original_wordpress_ticket_admission_verified'])
        self.assertFalse(response['incident_closed'])
        self.assertTrue(self.hold.exists())
    def test_wrong_public_key_rejected(self):
        other=Ed25519PrivateKey.generate().public_key().public_bytes(
            encoding=serialization.Encoding.Raw,format=serialization.PublicFormat.Raw)
        with self.assertRaisesRegex(m.IncidentReviewDenied,'SIGNATURE_INVALID'):
            m.verify_approval(self.sign(),self.request,other,now=self.now)
    def test_expired_signature_rejected(self):
        with self.assertRaisesRegex(m.IncidentReviewDenied,'EXPIRED_OR_TOO_BROAD'):
            m.verify_approval(self.sign(),self.request,self.public,now=self.now+360)
    def test_changed_request_binding_rejected_even_if_signature_is_valid(self):
        changed=dict(self.fields);changed['legacy_hold_sha256']='f'*64
        with self.assertRaisesRegex(m.IncidentReviewDenied,'BINDING_CHANGED'):
            m.verify_approval(self.sign(changed),self.request,self.public,now=self.now)
    def test_wrong_ability_rejected(self):
        changed=dict(self.fields);changed['approval_ability']='mad4b/host-source-release'
        with self.assertRaisesRegex(m.IncidentReviewDenied,'BINDING_CHANGED'):
            m.verify_approval(self.sign(changed),self.request,self.public,now=self.now)
    def test_extra_fields_rejected(self):
        changed=self.sign();changed['publication_authorized']=True
        with self.assertRaisesRegex(m.IncidentReviewDenied,'SHAPE_INVALID'):
            m.verify_approval(changed,self.request,self.public,now=self.now)
    def test_hold_change_invalidates_review(self):
        self.hold.write_text('CHANGED')
        with self.assertRaisesRegex(m.IncidentReviewDenied,'LEGACY_HOLD_IDENTITY_CHANGED'):
            m.plan()

if __name__=='__main__':unittest.main()
