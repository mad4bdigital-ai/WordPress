import importlib.util
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

script = Path(__file__).resolve().parents[1] / "mad4b_ci_latest_head_reaper.py"
spec = importlib.util.spec_from_file_location("reaper", script)
reaper = importlib.util.module_from_spec(spec)
sys.modules["reaper"] = reaper
spec.loader.exec_module(reaper)


def item(run_id, sha, pr=407, event="pull_request", branch="feature", state="queued"):
    return {
        "id": run_id, "head_sha": sha, "status": state,
        "event": event, "head_branch": branch,
        "pull_requests": [{"number": pr}],
    }


class LatestHeadReaperTests(unittest.TestCase):
    def test_only_stale_same_pr_is_candidate(self):
        assert reaper.candidate(item(10, "old"), number=407, sha="new", branch="feature")
        for row in [
            item(11, "new"),
            item(12, "old", pr=408),
            item(13, "old", event="push"),
            item(14, "old", branch="other"),
            item(15, "old", state="completed"),
            item(16, "old", event="pull_request_target"),
        ]:
            self.assertFalse(reaper.candidate(row, number=407, sha="new", branch="feature"))

    def test_missing_pr_association_is_not_attributed(self):
        row = item(13, "old")
        row["pull_requests"] = []
        self.assertFalse(reaper.candidate(row, number=407, sha="new", branch="feature"))

    def test_stale_run_collection_is_bounded_and_exact(self):
        def fake_api(method, repository, path, token):
            if "status=queued" in path:
                return {"workflow_runs": [item(101, "old"), item(102, "new"), item(103, "old", pr=408)]}
            return {"workflow_runs": []}
        with patch.object(reaper, "api", side_effect=fake_api):
            found = reaper.stale_runs("mad4bdigital-ai/WordPress", {
                "number": 407, "sha": "new", "branch": "feature"
            }, "token")
        self.assertEqual([x["id"] for x in found], [101])

    def test_changed_head_aborts_before_mutation(self):
        old = {"number": 407, "sha": "A", "branch": "feature"}
        newer = {"number": 407, "sha": "B", "branch": "feature"}
        with patch.object(reaper, "identify", side_effect=[old, newer]), \
             patch.object(reaper, "stale_runs", return_value=[item(101, "old")]), \
             patch.object(reaper, "api") as request:
            with self.assertRaisesRegex(RuntimeError, "PR_HEAD_MOVED"):
                reaper.run("mad4bdigital-ai/WordPress", 407, "token", True)
            request.assert_not_called()


    def test_pending_requested_states_are_eligible_but_same_head_is_not(self):
        for state in ("queued", "in_progress", "waiting", "pending", "requested"):
            self.assertTrue(reaper.candidate(
                item(100, "older", state=state), number=407, sha="latest", branch="feature"
            ))
            self.assertFalse(reaper.candidate(
                item(101, "latest", state=state), number=407, sha="latest", branch="feature"
            ))

    def test_overfull_search_window_is_partitioned_without_dropping_evidence(self):
        paths = []
        def fake_api(method, repository, path, token):
            paths.append(path)
            if "status=queued" not in path:
                return {"total_count": 0, "workflow_runs": []}
            if "created=" not in path:
                return {"total_count": 1000, "workflow_runs": []}
            if "page=1" in path:
                return {"total_count": 1, "workflow_runs": [item(100, "old")]}
            return {"total_count": 0, "workflow_runs": []}
        with patch.object(reaper, "api", side_effect=fake_api):
            actual = reaper.stale_runs("mad4bdigital-ai/WordPress", {
                "number": 407, "sha": "new", "branch": "feature",
                "created_at": "2026-10-17T00:00:00Z",
            }, "token")
        self.assertEqual([x["id"] for x in actual], [100])
        self.assertTrue(any("created=" in x for x in paths))

    def test_changes_during_long_cancel_batch_stop_at_next_recheck(self):
        earlier = {"number": 407, "sha": "new", "branch": "feature"}
        changed = {"number": 407, "sha": "later", "branch": "feature"}
        runs = [item(n, "old") for n in range(1, 29)]
        def fake_api(method, repository, path, token):
            return {}
        with patch.object(reaper, "identify", side_effect=[earlier, earlier, earlier, changed]), \
             patch.object(reaper, "stale_runs", return_value=runs), \
             patch.object(reaper, "api", side_effect=fake_api) as request, \
             patch.object(reaper.time, "sleep"):
            with self.assertRaisesRegex(RuntimeError, "PR_HEAD_MOVED_DURING_CANCELLATION"):
                reaper.run("mad4bdigital-ai/WordPress", 407, "token", True)
            self.assertEqual(request.call_count, 20)

    def test_pull_request_workflows_cannot_pin_concurrency_to_commit_sha(self):
        import re
        workflows = script.resolve().parents[1] / "workflows"
        checked = 0
        for workflow in sorted(workflows.glob("*.yml")):
            source = workflow.read_text(encoding="utf-8")
            if not re.search(r"^  pull_request(?:_target)?:", source, re.M):
                continue
            checked += 1
            block = re.search(r"^concurrency:\n((?:[ \t]+[^\n]*\n)+)", source, re.M)
            self.assertIsNotNone(block, workflow.name + " needs PR concurrency")
            lines = block.group(1)
            self.assertIn("github.event.pull_request.number", lines, workflow.name)
            self.assertIn("cancel-in-progress: true", lines, workflow.name)
            self.assertNotIn("github.event.pull_request.head.sha", lines, workflow.name)
            self.assertNotIn("github.sha", lines, workflow.name)
        self.assertGreater(checked, 50)

if __name__ == "__main__":
    unittest.main()
