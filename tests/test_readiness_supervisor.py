"""Purely local, network-free tests of the host-side native CLI supervisor."""
import contextlib
import importlib.util
import io
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "tools/mad4b-content-readiness-supervisor.py"
spec = importlib.util.spec_from_file_location("mad4b_readiness_supervisor", SOURCE)
supervisor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(supervisor)

UUID_A = "a3a7cc36-db7e-4a14-8456-b41857def05c"
UUID_B = "e5e64bca-ed62-48d2-b2b3-dff693cd702a"
C1 = "php-aaaaaaaaaaaaaaaaaaaa"
C2 = "php-bbbbbbbbbbbbbbbbbbbb"


def plan(uuid=UUID_A, *, due=True, eligible=True):
    return dict(
        contract="mad4b.cli-readiness.v1.plan",
        operation="content_readiness.scheduled_scan",
        site_uuid=uuid,
        context={"site_uuid": uuid},
        plan_sha256="a" * 64,
        eligible=eligible,
        due=due,
    )


class TestSupervisor(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.lock = Path(self.temp.name) / "supervisor.lock"

    def run_main(self, container_names, plans, args=("--dry-run",), command=None):
        output = io.StringIO()
        with patch.object(supervisor, "LOCK", self.lock), \
             patch.object(supervisor, "containers", return_value=container_names), \
             patch.object(supervisor, "wp_plan", side_effect=plans), \
             patch.object(supervisor, "command", side_effect=command or
                          AssertionError("native run must not execute")), \
             patch.object(sys, "argv", ["supervisor"] + list(args)), \
             contextlib.redirect_stdout(output):
            rc = supervisor.main()
        return rc, json.loads(output.getvalue())

    def test_normal_due_dry_run(self):
        rc, data = self.run_main([C1, C2], [plan(UUID_A), plan(UUID_B, due=False)])
        self.assertEqual(rc, 0)
        self.assertEqual([r["result"] for r in data["results"]],
                         ["due_dry_run", "not_due"])

    def test_denied_native_policy_never_runs(self):
        rc, data = self.run_main([C1], [plan(eligible=False)], ())
        self.assertEqual(rc, 0)
        self.assertEqual(data["results"][0]["result"], "policy_blocked")

    def test_duplicate_site_identity_refused(self):
        rc, data = self.run_main([C1, C2], [plan(UUID_A), plan(UUID_A)], ())
        self.assertEqual(rc, 1)
        self.assertEqual([r["reason"] for r in data["results"]],
                         ["duplicate_site_identity"] * 2)

    def test_native_run_requires_readback(self):
        call_args = []
        checks = [plan(UUID_A, due=True), plan(UUID_A, due=False)]
        def fake_command(argv, _timeout):
            call_args.append(argv)
            return (0, json.dumps({
                "contract": "mad4b.cli-readiness.v1.run",
                "issue_registry_count": 800,
            }), "")
        rc, data = self.run_main([C1], checks, (), fake_command)
        self.assertEqual(rc, 0)
        self.assertEqual(data["results"][0]["result"], "native_run_verified")
        self.assertEqual(len(call_args), 1)
        self.assertEqual(call_args[0][:5],
                         ["docker", "exec", "-u", "33:33", C1])

    def test_stale_readback_fails(self):
        rc, data = self.run_main(
            [C1], [plan(UUID_A, due=True), plan(UUID_A, due=True)], (),
            lambda _args, _timeout: (0, json.dumps({
                "contract": "mad4b.cli-readiness.v1.run"}), ""))
        self.assertEqual(rc, 1)
        self.assertEqual(data["results"][0]["reason"], "native_post_run_due_still_true")

    def test_invalid_plan_hash_fails_closed(self):
        p = plan()
        p["plan_sha256"] = "not-a-hash"
        rc, data = self.run_main([C1], [p], ())
        self.assertEqual(rc, 1)
        self.assertEqual(data["results"][0]["reason"], "plan_hash_invalid")


if __name__ == "__main__":
    unittest.main()
