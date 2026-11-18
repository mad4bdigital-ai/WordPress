#!/usr/bin/env python3
"""Hermetic tests. This never alters the real Original305 HOLD or WP option."""
import importlib.util,json,pathlib,types,unittest,unittest.mock,time
SOURCE=pathlib.Path(__file__).with_name('reconcile.py')
spec=importlib.util.spec_from_file_location('original305_incident',SOURCE)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
HOLD_DOC={'automatic_retry_allowed':False,
 'contract':'mad4b.original305-enrolled-readiness-supervisor.v1',
 'reason_code':'ISSUER_SIGNED_RESULT_UNCONFIRMED',
 'state':'AMBIGUOUS_SIGNED_WRITE_HOLD'}
RAW=(json.dumps(HOLD_DOC,sort_keys=True)+'\n').encode()
assert m.checksum(RAW)==m.EXPECTED_HOLD_SHA

def signed(issued=1794790958,expires=1794791138):
 return dict(exists=True,signature_valid=True,expired=True,
    issuer_epoch=issued,expires_epoch=expires,nonce_shape_verified=True,
    ready_claimed=True)

class Incident(unittest.TestCase):
 def verified(self,data=RAW,proof=None,policy=True,issuer='cf7489f3611aebe56c46717ee90cde3ff023e864cf0129c50f0d87f0f2c1166c'):
  sup=types.SimpleNamespace()
  with unittest.mock.patch.object(m.os,'geteuid',return_value=0),\
       unittest.mock.patch.object(m,'root_read',return_value=data),\
       unittest.mock.patch.object(m,'load_supervisor',return_value=(sup,issuer)),\
       unittest.mock.patch.object(m,'live_signed_readback',return_value=proof or signed()),\
       unittest.mock.patch.object(m,'current_host_ledger',side_effect=None if policy else RuntimeError('LEDGER_BAD')),\
       unittest.mock.patch.object(m,'HOLD',types.SimpleNamespace(stat=lambda:types.SimpleNamespace(st_mtime=1794790450))):
    return m.verified_evidence()
 def test_bounded_good_expired_cryptographic_readback(self):
  v=self.verified()
  self.assertEqual(v['hold_sha256'],m.EXPECTED_HOLD_SHA)
  self.assertTrue(v['old_signed_readiness_signature_valid'])
  self.assertTrue(v['old_signed_readiness_expired'])
  self.assertEqual(v['site_write_grants_issued'],0)
 def test_reject_changed_hold_and_wrong_reason(self):
  for invalid in [RAW+b'\n',RAW.replace(b'ISSUER_SIGNED_RESULT_UNCONFIRMED',b'OTHER_SIGNING_ERROR')]:
   with self.subTest(bad=invalid),self.assertRaises(RuntimeError):
    self.verified(data=invalid)
 def test_reject_missing_or_fake_signed_readback(self):
  for key,bad in [('signature_valid',False),('expired',False),
                  ('nonce_shape_verified',False),('ready_claimed',False),
                  ('exists',False)]:
   x=signed();x[key]=bad
   with self.subTest(key=key),self.assertRaisesRegex(RuntimeError,'NOT_INDEPENDENTLY_RESOLVED'):
    self.verified(proof=x)
 def test_reject_out_of_order_or_bad_expiry(self):
  for x in [signed(issued=1794790400),signed(expires=1794791130)]:
   with self.assertRaises(RuntimeError):
    self.verified(proof=x)
 def test_signed_host_policy_unchanged_mandatory(self):
  with self.assertRaisesRegex(RuntimeError,'LEDGER_BAD'):
   self.verified(policy=False)
 def test_no_signer_invoked_by_reconciler_and_no_grants(self):
  s=SOURCE.read_text()
  self.assertIn('mode==\'apply\'',s)
  self.assertIn('os.replace(HOLD,backup)',s)
  self.assertIn("new_signature_issued':False",s)
  self.assertIn("production_content_mutated':False",s)
  self.assertNotIn("'--refresh'",s)
  self.assertNotIn('wp_set_current_user',s)
  self.assertNotIn('--user=',s)
if __name__=='__main__':unittest.main(verbosity=2)
