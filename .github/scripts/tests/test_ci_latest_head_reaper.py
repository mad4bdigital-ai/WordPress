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


if __name__ == "__main__":
    unittest.main()
