#!/usr/bin/env python3
"""CI-independent native evidence replayer for exact MAD4B Staging package.

Produces observed five-gate results from executed checks. Never signs,
publishes, deploys, contacts WordPress or claims that repository-controlled
tests are trusted without independent operator review/isolation.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import zipfile

import mad4b_standalone_build as build

GATES = ("g9_delivery_contract", "php83_tree_syntax", "canonical_package_receipt",
         "isolated_zip_runtime_integrity", "exact_source_verification")
CONTRACT = "mad4b.standalone-native-evidence.v1"

PHP_VERIFIER = r'''
$root=rtrim($argv[1], "/") . "/";
define("ABSPATH", $root);
define("MAD4B_SCP_DIR", $root);
if (!function_exists("sanitize_key")) {
    function sanitize_key($value) {
        return (string) preg_replace("/[^a-z0-9_\\-]/", "", strtolower((string) $value));
    }
}
require MAD4B_SCP_DIR . "includes/class-mad4b-scp-functional-gap-evidence.php";
$proof=MAD4B_SCP_Functional_Gap_Evidence::package_integrity_status();
if (empty($proof["valid"]) || ($proof["integrity_level"] ?? "") !== "self_consistent_package") {
    fwrite(STDERR, "PACKAGED_RUNTIME_INTEGRITY_FAILED\\n");
    exit(1);
}
echo "PACKAGED_RUNTIME_INTEGRITY_PASS\\n";
'''


def check_command(argv: list[str], root: Path, timeout: int) -> str:
    try:
        p = build.command(argv, root, timeout)
        return "PASS" if p.returncode == 0 else "FAIL"
    except (OSError, subprocess.TimeoutExpired):
        return "BLOCKED"


def status_from_checks(rows: list[dict]) -> str:
    values = [row["state"] for row in rows]
    if any(value == "FAIL" for value in values):
        return "FAIL"
    if any(value == "BLOCKED" for value in values) or not values:
        return "BLOCKED"
    return "PASS"


def evidence(root: Path, source: str, zip_path: Path, receipt_path: Path,
             php: str) -> tuple[dict, dict]:
    results = {name: "BLOCKED" for name in GATES}
    notes: dict[str, object] = {"contract": CONTRACT, "source_commit_sha": source,
        "archive_sha256": "", "trusted_signer_verified": False,
        "github_ci_certified": False, "staging_certified": False,
        "production_authorized": False, "external_owner_review_required": True,
        "checks": []}
    try:
        build.source_identity(root, source)
        results["exact_source_verification"] = "PASS"
    except build.BuildBlocked as exc:
        notes["blocker"] = str(exc)
        return results, notes
    try:
        receipt = json.loads(receipt_path.read_text(encoding="utf-8"))
        build.verify_zip(zip_path, receipt, source)
        build.require(zip_path.stat().st_size <= 16 * 1024 * 1024,
                      "package_exceeds_staging_upload_limit")
        results["canonical_package_receipt"] = "PASS"
        notes["archive_sha256"] = build.sha(zip_path)
        notes["build_fingerprint"] = receipt["build_fingerprint"]
        notes["package_manifest_digest"] = receipt["package_manifest_digest"]
    except (build.BuildBlocked, ValueError, KeyError, OSError, zipfile.BadZipFile) as exc:
        notes["blocker"] = type(exc).__name__ if not isinstance(exc, build.BuildBlocked) else str(exc)
        return results, notes

    php_bin = shutil.which(php)
    if not php_bin:
        notes["blocker"] = "native_php_missing"
        return results, notes
    try:
        version = build.command([php_bin, "-r", "echo PHP_MAJOR_VERSION,'.',PHP_MINOR_VERSION;"],
                                root, 15)
        version_str = version.stdout.strip() if version.returncode == 0 else ""
    except (OSError, subprocess.TimeoutExpired):
        version_str = ""
    notes["native_php_version"] = version_str or "unavailable"
    if version_str != "8.3":
        notes["blocker"] = "native_php83_required"
        return results, notes

    lint = []
    for item in sorted((root / build.PLUGIN).rglob("*.php")):
        lint.append({"name": str(item.relative_to(root)),
                     "state": check_command([php_bin, "-l", str(item)], root, 30)})
    results["php83_tree_syntax"] = status_from_checks(lint)
    notes["php83_syntax"] = {"files_checked": len(lint),
                            "result": results["php83_tree_syntax"],
                            "failures": [row["name"] for row in lint if row["state"] != "PASS"][:20]}

    # Named native fixtures must actually execute; their mere presence is not evidence.
    checks = []
    g9 = root / build.PLUGIN / "tests/g9-delivery-contract.py"
    checks.append({"name": "g9-delivery-contract.py",
                   "state": check_command([sys.executable, str(g9)], root, 180)
                   if g9.is_file() else "BLOCKED"})
    for filename in build.TESTS:
        fixture = root / build.PLUGIN / "tests" / filename
        checks.append({"name": filename,
                       "state": check_command([php_bin, str(fixture)], root, 180)
                       if fixture.is_file() else "BLOCKED"})
    notes["checks"] = checks
    results["g9_delivery_contract"] = status_from_checks(checks)

    if results["canonical_package_receipt"] == "PASS":
        try:
            with tempfile.TemporaryDirectory(prefix="mad4b-zip-evidence-") as temp:
                # build.verify_zip already checked paths, types, file SHA and count.
                with zipfile.ZipFile(zip_path) as z:
                    z.extractall(temp)
                stage = Path(temp) / "mad4b-site-control-plane"
                results["isolated_zip_runtime_integrity"] = check_command(
                    [php_bin, "-r", PHP_VERIFIER, str(stage)], root, 120)
        except (OSError, ValueError, zipfile.BadZipFile):
            results["isolated_zip_runtime_integrity"] = "BLOCKED"

    try:
        build.source_identity(root, source)
    except build.BuildBlocked:
        results["exact_source_verification"] = "FAIL"
    notes["all_five_gates_pass"] = all(v == "PASS" for v in results.values())
    notes["gate_results"] = results
    notes["authorizing"] = False
    return results, notes


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--repo-root", required=True, type=Path)
    ap.add_argument("--expected-head", required=True)
    ap.add_argument("--zip", required=True, type=Path)
    ap.add_argument("--receipt", required=True, type=Path)
    ap.add_argument("--output-dir", required=True, type=Path)
    ap.add_argument("--php", default="php")
    args = ap.parse_args()
    root = args.repo_root.resolve(strict=True)
    output = args.output_dir
    if output.is_symlink() or output.resolve().is_relative_to(root):
        raise SystemExit("BLOCKED: unsafe evidence output location")
    try:
        build.require(build.SHA40.fullmatch(args.expected_head) is not None,
                      "invalid_exact_head")
        results, notes = evidence(root, args.expected_head,
                                  args.zip.resolve(strict=True), args.receipt.resolve(strict=True),
                                  args.php)
    except (build.BuildBlocked, OSError) as exc:
        raise SystemExit("BLOCKED: " + str(exc)) from exc
    output.mkdir(parents=True, exist_ok=True)
    notes["evidence_state"] = "OBSERVED_NOT_SIGNED"
    notes["all_five_gates_pass"] = all(v == "PASS" for v in results.values())
    # The existing offline publisher consumes the five exact keys and a separate
    # source-bound evidence bundle. Neither file is a trusted signer signature.
    (output / "GATE-RESULTS.json").write_text(
        json.dumps(results, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    (output / "NATIVE-TEST-EVIDENCE-BUNDLE.json").write_text(
        json.dumps(notes, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"gate_results": results, "evidence": notes["evidence_state"],
                      "eligible_for_independent_owner_review": notes["all_five_gates_pass"],
                      "staging_certified": False}, sort_keys=True))
    return 0 if notes["all_five_gates_pass"] else 2


if __name__ == "__main__":
    raise SystemExit(main())
