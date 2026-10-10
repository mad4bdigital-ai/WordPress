#!/usr/bin/env python3
"""No-network, no-Docker contract for the multi-environment CI evidence runner."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
MODULE = ROOT / "tools" / "mad4b-local-ci-parity.py"
spec = importlib.util.spec_from_file_location("mad4b_local_ci_parity", MODULE)
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class MultiEnvironmentCITest(unittest.TestCase):
    def setUp(self):
        self.sha = "a" * 40

    def test_sha_is_exact_and_not_a_branch_implicitly(self):
        self.assertEqual(self.sha, runner.resolve("commit", self.sha))
        for ref in ("", "HEAD", "A" * 40, "a" * 39, "a" * 41):
            with self.assertRaises(ValueError):
                runner.resolve("commit", ref)

    def test_dynamic_pull_request_is_not_fixed_to_258(self):
        self.assertEqual("350", runner.clean_reference("pull_request", "350"))
        self.assertEqual("8471", runner.clean_reference("pull_request", "8471"))
        for ref in ("0", "-1", "../123", "abc", "123/.."):
            with self.assertRaises(ValueError):
                runner.clean_reference("pull_request", ref)

    def test_branch_selector_is_safe(self):
        self.assertEqual("feature/new-build", runner.clean_reference("branch", "feature/new-build"))
        for ref in ("../main", "bad//name", "bad..name", "/main", "release.lock"):
            with self.assertRaises(ValueError):
                runner.clean_reference("branch", ref)

    def test_repo_is_allowlisted(self):
        with self.assertRaises(ValueError):
            runner.resolve("commit", self.sha, "another/repository")

    def test_site_types_are_not_assumed_docker(self):
        for target in ("hostinger", "wordpress_hosted", "wordpress_local"):
            with tempfile.TemporaryDirectory() as tmp:
                path = Path(tmp) / "snapshot.json"
                row = {"target_type": target, "site_url": "https://staging.example.test",
                       "profile_environment": "staging", "wordpress_environment": "staging",
                       "installed_source_sha": self.sha, "deployment_binding_ready": True}
                path.write_text(json.dumps(row), encoding="utf-8")
                result = runner.inspect_site_evidence(path, "https://staging.example.test", self.sha)
                self.assertEqual("OBSERVED_NOT_CERTIFIED", result["state"])
                self.assertFalse(result["host_snapshot_signed"])
                self.assertFalse(result["host_mutation_performed"])
                self.assertTrue(result["candidate_already_installed"])

    def test_stale_site_observation_is_not_candidate_identity(self):
        with tempfile.TemporaryDirectory() as tmp:
            p = Path(tmp) / "snapshot.json"
            p.write_text(json.dumps({
                "target_type": "hostinger", "site_url": "https://staging.example.test",
                "profile_environment": "staging", "wordpress_environment": "production",
                "installed_source_sha": "b" * 40, "deployment_binding_ready": False
            }), encoding="utf-8")
            state = runner.inspect_site_evidence(p, "https://staging.example.test", self.sha)
            self.assertFalse(state["candidate_already_installed"])
            self.assertFalse(state["deployment_binding_ready"])
            self.assertEqual("production", state["wordpress_environment"])

    def test_wrong_site_target_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            p = Path(tmp) / "snapshot.json"
            p.write_text(json.dumps({
                "target_type": "hostinger", "site_url": "https://unexpected.example.test",
                "profile_environment": "staging", "wordpress_environment": "staging",
                "installed_source_sha": self.sha
            }), encoding="utf-8")
            with self.assertRaises(ValueError):
                runner.inspect_site_evidence(p, "https://expected.example.test", self.sha)

    def test_no_silent_ci_or_production_promotion(self):
        text = MODULE.read_text(encoding="utf-8")
        for token in ('"github_ci_certified": False', '"staging_certified": False',
                      '"production_authorized": False', '"release_promotion_authorized": False',
                      '"all_github_workflows_reproduced": False',
                      '"hostinger_live_acceptance_run": False'):
            self.assertIn(token, text)
        self.assertNotIn("wp plugin install", text)
        self.assertNotIn("wp plugin update", text)
        self.assertNotIn("sshpass", text)

    def test_core_is_subset_of_extended(self):
        available = {r[0] for r in runner.GATES}
        self.assertGreaterEqual(len(runner.GATES), 20)
        self.assertTrue(runner.CORE_NAMES.issubset(available))
        self.assertEqual(len(available), len(runner.GATES))


if __name__ == "__main__":
    unittest.main(verbosity=2)
