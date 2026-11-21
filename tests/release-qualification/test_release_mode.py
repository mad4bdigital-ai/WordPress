import importlib.util
import json
from pathlib import Path
import unittest
ROOT = Path(__file__).resolve().parents[2]
module_path = ROOT / "tools" / "mad4b_release_qualification_mode.py"
spec = importlib.util.spec_from_file_location("mad4b_release_mode", module_path)
v = importlib.util.module_from_spec(spec)
spec.loader.exec_module(v)

class ReleaseQualificationTests(unittest.TestCase):
    def setUp(self):
        self.policy = v.load_policy(ROOT / ".github" / "mad4b-release-qualification-mode.json")
        self.base = {
            "contract": "mad4b.repository-release-verdict.v1",
            "repository_merge_gate_ready": True,
            "repository_release_ready": False,
            "artifact_required": True,
            "content_hub_artifact_required": False,
        }

    def test_default_optional_fleet_wide(self):
        self.assertEqual(self.policy["qualification_mode"], "optional")
        self.assertEqual(self.policy["optional_scope"], "manual_staging_only")

    def test_optional_does_not_claim_certification(self):
        x = v.evaluate(self.base, self.policy)
        self.assertFalse(x["release_artifact_verified"])
        self.assertFalse(x["automatic_publication_allowed"])
        self.assertFalse(x["site_execution_authorized"])
        self.assertFalse(x["production_authorized"])

    def test_owner_and_repository_merge_gate_never_optional(self):
        self.base["repository_merge_gate_ready"] = False
        with self.assertRaisesRegex(v.ReleaseQualificationDenied, "REPOSITORY_GOVERNANCE_STILL_REQUIRED"):
            v.evaluate(self.base, self.policy)

    def test_required_mode_reenables_artifact_gate(self):
        self.policy["qualification_mode"] = "required"
        with self.assertRaisesRegex(v.ReleaseQualificationDenied, "EXACT_RELEASE_ARTIFACT_NOT_CERTIFIED"):
            v.evaluate(self.base, self.policy)
        self.base["repository_release_ready"] = True
        self.assertTrue(v.evaluate(self.base, self.policy)["automatic_publication_allowed"])

    def test_optional_never_enables_auto_publish_even_when_artifact_valid(self):
        self.base["repository_release_ready"] = True
        self.assertFalse(v.evaluate(self.base, self.policy)["automatic_publication_allowed"])

    def test_missing_security_boundary_rejected(self):
        p = dict(self.policy)
        p["retained_requirements"] = ["repository_merge_gate"]
        import tempfile
        with tempfile.TemporaryDirectory() as d:
            path=Path(d)/"p.json"
            path.write_text(json.dumps(p),encoding="utf-8")
            with self.assertRaisesRegex(v.ReleaseQualificationDenied,"POLICY_INVALID"):
                v.load_policy(path)

    def test_policy_cannot_promote_optional_to_auto_publish(self):
        p=dict(self.policy);p["automatic_publication_when_optional"]=True
        import tempfile
        with tempfile.TemporaryDirectory() as d:
            path=Path(d)/"p.json"; path.write_text(json.dumps(p),encoding="utf-8")
            with self.assertRaisesRegex(v.ReleaseQualificationDenied,"POLICY_INVALID"):
                v.load_policy(path)

    def test_workflow_never_autopublishes_under_optional_mode(self):
        source=(ROOT/".github/workflows/mad4b-release-verdict.yml").read_text()
        self.assertIn("needs.verdict.outputs.qualification_mode == 'required'",source)
        self.assertIn("needs.verdict.outputs.artifact_required == 'true'",source)
        self.assertIn("Verify exact-head owner attestation",source)
        self.assertIn("tools/mad4b_release_qualification_mode.py",source)

    def test_host_runtime_write_authority_guards_unmodified(self):
        source=(ROOT/"wp-content/plugins/mad4b-site-control-plane/includes/"
                "class-mad4b-scp-runtime-release-set.php").read_text()
        self.assertIn("authorize_mutation(",source)
        self.assertIn("verified_bearer_has_scope(",source)
        self.assertIn("staging_guard()",source)
        self.assertIn("breakglass_active()",source)

    def test_root_github_ruleset_owner_requirement_retained(self):
        source=json.loads((ROOT/".github/mad4b-repository-governance-policy.json").read_text())
        self.assertTrue(source["single_owner_safety"]["required_by_repository_release_verdict"])
        self.assertTrue(source["single_owner_safety"]["owner_attestation_remains_exact_sha_scoped"])
        contexts=[x["context"] for x in source["required_status_checks"]["contexts"]]
        self.assertIn("Repository feature boundary",contexts)
        self.assertIn("Repository release verdict",contexts)

    def test_missing_verdict_rejected(self):
        with self.assertRaisesRegex(v.ReleaseQualificationDenied,"RELEASE_VERDICT_MISSING"):
            v.evaluate({},self.policy)

if __name__=="__main__":
    unittest.main()
