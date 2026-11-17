"""Portable first-party signatures, bounded standing consent and no fake grant."""
from __future__ import annotations
import base64
from copy import deepcopy
from datetime import datetime,timedelta,timezone
import hashlib
import importlib.util
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path(__file__).resolve().parents[1]
NATIVE=ROOT/'ops/native-source-publisher-v1'
sys.path.insert(0,str(NATIVE))
import standing_release_delegation as sd
spec=importlib.util.spec_from_file_location('native_source_standing_test',NATIVE/'native_source_publisher.py')
pub=importlib.util.module_from_spec(spec);sys.modules[spec.name]=pub;spec.loader.exec_module(pub)
ASSET='wp-content/plugins/mad4b-website/assets/home-search.js'
NOW=datetime(2026,11,17,12,tzinfo=timezone.utc)
UUID='a3a7cc36-db7e-4a14-8456-b41857def05c'
DELEGATION_ID='a321a321-1234-4123-8123-123456789abc'
NONCE='b321b321-1234-4123-8123-123456789abc'
SHA='f'*64
BEFORE='0'*64
COMMIT='d'*40

def iso(x):return x.isoformat()

class SignedStandingRelease(unittest.TestCase):
 def setUp(self):
  self.private=Ed25519PrivateKey.generate()
  self.public=base64.b64encode(self.private.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)).decode()
  self.policy={'master_approval_public_key_b64':self.public,'standing_delegation_enabled':True}
  self.bound={'operation_id':'source.code.release','provider':'mad4b-source-release',
              'approval_ability':'mad4b/native-source-release','manifest_sha256':SHA,
              'binding_sha256':'a'*64}
  self.plan={'company':'wayfatours','environment':'production','site_uuid':UUID,
        'origin':'https://wayfatours.com','git_commit':COMMIT,'plan_sha256':'b'*64,
        'source_binding':self.bound,'changed_count':1,'files':[
        {'source':ASSET,'target':ASSET,'source_bytes':2048,
         'source_sha256':'1'*64,'expected_before_sha256':BEFORE}]}
  self.cert={'contract':sd.CERT_CONTRACT,'delegation_id':DELEGATION_ID,
   'company':'wayfatours','environment':'production','site_uuid':UUID,'origin':'https://wayfatours.com',
   'operation_id':'source.code.release','provider':'mad4b-source-release','approval_ability':'mad4b/native-source-release',
   'source_manifest_sha256':SHA,'issued_at':iso(NOW-timedelta(hours=1)),
   'expires_at':iso(NOW+timedelta(days=7)),'allowed_targets':[ASSET],
   'max_changed_files':3,'max_total_bytes':64*1024}
  self.sign(self.cert,sd.CERT_CONTRACT)
  self.decision={'contract':sd.DECISION_CONTRACT,'delegation_id':DELEGATION_ID,
   'certificate_sha256':sd.sha(sd.canonical(self.cert)),'plan_sha256':self.plan['plan_sha256'],
   'operation_id':'source.code.release','operation_binding_sha256':'a'*64,
   'approval_ability':'mad4b/native-source-release','provider':'mad4b-source-release',
   'scope':'wayfatours:production','site_uuid':UUID,'origin':'https://wayfatours.com',
   'git_commit':COMMIT,'issued_at':iso(NOW-timedelta(minutes=1)),
   'expires_at':iso(NOW+timedelta(minutes=10)),'nonce':NONCE}
  self.sign(self.decision,sd.DECISION_CONTRACT)
  self.dep={'operation_id':'source.code.release','dependencies':[
   {'path':'native-source-publisher-v1/standing_release_delegation.py',
    'sha256':hashlib.sha256((NATIVE/'standing_release_delegation.py').read_bytes()).hexdigest()}]}
  self.tmp=tempfile.TemporaryDirectory(prefix='standing-mad4b-');self.addCleanup(self.tmp.cleanup)
 def sign(self,row,prefix):row['signature']=base64.b64encode(self.private.sign(sd.signed_message(prefix,row))).decode()
 def resign(self,which):self.sign(which,which['contract'])
 def verify(self):return sd.verify_plan_and_decision(self.plan,self.policy,self.cert,self.decision,now=NOW)
 def assertRejected(self,fragment=None):
  with self.assertRaises(sd.DelegationDenied) as err:self.verify()
  if fragment:self.assertIn(fragment,str(err.exception))
 def test_signed_plan_scope_is_eligible_not_an_execution_grant(self):
  r=self.verify();self.assertFalse(r['requires_new_manual_decision'])
  self.assertTrue(r['independent_broker_grant_still_required'])
  self.assertFalse(r['release_executed']);self.assertEqual(r['changed_files'],1)
 def test_no_standing_flag_no_claim_in_existing_original_313(self):
  policy={**self.policy,'standing_delegation_enabled':False}
  with self.assertRaisesRegex(pub.Denied,'NOT_ENROLLED'):
   pub.verify_master_approval(self.plan,policy,self.decision)
 def test_bad_certificate_signature_never_passes(self):
  self.cert['signature']='AA=='
  self.assertRejected('SIGNATURE')
 def test_bad_short_plan_signature_never_passes(self):
  self.decision['signature']='AA=='
  self.assertRejected('SIGNATURE')
 def test_different_plan_needs_a_fresh_signed_decision(self):
  self.plan['plan_sha256']='c'*64
  self.assertRejected('SCOPE_MISMATCH')
 def test_plan_change_signed_by_master_still_needs_exact_cert_scope(self):
  self.plan['origin']='https://different.example'
  self.decision['origin']=self.plan['origin'];self.resign(self.decision)
  self.assertRejected('SCOPE_MISMATCH')
 def test_cross_company_not_covered_by_existing_authority(self):
  self.plan['company']='other-company';self.decision['scope']='other-company:production';self.resign(self.decision)
  self.assertRejected('SCOPE_MISMATCH')
 def test_cannot_replace_plugin_php_even_when_master_cert_lists_it(self):
  malicious='wp-content/plugins/mad4b-website/assets/hooks.php'
  self.cert['allowed_targets']=[malicious];self.resign(self.cert)
  self.assertRejected('ALLOWED_ASSETS_INVALID')
 def test_cannot_touch_mu_php(self):
  self.cert['allowed_targets']=['wp-content/mu-plugins/runner.php'];self.resign(self.cert)
  self.assertRejected('ALLOWED_ASSETS_INVALID')
 def test_cannot_touch_host_or_policy_files(self):
  self.cert['allowed_targets']=['wp-content/plugins/mad4b-site-control-plane/includes/class-host-auth.php'];self.resign(self.cert)
  self.assertRejected('ALLOWED_ASSETS_INVALID')
 def test_symlink_like_traversal_path_in_signed_allowlist_denied(self):
  self.cert['allowed_targets']=['wp-content/plugins/mad4b-website/assets/../private.js'];self.resign(self.cert)
  self.assertRejected('ALLOWED_ASSETS_INVALID')
 def test_unlisted_asset_cannot_be_added_to_release(self):
  self.plan['files'][0]['target']='wp-content/plugins/mad4b-website/assets/other.js'
  self.plan['files'][0]['source']=self.plan['files'][0]['target']
  self.assertRejected('FILE_OUT_OF_SCOPE')
 def test_deny_new_files_without_preexisting_digest(self):
  self.plan['files'][0]['expected_before_sha256']=None
  self.assertRejected('EXISTING_FILE_UPDATE_ONLY')
 def test_deny_noop_updates(self):
  self.plan['files'][0]['expected_before_sha256']='1'*64
  self.assertRejected('EXISTING_FILE_UPDATE_ONLY')
 def test_batch_exceeds_signed_byte_limit(self):
  self.plan['files'][0]['source_bytes']=64*1024+1
  self.assertRejected('BATCH_LIMIT_INVALID')
 def test_batch_changed_count_forgery_rejected(self):
  self.plan['changed_count']=99
  self.assertRejected('BATCH_LIMIT_INVALID')
 def test_signed_certificate_cannot_live_over_30_days(self):
  self.cert['expires_at']=iso(NOW+timedelta(days=61));self.resign(self.cert)
  self.assertRejected('EXPIRED_OR_NOT_YET_ACTIVE')
 def test_expired_certificate_fails_closed(self):
  self.cert['expires_at']=iso(NOW-timedelta(seconds=1));self.resign(self.cert)
  self.assertRejected('EXPIRED_OR_NOT_YET_ACTIVE')
 def test_decision_must_expire_within_15_minutes(self):
  self.decision['expires_at']=iso(NOW+timedelta(hours=1));self.resign(self.decision)
  self.assertRejected('TOO_LONG')
 def test_revocation_cannot_be_simulated_by_updating_unsigned_cert(self):
  self.cert['allowed_targets']=['wp-content/plugins/mad4b-website/assets/other.js']
  self.assertRejected('SIGNATURE')
 def test_explicit_manifest_sha_pin_prevents_new_unreviewed_provider(self):
  self.plan['source_binding']['manifest_sha256']='9'*64
  self.assertRejected('SCOPE_MISMATCH')
 def test_strict_schema_does_not_accept_untrusted_extra_approver(self):
  self.decision['approved_by']=123
  self.assertRejected('SHAPE_INVALID')
 def test_pub_loader_requires_independent_enrolled_module_dependency(self):
  with mock.patch.object(pub,'enrolled_original_provider',return_value={'dependencies':[]}):
   with self.assertRaisesRegex(pub.Denied,'DEPENDENCY_NOT_PINNED'):
    pub.verify_master_approval(self.plan,self.policy,self.decision)
 def test_pub_uses_signed_certificate_when_enrolled_not_wp_ticket(self):
  with mock.patch.object(pub,'enrolled_original_provider',return_value=self.dep),\
       mock.patch.object(pub,'load_pinned_standing_module',return_value=sd),\
       mock.patch.object(sd,'read_enrolled_certificate',return_value=self.cert),\
       mock.patch.object(sd,'verify_plan_and_decision',wraps=sd.verify_plan_and_decision) as verifier:
   pub.verify_master_approval(self.plan,self.policy,self.decision,now=NOW)
   verifier.assert_called_once()
 def test_live_revocation_is_checked_during_readback(self):
  with mock.patch.object(pub,'enrolled_original_provider',return_value=self.dep),\
       mock.patch.object(pub,'load_pinned_standing_module',return_value=sd),\
       mock.patch.object(pub,'company_policy',return_value={}),\
       mock.patch.object(sd,'read_enrolled_certificate',side_effect=FileNotFoundError('revoked')):
   with self.assertRaisesRegex(pub.Denied,'PROOF_NOT_VALID'):
    pub.fresh_master_ticket_readback(self.plan,self.policy,self.decision)
 def test_one_time_signed_standing_decision_cannot_replay(self):
  directory=Path(self.tmp.name)
  with mock.patch.object(pub,'enrolled_original_provider',return_value=self.dep),\
       mock.patch.object(pub,'load_pinned_standing_module',return_value=sd),\
       mock.patch.object(pub,'company_policy',return_value={}),\
       mock.patch.object(sd,'read_enrolled_certificate',return_value=self.cert),\
       mock.patch.object(pub.core,'assert_pinned_receipt_directory',return_value=None),\
       mock.patch.object(sd,'verify_plan_and_decision',return_value={'release_executed':False}):
   pub.reserve_master_ticket_once(self.plan,self.policy,self.decision,directory)
   files=list(directory.iterdir());self.assertEqual(len(files),1)
   self.assertTrue(files[0].name.startswith('master-standing-'))
   with self.assertRaisesRegex(pub.Denied,'ALREADY_CLAIMED'):
    pub.reserve_master_ticket_once(self.plan,self.policy,self.decision,directory)
if __name__=='__main__':unittest.main(verbosity=2)
