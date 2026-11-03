import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from datetime import datetime, timezone, timedelta

ROOT=Path("/opt/mad4b-company-transport/channel-governance-v1/governance.py")
spec=importlib.util.spec_from_file_location("channel_governance",ROOT)
c=importlib.util.module_from_spec(spec)
spec.loader.exec_module(c)

class GovernancePolicyTest(unittest.TestCase):
    def test_tool_refusal_is_not_site_refusal(self):
        a=c.decision("TOOL_INVOCATION_BLOCKED")
        self.assertEqual(a["category"],"tool_policy")
        self.assertFalse(a["fallback_allowed"])
    def test_security_denial_never_switches(self):
        self.assertFalse(c.decision("ORIGINAL_AUTHORITY_DENIED")["fallback_allowed"])
    def test_host_identity_mismatch_never_switches(self):
        self.assertFalse(c.decision("HOST_IDENTITY_MISMATCH")["fallback_allowed"])
    def test_transport_can_replan_before_dispatch(self):
        self.assertTrue(c.decision("TRANSPORT_UNAVAILABLE_PRE_DISPATCH")["fallback_allowed"])
    def test_transport_cannot_replan_after_dispatch(self):
        self.assertFalse(c.decision("TRANSPORT_UNAVAILABLE_PRE_DISPATCH",effect_started=True)["fallback_allowed"])
    def test_uncertain_effect_never_replays(self):
        self.assertEqual(c.decision("EXECUTION_EFFECT_UNCERTAIN")["action"],"STOP_AND_RECONCILE_ORIGINAL_RECEIPT_NO_REPLAY")
    def test_read_only_failure_not_write_denial(self):
        self.assertEqual(c.decision("READ_ONLY_PROBE_FAILED")["category"],"diagnostics")
    def test_unknown_is_deny(self):
        self.assertEqual(c.decision("new_failure")["action"],"STOP_UNCLASSIFIED")
    def test_valid_status_and_stale(self):
        with tempfile.TemporaryDirectory() as td:
            p=Path(td)/"status.json"
            now=datetime.now(timezone.utc)
            p.write_text(json.dumps({"contract":"example","checked_at":now.isoformat()}))
            self.assertEqual(c.read_snapshot(p,"example",now=now)["state"],"CURRENT_SNAPSHOT")
            later=now+timedelta(seconds=1000)
            self.assertEqual(c.read_snapshot(p,"example",now=later)["state"],"STALE_SNAPSHOT")
    def test_schema_mismatch(self):
        with tempfile.TemporaryDirectory() as td:
            p=Path(td)/"status.json"
            p.write_text(json.dumps({"contract":"wrong","checked_at":datetime.now(timezone.utc).isoformat()}))
            self.assertEqual(c.read_snapshot(p,"example")["state"],"INVALID_STATUS_CONTRACT")
    def test_missing_snapshot_is_not_execution_failure(self):
        self.assertEqual(c.read_snapshot("/tmp/does-not-exist-387124.json","example")["state"],"STATUS_UNAVAILABLE")

if __name__ == "__main__": unittest.main()
