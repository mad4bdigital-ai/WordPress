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

    def test_hosted_site_probe_refuses_plain_http_or_injected_paths(self):
        for target_url in ("http://example.com", "https://user:pass@example.com",
                           "https://example.com/admin", "https://example.com/?token=secret",
                           "https://127.0.0.1"):
            with self.assertRaises(ValueError, msg=target_url):
                runner.probe_wordpress_rest(target_url, "hostinger")

    def test_hosted_site_probe_accepts_only_bounded_readonly_origin(self):
        from unittest.mock import patch
        class FakeResponse:
            status = 200
            def __enter__(self):
                return self
            def __exit__(self, *args):
                pass
            def read(self, maximum):
                return b'{"name":"Example WP","routes":{}}'
        class FakeOpener:
            def open(self, request, timeout=8):
                self.requested = request.full_url
                return FakeResponse()
        opener = FakeOpener()
        with patch.object(runner.urllib.request, "build_opener", return_value=opener):
            result = runner.probe_wordpress_rest("https://wp.example.com", "wordpress_hosted")
        self.assertEqual("REACHABLE_NOT_CERTIFIED", result["state"])
        self.assertEqual("https://wp.example.com/wp-json/", result["endpoint"])
        self.assertTrue(result["read_only"])

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

    def test_manifest_is_pinned_to_exact_candidate_files(self):
        baseline = json.loads((ROOT / "tools/mad4b-local-ci-gates.json").read_text(encoding="utf-8"))
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "tools"
            path.mkdir()
            fixture = path / "mad4b-local-ci-gates.json"
            fixture.write_text(json.dumps(baseline), encoding="utf-8")
            cases, fingerprint = runner.load_manifest(Path(tmp), "extended")
            self.assertEqual(20, len(cases))
            self.assertEqual(64, len(fingerprint))
            for mode in ("remove_gate", "rewrite_file", "rewrite_args", "shrink_profile"):
                mutated = json.loads(json.dumps(baseline))
                gates = mutated["gates"]
                if mode == "remove_gate":
                    gates.pop(0)
                elif mode == "rewrite_file":
                    gates[0]["file"] = "staging-source-selector-runtime.php"
                elif mode == "rewrite_args":
                    gates[0]["args"] = ["unexpected"]
                else:
                    gates[0]["profiles"].remove("core")
                fixture.write_text(json.dumps(mutated), encoding="utf-8")
                with self.subTest(mode=mode), self.assertRaisesRegex(
                        ValueError, "required local CI baseline gate"):
                    runner.load_manifest(Path(tmp), "extended")

    def test_manifest_allows_additional_gates_without_shrinking_baseline(self):
        baseline = json.loads((ROOT / "tools/mad4b-local-ci-gates.json").read_text(encoding="utf-8"))
        baseline["gates"].append({
            "name": "additional-contract",
            "language": "php",
            "file": "staging-source-selector-runtime.php",
            "args": [],
            "profiles": ["extended"],
        })
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "tools"
            path.mkdir()
            (path / "mad4b-local-ci-gates.json").write_text(
                json.dumps(baseline), encoding="utf-8")
            expanded, _ = runner.load_manifest(Path(tmp), "extended")
            core, _ = runner.load_manifest(Path(tmp), "core")
            self.assertEqual(21, len(expanded))
            self.assertEqual(13, len(core))

    def test_manifest_refuses_arbitrary_commands_and_traversal(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "tools"
            path.mkdir()
            obj = {"contract": "mad4b.local-ci-gate-manifest.v1",
                   "non_authorizing": True, "github_workflows_fully_represented": False,
                   "gates": [{
                       "name": "arbitrary-command", "language": "php",
                       "file": "../../do-evil.php", "args": [],
                       "profiles": ["extended"]}]}
            file = path / "mad4b-local-ci-gates.json"
            file.write_text(json.dumps(obj), encoding="utf-8")
            with self.assertRaises(ValueError):
                runner.load_manifest(Path(tmp), "extended")
            obj["gates"][0]["file"] = "staging-source-selector-runtime.php"
            obj["gates"][0]["args"] = ["; rm -rf /"]
            file.write_text(json.dumps(obj), encoding="utf-8")
            with self.assertRaises(ValueError):
                runner.load_manifest(Path(tmp), "extended")

    def test_core_is_subset_of_extended(self):
        core, core_sha = runner.load_manifest(ROOT, "core")
        expanded, expanded_sha = runner.load_manifest(ROOT, "extended")
        self.assertGreaterEqual(len(expanded), 20)
        self.assertTrue({c[0] for c in core}.issubset({c[0] for c in expanded}))
        self.assertEqual(core_sha, expanded_sha)
        self.assertEqual(len({x[0] for x in expanded}), len(expanded))


if __name__ == "__main__":
    unittest.main(verbosity=2)
