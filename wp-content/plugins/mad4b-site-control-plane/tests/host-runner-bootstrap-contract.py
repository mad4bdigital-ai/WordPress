#!/usr/bin/env python3
"""Executable contract for bounded Host Runner bootstrap/enrollment."""

from __future__ import annotations

import importlib.util
import json
import tempfile
from datetime import datetime, timedelta, timezone
from pathlib import Path


ROOT = Path(__file__).resolve().parents[4]


def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module


bootstrap = load("mad4b_host_runner_bootstrap", ROOT / "tools/mad4b_host_runner_bootstrap.py")
runner_source = ROOT / "tools/mad4b_host_runner.py"


def iso(dt):
    return dt.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")


with tempfile.TemporaryDirectory() as td:
    tmp = Path(td)
    wp = tmp / "wordpress"
    (wp / "wp-content").mkdir(parents=True)
    (wp / "wp-config.php").write_text("<?php // bootstrap fixture\n", encoding="utf-8")
    site_uuid = "11111111-2222-4333-8444-555555555555"

    material = {
        "contract": bootstrap.RUNNER_PACKAGE_CONTRACT,
        "version": "0.1.0-ci",
        "source_commit_sha": "a" * 40,
        "entrypoint": "mad4b_host_runner.py",
        "entrypoint_sha256": bootstrap.sha256_file(runner_source),
        "supported_operation_contracts": ["mad4b.host-runner.v1"],
        "runtime_profile": "shared-hosting-account-local",
        "install_zone": "wp-content/mad4b-runner/bin",
    }
    package = dict(material)
    package["package_sha256"] = bootstrap.sha256_bytes(bootstrap.canonical_json(material))

    plan = bootstrap.build_bootstrap_plan(
        wp, site_uuid, "staging", package, runner_source, "install bounded CI runner package"
    )
    assert plan["authority_effect"]["host_write_granted"] is False
    assert plan["authority_effect"]["host_execution_granted"] is False
    assert plan["authority_effect"]["production_authorized"] is False
    assert plan["scheduler_profile"]["arbitrary_arguments_allowed"] is False

    try:
        bootstrap.apply_bootstrap(plan, runner_source, "0" * 64)
        raise SystemExit("bootstrap accepted wrong exact-plan attestation")
    except ValueError as exc:
        if "exact plan" not in str(exc):
            raise

    receipt = bootstrap.apply_bootstrap(plan, runner_source, plan["plan_sha256"])
    install_root = Path(receipt["install_root"])
    assert receipt["readback_verdict"] == "PASS"
    assert receipt["scheduler_registration_applied"] is False
    assert receipt["production_authorized"] is False
    assert receipt["host_write_granted"] is False
    assert receipt["host_execution_granted"] is False
    assert bootstrap.sha256_file(install_root / "mad4b_host_runner.py") == package["entrypoint_sha256"]

    try:
        bootstrap.apply_bootstrap(plan, runner_source, plan["plan_sha256"])
        raise SystemExit("bootstrap reinstalled same package version")
    except ValueError as exc:
        if "already installed" not in str(exc):
            raise

    # Package tampering fails before install.
    bad_package = dict(package)
    bad_package["entrypoint_sha256"] = "0" * 64
    try:
        bootstrap.build_bootstrap_plan(
            wp, site_uuid, "staging", bad_package, runner_source, "reject tampered package"
        )
        raise SystemExit("bootstrap accepted tampered runner package")
    except ValueError as exc:
        if "entrypoint digest mismatch" not in str(exc):
            raise

    # Enrollment envelope is target/package bound and single-use.
    key = b"k" * 64
    target = bootstrap.target_identity(wp, site_uuid, "staging")
    envelope = bootstrap.build_enrollment_envelope(
        target,
        package["package_sha256"],
        "ci-host-runner",
        iso(datetime.now(timezone.utc) + timedelta(minutes=5)),
        key,
    )
    record = bootstrap.consume_enrollment(wp, envelope, key, package["package_sha256"])
    assert record["bootstrap_credential_consumed"] is True
    assert record["write_eligible"] is False
    assert record["host_execution_authority_granted"] is False
    assert record["production_authorized"] is False

    try:
        bootstrap.consume_enrollment(wp, envelope, key, package["package_sha256"])
        raise SystemExit("single-use enrollment envelope replayed")
    except ValueError as exc:
        if "replay denied" not in str(exc):
            raise

    expired = bootstrap.build_enrollment_envelope(
        target,
        package["package_sha256"],
        "ci-host-runner-expired",
        iso(datetime.now(timezone.utc) - timedelta(minutes=1)),
        key,
    )
    try:
        bootstrap.consume_enrollment(wp, expired, key, package["package_sha256"])
        raise SystemExit("expired enrollment envelope was accepted")
    except ValueError as exc:
        if "expired" not in str(exc):
            raise

    tampered = dict(envelope)
    tampered["enrollment_id"] = "99999999-8888-4777-8666-555555555555"
    try:
        bootstrap.consume_enrollment(wp, tampered, key, package["package_sha256"])
        raise SystemExit("tampered enrollment envelope was accepted")
    except ValueError as exc:
        if "integrity mismatch" not in str(exc):
            raise

    wrong_package = bootstrap.build_enrollment_envelope(
        target,
        "b" * 64,
        "ci-host-runner-wrong-package",
        iso(datetime.now(timezone.utc) + timedelta(minutes=5)),
        key,
    )
    try:
        bootstrap.consume_enrollment(wp, wrong_package, key, package["package_sha256"])
        raise SystemExit("enrollment accepted wrong package identity")
    except ValueError as exc:
        if "package mismatch" not in str(exc):
            raise

print("mad4b.host-runner.bootstrap-enrollment.v1: PASS")
