#!/usr/bin/env python3
import hashlib
import importlib.util
import json
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]

def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module

import sys
sys.path.insert(0, str(ROOT / "tools"))
root_trust = load("root_trust", ROOT / "tools/verify_release_root_trust.py")
recovery = load("recovery_plane", ROOT / "tools/mad4b_recovery_plane.py")

if recovery.SUPPORTED_ENVIRONMENTS != {"staging"}:
    raise SystemExit("Recovery Plane must remain Staging-only in this implementation slice")
if recovery.PLUGIN_SLUG != "mad4b-site-control-plane":
    raise SystemExit("Recovery Plane must not become a caller-selectable arbitrary plugin mutator")


def known_good_fixture(tmp: Path, source_sha: str):
    artifact = tmp / "mad4b-site-control-plane-0.4.0-rc.59.zip"
    install = tmp / "install-manifest.json"
    files = {
        "mad4b-site-control-plane.php": b"<?php\n// known good\n",
        "includes/health.php": b"<?php\nreturn true;\n",
    }
    rows = []
    canonical = []
    for rel, raw in sorted(files.items()):
        digest = hashlib.sha256(raw).hexdigest()
        rows.append({"path": rel, "bytes": len(raw), "sha256": digest})
        canonical.append(f"{rel}\0{len(raw)}\0{digest}\n".encode())
    package_digest = hashlib.sha256(b"".join(canonical)).hexdigest()
    build_fingerprint = hashlib.sha256(b"known-good-build").hexdigest()
    provenance = {
        "contract": root_trust.PROVENANCE_CONTRACT,
        "source_commit_sha": source_sha,
        "build_fingerprint": build_fingerprint,
        "package_manifest_digest": package_digest,
        "package_files": rows,
    }
    with zipfile.ZipFile(artifact, "w", zipfile.ZIP_DEFLATED) as z:
        for rel, raw in files.items():
            z.writestr(f"mad4b-site-control-plane/{rel}", raw)
        z.writestr(
            "mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json",
            json.dumps(provenance, sort_keys=True),
        )
    install_data = {
        "contract": root_trust.INSTALL_CONTRACT,
        "repository": root_trust.REPOSITORY,
        "commit": source_sha,
        "build_fingerprint": build_fingerprint,
        "package_manifest_digest": package_digest,
        "control_plane": {
            "version": "0.4.0-rc.59",
            "archive": artifact.name,
            "sha256": root_trust.sha256_file(artifact),
        },
    }
    install.write_text(json.dumps(install_data), encoding="utf-8")
    receipt = {
        "contract": root_trust.VERIFICATION_CONTRACT,
        "verified": True,
        "attestation_verified": True,
        "runtime_self_attestation_authoritative": False,
        "verification_boundary": "external_release_verifier",
        "repository": root_trust.REPOSITORY,
        "signer_workflow": root_trust.SIGNER_WORKFLOW,
        "signer_digest": "c" * 40,
        "verified_attestation_count": 1,
        "trusted_signer_ref": root_trust.TRUSTED_SIGNER_REF,
        "trusted_signer_event": "workflow_dispatch",
        "trusted_runner_environment": "github-hosted",
        "artifact_sha256": install_data["control_plane"]["sha256"],
        "source_commit_sha": source_sha,
        "build_fingerprint": build_fingerprint,
        "package_manifest_digest": package_digest,
        "control_plane_version": "0.4.0-rc.59",
    }
    return artifact, install, receipt


with tempfile.TemporaryDirectory() as td:
    tmp = Path(td)
    wp = tmp / "wordpress"
    plugins = wp / "wp-content" / "plugins"
    old = plugins / recovery.PLUGIN_SLUG
    old.mkdir(parents=True)
    (wp / "wp-config.php").write_text("<?php // staging fixture\n", encoding="utf-8")
    (old / "mad4b-site-control-plane.php").write_text("<?php // broken old runtime\n", encoding="utf-8")

    source_sha = "d" * 40
    artifact, install, receipt = known_good_fixture(tmp, source_sha)

    candidate_receipt = dict(receipt)
    candidate_receipt["trusted_signer_ref"] = "refs/pull/57/merge"
    candidate_receipt["trusted_signer_event"] = "pull_request"
    try:
        recovery.build_restore_plan(
            wp,
            "staging",
            "INC-CANDIDATE-UNTRUSTED",
            "Reject PR-controlled candidate attestation at Recovery Plane boundary.",
            candidate_receipt,
        )
        raise SystemExit("Recovery Plane accepted a PR-controlled candidate attestation")
    except ValueError as exc:
        if "trusted release ref" not in str(exc):
            raise

    plan = recovery.build_restore_plan(
        wp,
        "staging",
        "INC-ROOT-TRUST-001",
        "Restore externally attested known-good Control Plane.",
        receipt,
    )
    if plan["authorization"]["production_authorized"] is not False:
        raise SystemExit("recovery plan unexpectedly authorizes Production")
    if plan["authorization"]["exact_plan_attestation_required"] is not True:
        raise SystemExit("recovery plan must require exact plan attestation")

    # Execution TOCTOU: current plugin change after planning must fail closed.
    original = (old / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
    (old / "mad4b-site-control-plane.php").write_text(original + "// drift\n", encoding="utf-8")
    try:
        recovery.assert_plan_current(plan)
        raise SystemExit("stale recovery plan was accepted after target drift")
    except ValueError as exc:
        if "target changed" not in str(exc):
            raise
    (old / "mad4b-site-control-plane.php").write_text(original, encoding="utf-8")

    # Re-plan exact current state, then prove owner attestation is plan-bound.
    plan = recovery.build_restore_plan(
        wp,
        "staging",
        "INC-ROOT-TRUST-002",
        "Restore exact known-good package after target re-read.",
        receipt,
    )
    try:
        recovery.apply_restore(plan, artifact, receipt, "0" * 64)
        raise SystemExit("recovery apply accepted the wrong owner plan attestation")
    except ValueError as exc:
        if "OWNER_ATTEST_SINGLE_OWNER" not in str(exc):
            raise

    result = recovery.apply_restore(
        plan,
        artifact,
        receipt,
        plan["plan_sha256"],
    )
    live = plugins / recovery.PLUGIN_SLUG
    if "known good" not in (live / "mad4b-site-control-plane.php").read_text(encoding="utf-8"):
        raise SystemExit("known-good plugin was not restored")
    if result["post_recovery"]["source_commit_sha"] != source_sha:
        raise SystemExit("post-recovery source identity mismatch")
    if result["post_recovery"]["production_authorized"] is not False:
        raise SystemExit("Recovery Plane widened Production authority")
    quarantine = wp / result["previous"]["quarantine_path"]
    if not quarantine.is_dir():
        raise SystemExit("previous plugin was not quarantined for rollback")
    if not Path(result["receipt_path"]).is_file():
        raise SystemExit("recovery receipt was not persisted")

    # The old broken plugin must remain available only as quarantine evidence.
    if "broken old runtime" not in (quarantine / "mad4b-site-control-plane.php").read_text(encoding="utf-8"):
        raise SystemExit("quarantined rollback evidence is not the previous plugin")

print("out-of-band recovery plane contract: PASS")
