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
        self.producer_manifest={
            "provider":"demo.capability",
            "operation_id":"demo.capability.verify"}
        self.policy["capability_acceptance"] = {
            "contract": check_module.REGISTRY_CONTRACT,
            "catalog_path": "ops/company-code-release-v1/catalog/capability-acceptance.json",
            "providers": {"demo.capability": {
                "enabled": True,
                "capabilities": ["site.functional.parity"],
                "public_key_b64": base64.b64encode(public).decode(),
                "producer_operation_id":self.producer_manifest["operation_id"],
                "producer_manifest_sha256":release.capability_checks.sha(
                    release.capability_checks.canonical(self.producer_manifest)),
            }}}
        self.plan = release.make_plan(self.request, self.policy)
        self.assertEqual(len(self.plan["capability_acceptance"]["checks"]), 1)

    def apply(self, **extra):
        return release.apply_release(
            self.plan, self.policy, self.grant(), self.receipts,
            runtime_check=lambda *_: None,
            validate_code=lambda *_: None,
            preflight_code=lambda *_: None,
            producer_check=lambda *_: {"producer_operations": [
                self.producer_manifest["operation_id"]]},
            produce_capabilities=extra.pop("produce_capabilities",
                                           lambda *_: None), **extra)

    def test_unenrolled_runtime_provider_blocks_before_first_write(self):
        self.enroll()
        with self.assertRaisesRegex(Exception, "PRODUCER_"):
            release.apply_release(
                self.plan, self.policy, self.grant(), self.receipts,
                runtime_check=lambda *_:None,
                validate_code=lambda *_:None,
                preflight_code=lambda *_:None)
        self.assertIn("OLD",self.target.read_text())
        self.assertFalse(list(self.receipts.glob("*.intent.json")))

    def test_wired_registry_without_signed_dispatch_denied_before_write(self):
        self.enroll()
        with self.assertRaisesRegex(Exception,"PRODUCER_DISPATCH_NOT_ENROLLED"):
            release.apply_release(
                self.plan,self.policy,self.grant(),self.receipts,
                runtime_check=lambda *_:None,
                validate_code=lambda *_:None,
                preflight_code=lambda *_:None,
                producer_check=lambda *_:{"producer_operations":[
                    self.producer_manifest["operation_id"]]})
        self.assertIn("OLD",self.target.read_text())
        self.assertFalse(list(self.receipts.glob("*.intent.json")))

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
        def attest(plan, policy, receipts, nonce, started, challenge,
                   postimage_sha256):
            directory = receipts / "capability-acceptance"
            directory.mkdir(mode=0o700)
            payload = {
                "contract": contract.EVIDENCE_CONTRACT,
                "provider": "demo.capability",
                "producer_operation_id":self.producer_manifest["operation_id"],
                "plan_sha256": plan["plan_sha256"],
                "git_commit": plan["git_commit"],
                "site_uuid": plan["site_uuid"],
                "apply_nonce": nonce,
                "challenge": challenge,
                "postimage_sha256": postimage_sha256,
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
            return contract.verify_evidence(
                plan, policy, receipts, nonce, started, challenge,
                postimage_sha256)
        result = self.apply(produce_capabilities=attest)
        self.assertEqual(result["state"], "APPLIED_VERIFIED", result)
        self.assertIn("NEW", self.target.read_text())
        verified = contract.verify_evidence(
            self.plan, self.policy, self.receipts, self.grant()["nonce"],
            result["postimage_verified_at"],
            result["acceptance_challenge"], result["postimage_sha256"])
        self.assertEqual(verified["verified_providers"], ["demo.capability"])
        self.assertEqual(result["capability_evidence_sha256"],
                         verified["evidence_sha256"])
        self.assertEqual(len(result["acceptance_challenge"]), 64)

    def test_external_edit_during_provider_readback_never_certifies_apply(self):
        self.enroll()
        def external_writer(plan, policy, receipts, nonce, observed_at,
                            challenge, postimage):
            self.target.write_text('<?php echo "FOREIGN AFTER POSTIMAGE";')
            return {"verified_checks":1,
                    "evidence_sha256":{"demo.capability":"d"*64}}
        result=self.apply(produce_capabilities=external_writer,
                          validate_capabilities=lambda *_:{
                              "verified_checks":1,
                              "evidence_sha256":{"demo.capability":"d"*64}})
        self.assertEqual(result["state"],"ROLLBACK_UNVERIFIED",result)
        self.assertIn("FOREIGN AFTER POSTIMAGE",self.target.read_text())
        self.assertFalse(result["original_313_receipt"])
        self.assertIsNone(result["acceptance_challenge"])
        self.assertIn("TARGET_CONTENT_DRIFT",result["failed_reason"])

    def test_registered_provider_revocation_invalidates_plan(self):
        self.enroll()
        self.policy["capability_acceptance"]["providers"][
            "demo.capability"]["enabled"] = False
        with self.assertRaisesRegex(Exception, "PROVIDER_NOT_ENROLLED"):
            self.apply()
        self.assertIn("OLD", self.target.read_text())


if __name__ == "__main__":
    unittest.main()
