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
    if not Path(result["journal_path"]).is_file():
        raise SystemExit("recovery mutation journal was not persisted")
    journal = json.loads(Path(result["journal_path"]).read_text(encoding="utf-8"))
    if journal.get("evidence_state") != "DURABLE_VERIFIED_RECEIPT" or journal.get("terminal") is not True:
        raise SystemExit("recovery journal did not reach durable terminal evidence")

    # The old broken plugin must remain available only as quarantine evidence.
    if "broken old runtime" not in (quarantine / "mad4b-site-control-plane.php").read_text(encoding="utf-8"):
        raise SystemExit("quarantined rollback evidence is not the previous plugin")

    # Read-only status must verify installed provenance without loading WordPress.
    status = recovery.recovery_status(wp, "staging")
    if status["mutation_performed"] is not False or status["read_only"] is not True:
        raise SystemExit("Recovery status must remain observational")
    if status["installed_provenance"]["valid"] is not True:
        raise SystemExit("Recovery status did not verify installed known-good provenance")
    if status["installed_provenance"]["identity"]["source_commit_sha"] != source_sha:
        raise SystemExit("Recovery status source identity mismatch")

    # Exact-plan disable/quarantine provides an out-of-band stop path for a bad plugin.
    disable_plan = recovery.build_disable_plan(
        wp,
        "staging",
        "INC-DISABLE-001",
        "Disable exact installed Control Plane without loading WordPress.",
    )
    try:
        recovery.apply_disable(disable_plan, "0" * 64)
        raise SystemExit("Recovery disable accepted the wrong owner plan attestation")
    except ValueError as exc:
        if "OWNER_ATTEST_SINGLE_OWNER" not in str(exc):
            raise

    disabled = recovery.apply_disable(disable_plan, disable_plan["plan_sha256"])
    if live.exists():
        raise SystemExit("Recovery disable left the plugin active at the canonical path")
    disabled_quarantine = wp / disabled["previous"]["quarantine_path"]
    if not disabled_quarantine.is_dir():
        raise SystemExit("Recovery disable did not quarantine exact prior plugin bytes")
    if disabled["post_disable"]["production_authorized"] is not False:
        raise SystemExit("Recovery disable widened Production authority")
    if not Path(disabled["receipt_path"]).is_file() or not Path(disabled["journal_path"]).is_file():
        raise SystemExit("Recovery disable did not persist receipt and journal")
    disabled_journal = json.loads(Path(disabled["journal_path"]).read_text(encoding="utf-8"))
    if disabled_journal.get("evidence_state") != "DURABLE_VERIFIED_RECEIPT":
        raise SystemExit("Recovery disable journal did not reach durable evidence")

    absent_status = recovery.recovery_status(wp, "staging")
    if absent_status["target"]["plugin_present"] is not False:
        raise SystemExit("Recovery status did not observe disabled plugin")

    # Restore after disable proves the Recovery Plane can hand control back without plugin boot.
    restore_after_disable = recovery.build_restore_plan(
        wp,
        "staging",
        "INC-RESTORE-AFTER-DISABLE",
        "Restore externally attested package after exact-plan disable.",
        receipt,
    )
    restored_again = recovery.apply_restore(
        restore_after_disable,
        artifact,
        receipt,
        restore_after_disable["plan_sha256"],
    )
    if restored_again["post_recovery"]["source_commit_sha"] != source_sha:
        raise SystemExit("Recovery restore after disable did not restore exact source identity")
    if not live.is_dir():
        raise SystemExit("Recovery restore after disable did not reactivate canonical plugin path")


# Evidence persistence failure after a real side effect must never trigger a blind retry.
with tempfile.TemporaryDirectory() as td:
    tmp = Path(td)
    wp = tmp / "wordpress"
    plugins = wp / "wp-content" / "plugins"
    old = plugins / recovery.PLUGIN_SLUG
    old.mkdir(parents=True)
    (wp / "wp-config.php").write_text("<?php // uncertainty fixture\n", encoding="utf-8")
    (old / "mad4b-site-control-plane.php").write_text(
        "<?php // old runtime before uncertain restore\n", encoding="utf-8"
    )
    source_sha = "e" * 40
    artifact, install, receipt = known_good_fixture(tmp, source_sha)
    plan = recovery.build_restore_plan(
        wp,
        "staging",
        "INC-EVIDENCE-UNCERTAIN",
        "Inject final receipt persistence failure after verified restore side effect.",
        receipt,
    )

    original_atomic = recovery.atomic_json_write

    def fail_receipt_only(path, data):
        if path.parent.name == "receipts":
            raise OSError("simulated receipt persistence failure")
        return original_atomic(path, data)

    recovery.atomic_json_write = fail_receipt_only
    try:
        recovery.apply_restore(plan, artifact, receipt, plan["plan_sha256"])
        raise SystemExit("recovery apply unexpectedly succeeded without durable receipt")
    except RuntimeError as exc:
        if "MUTATED_BUT_EVIDENCE_UNCERTAIN" not in str(exc):
            raise
    finally:
        recovery.atomic_json_write = original_atomic

    live = plugins / recovery.PLUGIN_SLUG
    if "known good" not in (live / "mad4b-site-control-plane.php").read_text(encoding="utf-8"):
        raise SystemExit("uncertain-evidence fixture did not leave the verified side effect in place")

    summary = recovery.recovery_journal_summary(wp)
    if summary["uncertain"] != 1 or summary["reconciliation_required"] is not True:
        raise SystemExit("uncertain mutation was not surfaced by recovery journal summary")

    reconciliation = recovery.reconcile_recovery_evidence(wp, "staging")
    if reconciliation["blind_retry_allowed"] is not False:
        raise SystemExit("recovery reconciliation allowed a blind retry")
    matches = [
        row for row in reconciliation["reconciliations"]
        if row.get("plan_sha256") == plan["plan_sha256"]
    ]
    if len(matches) != 1:
        raise SystemExit("uncertain mutation reconciliation row missing")
    row = matches[0]
    if row["reconciliation_status"] != "RUNTIME_EFFECT_OBSERVED_NO_RECEIPT":
        raise SystemExit("reconciliation did not detect side effect without receipt")
    if row["safe_to_blind_retry"] is not False:
        raise SystemExit("uncertain mutation was marked safe for blind retry")


