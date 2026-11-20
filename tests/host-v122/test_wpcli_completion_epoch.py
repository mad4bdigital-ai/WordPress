#!/usr/bin/env python3
"""Original313 v122 authentic after-WPCLI time, bounded windows, no replay."""
from pathlib import Path
import importlib.util
import hashlib
import unittest
ROOT=Path(__file__).resolve().parents[2]
P=ROOT/"ops/host-anchor-v122/original313_first_owner_enrollment_issuer.py"
ORIGINAL=Path("/opt/mad4b-company-transport/native-313-independent/host-authority-v105/original313_first_owner_enrollment_issuer.py")
sp=importlib.util.spec_from_file_location("v122_original_independent",P)
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
class Original313RealClockTests(unittest.TestCase):
    def test_real_slow_20_second_wpcli_readback_is_accepted_when_current(self):
        start=1000000
        self.assertTrue(m.verified_fresh_readback_epoch(
           start,start+20,start+19,start+220,start+1))
    def test_old_pre_wpcli_timestamp_check_would_have_rejected_same_read(self):
        start=1000000
        self.assertFalse(start-15<=start+19<=start+5)
        self.assertTrue(m.verified_fresh_readback_epoch(
           start,start+20,start+19,start+220,start+1))
    def test_stale_wp_readback_is_denied(self):
        with self.assertRaises(m.EnrollmentHeld):
            m.verified_fresh_readback_epoch(1000000,1000020,1000000,
                                           1000400,1000001)
    def test_future_wp_readback_is_denied(self):
        with self.assertRaises(m.EnrollmentHeld):
            m.verified_fresh_readback_epoch(1000000,1000020,1000026,
                                           1000400,1000001)
    def test_expired_owner_request_denied_even_with_new_readback(self):
        with self.assertRaises(m.EnrollmentHeld):
            m.verified_fresh_readback_epoch(1000000,1000020,1000020,
                                           1000030,1000001)
    def test_stale_claim_denied_even_with_new_readback(self):
        with self.assertRaises(m.EnrollmentHeld):
            m.verified_fresh_readback_epoch(1000000,1000020,1000020,
                                           1000400,999920)
    def test_clock_delay_over_original_wpcli_bound_denied(self):
        with self.assertRaises(m.EnrollmentHeld):
            m.verified_fresh_readback_epoch(1000000,1000046,1000046,
                                           1000600,1000001)
    def test_null_epoch_or_bool_rejected(self):
        for bad in (None,True,"1000020"):
            with self.subTest(bad=bad),self.assertRaises(m.EnrollmentHeld):
                m.verified_fresh_readback_epoch(1000000,1000020,bad,
                                                1000400,1000001)
    def test_immutable_original_v105_retained_and_signer_not_modified(self):
        old=ORIGINAL.read_text()
        new=P.read_text()
        self.assertIn('def first_party_originals(',new)
        self.assertEqual(new.count('v99.require_enrolled_live_signers('),
                         old.count('v99.require_enrolled_live_signers('))
        self.assertEqual(new.count('store.write_new(scope,signed)'),
                         old.count('store.write_new(scope,signed)'))
        self.assertEqual(new.count('v95.verify_against_originals('),
                         old.count('v95.verify_against_originals('))
        self.assertEqual(new.count('v86.inspect_claimed_original('),
                         old.count('v86.inspect_claimed_original('))
        self.assertEqual(hashlib.sha256(ORIGINAL.read_bytes()).hexdigest(),
                         '9abf86765eed1f7128258c997a474aecc6c872ad0552079189c61e5a6c1f3b71')
if __name__=="__main__":unittest.main(verbosity=2)
