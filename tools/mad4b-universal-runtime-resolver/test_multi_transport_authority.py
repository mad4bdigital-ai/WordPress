"""Isolated regression tests: authentication transport is not an authorization grant."""
import json
import unittest
from unittest.mock import patch
import multi_transport_authority as m

SCOPE={"site_uuid":"49c562d1-8f2f-456f-b454-26816c6ba4cb",
       "canonical_origin":"https://staging.allroyalegypt.com",
       "environment":"staging","transport_binding":"hosting-2:staging"}
TARGET="all-royal-egypt:staging"

class MultiTransportAuthorityTests(unittest.TestCase):
    def test_oauth_only_usable_in_real_bearer_request(self):
        self.assertEqual(m.oauth_status()["state"],"request_context_required")
        with patch.object(m,"exact_binding",return_value=True):
            out=m.decide(TARGET,SCOPE,"governed.write","oauth_mcp")
        self.assertEqual(out["state"],"awaiting_provider")

    def test_broker_transport_is_not_job_eligibility(self):
        with patch.object(m,"execute",side_effect=[
             {"contract":"mad4b.broker-exact-ssh-authority.v1",
              "verifier_registered":True,"shell_executed":False},
             {"contract":"mad4b.broker-exact-ssh-authority.v1","selftest":"PASS",
              "signed_central_preflight_round_trip":True,
              "unknown_job_denied":True}]):
            result=m.broker_status()
        self.assertEqual(result["state"],"verified")
        self.assertFalse(result["job_execution_eligible"])

    def test_broker_without_exact_job_does_not_admit_shell(self):
        with patch.object(m,"exact_binding",return_value=True):
            result=m.decide(TARGET,SCOPE,"maintenance.shell","broker")
        self.assertEqual(result["state"],"awaiting_provider")

    def test_unknown_job_is_denied_by_native_signed_authority(self):
        with patch.object(m,"execute",return_value={
            "contract":"mad4b.broker-exact-ssh-authority.v1",
            "eligible":False,"verified":False}):
            result=m.broker_exact_job_preflight("0"*32,"hosting-1","a"*64)
        self.assertEqual(result["state"],"blocked_authority")

    def test_broker_unknown_target_not_executed(self):
        with patch.object(m,"execute",side_effect=AssertionError("never query unregistered target")):
            result=m.broker_exact_job_preflight("0"*32,"hosting-2","a"*64)
        self.assertEqual(result["state"],"blocked_authority")

    def test_unknown_capability_denied_for_all_transports(self):
        with patch.object(m,"exact_binding",return_value=True):
            for auth in ("oauth_mcp","vps_ssh_wpcli","broker"):
                self.assertEqual(m.decide(TARGET,SCOPE,"raw_sql_everywhere",auth)["state"],
                                 "blocked_authority")

    def test_original_operator_is_only_host_shell_admission(self):
        with patch.object(m,"exact_binding",return_value=True):
            with patch.object(m,"host_admit_existing_canary",return_value={
                 "state":"verified","reason":"native_operator_plan"}):
                d=m.decide(TARGET,SCOPE,"maintenance.shell","vps_ssh_wpcli")
        self.assertEqual(d["state"],"verified")

    def test_wrong_company_binding_denied_even_with_host(self):
        with patch.object(m,"exact_binding",return_value=False):
            verdict=m.decide(TARGET,SCOPE,"maintenance.shell","vps_ssh_wpcli")
        self.assertEqual(verdict["state"],"review_required")

    def test_host_policy_revocation_does_not_reuse_previous_receipt(self):
        fake={"contract":"mad4b.native-vps-operator.v1","revoked":True,
              "allow_general_shell":True,"scopes":{TARGET:{"allow_operator":True}}}
        class File:
            def __init__(self,value):self.value=value
            def read_text(self):return json.dumps(self.value)
            def is_file(self):return True
        with patch.object(m,"exact_binding",return_value=True):
            with patch.object(m,"NATIVE",File({})):
                with patch.object(m,"OPERATOR_POLICY",File(fake)):
                    verdict=m.host_status(TARGET,SCOPE)
        self.assertEqual(verdict["state"],"blocked_authority")

    def test_signed_broker_claimed_list_empty_without_execution(self):
        with patch.object(m,"execute",return_value={
            "contract":"mad4b.broker-exact-ssh-authority.v1","claimed":[]}):
            v=m.claimed_broker_jobs()
        self.assertEqual(v["state"],"verified")
        self.assertEqual(v["claimed_job_count"],0)
        self.assertFalse(v["shell_executed"])

    def test_broker_claim_requires_original_per_job_preflight(self):
        job={"job_id":"a"*32,"target_id":"hosting-1",
             "command_sha256":"b"*64}
        with patch.object(m,"execute",return_value={
             "contract":"mad4b.broker-exact-ssh-authority.v1",
             "claimed":[job]}):
            with patch.object(m,"broker_exact_job_preflight",return_value={
                 "state":"blocked_dependency","reason":"independent_host_execution_proof_required"
                 }) as proof:
                result=m.claimed_broker_jobs()
        self.assertEqual(result["claimed_job_count"],1)
        proof.assert_called_once_with("a"*32,"hosting-1","b"*64)
        self.assertEqual(result["evaluated"][0]["state"],"blocked_dependency")
        self.assertFalse(result["shell_executed"])

    def test_broker_eligibility_is_not_host_execution_authority(self):
        verified={"contract":"mad4b.broker-exact-ssh-authority.v1",
                  "verified":True,"eligible":True,"job_id":"a"*32,
                  "target_id":"hosting-1","command_sha256":"b"*64,
                  "central_scope_only":True,"shell_executed":False}
        with patch.object(m,"execute",return_value=verified):
            result=m.broker_exact_job_preflight("a"*32,"hosting-1","b"*64)
        self.assertEqual(result["state"],"blocked_dependency")
        self.assertFalse(result["shell_executed"])

if __name__=="__main__":
    unittest.main()
