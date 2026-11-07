"""No host commands: classify a failed tool boundary from immutable evidence."""
import hashlib
import json
import sqlite3
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from operator_boundary_diagnostic import classify


class BoundaryTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / "operator"
        self.root.mkdir()
        self.id = "faore-safety-test123"
        self.case = self.root / self.id
        self.case.mkdir()
        (self.root / "receipts").mkdir()
        manifest = {"canary_id": self.id, "digests": {}}
        for name in ("apply.sh", "verify.sh", "rollback.sh", "backup.json"):
            p = self.case / name
            p.write_bytes(b"safe-immutable-evidence")
            p.chmod(0o600)
            manifest["digests"][name] = hashlib.sha256(p.read_bytes()).hexdigest()
        (self.case / "manifest.json").write_text(json.dumps(manifest))
        (self.case / "operator-requirements.json").write_text(json.dumps({
            "operator": {"correlation_id": self.id},
            "tool_arguments": {"key_name": "shell_access"},
            "site_scope": {"company": "tours-central", "environment": "staging"},
            "status": {"operator_plan": "ADMITTED_REQUIRES_DIRECT_OPERATOR_APPLY"},
        }))
        self.db = Path(self.temp.name) / "events.sqlite"
        with sqlite3.connect(self.db) as db:
            db.execute("CREATE TABLE events (job_id TEXT, event TEXT)")

    def inspect(self, platform_refusal=False):
        return classify(self.id, root=self.root, journal=self.db,
                        platform_refusal=platform_refusal)

    def test_known_tool_refusal_and_zero_events(self):
        row = self.inspect(platform_refusal=True)
        self.assertEqual(row["state"], "PLATFORM_REFUSAL_RECORDED_NO_HOST_ATTEMPT")
        self.assertTrue(all(row["input_integrity"].values()))
        self.assertFalse(row["operator_receipt_present"])
        self.assertEqual(row["host_journal_events"], 0)
        self.assertFalse(row["performs_target_action"])
        self.assertFalse(row["can_change_platform_safety_decision"])

    def test_no_refusal_does_not_invent_cause(self):
        self.assertEqual(self.inspect()["state"],
                         "NO_HOST_EXECUTION_EVIDENCE_CAUSE_UNDETERMINED")

    def test_denied_boundary_explains_owner_without_retry_or_new_grant(self):
        result=self.inspect(platform_refusal=True)
        self.assertEqual(result["blocker_owner"],"EXTERNAL_PLATFORM_SAFETY")
        self.assertEqual(result["resolution_code"],"REVIEW_ORIGINAL_PLATFORM_REFUSAL")
        self.assertEqual(result["platform_refusal_provenance"],"CALLER_REPORTED")
        self.assertFalse(result["automatic_retry_allowed"])
        self.assertFalse(result["alternative_execution_lane_allowed"])
        self.assertFalse(result["host_grant_change_required"])
        self.assertFalse(result["platform_policy_rule_known"])

    def test_undetermined_case_does_not_invent_platform_refusal(self):
        result=self.inspect()
        self.assertEqual(result["blocker_owner"],"UNDETERMINED")
        self.assertEqual(result["resolution_code"],"INSPECT_ORIGINAL_DELIVERY")
        self.assertEqual(result["platform_refusal_provenance"],"NOT_REPORTED")

    def test_receipt_requires_reconciliation(self):
        (self.root / "receipts" / (self.id + ".json")).write_text("{}")
        self.assertEqual(self.inspect(platform_refusal=True)["state"],
                         "EXECUTION_EVIDENCE_PRESENT_RECONCILE_BEFORE_ACTION")

    def test_journal_event_requires_reconciliation(self):
        with sqlite3.connect(self.db) as db:
            db.execute("INSERT INTO events VALUES (?, ?)",
                       ("operator:" + self.id, "OPERATOR_EXECUTION_ATTEMPTED"))
        self.assertEqual(self.inspect(platform_refusal=True)["host_journal_events"], 1)
        self.assertEqual(self.inspect(platform_refusal=True)["state"],
                         "EXECUTION_EVIDENCE_PRESENT_RECONCILE_BEFORE_ACTION")

    def test_changed_input_never_reports_valid(self):
        (self.case / "apply.sh").write_bytes(b"changed")
        self.assertEqual(self.inspect(platform_refusal=True)["state"],
                         "INPUT_INTEGRITY_FAILED")

    def test_missing_manifest_fails(self):
        (self.case / "manifest.json").unlink()
        with self.assertRaisesRegex(RuntimeError, "CASE_EVIDENCE_MISSING"):
            self.inspect()

    def test_invalid_correlation_fails(self):
        with self.assertRaisesRegex(ValueError, "CORRELATION_ID_INVALID"):
            classify("../../invalid", root=self.root, journal=self.db)

    def test_journal_unavailable_fails_closed(self):
        with self.assertRaisesRegex(RuntimeError, "HOST_JOURNAL_UNAVAILABLE"):
            classify(self.id, root=self.root, journal=self.db / "bad",
                     platform_refusal=True)

    def test_symlinked_case_folder_refused(self):
        linked = self.root / "faore-symlink-case"
        linked.symlink_to(self.case, target_is_directory=True)
        with self.assertRaisesRegex(RuntimeError, "CASE_PATH_UNSAFE"):
            classify(linked.name, root=self.root, journal=self.db,
                     platform_refusal=True)


if __name__ == "__main__":
    unittest.main()
