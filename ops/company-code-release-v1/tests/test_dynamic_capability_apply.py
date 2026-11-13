"""Signed dynamic acceptance gates the Host receipt, not just the finalizer."""
import base64
import datetime as dt
import json
from pathlib import Path
import os
import unittest
import test_release as core
release = core.release
shell = core.shell
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives import serialization


class DynamicCapabilityApplyTests(unittest.TestCase):
    setUp = core.CodeReleaseTests.setUp
    tearDown = core.CodeReleaseTests.tearDown
    grant = core.CodeReleaseTests.grant

    def enroll(self):
        check_module = release.capability_checks
        self.acceptance_sk = Ed25519PrivateKey.generate()
        public = self.acceptance_sk.public_key().public_bytes(
            serialization.Encoding.Raw, serialization.PublicFormat.Raw)
        catalog_path = self.repo / "ops/company-code-release-v1/catalog/capability-acceptance.json"
        catalog_path.parent.mkdir(parents=True)
        catalog_path.write_text(json.dumps({
            "contract": check_module.CATALOG_CONTRACT,
            "checks": [{"provider": "demo.capability",
                        "capability": "site.functional.parity",
                        "id": "parity.smoke",
                        "targets": ["wp-content/mu-plugins/mad4b-approved.php"]}]}))
        shell("git", "-C", str(self.repo), "add", ".")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "commit", "-qm", "catalog")
        self.request["git_commit"] = shell("git", "-C", str(self.repo), "rev-parse", "HEAD")
        self.policy["capability_acceptance"] = {
            "contract": check_module.REGISTRY_CONTRACT,
            "catalog_path": "ops/company-code-release-v1/catalog/capability-acceptance.json",
            "providers": {"demo.capability": {
                "enabled": True,
                "capabilities": ["site.functional.parity"],
                "public_key_b64": base64.b64encode(public).decode(),
            }}}
        self.plan = release.make_plan(self.request, self.policy)
        self.assertEqual(len(self.plan["capability_acceptance"]["checks"]), 1)

    def apply(self, **extra):
        return release.apply_release(
            self.plan, self.policy, self.grant(), self.receipts,
            runtime_check=lambda *_: None,
            validate_code=lambda *_: None,
            preflight_code=lambda *_: None, **extra)

    def test_missing_provider_evidence_rolls_back_without_success(self):
        self.enroll()
        result = self.apply()
        self.assertEqual(result["state"], "ROLLED_BACK", result)
        self.assertIn("CAPABILITY_ACCEPTANCE_RECEIPT_DIRECTORY_UNTRUSTED",
                      result["failed_reason"])
        self.assertIn("OLD", self.target.read_text())
        self.assertFalse(result["original_313_receipt"])

    def test_verified_provider_can_pass_with_exact_signed_attestation(self):
        if os.geteuid() != 0:
            self.skipTest("production root-private evidence fixture")
        self.enroll()
        contract = release.capability_checks
        def attest(plan, policy, receipts, nonce, started):
            directory = receipts / "capability-acceptance"
            directory.mkdir(mode=0o700)
            payload = {
                "contract": contract.EVIDENCE_CONTRACT,
                "provider": "demo.capability",
                "plan_sha256": plan["plan_sha256"],
                "git_commit": plan["git_commit"],
                "site_uuid": plan["site_uuid"],
                "apply_nonce": nonce,
                "catalog_sha256": plan["capability_acceptance"]["catalog_sha256"],
                "observed_at": dt.datetime.now(dt.timezone.utc).isoformat(),
                "results": [{"capability": "site.functional.parity",
                             "id": "parity.smoke", "passed": True}],
            }
            payload["signature"] = base64.b64encode(
                self.acceptance_sk.sign(contract.canonical(payload))).decode()
            proof = directory / (nonce + ".demo.capability.json")
            proof.write_text(json.dumps(payload))
            proof.chmod(0o600)
            return contract.verify_evidence(plan, policy, receipts, nonce, started)
        result = self.apply(validate_capabilities=attest)
        self.assertEqual(result["state"], "APPLIED_VERIFIED", result)
        self.assertIn("NEW", self.target.read_text())
        intent_path = release.apply_intent_path(self.receipts, self.grant()["nonce"])
        intent = json.loads(intent_path.read_text())
        verified = contract.verify_evidence(self.plan, self.policy,
                                            self.receipts, self.grant()["nonce"], intent["at"])
        self.assertEqual(verified["verified_providers"], ["demo.capability"])

    def test_registered_provider_revocation_invalidates_plan(self):
        self.enroll()
        self.policy["capability_acceptance"]["providers"][
            "demo.capability"]["enabled"] = False
        with self.assertRaisesRegex(Exception, "PROVIDER_ENROLLMENT_INVALID"):
            self.apply()
        self.assertIn("OLD", self.target.read_text())


if __name__ == "__main__":
    unittest.main()
