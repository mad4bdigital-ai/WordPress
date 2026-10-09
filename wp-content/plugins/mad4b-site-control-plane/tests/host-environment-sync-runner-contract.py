#!/usr/bin/env python3
"""Pure/isolated Host Runner test: governed WordPress staging environment sync.

Uses only temporary roots; never reads or writes a real WordPress site.
"""
import importlib.util
import base64
import json
import os
import stat
import tempfile
import uuid
from pathlib import Path

try:
    from cryptography.hazmat.primitives import serialization
    from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
    from cryptography.exceptions import InvalidSignature
except ImportError as exc:
    raise AssertionError("BLOCKED: Host Runner receipt signature acceptance needs audited cryptography package") from exc

RUNNER_PATH = Path(__file__).resolve().parents[4] / "tools" / "mad4b_host_runner.py"
spec = importlib.util.spec_from_file_location("mad4b_host_runner", RUNNER_PATH)
assert spec and spec.loader
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


def expect_rejection(fn, desc):
    try:
        fn()
    except (ValueError, RuntimeError, InvalidSignature, runner.HostRunnerResourceError):
        return
    raise AssertionError(desc)


def profile_for(site, private, site_uuid, config, workspace):
    value = {
        "profile_id": "fixture-staging-runner",
        "site_uuid": site_uuid,
        "environment": "staging",
        "wordpress_root": str(site.resolve()),
        "wp_config_sha256": runner.sha256_file(config),
        "journal_root": str(workspace / "journals"),
        "rollback_root": str(workspace / "rollback"),
        "bridge_root": str(workspace / "bridge"),
        "host_environment_backup_root": str(private),
    }
    value["target_fingerprint"] = runner.sha256_bytes(runner.canonical_json({
        "site_uuid": site_uuid, "environment": "staging",
        "wordpress_root": value["wordpress_root"],
        "wp_config_sha256": value["wp_config_sha256"],
    }))
    return value


def exact_sync(profile):
    plan = {
        "contract": runner.WP_ENVIRONMENT_SYNC_CONTRACT,
        "operation_id": "wordpress_environment_sync",
        "operation_version": 1,
        "runner_profile_id": profile["profile_id"],
        "site_uuid": profile["site_uuid"],
        "environment": "staging",
        "target_fingerprint": profile["target_fingerprint"],
        "expected_wp_config_sha256": profile["wp_config_sha256"],
        "expected_wordpress_environment": "production",
        "desired_wordpress_environment": "staging",
        "expected_profile_digest": "a"*64,
        "expected_profile_revision": 1,
        "deployment_binding_digest": "b"*64,
        "change_strategy": "guarded_wp_config_insert_before_settings",
        "backup_before_replace": True,
        "rollback_on_failed_readback": True,
        "require_new_bootstrap_verification": True,
        "caller_supplied_path_allowed": False,
        "caller_supplied_php_allowed": False,
        "production_authorized": False,
        "reason": "Staging exact host verification",
    }
    plan["plan_sha256"] = runner.plan_digest(plan)
    return plan


def wrapped(op, plan):
    return {
        "operation_id": op,
        "input": {"plan": plan},
        "plan_sha256": plan["plan_sha256"],
        "job_id": str(uuid.uuid4()),
        "approval_ref": "approved:fixture-staging",
    }


