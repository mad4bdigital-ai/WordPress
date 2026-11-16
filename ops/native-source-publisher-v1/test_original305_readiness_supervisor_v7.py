"""Offline Original305 V3 renewal tests: zero real signer/WordPress calls."""
from __future__ import annotations
import json
import subprocess
import unittest
from unittest import mock
import original305_readiness_supervisor_v7 as sup

class SupervisorV3Contracts(unittest.TestCase):
    def setUp(self):
        issuer=mock.patch.object(sup,'pinned_issuer',return_value=sup.ROOT/sup.ISSUER)
        issuer.start();self.addCleanup(issuer.stop)
        hold=mock.patch.object(sup,'HOLD')
        h=hold.start();h.exists.return_value=False
        self.addCleanup(hold.stop)
        pause=mock.patch.object(sup.time,'sleep',return_value=None)
        pause.start();self.addCleanup(pause.stop)

    def test_current_valid_lease_skips_signer_completely(self):
        with mock.patch.object(sup,'independent_signed_lease_since',return_value=True) as check:
            signer=mock.Mock(side_effect=AssertionError('DO_NOT_SIGN'))
            value,rc=sup.dispatch(runner=signer)
        self.assertEqual(rc,0)
        self.assertEqual(value['state'],'ORIGINAL305_CURRENT_SIGNED_LEASE_NO_REFRESH_NEEDED')
        self.assertFalse(value['new_signature_issued'])
        self.assertFalse(value['host_release_authorized'])
        check.assert_called_once_with(min_remaining=90)
        signer.assert_not_called()

    def test_no_valid_lease_uses_single_original_issuer(self):
        result=subprocess.CompletedProcess(['python'],0,stdout=json.dumps({
          'state':'ORIGINAL305_SIGNED_HOST_PROVIDER_READY',
          'original_broker_readiness_signature_verified':True,
          'host_release_authorized':False}),stderr='')
        signer=mock.Mock(return_value=result)
        with mock.patch.object(sup,'independent_signed_lease_since',return_value=False):
            value,rc=sup.dispatch(runner=signer)
        self.assertEqual(rc,0)
        self.assertEqual(value['state'],'ORIGINAL305_SIGNED_REFRESH_VERIFIED')
        signer.assert_called_once()
        self.assertEqual(signer.call_args[0][0][-1],'--refresh')

    def test_concurrent_lock_and_valid_external_lease_is_success_without_resign(self):
        denied=subprocess.CompletedProcess(['python'],3,stdout=json.dumps({
          'state':'ORIGINAL_BROKER_READINESS_DENIED',
          'error_code':'ORIGINAL_BROKER_HOST_REFRESH_CONCURRENT_DEFER'}),stderr='')
        signer=mock.Mock(return_value=denied)
        with mock.patch.object(sup,'independent_signed_lease_since',side_effect=[False,True]) as verify, mock.patch.object(sup,'sticky_hold') as hold:
            value,rc=sup.dispatch(runner=signer)
        self.assertEqual(rc,0)
        self.assertEqual(value['state'],'ORIGINAL305_CURRENT_SIGNED_LEASE_INDEPENDENTLY_VERIFIED')
        self.assertFalse(value['new_signature_issued'])
        signer.assert_called_once();hold.assert_not_called()
        self.assertEqual(verify.call_count,2)

    def test_concurrent_lock_without_accepted_proof_is_only_deferred(self):
        denied=subprocess.CompletedProcess(['python'],3,stdout=json.dumps({
          'state':'ORIGINAL_BROKER_READINESS_DENIED',
          'error_code':'ORIGINAL_BROKER_HOST_REFRESH_CONCURRENT_DEFER'}),stderr='')
        with mock.patch.object(sup,'independent_signed_lease_since',return_value=False), mock.patch.object(sup,'sticky_hold') as hold:
            value,rc=sup.dispatch(runner=lambda *a,**k:denied)
        self.assertEqual(rc,3)
        self.assertEqual(value['state'],'RETRYABLE_PREWRITE_HOLD')
        hold.assert_not_called()

    def test_postwrite_error_current_verified_lease_does_not_latch_hold(self):
        denied=subprocess.CompletedProcess(['python'],5,stdout=json.dumps({
          'state':'ORIGINAL_BROKER_WRITE_RESULT_UNCONFIRMED',
          'error_code':'ORIGINAL305_SIGNED_POSTWRITE_ROOT_READBACK_UNCONFIRMED'}),stderr='')
        with mock.patch.object(sup,'independent_signed_lease_since',side_effect=[False,True]) as check, mock.patch.object(sup,'sticky_hold') as hold:
            value,rc=sup.dispatch(runner=lambda *a,**k:denied)
        self.assertEqual(rc,0)
        self.assertEqual(value['state'],'ORIGINAL305_CURRENT_SIGNED_LEASE_INDEPENDENTLY_VERIFIED')
        self.assertIsNone(value['new_signature_issued'])
        hold.assert_not_called()

    def test_unknown_write_without_proof_latches_hold(self):
        denied=subprocess.CompletedProcess(['python'],5,stdout=json.dumps({
          'state':'ORIGINAL_BROKER_WRITE_RESULT_UNCONFIRMED',
          'error_code':'ORIGINAL_WORDPRESS_SIGNED_WRITE_RESULT_UNKNOWN_TIMEOUT'}),stderr='')
        with mock.patch.object(sup,'independent_signed_lease_since',return_value=False), mock.patch.object(sup,'sticky_hold') as hold:
            value,rc=sup.dispatch(runner=lambda *a,**k:denied)
        self.assertEqual(rc,5)
        self.assertEqual(value['state'],'AMBIGUOUS_SIGNED_WRITE_HOLD_NO_AUTOMATIC_RETRY')
        hold.assert_called_once()

    def test_stale_wordpress_prewrite_is_retryable_not_permanent_hold(self):
        denied=subprocess.CompletedProcess(['python'],3,stdout=json.dumps({
            'state':'ORIGINAL_BROKER_READINESS_DENIED',
            'error_code':'ORIGINAL_WORDPRESS_SIGNED_WRITE_STALE_PREWRITE'}),stderr='')
        with mock.patch.object(sup,'independent_signed_lease_since',return_value=False), \
             mock.patch.object(sup,'sticky_hold') as hold:
            result,rc=sup.dispatch(runner=lambda *a,**k:denied)
        self.assertEqual(rc,3)
        self.assertEqual(result['state'],'RETRYABLE_PREWRITE_HOLD')
        hold.assert_not_called()

    def test_ambiguous_readback_retries_only_read_only_verifier(self):
        check=mock.Mock(side_effect=[False,True])
        sleep=mock.Mock()
        with mock.patch.object(sup,'independent_signed_lease_since',check):
            self.assertTrue(sup.independently_confirm_ambiguous_delivery(sleeper=sleep))
        self.assertEqual(check.call_count,2)
        sleep.assert_called_once_with(2)
        for call in check.call_args_list:
            self.assertEqual(call.kwargs,{'min_remaining':25})

    def test_ambiguous_readback_denial_after_bounded_retry(self):
        check=mock.Mock(return_value=False)
        sleep=mock.Mock()
        with mock.patch.object(sup,'independent_signed_lease_since',check):
            self.assertFalse(sup.independently_confirm_ambiguous_delivery(sleeper=sleep))
        self.assertEqual(check.call_count,2)
        sleep.assert_called_once_with(2)

    def test_existing_hold_never_signs_or_auto_clears(self):
        sup.HOLD.exists.return_value=True
        with mock.patch.object(sup,'file_read',return_value=b'{}'):
            signer=mock.Mock(side_effect=AssertionError('MUST_NOT_SIGN'))
            result,rc=sup.dispatch(runner=signer)
        self.assertEqual(rc,5)
        self.assertEqual(result['state'],'AMBIGUOUS_SIGNED_WRITE_HOLD_NO_AUTOMATIC_RETRY')
        signer.assert_not_called()

if __name__=='__main__': unittest.main()
