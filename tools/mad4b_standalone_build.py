#!/usr/bin/env python3
"""Deterministic, CI-independent MAD4B source builder. Never certifies/releases/deploys.

A pinned clean git checkout and locally available *certified* MCP Adapter bytes
are required. Reuses the canonical package builder; does not call WordPress,
GitHub Actions, a shell interpreter, or the offline Ed25519 publisher.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile

PLUGIN = Path("wp-content/plugins/mad4b-site-control-plane")
CANONICAL_BUILDER = PLUGIN / "tests/build-deterministic-control-plane-package.py"
POLICY = PLUGIN / "config/runtime-release-policy.json"
PROFILES = PLUGIN / "config/certified-provider-profiles.json"
CONTRACT = "mad4b.standalone-source-build.v1"
SHA40 = re.compile(r"^[0-9a-f]{40}$")
VERSION = re.compile(r"^ \* Version: ([0-9A-Za-z._-]+)", re.MULTILINE)
TESTS = (
    "admin-surface-coverage-runtime.php",
    "admin-operation-profiles-runtime.php",
    "manual-workflow-bridge-policy-runtime.php",
    "manual-workflow-bridge-apply-runtime.php",
    "plugin-update-recovery-runtime.php",
    "plugin-update-evidence-runtime.php",
    "ci-outage-attestation-runtime.php",
    "ci-outage-selected-head-runtime.php",
)


class BuildBlocked(Exception):
    pass


def sha(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for part in iter(lambda: f.read(1048576), b""):
            h.update(part)
    return h.hexdigest()


def command(argv: list[str], cwd: Path, timeout: int = 120) -> subprocess.CompletedProcess:
    return subprocess.run(argv, cwd=str(cwd), text=True, capture_output=True,
                          timeout=timeout, check=False)


def require(condition: bool, reason: str) -> None:
    if not condition:
        raise BuildBlocked(reason)


def source_identity(root: Path, expected: str) -> None:
    require(SHA40.fullmatch(expected) is not None, "invalid_exact_source_sha")
    try:
        head = command(["git", "rev-parse", "HEAD"], root, 15)
        require(head.returncode == 0 and head.stdout.strip() == expected,
                "source_head_mismatch")
        dirty = command(["git", "status", "--porcelain", "--untracked-files=all"], root, 25)
        require(dirty.returncode == 0 and not dirty.stdout.strip(), "source_worktree_dirty")
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise BuildBlocked("git_source_check_unavailable") from exc


def certified_adapter(root: Path, archive: Path) -> tuple[str, str]:
    policy = json.loads((root / POLICY).read_text(encoding="utf-8"))
    profiles = json.loads((root / PROFILES).read_text(encoding="utf-8"))
    require(policy.get("contract") == "mad4b.runtime-release-policy.v1",
            "release_policy_contract_invalid")
    version = str(policy.get("target_adapter_version", ""))
    profile = ((profiles.get("providers") or {}).get("mcp_adapter") or {}).get(version) or {}
    require(bool(version) and profile.get("version") == version, "certified_adapter_profile_missing")
    require(archive.is_file() and not archive.is_symlink(), "certified_adapter_archive_missing")
    expected_size = int(profile.get("archive_bytes") or 0)
    expected_sha = str(profile.get("archive_sha256") or "")
    require(expected_size > 0 and archive.stat().st_size == expected_size,
            "certified_adapter_archive_size_mismatch")
    require(len(expected_sha) == 64 and sha(archive) == expected_sha,
            "certified_adapter_archive_sha_mismatch")
    try:
        with zipfile.ZipFile(archive) as z:
            require(z.testzip() is None, "certified_adapter_archive_corrupt")
    except (OSError, zipfile.BadZipFile) as exc:
        raise BuildBlocked("certified_adapter_not_zip") from exc
    return version, expected_sha


def verify_zip(archive: Path, receipt: dict, source: str) -> None:
    require(receipt.get("contract") == "mad4b.deterministic-control-plane-package.v1",
            "canonical_receipt_contract_mismatch")
    require(receipt.get("source_commit_sha") == source, "receipt_source_mismatch")
    require(receipt.get("archive_compression") == "stored", "archive_compression_mismatch")
    require(receipt.get("archive_bytes") == archive.stat().st_size and
            receipt.get("archive_sha256") == sha(archive), "archive_hash_or_size_mismatch")
    with zipfile.ZipFile(archive) as z:
        items = z.infolist()
        require(len(items) == receipt.get("archive_file_count"), "archive_file_count_mismatch")
        require(len(set(row.filename.casefold() for row in items)) == len(items),
                "duplicate_casefolded_archive_path")
        for row in items:
            path = row.filename
            require(path.startswith("mad4b-site-control-plane/") and
                    not path.endswith("/") and ".." not in Path(path).parts and
                    "\\\\" not in path and
                    (row.external_attr >> 16) & 0o170000 == 0o100000 and
                    row.compress_type == zipfile.ZIP_STORED, "unsafe_archive_entry")
        require(z.testzip() is None, "archive_crc_mismatch")
        provenance = json.loads(z.read("mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json"))
        require(provenance.get("source_commit_sha") == source and
                provenance.get("build_fingerprint") == receipt.get("build_fingerprint") and
                provenance.get("package_manifest_digest") == receipt.get("package_manifest_digest"),
                "embedded_provenance_mismatch")
        # Independently recompute every file hash and the producer's canonical
        # manifest digest from ZIP bytes. A valid CRC is not source integrity.
        rows = provenance.get("package_files")
        require(isinstance(rows, list) and len(rows) == receipt.get("manifest_file_count"),
                "embedded_manifest_count_mismatch")
        expected_names = {"mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json"}
        canonical = []
        for entry in rows:
            require(isinstance(entry, dict), "embedded_manifest_row_invalid")
            name, digest, size = entry.get("path"), entry.get("sha256"), entry.get("bytes")
            require(isinstance(name, str) and name and
                    not name.startswith("/") and ".." not in Path(name).parts and
                    isinstance(digest, str) and len(digest) == 64 and
                    isinstance(size, int) and size >= 0, "embedded_manifest_row_invalid")
            member = "mad4b-site-control-plane/" + name
            require(member not in expected_names and member in z.namelist(),
                    "embedded_manifest_member_missing_or_duplicate")
            raw = z.read(member)
            require(len(raw) == size and hashlib.sha256(raw).hexdigest() == digest,
                    "embedded_manifest_member_hash_mismatch")
            expected_names.add(member)
            canonical.append(name.encode("utf-8") + bytes([0]) + str(size).encode("ascii") + bytes([0]) + digest.encode("ascii") + bytes([10]))
        require(set(z.namelist()) == expected_names, "unexpected_archive_members")
        manifest_sha = hashlib.sha256(b"".join(canonical)).hexdigest()
        require(manifest_sha == receipt.get("package_manifest_digest"),
                "computed_manifest_digest_mismatch")


def native_checks(root: Path, php: str) -> dict:
    """Observed local execution; never a signer-approved release certificate."""
    checks = []
    php_bin = shutil.which(php)
    version = ""
    if php_bin:
        try:
            p = command([php_bin, "-r", "echo PHP_MAJOR_VERSION,'.',PHP_MINOR_VERSION;"], root, 15)
            version = p.stdout.strip() if p.returncode == 0 else ""
        except (OSError, subprocess.TimeoutExpired):
            pass
    files = sorted((root / PLUGIN).rglob("*.php"))
    lint_state = "BLOCKED"
    if php_bin and version == "8.3":
        lint_state = "PASS"
        for path in files:
            try:
                p = command([php_bin, "-l", str(path)], root, 30)
                if p.returncode:
                    lint_state = "FAIL"
                    break
            except (OSError, subprocess.TimeoutExpired):
                lint_state = "BLOCKED"
                break
    checks.append({"gate": "php83_tree_syntax", "state": lint_state,
                   "php_version": version or "unavailable", "files_total": len(files),
                   "complete_lint": lint_state == "PASS"})
    for filename in TESTS:
        f = root / PLUGIN / "tests" / filename
        state = "BLOCKED"
        if php_bin and f.is_file():
            try:
                p = command([php_bin, str(f)], root, 120)
                state = "PASS" if p.returncode == 0 else "FAIL"
            except (OSError, subprocess.TimeoutExpired):
                state = "BLOCKED"
        checks.append({"gate": filename, "state": state})
    return {"checks": checks,
            "local_tests_passed": all(x["state"] == "PASS" for x in checks),
            "release_certified": False, "staging_certified": False,
            "external_runner_trust_verified": False}


def build(root: Path, output: Path, source: str, adapter: Path,
          *, run_tests: bool = False, php: str = "php") -> dict:
    root = root.resolve(strict=True)
    # Check symlinks BEFORE resolve(), otherwise it hides a redirected path.
    require(not output.is_symlink() and not adapter.is_symlink(),
            "symlinked_output_or_adapter_denied")
    output = output.resolve()
    adapter = adapter.resolve()
    require(not output.is_relative_to(root),
            "output_must_be_outside_source_checkout")
    source_identity(root, source)
    version, adapter_sha = certified_adapter(root, adapter)
    plugin = root / PLUGIN
    main = (plugin / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
    hit = VERSION.search(main)
    require(hit is not None, "plugin_version_missing")
    plugin_version = hit.group(1)
    require((root / CANONICAL_BUILDER).is_file(), "canonical_builder_missing")
    require(not output.exists() or not any(output.iterdir()), "output_must_be_empty")
    output.mkdir(parents=True, exist_ok=True)
    # The plugin source is never edited by the provenance producer.
    with tempfile.TemporaryDirectory(prefix="mad4b-standalone-") as tmp:
        stage = Path(tmp) / "mad4b-site-control-plane"
        shutil.copytree(plugin, stage, symlinks=True)
        shutil.rmtree(stage / "tests", ignore_errors=True)
        archive = output / ("mad4b-site-control-plane-" + source + ".zip")
        receipt_file = output / "CANONICAL-PACKAGE-RECEIPT.json"
        cmd = [sys.executable, str(root / CANONICAL_BUILDER),
               "--root", str(stage), "--output-zip", str(archive),
               "--version", plugin_version, "--source-sha", source,
               "--adapter-version", version, "--adapter-sha", adapter_sha,
               "--receipt-output", str(receipt_file),
               "--provenance-output", str(output / "MAD4B-BUILD-PROVENANCE.json"),
               "--build-fingerprint-output", str(output / "BUILD-FINGERPRINT.txt"),
               "--manifest-digest-output", str(output / "PACKAGE-MANIFEST-DIGEST.txt")]
        try:
            result = command(cmd, root, 180)
        except (OSError, subprocess.TimeoutExpired) as exc:
            raise BuildBlocked("canonical_builder_unavailable") from exc
        require(result.returncode == 0 and archive.is_file() and receipt_file.is_file(),
                "canonical_builder_failed")
        receipt = json.loads(receipt_file.read_text(encoding="utf-8"))
        verify_zip(archive, receipt, source)
        require(archive.stat().st_size <= 16 * 1024 * 1024,
                "package_exceeds_staging_upload_limit")
    source_identity(root, source)
    result = {"contract": CONTRACT, "source_commit_sha": source,
              "profile": "local-checks" if run_tests else "build-only",
              "build_state": "BUILT_UNVERIFIED", "zip_filename": archive.name,
              "archive_sha256": sha(archive), "archive_bytes": archive.stat().st_size,
              "build_fingerprint": receipt["build_fingerprint"],
              "package_manifest_digest": receipt["package_manifest_digest"],
              "certified_adapter_version": version, "certified_adapter_sha256": adapter_sha,
              "canonical_package_receipt": receipt_file.name,
              "tests": native_checks(root, php) if run_tests else
                  {"checks": [], "local_tests_passed": False, "release_certified": False},
              "github_ci_certified": False, "staging_certified": False,
              "production_authorized": False, "publish_authorized": False,
              "mcp_install_performed": False,
              "next_step": "independent_owner_review_and_source_bound_evidence"}
    (output / "STANDALONE-BUILD-REPORT.json").write_text(
        json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return result


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--repo-root", type=Path, required=True)
    p.add_argument("--expected-head", required=True)
    p.add_argument("--adapter-archive", type=Path, required=True)
    p.add_argument("--output-dir", type=Path, required=True)
    p.add_argument("--profile", choices=("build-only", "local-checks"), default="build-only")
    p.add_argument("--php", default="php")
    args = p.parse_args()
    try:
        report = build(args.repo_root, args.output_dir, args.expected_head,
                       args.adapter_archive, run_tests=args.profile == "local-checks",
                       php=args.php)
    except (BuildBlocked, FileNotFoundError, ValueError, json.JSONDecodeError,
            KeyError, zipfile.BadZipFile, OSError) as exc:
        print("BLOCKED: " + (str(exc) if isinstance(exc, BuildBlocked)
                             else type(exc).__name__), file=sys.stderr)
        return 2
    print(json.dumps(report, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
