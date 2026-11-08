"""Hermetic Git-to-tenant release tests: no live WordPress or remote mutations."""
import base64
import datetime as dt
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
import sys

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

MODULE = Path(__file__).resolve().parents[1] / "company_code_release.py"
spec = importlib.util.spec_from_file_location("company_code_release", MODULE)
release = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = release
spec.loader.exec_module(release)


def shell(*args):
    return subprocess.check_output(args, stderr=subprocess.DEVNULL).decode().strip()


class CodeReleaseTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = Path(self.tmp.name)
        self.repo = base / "git-source"
        self.site = base / "wordpress"
        self.receipts = base / "receipts"
        self.repo.mkdir()
        self.site.mkdir()
        self.receipts.mkdir(mode=0o700)
        (self.repo / "ops" / "artifacts").mkdir(parents=True)
        (self.site / "wp-content" / "mu-plugins").mkdir(parents=True)
        self.source = self.repo / "ops" / "artifacts" / "approved.php"
        self.source.write_text("<?php echo 'NEW';\n")
        self.target = self.site / "wp-content" / "mu-plugins" / "mad4b-approved.php"
        self.target.write_text("<?php echo 'OLD';\n")
        shell("git", "-C", str(self.repo), "init", "-q")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "add", ".")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "commit", "-qm", "fixture")
        self.commit = shell("git", "-C", str(self.repo), "rev-parse", "HEAD")
        self.private = Ed25519PrivateKey.generate()
        public = self.private.public_key().public_bytes(
            serialization.Encoding.Raw, serialization.PublicFormat.Raw
        )
        self.policy = {
            "contract": release.POLICY_CONTRACT, "source_git_root": str(self.repo),
            "receipt_dir": str(self.receipts),
            "broker_public_key_b64": base64.b64encode(public).decode(),
            "targets": {"demo:staging": {
                "root": str(self.site), "container": "demo-php",
                "site_uuid": "c01aa1c0-1111-4444-8888-0123456789ab",
                "origin": "https://demo.example.invalid",
                "allowed_targets": [
                    "wp-content/mu-plugins/mad4b-approved.php",
                    "wp-content/mu-plugins/mad4b-new.php",
                ],
            }},
        }
        self.request = {
            "contract": release.SPEC_CONTRACT,
            "company": "demo", "environment": "staging",
            "site_uuid": self.policy["targets"]["demo:staging"]["site_uuid"],
            "git_commit": self.commit,
            "files": [{
                "source": "ops/artifacts/approved.php",
                "target": "wp-content/mu-plugins/mad4b-approved.php",
                "expected_before_sha256": release.digest(self.target.read_bytes()),
            }],
        }
        self.plan = release.make_plan(self.request, self.policy)

    def tearDown(self):
        self.tmp.cleanup()

    def grant(self, action="apply"):
        item = {
            "contract": release.AUTH_CONTRACT,
            "issuer": "mad4b-company-broker",
            "action": action, "plan_sha256": self.plan["plan_sha256"],
            "company": "demo", "environment": "staging",
            "site_uuid": self.request["site_uuid"],
            "nonce": "fixture_nonce_0123456789",
            "expires_at": (dt.datetime.now(dt.timezone.utc)
                           + dt.timedelta(minutes=10)).isoformat(),
        }
        item["signature"] = base64.b64encode(
            self.private.sign(release.grant_message(item))
        ).decode()
        return item

    def authorized_apply(self, grant=None, *, validator=None):
        return release.apply_release(self.plan, self.policy, grant or self.grant(),
                                     self.receipts,
                                     runtime_check=lambda p, cfg: None,
                                     validate_code=validator or (lambda p: None))

    def test_plan_is_non_mutating_and_from_git_commit(self):
        self.assertEqual(self.target.read_text(), "<?php echo 'OLD';\n")
        self.source.write_text("<?php echo 'uncommitted';\n")
        self.assertEqual(self.plan["files"][0]["source_sha256"],
                         release.digest(b"<?php echo 'NEW';\n"))
        self.assertEqual(self.target.read_text(), "<?php echo 'OLD';\n")

    def test_exact_scope_authorized_apply_and_readback(self):
        result = self.authorized_apply()
        self.assertEqual(result["state"], "APPLIED_VERIFIED")
        self.assertEqual(self.target.read_text(), "<?php echo 'NEW';\n")
        self.assertEqual(release.check_sha_readback(self.plan, self.policy)["state"],
                         "READBACK_VERIFIED")
        self.assertTrue(list(self.receipts.glob("*.json")))
        self.assertTrue(result["broker_grant_signature_verified"])
        self.assertFalse(result["broker_receipt_verified"])
        self.assertFalse(result["original_313_receipt"])
        with self.assertRaisesRegex(release.Denied, "GRANT_ALREADY_USED"):
            self.authorized_apply()

    def test_invalid_broker_signature_rejected(self):
        grant = self.grant()
        grant["signature"] = base64.b64encode(b"invalid").decode()
        with self.assertRaisesRegex(release.Denied, "BROKER_SIGNATURE"):
            self.authorized_apply(grant)
        self.assertIn("OLD", self.target.read_text())

    def test_nonce_replay_blocked_in_alternate_private_receipt_directory(self):
        self.authorized_apply()
        alternate = self.repo / "fresh-receipts"
        alternate.mkdir(mode=0o700)
        with self.assertRaisesRegex(release.Denied,
                                    "RECEIPT_DIRECTORY_NOT_PINNED"):
            release.apply_release(
                self.plan, self.policy, self.grant(), alternate,
                runtime_check=lambda p, cfg: None,
                validate_code=lambda p: None
            )
        self.assertEqual(self.target.read_text(), "<?php echo 'NEW';\n")

    def test_missing_receipt_root_policy_denied(self):
        self.policy.pop("receipt_dir")
        with self.assertRaisesRegex(release.Denied,
                                    "RECEIPT_ROOT_NOT_CONFIGURED"):
            self.authorized_apply()
        self.assertEqual(self.target.read_text(), "<?php echo 'OLD';\n")

    def test_broker_action_scope_rejected(self):
        grant = self.grant()
        grant["company"] = "other"
        with self.assertRaisesRegex(release.Denied, "BROKER_GRANT_NOT_BOUND"):
            self.authorized_apply(grant)

    def test_preimage_drift_blocks_write(self):
        self.target.write_text("<?php echo 'someone else changed this';")
        with self.assertRaisesRegex(release.Denied, "TARGET_CONTENT_DRIFT"):
            self.authorized_apply()
        self.assertIn("someone else", self.target.read_text())

    def test_validator_failure_rolls_back_and_records_receipt(self):
        def fail(_):
            raise RuntimeError("syntax failure fixture")
        result = self.authorized_apply(validator=fail)
        self.assertEqual(result["state"], "ROLLED_BACK")
        self.assertEqual(self.target.read_text(), "<?php echo 'OLD';\n")
        self.assertFalse(result["original_313_receipt"])

    def test_unenrolled_target_denied(self):
        request = json.loads(json.dumps(self.request))
        request["files"][0]["target"] = "wp-content/plugins/other/unsafe.php"
        with self.assertRaisesRegex(release.Denied, "TARGET_FILE_NOT_ALLOWED"):
            release.make_plan(request, self.policy)

    def test_symlink_denied(self):
        self.target.unlink()
        self.target.symlink_to(self.source)
        with self.assertRaisesRegex(release.Denied, "SYMLINK_PATH_DENIED"):
            release.make_plan(self.request, self.policy)

    def test_target_uuid_mismatch_denied(self):
        request = json.loads(json.dumps(self.request))
        request["site_uuid"] = "00000000-0000-0000-0000-000000000000"
        with self.assertRaisesRegex(release.Denied, "SITE_UUID_DRIFT"):
            release.make_plan(request, self.policy)

    def test_no_new_file_without_explicit_allowlist(self):
        self.request["files"][0]["target"] = "wp-content/mu-plugins/mad4b-new.php"
        self.request["files"][0]["expected_before_sha256"] = None
        plan = release.make_plan(self.request, self.policy)
        self.assertEqual(plan["changed_count"], 1)
        self.assertFalse((self.site / "wp-content/mu-plugins/mad4b-new.php").exists())

    def test_production_plan_uses_exact_binding_without_environment_waiver(self):
        # Production is no longer categorically denied, but its site profile,
        # per-file preimage and separate signature remain mandatory.
        self.policy["targets"]["demo:production"] = dict(
            self.policy["targets"]["demo:staging"])
        self.request["environment"] = "production"
        plan = release.make_plan(self.request, self.policy)
        self.assertEqual(plan["environment"], "production")
        release.confirm_plan(plan, self.policy, expect_preimage=True)
        with self.assertRaisesRegex(release.Denied,
                                    "BROKER_GRANT_NOT_BOUND_TO_RELEASE"):
            release.verify_grant(plan, self.policy, self.grant(), "apply")
        production_grant = self.grant()
        production_grant.update(
            environment="production", plan_sha256=plan["plan_sha256"],
        )
        production_grant["signature"] = base64.b64encode(
            self.private.sign(release.grant_message(production_grant))
        ).decode()
        outcome = release.apply_release(
            plan, self.policy, production_grant, self.receipts,
            runtime_check=lambda p,c:None, validate_code=lambda p:None
        )
        self.assertEqual(outcome["state"], "APPLIED_VERIFIED")
        self.assertEqual(self.target.read_text(), "<?php echo 'NEW';\n")
        self.assertFalse(outcome["original_313_receipt"])

    def test_custom_environment_plan_and_signed_exact_scope(self):
        self.policy["targets"]["demo:qa"] = dict(
            self.policy["targets"]["demo:staging"])
        self.request["environment"] = "qa"
        plan = release.make_plan(self.request, self.policy)
        self.assertEqual(plan["environment"], "qa")
        release.confirm_plan(plan, self.policy, expect_preimage=True)
        self.policy["targets"].pop("demo:qa")
        with self.assertRaisesRegex(release.Denied, "TARGET_NOT_ENROLLED"):
            release.confirm_plan(plan, self.policy, expect_preimage=True)

    def test_named_environment_no_longer_sets_authority(self):
        self.request["environment"] = "production"
        with self.assertRaisesRegex(release.Denied, "TARGET_NOT_ENROLLED"):
            release.make_plan(self.request, self.policy)

    def test_signed_grant_cannot_cross_environment(self):
        self.policy["targets"]["demo:production"] = dict(
            self.policy["targets"]["demo:staging"])
        self.request["environment"] = "production"
        prod = release.make_plan(self.request, self.policy)
        signed_staging = self.grant()
        with self.assertRaisesRegex(release.Denied,
                                    "BROKER_GRANT_NOT_BOUND_TO_RELEASE"):
            release.verify_grant(prod, self.policy, signed_staging, "apply")

    def test_separate_signed_rollback_restores_original(self):
        result = self.authorized_apply()
        self.assertEqual(result["state"], "APPLIED_VERIFIED")
        grant = self.grant("rollback")
        grant["nonce"] = "rollback_nonce_abcdef12345678"
        grant["signature"] = base64.b64encode(
            self.private.sign(release.grant_message(grant))
        ).decode()
        undo = release.rollback_release(
            self.plan, self.policy, grant, self.receipts,
            runtime_check=lambda p, cfg: None
        )
        self.assertEqual(undo["state"], "ROLLBACK_VERIFIED")
        self.assertEqual(self.target.read_text(), "<?php echo 'OLD';\n")
        self.assertEqual(undo["plan_sha256"], self.plan["plan_sha256"])

    def test_rollback_denied_after_policy_target_revocation(self):
        self.authorized_apply()
        self.policy["targets"]["demo:staging"]["allowed_targets"] = []
        grant = self.grant("rollback")
        grant["nonce"] = "rollback_revoked_abcdef1234"
        grant["signature"] = base64.b64encode(
            self.private.sign(release.grant_message(grant))
        ).decode()
        with self.assertRaisesRegex(release.Denied, "INCOMPLETE_TARGET_BINDING|PLAN_TARGET_NO_LONGER_ALLOWED"):
            release.rollback_release(self.plan, self.policy, grant, self.receipts,
                                     runtime_check=lambda p, cfg: None)
        self.assertEqual(self.target.read_text(), "<?php echo 'NEW';\n")

    def test_rollback_denied_if_git_source_hash_drift(self):
        self.authorized_apply()
        source_row = self.plan["files"][0]
        source_row["source_sha256"] = "0" * 64
        grant = self.grant("rollback")
        grant["nonce"] = "rollback_drift_abcdef12345"
        grant["signature"] = base64.b64encode(
            self.private.sign(release.grant_message(grant))
        ).decode()
        with self.assertRaisesRegex(release.Denied, "PLAN_TAMPERED"):
            release.rollback_release(self.plan, self.policy, grant, self.receipts,
                                     runtime_check=lambda p, cfg: None)
        self.assertEqual(self.target.read_text(), "<?php echo 'NEW';\n")

    def test_rollback_without_prior_verified_apply_denied(self):
        grant = self.grant("rollback")
        with self.assertRaisesRegex(release.Denied, "NO_VERIFIED_APPLY"):
            release.rollback_release(
                self.plan, self.policy, grant, self.receipts,
                runtime_check=lambda p, cfg: None
            )

    def test_backup_written_before_target_change(self):
        self.authorized_apply()
        path = release.backup_path(self.receipts, self.plan)
        self.assertTrue(path.exists())
        self.assertEqual(path.stat().st_mode & 0o777, 0o600)
        backup = release.load_backup(self.plan, self.receipts)
        self.assertEqual(backup["files"][0]["sha256"],
                         self.plan["files"][0]["expected_before_sha256"])

    def test_rollback_rejects_concurrent_edit(self):
        self.authorized_apply()
        self.target.write_text("<?php echo 'THIRD PARTY';\\n")
        grant = self.grant("rollback")
        grant["nonce"] = "rollback_nonce_abcdef12345678"
        grant["signature"] = base64.b64encode(
            self.private.sign(release.grant_message(grant))
        ).decode()
        with self.assertRaisesRegex(release.Denied, "TARGET_CONTENT_DRIFT"):
            release.rollback_release(self.plan, self.policy, grant, self.receipts,
                                     runtime_check=lambda p, cfg: None)
        self.assertIn("THIRD PARTY", self.target.read_text())

    def test_readback_rejects_unapplied_plan(self):
        with self.assertRaisesRegex(release.Denied, "TARGET_CONTENT_DRIFT"):
            release.check_sha_readback(self.plan, self.policy)

    def test_acceptance_checks_are_immutable_named_functions(self):
        self.request["acceptance_checks"] = ["wp_boot", "copy_coverage_readiness"]
        plan = release.make_plan(self.request, self.policy)
        self.assertEqual(plan["acceptance_checks"],
                         ["wp_boot", "copy_coverage_readiness"])
        release.confirm_plan(plan, self.policy, expect_preimage=True)
        plan["acceptance_checks"] = ["wp_boot"]
        with self.assertRaisesRegex(release.Denied, "PLAN_TAMPERED"):
            release.confirm_plan(plan, self.policy, expect_preimage=True)

    def test_unknown_or_injected_acceptance_check_denied(self):
        for value in ([ "wp_boot", "shell:rm -rf /" ],
                      [ "wp_boot", "wp_boot" ],
                      [ "copy_coverage_readiness" ],
                      [ "wp_boot", { "php": "exit(0);" } ]):
            with self.subTest(value=value):
                self.request["acceptance_checks"] = value
                with self.assertRaisesRegex(release.Denied,
                                            "UNREGISTERED_ACCEPTANCE_CHECK"):
                    release.make_plan(self.request, self.policy)

    def test_copy_coverage_runtime_acceptance_uses_fixed_wp_evaluation(self):
        self.plan["acceptance_checks"] = ["wp_boot", "copy_coverage_readiness"]
        calls = []
        original = release.run
        def fake(command, timeout=20):
            calls.append(command)
            if command[-2:] == ["php", "-l"]:
                return b"No syntax errors detected"
            if "php" in command and "-l" in command:
                return b"No syntax errors detected"
            if "MAD4B_CODE_RELEASE_COPY_READINESS_PASS" in command[-1]:
                return b"MAD4B_CODE_RELEASE_COPY_READINESS_PASS"
            return b"MAD4B_CODE_RELEASE_WP_BOOT_OK"
        try:
            release.run = fake
            release.php_lint(self.plan)
            self.assertEqual(len(calls), 3)
            self.assertIn("mad4b_copy_coverage_readiness_advisories", calls[-1][-1])
            self.assertNotIn("shell:", " ".join(" ".join(item) for item in calls))
        finally:
            release.run = original

    def test_copy_coverage_acceptance_ignores_unrelated_incomplete_scan(self):
        plan = dict(self.plan)
        plan["acceptance_checks"] = ["wp_boot", "copy_coverage_readiness"]
        cmds = []
        before = release.run
        def fake_run(command, timeout=20):
            cmds.append(command)
            if "php" in command and "-l" in command:
                return b"No syntax errors detected"
            if "MAD4B_CODE_RELEASE_COPY_READINESS_PASS" in command[-1]:
                return b"MAD4B_CODE_RELEASE_COPY_READINESS_PASS"
            return b"MAD4B_CODE_RELEASE_WP_BOOT_OK"
        try:
            release.run = fake_run
            release.php_lint(plan)
        finally:
            release.run = before
        script = cmds[-1][-1]
        self.assertIn('mad4b.content-readiness.discovery.v1', script)
        self.assertIn('mad4b-elementor-copy-coverage', script)
        self.assertIn('apply_filters("mad4b/content_readiness/external_issues",[])',
                      script)
        self.assertNotIn('$r["complete"]', script)
        self.assertNotIn("exit(3);'\n            'echo", script)

    def test_copy_coverage_acceptance_failure_blocks_release(self):
        self.plan["acceptance_checks"] = ["wp_boot", "copy_coverage_readiness"]
        original = release.run
        def fake(command, timeout=20):
            if "php" in command and "-l" in command:
                return b"No syntax errors detected"
            if "MAD4B_CODE_RELEASE_COPY_READINESS_PASS" in command[-1]:
                return b""
            return b"MAD4B_CODE_RELEASE_WP_BOOT_OK"
        try:
            release.run = fake
            with self.assertRaisesRegex(release.Denied,
                                        "COPY_COVERAGE_RUNTIME_ACCEPTANCE_NOT_PROVEN"):
                release.php_lint(self.plan)
        finally:
            release.run = original

    def test_malicious_paths_denied(self):
        for value in ("../wp-config.php", "/etc/passwd", "foo//bar.php",
                      "foo\\bar.php", "wp-content/mu-plugins/x.php/../y.php"):
            with self.subTest(value=value):
                with self.assertRaises(release.Denied):
                    release.exact_relative(value)

    def test_broker_expired_grant_denied(self):
        grant = self.grant()
        grant["expires_at"] = (dt.datetime.now(dt.timezone.utc)
                               - dt.timedelta(seconds=5)).isoformat()
        grant["signature"] = base64.b64encode(
            self.private.sign(release.grant_message(grant))
        ).decode()
        with self.assertRaisesRegex(release.Denied, "BROKER_SIGNATURE_OR_EXPIRY_INVALID"):
            self.authorized_apply(grant)

    def runtime_fixture(self):
        registry = {
            "contract": "mad4b.company-native-target-runtime-bindings.v1",
            "targets": {"demo:staging": {
                "site_uuid": self.request["site_uuid"],
                "canonical_origin": "https://demo.example.invalid",
                "target_container": "legacy-renamed-container",
                "coolify_service_uuid": "example123service",
            }},
        }
        observed = {
            "contract": "mad4b.faore.native-operator-dynamic-target.v1",
            "company_id": "demo", "environment": "staging",
            "site_uuid": self.request["site_uuid"],
            "canonical_origin": "https://demo.example.invalid",
            "target_container": "demo-php",
            "coolify_service_uuid": "example123service",
            "read_only": True, "authorization_created": False,
            "native_execution_performed": False,
        }
        mounts = [{
            "Type": "bind", "RW": True,
            "Destination": "/var/www/html",
            "Source": str(self.site),
        }]
        return registry, observed, mounts

    def test_verified_dynamic_container_rotates_old_registry_alias(self):
        registry, observed, mounts = self.runtime_fixture()
        release.assert_runtime_binding(
            self.plan, self.policy,
            read_registry=lambda: registry,
            read_original_target=lambda c, e: observed,
            read_mounts=lambda name: mounts,
        )
        self.assertEqual(registry["targets"]["demo:staging"]["target_container"],
                         "legacy-renamed-container")

    def test_dynamic_binding_rejects_stale_git_plan_container(self):
        registry, observed, mounts = self.runtime_fixture()
        self.plan["container"] = "outdated-php"
        with self.assertRaisesRegex(release.Denied, "DYNAMIC_CONTAINER_RELEASE_PLAN_DRIFT"):
            release.assert_runtime_binding(
                self.plan, self.policy,
                read_registry=lambda: registry,
                read_original_target=lambda c, e: observed,
                read_mounts=lambda name: mounts,
            )

    def test_dynamic_binding_rejects_unverified_site_identity(self):
        registry, observed, mounts = self.runtime_fixture()
        observed["site_uuid"] = "00000000-0000-0000-0000-000000000000"
        with self.assertRaisesRegex(release.Denied, "ORIGINAL_DYNAMIC_TARGET_IDENTITY_INVALID"):
            release.assert_runtime_binding(
                self.plan, self.policy,
                read_registry=lambda: registry,
                read_original_target=lambda c, e: observed,
                read_mounts=lambda name: mounts,
            )

    def test_dynamic_binding_rejects_foreign_mount(self):
        registry, observed, mounts = self.runtime_fixture()
        mounts[0]["Source"] = str(self.repo)
        with self.assertRaisesRegex(release.Denied, "TARGET_CONTAINER_MOUNT_MISMATCH"):
            release.assert_runtime_binding(
                self.plan, self.policy,
                read_registry=lambda: registry,
                read_original_target=lambda c, e: observed,
                read_mounts=lambda name: mounts,
            )

    def test_dynamic_binding_rejects_coolify_service_drift(self):
        registry, observed, mounts = self.runtime_fixture()
        observed["coolify_service_uuid"] = "foreign123service"
        with self.assertRaisesRegex(release.Denied, "ORIGINAL_COOLIFY_SERVICE_DRIFT"):
            release.assert_runtime_binding(
                self.plan, self.policy,
                read_registry=lambda: registry,
                read_original_target=lambda c, e: observed,
                read_mounts=lambda name: mounts,
            )

    def test_doctor_distinguishes_305_312_from_missing_release_handler(self):
        original = {
            "contract": "mad4b.company-code-release.lifecycle-bridge.v1",
            "company": "demo", "environment": "staging",
            "site_uuid": self.request["site_uuid"],
            "original_305_standing_wpcli_verified": True,
            "original_312_verifier_registered": True,
            "original_313_code_release_handler_registered": False,
            "company_code_release_dispatch_ability_registered": False,
            "blockers": ["ORIGINAL_313_COMPANY_CODE_RELEASE_HANDLER_NOT_REGISTERED"],
        }
        result = release.release_admission_doctor(
            self.plan,self.policy,
            fixed_policy_path=self.repo/"no-installed-policy",
            runtime_check=lambda p,c: None, original_broker_status=original)
        self.assertEqual(result["state"],"HOLD_CODE_RELEASE_OPERATION_ENROLLMENT")
        self.assertTrue(result["original_305_standing_wpcli_verified"])
        self.assertTrue(result["original_312_verifier_registered"])
        self.assertFalse(result["production_execution_authorized"])
        self.assertFalse(result["production_mutated"])

    def test_original_broker_doctor_refuses_other_company_identity(self):
        foreign = {
            "contract": "mad4b.company-code-release.lifecycle-bridge.v1",
            "company": "foreign", "environment": "staging",
            "site_uuid": self.request["site_uuid"],
        }
        with self.assertRaisesRegex(release.Denied,
                                    "ORIGINAL_BROKER_STATUS_SCOPE_INVALID"):
            release.release_admission_doctor(
                self.plan,self.policy,
                fixed_policy_path=self.repo/"no-installed-policy",
                runtime_check=lambda p,c: None,
                original_broker_status=foreign)

    def test_admission_doctor_reports_unregistered_broker_without_grant(self):
        report = release.release_admission_doctor(
            self.plan, self.policy,
            fixed_policy_path=self.repo / "absent-policy.json",
            runtime_check=lambda plan, policy: None
        )
        self.assertTrue(report["git_plan_verified"])
        self.assertTrue(report["original_runtime_binding_verified"])
        self.assertFalse(report["host_policy_installed_and_pinned"])
        self.assertEqual(report["state"], "HOLD_ORIGINAL_BROKER_ENROLLMENT")
        self.assertFalse(report["production_execution_authorized"])
        self.assertFalse(report["production_mutated"])
        self.assertIn("PINNED_HOST_RELEASE_POLICY_NOT_ENROLLED",
                      report["blockers"])

    def test_admission_doctor_exposes_preimage_drift_without_write(self):
        self.target.write_text("<?php echo 'OTHER';\n")
        report = release.release_admission_doctor(
            self.plan, self.policy,
            fixed_policy_path=self.repo / "absent-policy.json",
            runtime_check=lambda plan, policy: None
        )
        self.assertFalse(report["git_plan_verified"])
        self.assertEqual(report["state"], "HOLD_GIT_PLAN_OR_TARGET_DRIFT")
        self.assertFalse(report["production_execution_authorized"])
        self.assertIn("OTHER", self.target.read_text())

    def test_plan_mutation_denied(self):
        self.plan["files"][0]["source_sha256"] = "0" * 64
        with self.assertRaisesRegex(release.Denied, "PLAN_TAMPERED"):
            self.authorized_apply()


if __name__ == "__main__":
    unittest.main(verbosity=2)
