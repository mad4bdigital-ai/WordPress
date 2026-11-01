"""Static contract of Content Hub independent package and repository gate."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class ContentHubVerdictTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.verdict = (ROOT / ".github/workflows/mad4b-release-verdict.yml").read_text()
        cls.workflow = (ROOT / ".github/workflows/mad4b-content-hub-package.yml").read_text()
        cls.script = (ROOT / "tools/mad4b_content_hub_package.py").read_text()

    def test_original_plugin_source_is_runtime_component(self):
        self.assertIn("'ops/mad4b-governed-task-context-v1/',", self.verdict)
        self.assertIn("content_hub_required = any(", self.verdict)
        self.assertIn("'content_hub_artifact_required': content_hub_required", self.verdict)

    def test_independent_original_package_checked_at_verdict(self):
        self.assertIn("required = list(required) + ['Content Hub exact-head package']", self.verdict)
        self.assertIn("tools/mad4b_content_hub_package.py','verify'", self.verdict)
        self.assertIn("hub_artifact_ready=not content_hub_artifact_required", self.verdict)
        self.assertIn("and hub_artifact_ready", self.verdict)
        self.assertIn("or verdict.get('content_hub_artifact_required')", self.verdict)

    def test_does_not_pass_off_control_plane_as_hub(self):
        self.assertIn("exact_name='mad4b-content-hub-exact-head-'", self.verdict)
        self.assertIn("'component':'mad4b-content-hub'", self.verdict)
        self.assertIn("authorizes_site_deployment':False", self.verdict)

    def test_exact_head_build_and_immutable_github_receipt(self):
        self.assertIn("ref: ${{ env.SOURCE_SHA }}", self.workflow)
        self.assertIn("name: Content Hub exact-head package", self.workflow)
        self.assertIn("actions/upload-artifact@v4", self.workflow)
        self.assertIn("a['workflow_run']['head_sha']==head", self.workflow)
        self.assertIn("actions/attest@v4", self.workflow)

    def test_rollback_is_not_claimed_by_package(self):
        self.assertIn('"deployment_authorized": False', self.script)
        self.assertIn('"runtime_mutation_performed": False', self.script)
        self.assertIn('release_receipt_mismatch', self.script)


if __name__ == "__main__":
    unittest.main()
