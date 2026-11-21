"""Prove original Broker V8 prewrite denial cannot become an ambiguous HOLD."""
from __future__ import annotations
import json,subprocess,unittest
from unittest import mock
import original305_readiness_supervisor_v7 as s

class Original305PhaseTests(unittest.TestCase):
 def setUp(self):
  a=mock.patch.object(s,'pinned_issuer',return_value=s.ROOT/s.ISSUER)
  a.start();self.addCleanup(a.stop)
  h=mock.patch.object(s,'HOLD')
  h.start().exists.return_value=False;self.addCleanup(h.stop)
  sleep=mock.patch.object(s.time,'sleep',return_value=None)
  sleep.start();self.addCleanup(sleep.stop)
 def proc(self,rc,state,code):
  return subprocess.CompletedProcess(['python'],rc,json.dumps({'state':state,'error_code':code}),'')
 def test_every_pinned_original_v8_known_prewrite_wp_rejection_is_not_a_hold(self):
  codes=['ORIGINAL_WORDPRESS_SIGNED_WRITE_PROVIDER_MISSING',
         'ORIGINAL_WORDPRESS_SIGNED_WRITE_PUBLIC_KEY_MISSING',
         'ORIGINAL_WORDPRESS_SIGNED_WRITE_SIGNATURE_REJECTED',
         'ORIGINAL_WORDPRESS_SIGNED_WRITE_SCOPE_DRIFT']
  for code in codes:
   with self.subTest(code=code),mock.patch.object(s,'sticky_hold') as hold,\
        mock.patch.object(s,'independent_signed_lease_since',return_value=False):
    ret,rc=s.dispatch(runner=lambda *a,**k:self.proc(3,'ORIGINAL_BROKER_READINESS_DENIED',code))
   self.assertEqual((ret['state'],rc),('DEFINITIVE_PREWRITE_DENIED',4))
   self.assertEqual(ret['error_code'],code)
   hold.assert_not_called()
 def test_unknown_prewrite_code_from_enrolled_broker_remains_diagnostic_not_write_hold(self):
  with mock.patch.object(s,'sticky_hold') as hold, mock.patch.object(s,'independent_signed_lease_since',return_value=False):
   ret,rc=s.dispatch(runner=lambda *a,**k:self.proc(3,'ORIGINAL_BROKER_READINESS_DENIED','ORIGINAL_BROKER_ERROR_REDACTED'))
  self.assertEqual((ret['state'],rc),('DEFINITIVE_PREWRITE_DENIED',4))
  hold.assert_not_called()
 def test_mismatched_exit_and_state_cannot_masquerade_as_definitive_prewrite(self):
  with mock.patch.object(s,'sticky_hold') as hold, mock.patch.object(s,'independent_signed_lease_since',return_value=False):
   ret,rc=s.dispatch(runner=lambda *a,**k:self.proc(3,'ORIGINAL_BROKER_WRITE_RESULT_UNCONFIRMED','ORIGINAL_WORDPRESS_SIGNED_WRITE_SIGNATURE_REJECTED'))
  self.assertEqual((ret['state'],rc),('AMBIGUOUS_SIGNED_WRITE_HOLD_NO_AUTOMATIC_RETRY',5))
  hold.assert_called_once()
 def test_postwrite_state_is_never_prewrite_even_if_error_looks_retryable(self):
  with mock.patch.object(s,'sticky_hold') as hold, mock.patch.object(s,'independent_signed_lease_since',return_value=False):
   ret,rc=s.dispatch(runner=lambda *a,**k:self.proc(5,'ORIGINAL_BROKER_WRITE_RESULT_UNCONFIRMED','ORIGINAL_WORDPRESS_SIGNED_WRITE_STALE_PREWRITE'))
  self.assertEqual(rc,5)
  hold.assert_called_once()
 def test_stale_prewrite_stays_retryable_without_latching_hold(self):
  with mock.patch.object(s,'sticky_hold') as hold, mock.patch.object(s,'independent_signed_lease_since',return_value=False):
   ret,rc=s.dispatch(runner=lambda *a,**k:self.proc(3,'ORIGINAL_BROKER_READINESS_DENIED','ORIGINAL_WORDPRESS_SIGNED_WRITE_STALE_PREWRITE'))
  self.assertEqual((ret['state'],rc),('RETRYABLE_PREWRITE_HOLD',3))
  hold.assert_not_called()
 def test_old_hold_is_never_silently_cleared(self):
  s.HOLD.exists.return_value=True
  with mock.patch.object(s,'file_read',return_value=b'{}'):
   runner=mock.Mock(side_effect=AssertionError('must not invoke signer'))
   ret,rc=s.dispatch(runner=runner)
  self.assertEqual(rc,5)
  runner.assert_not_called()
if __name__=='__main__':unittest.main()