def test_flow():
    with tempfile.TemporaryDirectory(prefix="mad4b-host-env-") as work:
        tmp = Path(work)
        site = tmp / "site"
        site.mkdir()
        content = site / "wp-content" / "mad4b-runner"
        content.mkdir(parents=True)
        private = tmp / "secret-host-snapshots"
        private.mkdir(mode=0o700)
        private.chmod(0o700)
        config = site / "wp-config.php"
        original = (b"<?php\n// exact fixture settings\n"
                    b"define('DB_PASSWORD', 'test-only-not-a-real-secret');\n"
                    b"if ( ! defined( 'ABSPATH' ) ) define('ABSPATH', __DIR__ . '/');\n"
                    b"require_once ABSPATH . 'wp-settings.php';\n")
        config.write_bytes(original)
        config.chmod(0o600)
        site_uuid = "11111111-2222-4333-8444-555555555555"
        profile = profile_for(site, private, site_uuid, config, content)
        signer = Ed25519PrivateKey.generate()
        private_file = tmp / "runner-env-signing.pem"
        private_file.write_bytes(signer.private_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PrivateFormat.PKCS8,
            encryption_algorithm=serialization.NoEncryption(),
        ))
        private_file.chmod(0o600)
        public_raw = signer.public_key().public_bytes(
            encoding=serialization.Encoding.Raw, format=serialization.PublicFormat.Raw
        )
        profile["host_environment_receipt_signing_key_file"] = str(private_file)
        profile["host_environment_receipt_signing_public_key_b64"] = base64.b64encode(public_raw).decode("ascii")
        runner._wp_environment_receipt_signing_key(profile)
        plan = exact_sync(profile)
        verified = wrapped("wordpress_environment_sync", plan)
        result = runner.execute_wp_environment_sync(profile, verified)
        assert result["readback_verdict"] == "PASS"
        assert result["mutation_performed"] is True
        assert result["fresh_wordpress_bootstrap_verified"] is False
        assert result["release_certified"] is False
        assert runner.sha256_file(config) == result["after_sha256"]
        assert config.read_bytes().count(b"define( 'WP_ENVIRONMENT_TYPE', 'staging' );") == 1
        assert stat.S_IMODE(config.stat().st_mode) == 0o600
        assert not (content / "rollback" / f"{verified['job_id']}.bin").exists()
        snapshot = private / f"{verified['job_id']}.bin"
        assert snapshot.read_bytes() == original
        assert stat.S_IMODE(snapshot.stat().st_mode) == 0o600


        # Detached Host-private attestation is independently verifiable with a
        # pinned public key. Neither unsigned nor tampered spool data qualifies.
        signed_receipt = {
            "job_id": verified["job_id"],
            "site_uuid": site_uuid,
            "environment": "staging",
            "operation_id": "wordpress_environment_sync",
            "plan_sha256": plan["plan_sha256"],
            "authority_ref": "c"*64,
            "approval_ref": verified["approval_ref"],
            "target_fingerprint": profile["target_fingerprint"],
            "runner_source_sha256": runner.sha256_file(RUNNER_PATH),
            "completed_at": runner.utc_now(),
            "readback_verdict": "PASS",
            "mutation_performed": True,
            "result": {
                **{k: v for k, v in result.items() if not k.startswith("_")},
            }
        }
        attestation = runner._wp_environment_sign_receipt(profile, signed_receipt)
        signature = base64.b64decode(attestation["signature_b64"], validate=True)
        assert attestation["pinned_public_key_sha256"] == runner.sha256_bytes(public_raw)
        signed_bytes = runner.canonical_json(runner._wp_environment_receipt_payload(signed_receipt))
        signer.public_key().verify(signature, signed_bytes)
        signed_receipt["result"]["after_sha256"] = "0"*64
        expect_rejection(
            lambda: signer.public_key().verify(
                signature, runner.canonical_json(runner._wp_environment_receipt_payload(signed_receipt))
            ),
            "tampered Host receipt incorrectly retained independent signature",
        )
        signed_receipt["result"]["after_sha256"] = result["after_sha256"]
        profile["host_environment_receipt_signing_public_key_b64"] = base64.b64encode(b"x"*32).decode("ascii")
        expect_rejection(
            lambda: runner._wp_environment_sign_receipt(profile, signed_receipt),
            "Host Runner accepted a signer different from its pinned public key",
        )
        profile["host_environment_receipt_signing_public_key_b64"] = base64.b64encode(public_raw).decode("ascii")

        # A replayed Staging plan must not add a second define after boot.
        updated = profile_for(site, private, site_uuid, config, content)
        updated["host_environment_receipt_signing_key_file"] = profile["host_environment_receipt_signing_key_file"]
        updated["host_environment_receipt_signing_public_key_b64"] = profile["host_environment_receipt_signing_public_key_b64"]
        expect_rejection(
            lambda: runner.execute_wp_environment_sync(updated, wrapped("wordpress_environment_sync", exact_sync(updated))),
            "duplicate WP_ENVIRONMENT_TYPE was not denied",
        )
        # Persist minimal independently verified successful Bridge receipt.
        receipts = content / "bridge" / "receipts"
        receipts.mkdir(parents=True)
        receipt = {
            **signed_receipt,
            "contract": runner.RECEIPT_CONTRACT,
            "bridge_contract": "mad4b.host-bridge-execution.v1",
            "host_environment_attestation": attestation,
        }
        runner._wp_environment_verify_signed_receipt(updated, receipt)
        receipt_path = receipts / f"{verified['job_id']}.json"
        runner.atomic_json_write(receipt_path, receipt)
        rollback = {
            "contract": runner.WP_ENVIRONMENT_ROLLBACK_CONTRACT,
            "operation_id": "wordpress_environment_rollback",
            "operation_version": 1,
            "runner_profile_id": updated["profile_id"],
            "site_uuid": site_uuid,
            "environment": "staging",
            "target_fingerprint": updated["target_fingerprint"],
            "source_job_id": verified["job_id"],
            "source_receipt_sha256": runner.sha256_file(receipt_path),
            "expected_wp_config_sha256": result["after_sha256"],
            "restore_wp_config_sha256": result["before_sha256"],
            "backup_before_replace": True,
            "rollback_on_failed_readback": True,
            "require_new_bootstrap_verification": True,
            "caller_supplied_path_allowed": False,
            "caller_supplied_php_allowed": False,
            "production_authorized": False,
            "reason": "Revert exact Staging environment test",
        }
        rollback["plan_sha256"] = runner.plan_digest(rollback)
        wrong = dict(rollback, source_receipt_sha256="0"*64)
        wrong["plan_sha256"] = runner.plan_digest(wrong)
        expect_rejection(
            lambda: runner.execute_wp_environment_rollback(updated, wrapped("wordpress_environment_rollback", wrong)),
            "tampered source receipt accepted",
        )
        undone = runner.execute_wp_environment_rollback(updated, wrapped("wordpress_environment_rollback", rollback))
        assert undone["readback_verdict"] == "PASS"
        assert config.read_bytes() == original
        assert stat.S_IMODE(config.stat().st_mode) == 0o600
        assert undone["fresh_wordpress_bootstrap_verified"] is False

        # Any other wordpress environment declaration and missing bootstrap deny.
        for raw in (
            b"<?php\n define('WP_ENVIRONMENT_TYPE', 'production');\nrequire_once ABSPATH . 'wp-settings.php';\n",
            b"<?php\n// WP_ENVIRONMENT_TYPE ambiguous legacy value\nrequire_once ABSPATH . 'wp-settings.php';\n",
            b"<?php\n echo 'no WordPress bootstrap';\n",
        ):
            expect_rejection(lambda raw=raw: runner._wp_environment_expected_bytes(raw),
                             "ambiguous bootstrap accepted")
        expect_rejection(
            lambda: runner._wp_environment_private_backup_root({
                **profile, "host_environment_backup_root": str(site / "wp-content")
            }),
            "public rollback store permitted",
        )
        outside = tmp / "outside-symlink"
        outside.symlink_to(private, target_is_directory=True)
        expect_rejection(
            lambda: runner._wp_environment_private_backup_root({
                **profile, "host_environment_backup_root": str(outside)
            }),
            "symlinked rollback store permitted",
        )
        production = dict(profile, environment="production")
        expect_rejection(lambda: runner._wp_environment_config(production),
                         "Production host edit accepted")
        print("MAD4B_HOST_WORDPRESS_ENVIRONMENT_SYNC: PASS")


if __name__ == "__main__":
    test_flow()
