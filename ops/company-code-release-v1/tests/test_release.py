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

    def capability_portability_fixture(self):
        """The example class moved to a plugin; a MU path isn't a permanent owner."""
        plugin = self.site / "wp-content/plugins/mad4b-experience"
        relative = "src/presentation/modules/374-mad4b-owner-bridge.php"
        replacement = plugin / relative
        replacement.parent.mkdir(parents=True)
        replacement.write_text("<?php echo 'PLUGIN';\n")
        manifest = plugin / "mad4b-portability.json"
        manifest.write_text(json.dumps({
            "contract": "mad4b.plugin-portability-profile.v1",
            "plugin": "mad4b-experience",
            "company_specific_legacy_mu_replacements": [{
                "legacy_file": "mad4b-approved.php",
                "replacement": relative,
                "reconcile_contract": "mad4b.option-owner-v1",
            }],
        }))
        target = "wp-content/plugins/mad4b-experience/" + relative
        self.policy["targets"]["demo:staging"]["allowed_targets"].append(target)
        return manifest, replacement, target

    def test_legacy_mu_file_is_not_permanently_protected(self):
        manifest, new_file, target = self.capability_portability_fixture()
        # Legitimate legacy maintenance may continue while the canonical
        # module is parked. The record does not infer the active hook owner.
        plan = release.make_plan(self.request, self.policy)
        self.assertEqual(len(plan["owner_reconciliation"]["relations"]), 1)
        entry = plan["owner_reconciliation"]["relations"][0]
        self.assertEqual(entry["selected_role"], "legacy")
        self.assertTrue(entry["replacement_present"])
        self.assertFalse(entry["runtime_owner_inferred"])
        self.assertFalse(entry["retirement_authorized"])
        release.confirm_plan(plan, self.policy, expect_preimage=True)

    def test_replacement_with_existing_legacy_owner_requires_proof(self):
        _, new_file, target = self.capability_portability_fixture()
        request = json.loads(json.dumps(self.request))
        request["files"][0]["target"] = target
        request["files"][0]["expected_before_sha256"] = release.digest(
            new_file.read_bytes())
        with self.assertRaisesRegex(release.Denied,
                                    "OWNER_TRANSITION_RUNTIME_PROOF_REQUIRED"):
            release.make_plan(request, self.policy)
        self.assertIn("OLD", self.target.read_text())

    def test_retired_mu_file_does_not_block_canonical_plugin_update(self):
        _, new_file, target = self.capability_portability_fixture()
        self.target.unlink()  # Retirement is represented, not performed by planner.
        request = json.loads(json.dumps(self.request))
        request["files"][0]["target"] = target
        request["files"][0]["expected_before_sha256"] = release.digest(
            new_file.read_bytes())
        plan = release.make_plan(request, self.policy)
        relation = plan["owner_reconciliation"]["relations"][0]
        self.assertFalse(relation["legacy_present"])
        self.assertTrue(relation["replacement_present"])
        self.assertEqual(relation["selected_role"], "canonical")
        release.confirm_plan(plan, self.policy, expect_preimage=True)
        # A concurrent reintroduction of the old MU owner MUST invalidate
        # the pinned release even when the plugin's own preimage is unchanged.
        self.target.write_text("<?php echo 'unexpected second owner';")
        with self.assertRaisesRegex(release.Denied,
                                    "OWNER_TRANSITION_RUNTIME_PROOF_REQUIRED"):
            release.confirm_plan(plan, self.policy, expect_preimage=True)

    def test_cannot_transfer_both_owners_via_unsigned_file_pair(self):
        _, new_file, target = self.capability_portability_fixture()
        request = json.loads(json.dumps(self.request))
        request["files"].append({
            "source": "ops/artifacts/approved.php", "target": target,
            "expected_before_sha256": release.digest(new_file.read_bytes()),
        })
        with self.assertRaisesRegex(release.Denied,
                                    "DUAL_OWNER_MIGRATION_NOT_CERTIFIED"):
            release.make_plan(request, self.policy)

    def test_portability_manifest_drift_blocks_original_release(self):
        manifest, _, _ = self.capability_portability_fixture()
        plan = release.make_plan(self.request, self.policy)
        record = json.loads(manifest.read_text())
        record["company_specific_legacy_mu_replacements"][0][
            "reconcile_contract"] = "later-owner-revision"
        manifest.write_text(json.dumps(record))
        with self.assertRaisesRegex(release.Denied,
                                    "CAPABILITY_OWNER_STATE_DRIFT"):
            release.confirm_plan(plan, self.policy, expect_preimage=True)

    def test_unrelated_mu_patch_does_not_impose_legacy_file_retention(self):
        self.capability_portability_fixture()
        request = json.loads(json.dumps(self.request))
        request["files"][0]["target"] = "wp-content/mu-plugins/mad4b-new.php"
        request["files"][0]["expected_before_sha256"] = None
        plan = release.make_plan(request, self.policy)
        self.assertEqual(plan["owner_reconciliation"]["relations"], [])
        release.confirm_plan(plan, self.policy, expect_preimage=True)

    def source_portability_fixture(self):
        """An immutable source can declare MU -> plugin migration before Wayfa does."""
        root = self.repo / "wp-content/plugins/mad4b-experience"
        root.mkdir(parents=True, exist_ok=True)
        replacement = "src/presentation/modules/374-mad4b-owner-bridge.php"
        path = root / "mad4b-portability.json"
        path.write_text(json.dumps({
            "contract": "mad4b.plugin-portability-profile.v1",
            "plugin": "mad4b-experience",
            "company_specific_legacy_mu_replacements": [{
                "legacy_file": "mad4b-approved.php",
                "replacement": replacement,
                "reconcile_contract": "mad4b.owner-transition-v1",
            }],
        }))
        shell("git", "-C", str(self.repo), "add", ".")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "commit", "-qm", "profile")
        self.commit = shell("git", "-C", str(self.repo), "rev-parse", "HEAD")
        self.request["git_commit"] = self.commit
        target = "wp-content/plugins/mad4b-experience/" + replacement
        self.policy["targets"]["demo:staging"]["allowed_targets"].append(target)
        return path, target

    def test_git_source_only_migration_blocks_late_target_plugin_copy(self):
        _, target = self.source_portability_fixture()
        self.assertFalse((self.site / "wp-content/plugins/mad4b-experience/mad4b-portability.json").exists())
        request = json.loads(json.dumps(self.request))
        request["files"][0]["target"] = target
        request["files"][0]["expected_before_sha256"] = None
        with self.assertRaisesRegex(release.Denied,
                                    "OWNER_TRANSITION_RUNTIME_PROOF_REQUIRED"):
            release.make_plan(request, self.policy)
        # Source Git is a candidate lineage, never permission to take over
        # an existing MU hook owner on the target tenant.
        self.assertIn("OLD", self.target.read_text())

    def test_source_only_owner_mapping_is_discovered_without_global_mu_block(self):
        _, _ = self.source_portability_fixture()
        plan = release.make_plan(self.request, self.policy)
        migration = plan["owner_reconciliation"]["relations"][0]
        self.assertEqual(migration["selected_role"], "legacy")
        self.assertIsNotNone(migration["source_manifest_sha256"])
        self.assertIsNone(migration["target_manifest_sha256"])
        release.confirm_plan(plan, self.policy, expect_preimage=True)
        # Working-tree edits cannot rewrite the source proof pinned by Git.
        source = self.repo / "wp-content/plugins/mad4b-experience/mad4b-portability.json"
        source.write_text("{bad uncommitted JSON}")
        release.confirm_plan(plan, self.policy, expect_preimage=True)

    def test_divergent_source_and_tenant_successor_is_not_silently_overridden(self):
        self.source_portability_fixture()
        target = self.site / "wp-content/plugins/mad4b-experience"
        target.mkdir(parents=True, exist_ok=True)
        (target / "mad4b-portability.json").write_text(json.dumps({
            "contract": "mad4b.plugin-portability-profile.v1",
            "plugin": "mad4b-experience",
            "company_specific_legacy_mu_replacements": [{
                "legacy_file": "mad4b-approved.php",
                "replacement": "src/presentation/modules/another-owner.php",
                "reconcile_contract": "mad4b.owner-transition-v1",
            }],
        }))
        with self.assertRaisesRegex(release.Denied,
                                    "PORTABILITY_OWNER_MIGRATION_CONFLICT"):
            release.make_plan(self.request, self.policy)

    def test_canonical_plugin_release_readback_after_legacy_retirement(self):
        _, new_file, target = self.capability_portability_fixture()
        self.target.unlink()
        request = json.loads(json.dumps(self.request))
        request["files"][0]["target"] = target
        request["files"][0]["expected_before_sha256"] = release.digest(new_file.read_bytes())
        plan = release.make_plan(request, self.policy)
        self.plan = plan
        result = self.authorized_apply()
        self.assertEqual(result["state"], "APPLIED_VERIFIED")
        self.assertEqual(new_file.read_text(), "<?php echo 'NEW';\n")
        release.confirm_plan(plan, self.policy, expect_preimage=False)
        self.assertFalse(self.target.exists())

    def test_cannot_install_source_only_owner_manifest_over_active_mu(self):
        _, _ = self.source_portability_fixture()
        target = "wp-content/plugins/mad4b-experience/mad4b-portability.json"
        self.policy["targets"]["demo:staging"]["allowed_targets"].append(target)
        request = json.loads(json.dumps(self.request))
        request["files"][0].update(source=target, target=target,
                                   expected_before_sha256=None)
        with self.assertRaisesRegex(release.Denied,
                                    "OWNER_PROFILE_MIGRATION_REQUIRES_PROOF"):
            release.make_plan(request, self.policy)

    def test_portability_manifest_can_install_after_mu_retired(self):
        _, _ = self.source_portability_fixture()
        (self.site / "wp-content/plugins/mad4b-experience").mkdir(parents=True)
        target = "wp-content/plugins/mad4b-experience/mad4b-portability.json"
        self.policy["targets"]["demo:staging"]["allowed_targets"].append(target)
        self.target.unlink()
        request = json.loads(json.dumps(self.request))
        request["files"][0].update(source=target, target=target,
                                   expected_before_sha256=None)
        plan = release.make_plan(request, self.policy)
        self.assertEqual(plan["owner_reconciliation"]["relations"][0][
            "selected_role"], "profile")
        self.assertTrue(plan["owner_reconciliation"]["relations"][0][
            "manifest_target_selected"])
        release.confirm_plan(plan, self.policy, expect_preimage=True)
        self.plan = plan
        result = self.authorized_apply()
        self.assertEqual(result["state"], "APPLIED_VERIFIED", result)
        release.confirm_plan(plan, self.policy, expect_preimage=False)

    def test_existing_dynamic_catalog_stays_functional_with_owner_gate(self):
        # The original registered host Provider has a newer dynamic catalog
        # than the source branch. The feature must preserve that authority
        # contract, not regress to a fixed MU allowlist.
        target = "wp-content/mu-plugins/mad4b-catalog-migration-test.php"
        source = self.repo / target
        source.parent.mkdir(parents=True, exist_ok=True)
        source.write_text("<?php echo 'catalog target';\n")
        catalog = self.repo / "ops/company-code-release-v1/catalog/mad4b-release-components.json"
        catalog.parent.mkdir(parents=True, exist_ok=True)
        catalog.write_text(json.dumps({
            "contract": "mad4b.company-release-components.v1",
            "components": [{"id": "mad4b-catalog-source-test", "files": [target]}],
        }))
        shell("git", "-C", str(self.repo), "add", ".")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "commit", "-qm", "catalog")
        self.commit = shell("git", "-C", str(self.repo), "rev-parse", "HEAD")
        self.policy["targets"]["demo:staging"]["release_catalog"] = (
            "ops/company-code-release-v1/catalog/mad4b-release-components.json")
        request = json.loads(json.dumps(self.request))
        request["git_commit"] = self.commit
        request["files"][0].update(source=target, target=target,
                                   expected_before_sha256=None)
        plan = release.make_plan(request, self.policy)
        self.assertEqual(plan["release_catalog"],
                         self.policy["targets"]["demo:staging"]["release_catalog"])
        self.assertEqual(plan["owner_reconciliation"]["relations"], [])
        release.confirm_plan(plan, self.policy, expect_preimage=True)
        self.plan = plan
        result = self.authorized_apply()
        self.assertEqual(result["state"], "APPLIED_VERIFIED", result)
        self.assertEqual((self.site / target).read_text(), source.read_text())

    def test_untrusted_portability_path_is_rejected(self):
        manifest, _, _ = self.capability_portability_fixture()
        record = json.loads(manifest.read_text())
        record["company_specific_legacy_mu_replacements"][0][
            "replacement"] = "src/../../other-plugin/untrusted.php"
        manifest.write_text(json.dumps(record))
        with self.assertRaisesRegex(release.Denied,
                                    "NONCANONICAL_FILE_PATH|UNSAFE_FILE_PATH"):
            release.make_plan(self.request, self.policy)

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
        self.assertIn('apply_filters("mad4b/content_readiness/external_issues",'
                      '[],["post_types"=>["elementor_library"]])', script)
        self.assertNotIn('apply_filters("mad4b/content_readiness/external_issues",[])',
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