# Disable receipt failure rolls back the mutation and records rollback truthfully.
with tempfile.TemporaryDirectory() as td:
    tmp = Path(td)
    wp = tmp / "wordpress"
    plugins = wp / "wp-content" / "plugins"
    live = plugins / recovery.PLUGIN_SLUG
    live.mkdir(parents=True)
    (wp / "wp-config.php").write_text("<?php // disable rollback fixture\n", encoding="utf-8")
    (live / "mad4b-site-control-plane.php").write_text(
        "<?php // runtime to preserve on evidence failure\n", encoding="utf-8"
    )
    plan = recovery.build_disable_plan(
        wp,
        "staging",
        "INC-DISABLE-EVIDENCE-FAIL",
        "Inject disable receipt failure and require rollback to original runtime.",
    )
    original_atomic = recovery.atomic_json_write

    def fail_disable_receipt(path, data):
        if path.parent.name == "receipts":
            raise OSError("simulated disable receipt failure")
        return original_atomic(path, data)

    recovery.atomic_json_write = fail_disable_receipt
    try:
        recovery.apply_disable(plan, plan["plan_sha256"])
        raise SystemExit("disable unexpectedly succeeded without durable receipt")
    except RuntimeError as exc:
        if "MUTATED_BUT_EVIDENCE_UNCERTAIN" not in str(exc):
            raise
    finally:
        recovery.atomic_json_write = original_atomic

    if not live.is_dir():
        raise SystemExit("disable evidence failure did not roll back the plugin path")
    summary = recovery.recovery_journal_summary(wp)
    rows = [row for row in summary["entries"] if row.get("plan_sha256") == plan["plan_sha256"]]
    if len(rows) != 1 or rows[0]["evidence_state"] != "ROLLED_BACK_AFTER_FAILURE":
        raise SystemExit("disable rollback journal does not truthfully report rollback")


# Archive/path confinement: zip-slip and symlink entries must fail before mutation.
with tempfile.TemporaryDirectory() as td:
    tmp = Path(td)
    stage = tmp / "stage"
    malicious = tmp / "zip-slip.zip"
    with zipfile.ZipFile(malicious, "w") as z:
        z.writestr("mad4b-site-control-plane/../../escaped.php", b"<?php // escape")
    try:
        recovery.safe_extract_control_plane(malicious, stage)
        raise SystemExit("Recovery extractor accepted zip-slip path")
    except ValueError as exc:
        if "unsafe archive path" not in str(exc):
            raise
    if (tmp / "escaped.php").exists():
        raise SystemExit("zip-slip fixture escaped extraction root")

with tempfile.TemporaryDirectory() as td:
    tmp = Path(td)
    stage = tmp / "stage"
    malicious = tmp / "symlink.zip"
    info = zipfile.ZipInfo("mad4b-site-control-plane/link.php")
    info.create_system = 3
    info.external_attr = (0o120777 << 16)
    with zipfile.ZipFile(malicious, "w") as z:
        z.writestr(info, "../../outside.php")
    try:
        recovery.safe_extract_control_plane(malicious, stage)
        raise SystemExit("Recovery extractor accepted symlink archive entry")
    except ValueError as exc:
        if "symlink forbidden" not in str(exc):
            raise

# Recovery workspace itself cannot be redirected through a symlink.
with tempfile.TemporaryDirectory() as td:
    tmp = Path(td)
    wp = tmp / "wordpress"
    plugins = wp / "wp-content" / "plugins"
    old = plugins / recovery.PLUGIN_SLUG
    old.mkdir(parents=True)
    (wp / "wp-config.php").write_text("<?php // recovery-root-symlink fixture\n", encoding="utf-8")
    (old / "mad4b-site-control-plane.php").write_text("<?php // old runtime\n", encoding="utf-8")
    external = tmp / "external-recovery"
    external.mkdir()
    (wp / "wp-content" / "mad4b-recovery").symlink_to(external, target_is_directory=True)
    source_sha = "f" * 40
    artifact, install, receipt = known_good_fixture(tmp, source_sha)
    plan = recovery.build_restore_plan(
        wp,
        "staging",
        "INC-PATH-CONFINEMENT",
        "Reject symlinked Recovery Plane workspace before mutation.",
        receipt,
    )
    try:
        recovery.apply_restore(plan, artifact, receipt, plan["plan_sha256"])
        raise SystemExit("Recovery Plane accepted symlinked recovery workspace")
    except ValueError as exc:
        if "recovery root symlink is forbidden" not in str(exc):
            raise
    if "old runtime" not in (old / "mad4b-site-control-plane.php").read_text(encoding="utf-8"):
        raise SystemExit("symlinked recovery workspace fixture mutated live plugin")

print("out-of-band recovery plane contract: PASS")
