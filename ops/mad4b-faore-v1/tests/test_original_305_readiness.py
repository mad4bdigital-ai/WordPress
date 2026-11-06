"""Isolated Original 305 readiness inspection, without touching any site."""
import json
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import original_305_readiness as m

CENTRAL={"environment":"staging","container":"php-observed"}


def result(**changes):
    row={
        "contract":"mad4b.faore.original-305-readiness.v1",
        "company":"wayfatours",
        "central_site_profile_bound":True,
        "company_target_registered":True,
        "matching_transports_seen":2,
        "verified_grant_transport_pairs":1,
        "current_unexpired_request_count":0,
        "previous_or_expired_request_count":4,
        "transport_and_grant_ready":True,
        "ambiguous_verified_pairs":False,
        "new_authenticated_305_request_required":True,
        "authenticated_owner_session_proved":False,
        "job_created":False,
        "grant_issued":False,
        "original_313_certified":False,
        "mutation_performed":False
    }
    row.update(changes)
    return SimpleNamespace(returncode=0, stdout=json.dumps(row), stderr="")


class Original305ReadinessTests(unittest.TestCase):
    def run_case(self, response=None, company="wayfatours"):
        calls=[]
        def runner(args,**kwargs):
            calls.append(args)
            return response if response is not None else result()
        value=m.observe(company,discover=lambda environment:CENTRAL,runner=runner)
        return value,calls

    def test_exact_existing_pair_is_not_new_grant(self):
        actual,calls=self.run_case()
        self.assertTrue(actual["transport_and_grant_ready"])
        self.assertTrue(actual["new_authenticated_305_request_required"])
        self.assertFalse(actual["authenticated_owner_session_proved"])
        self.assertFalse(actual["original_313_certified"])
        self.assertFalse(actual["job_created"])
        self.assertEqual(calls[0][4], "php-observed")
        self.assertEqual(calls[0][8], "eval")

    def test_input_companies_never_interpolate_shell_tokens(self):
        for company in ("../../etc/passwd", "foo;echo", "x", "Wayfa"):
            with self.subTest(company=company):
                with self.assertRaisesRegex(ValueError,"COMPANY_INVALID"):
                    m.observe(company,discover=lambda environment:CENTRAL)

    def test_readback_mismatch_rejected(self):
        for data in (result(company="foreign"),
                     result(contract="foreign"),
                     result(job_created=True),
                     result(grant_issued=True),
                     result(authenticated_owner_session_proved=True),
                     result(original_313_certified=True),
                     result(mutation_performed=True),
                     result(verified_grant_transport_pairs=2,
                            transport_and_grant_ready=True)):
            with self.subTest(data=data.stdout):
                with self.assertRaises(RuntimeError):
                    self.run_case(data)

    def test_count_types_and_boundaries_rejected(self):
        for value in (True, -1, 151, 1.5, "1"):
            with self.subTest(value=value):
                with self.assertRaisesRegex(RuntimeError, "COUNTS_INVALID"):
                    self.run_case(result(current_unexpired_request_count=value))

    def test_busy_retries_once(self):
        calls=[]
        def runner(args,**kwargs):
            calls.append(args)
            if len(calls)==1:
                return SimpleNamespace(returncode=1,stdout="",
                                       stderr="STAGING_WPCLI_BUSY")
            return result()
        delays=[]
        data=m.observe("wayfatours",discover=lambda environment:CENTRAL,
                       runner=runner,sleeper=delays.append)
        self.assertEqual(delays,[3])
        self.assertEqual(len(calls),2)
        self.assertTrue(data["transport_and_grant_ready"])

    def test_nonbusy_denied_without_retry(self):
        calls=[]
        def runner(args,**kwargs):
            calls.append(args)
            return SimpleNamespace(returncode=1,stdout="",
                                   stderr="NOT_ALLOWED")
        with self.assertRaisesRegex(RuntimeError,"READBACK_UNAVAILABLE"):
            m.observe("wayfatours",discover=lambda environment:CENTRAL,
                      runner=runner,sleeper=lambda _ :None)
        self.assertEqual(len(calls),1)


if __name__=="__main__":
    unittest.main()
