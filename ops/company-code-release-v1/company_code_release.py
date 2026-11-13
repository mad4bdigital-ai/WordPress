#!/usr/bin/env python3
"""MAD4B Company Code Release v1.

Git-backed, exact-scope company code lifecycle. The generic host executor never
creates an approval. Mutations require a separately issued, verifiable Broker
grant bound to the company, environment, site UUID and immutable release plan.
"""
from __future__ import annotations

import argparse
import base64
import datetime as dt
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import tempfile
from typing import Any, Callable

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey

POLICY_PATH = Path("/etc/mad4b/company-code-release-policy.json")
AUTH_CONTRACT = "mad4b.company-code-release.authorization.v1"
PLAN_CONTRACT = "mad4b.company-code-release.plan.v1"
POLICY_CONTRACT = "mad4b.company-code-release-policy.v1"
SPEC_CONTRACT = "mad4b.company-code-release.request.v1"
RECEIPT_CONTRACT = "mad4b.company-code-release.receipt.v1"
ALLOWED_EXTENSIONS = {".php", ".css", ".js", ".json", ".txt", ".md"}
# Tests are fixed named operations. A release request cannot inject PHP,
# shell commands, arbitrary URLs or executable callbacks.
ALLOWED_ACCEPTANCE_CHECKS = ("wp_boot", "copy_coverage_readiness")
GIT_SHA = re.compile(r"^[a-f0-9]{40}$")
HEX_SHA = re.compile(r"^[a-f0-9]{64}$")
SCOPE = re.compile(r"^[a-z][a-z0-9-]{1,63}$")
NONCE = re.compile(r"^[a-zA-Z0-9_-]{16,96}$")


class Denied(Exception):
    """Policy, provenance, concurrency or runtime precondition failed."""


def canonical(data: Any) -> bytes:
    return json.dumps(data, ensure_ascii=False, sort_keys=True,
                      separators=(",", ":")).encode("utf-8")


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def fail_if(condition: bool, reason: str) -> None:
    if condition:
        raise Denied(reason)


def no_link(path: Path) -> None:
    """Reject symlinks along existing path components, not just at the leaf."""
    for parent in [path, *path.parents]:
        fail_if(parent.is_symlink(), "SYMLINK_PATH_DENIED")


def exact_relative(value: Any) -> str:
    fail_if(not isinstance(value, str) or not value or len(value) > 240,
            "INVALID_FILE_PATH")
    path = PurePosixPath(value)
    fail_if(path.is_absolute() or ".." in path.parts or "." in path.parts or
            "\\" in value or "//" in value or "\x00" in value,
            "UNSAFE_FILE_PATH")
    fail_if(path.as_posix() != value or
            path.suffix.lower() not in ALLOWED_EXTENSIONS,
            "NONCANONICAL_FILE_PATH")
    return value


def permitted_target(relative: str) -> bool:
    parts = PurePosixPath(relative).parts
    if len(parts) < 3 or parts[:2] != ("wp-content", "plugins") and parts[:2] != ("wp-content", "mu-plugins"):
        return False
    if parts[1] == "mu-plugins":
        return len(parts) >= 3
    return len(parts) >= 4 and parts[2].startswith(("mad4b-", "etg-"))


def safe_regular(path: Path, *, max_size: int = 4_000_000) -> bytes:
    no_link(path)
    fail_if(not path.is_file(), "MISSING_REGULAR_FILE")
    fail_if(path.stat().st_size > max_size, "FILE_SIZE_LIMIT")
    return path.read_bytes()


def trusted_config(path: Path) -> dict:
    # The running executor can only read this fixed policy file.
    fail_if(path.resolve() != POLICY_PATH, "POLICY_LOCATION_NOT_PINNED")
    no_link(path)
    st = path.stat()
    fail_if(st.st_uid != 0 or stat.S_IMODE(st.st_mode) & 0o022,
            "POLICY_NOT_ROOT_OWNED_READONLY")
    policy = json.loads(safe_regular(path, max_size=200_000))
    fail_if(policy.get("contract") != POLICY_CONTRACT, "POLICY_CONTRACT_INVALID")
    return policy


def checked_policy(policy: dict) -> None:
    fail_if(not isinstance(policy, dict) or policy.get("contract") != POLICY_CONTRACT,
            "POLICY_CONTRACT_INVALID")
    fail_if(not isinstance(policy.get("targets"), dict), "NO_TARGET_REGISTRY")
    fail_if(not isinstance(policy.get("source_git_root"), str), "NO_GIT_ROOT")


def run(command: list[str], timeout: int = 15) -> bytes:
    process = subprocess.run(command, stdout=subprocess.PIPE,
                             stderr=subprocess.PIPE, timeout=timeout, check=False)
    fail_if(process.returncode != 0,
            "SUBPROCESS_FAILED:" + command[0] + ":" +
            process.stderr.decode(errors="replace")[-160:])
    return process.stdout


def git_bytes(repo: Path, commit: str, relative: str) -> bytes:
    fail_if(not GIT_SHA.fullmatch(commit), "COMMIT_MUST_BE_FULL_SHA")
    rel = exact_relative(relative)
    # Git object, not mutable working-tree contents, is the source of truth.
    run(["git", "-C", str(repo), "cat-file", "-e", commit + "^{commit}"])
    tree_line = run(["git", "-C", str(repo), "ls-tree", commit, "--", rel]).decode()
    fail_if(not tree_line.startswith(("100644 blob ", "100755 blob ")),
            "GIT_SOURCE_MUST_BE_REGULAR_FILE")
    data = run(["git", "-C", str(repo), "show", commit + ":" + rel])
    fail_if(len(data) > 4_000_000, "SOURCE_ARTIFACT_TOO_LARGE")
    return data


def registered_release_targets(policy: dict, entry: dict, commit: str) -> set[str]:
    """Resolve per-company deployable modules from a Git-committed catalog.

    Merely being under mu-plugins is not authority: each target must be explicitly
    catalogued at the immutable requested commit and scoped by the bound policy.
    """
    static = set(entry["allowed_targets"])
    catalog_path = entry.get("release_catalog")
    if catalog_path is None:
        return static
    fail_if(not isinstance(catalog_path, str) or
            catalog_path != "ops/company-code-release-v1/catalog/mad4b-release-components.json",
            "RELEASE_CATALOG_NOT_CANONICAL")
    catalog = json.loads(git_bytes(Path(policy["source_git_root"]), commit, catalog_path))
    fail_if(not isinstance(catalog, dict) or
            catalog.get("contract") != "mad4b.company-release-components.v1" or
            sorted(catalog) != ["components", "contract"],
            "RELEASE_CATALOG_INVALID")
    components = catalog.get("components")
    fail_if(not isinstance(components, list) or not 1 <= len(components) <= 500,
            "RELEASE_CATALOG_COMPONENTS_INVALID")
    for component in components:
        fail_if(not isinstance(component, dict) or
                sorted(component) != ["files", "id"] or
                not isinstance(component["id"], str) or
                not re.fullmatch(r"mad4b-[a-z0-9-]{3,90}", component["id"]),
                "RELEASE_COMPONENT_INVALID")
        files = component["files"]
        fail_if(not isinstance(files, list) or not 1 <= len(files) <= 24,
                "RELEASE_COMPONENT_FILES_INVALID")
        for target in files:
            fail_if(not isinstance(target, str), "RELEASE_COMPONENT_PATH_INVALID")
            target = exact_relative(target)
            fail_if(not permitted_target(target) or
                    not target.startswith("wp-content/mu-plugins/mad4b-") or
                    not target.endswith(".php"), "RELEASE_COMPONENT_SCOPE_INVALID")
            git_bytes(Path(policy["source_git_root"]), commit, target)
            static.add(target)
    return static


def target_binding(policy: dict, company: str, environment: str) -> dict:
    fail_if(not SCOPE.fullmatch(company) or not SCOPE.fullmatch(environment),
            "INVALID_COMPANY_ENVIRONMENT")
    entry = policy.get("targets", {}).get(company + ":" + environment)
    fail_if(not isinstance(entry, dict), "TARGET_NOT_ENROLLED")
    for field in ("site_uuid", "root", "container", "origin", "allowed_targets"):
        fail_if(not entry.get(field), "INCOMPLETE_TARGET_BINDING:" + field)
    fail_if(not re.fullmatch(r"[a-f0-9-]{36}", entry["site_uuid"]),
            "INVALID_SITE_UUID")
    fail_if(not entry["origin"].startswith("https://"),
            "UNSAFE_TARGET_ORIGIN")
    # Environment labels never confer or remove code-release authority.
    # Every environment must instead match a dedicated enrolled company:
    # environment binding, immutable Git plan, and independently signed grant.
    return entry


def make_plan(spec: dict, policy: dict) -> dict:
    checked_policy(policy)
    fail_if(spec.get("contract") != SPEC_CONTRACT, "INVALID_REQUEST_CONTRACT")
    company, env = spec.get("company"), spec.get("environment")
    entry = target_binding(policy, company, env)
    fail_if(spec.get("site_uuid") != entry["site_uuid"], "SITE_UUID_DRIFT")
    repo = Path(policy["source_git_root"])
    root = Path(entry["root"])
    fail_if(not root.is_dir() or not repo.is_dir(), "ROOT_NOT_AVAILABLE")
    no_link(root)
    files = spec.get("files")
    fail_if(not isinstance(files, list) or not 1 <= len(files) <= 24,
            "INVALID_RELEASE_FILE_COUNT")
    allowlist = registered_release_targets(policy, entry, spec["git_commit"])
    checks = spec.get("acceptance_checks", ["wp_boot"])
    fail_if(not isinstance(checks, list) or len(checks) < 1 or
            len(checks) > len(ALLOWED_ACCEPTANCE_CHECKS) or
            any(not isinstance(check, str) for check in checks) or
            len(set(checks)) != len(checks) or
            "wp_boot" not in checks or
            any(check not in ALLOWED_ACCEPTANCE_CHECKS for check in checks),
            "UNREGISTERED_ACCEPTANCE_CHECK")
    rows, seen = [], set()
    for record in files:
        fail_if(not isinstance(record, dict), "INVALID_FILE_RECORD")
        source = exact_relative(record.get("source"))
        target = exact_relative(record.get("target"))
        fail_if(not permitted_target(target) or target not in allowlist,
                "TARGET_FILE_NOT_ALLOWED")
        fail_if(target not in set(entry["allowed_targets"]) and source != target,
                "CATALOG_SOURCE_TARGET_MISMATCH")
        fail_if(target in seen, "DUPLICATE_TARGET")
        seen.add(target)
        before = record.get("expected_before_sha256")
        fail_if(before is not None and
                (not isinstance(before, str) or not HEX_SHA.fullmatch(before)),
                "INVALID_PREIMAGE_DIGEST")
        src = git_bytes(repo, spec.get("git_commit"), source)
        dest = root / target
        no_link(dest)
        fail_if(dest.exists() and not dest.is_file(), "NON_FILE_TARGET")
        old = dest.read_bytes() if dest.exists() else None
        old_digest = digest(old) if old is not None else None
        fail_if(before != old_digest, "TARGET_PREIMAGE_MISMATCH:" + target)
        rows.append({
            "source": source, "target": target,
            "source_sha256": digest(src),
            "expected_before_sha256": before,
            "source_bytes": len(src),
            "source_mode": 0o644,  # executable scripts do not run as part of release
        })
    payload = {
        "contract": PLAN_CONTRACT, "company": company,
        "environment": env, "site_uuid": entry["site_uuid"],
        "origin": entry["origin"], "container": entry["container"],
        "git_commit": spec["git_commit"], "git_root": str(repo.resolve()),
        "target_root": str(root.resolve()), "files": rows,
        "acceptance_checks": checks,
        "release_catalog": entry.get("release_catalog"),
    }
    payload["plan_sha256"] = digest(canonical(payload))
    payload["changed_count"] = sum(
        x["source_sha256"] != x["expected_before_sha256"] for x in rows
    )
    return payload


def confirm_plan(plan: dict, policy: dict, *, expect_preimage: bool) -> None:
    checked_policy(policy)
    fail_if(plan.get("contract") != PLAN_CONTRACT, "PLAN_CONTRACT_INVALID")
    stored = dict(plan)
    stored.pop("plan_sha256", None)
    stored.pop("changed_count", None)
    fail_if(digest(canonical(stored)) != plan.get("plan_sha256"),
            "PLAN_TAMPERED")
    entry = target_binding(policy, plan["company"], plan["environment"])
    fail_if(entry["site_uuid"] != plan.get("site_uuid") or
            entry["origin"] != plan.get("origin") or
            entry["container"] != plan.get("container") or
            str(Path(entry["root"]).resolve()) != plan.get("target_root") or
            str(Path(policy["source_git_root"]).resolve()) != plan.get("git_root"),
            "PLAN_SCOPE_CHANGED")
    catalog_at_plan = plan.get("release_catalog")
    # Plans signed before catalog enrollment remain limited to the explicit
    # original static paths. Never retroactively elevate a legacy plan.
    if catalog_at_plan is None:
        allowlist = set(entry["allowed_targets"])
    else:
        fail_if(catalog_at_plan != entry.get("release_catalog"),
                "RELEASE_CATALOG_BINDING_DRIFT")
        allowlist = registered_release_targets(policy, entry, plan["git_commit"])
    checks = plan.get("acceptance_checks", [])
    fail_if(not isinstance(checks, list) or not checks or
            any(not isinstance(check, str) for check in checks) or
            len(set(checks)) != len(checks) or
            "wp_boot" not in checks or
            any(check not in ALLOWED_ACCEPTANCE_CHECKS for check in checks),
            "PLAN_ACCEPTANCE_CHECK_NOT_ALLOWED")
    for row in plan["files"]:
        target = exact_relative(row["target"])
        fail_if(target not in allowlist or not permitted_target(target),
                "PLAN_TARGET_NO_LONGER_ALLOWED")
        fail_if(target not in set(entry["allowed_targets"]) and row["source"] != target,
                "PLAN_CATALOG_SOURCE_TARGET_MISMATCH")
        fresh = git_bytes(Path(plan["git_root"]), plan["git_commit"], row["source"])
        fail_if(digest(fresh) != row["source_sha256"], "SOURCE_COMMIT_DRIFT")
        target_file = Path(plan["target_root"]) / target
        no_link(target_file)
        actual = digest(target_file.read_bytes()) if target_file.is_file() else None
        expected = row["expected_before_sha256"] if expect_preimage else row["source_sha256"]
        fail_if(actual != expected, "TARGET_CONTENT_DRIFT:" + target)


def grant_message(grant: dict) -> bytes:
    signed = {k: v for k, v in grant.items() if k != "signature"}
    return (AUTH_CONTRACT + "\n").encode() + canonical(signed)


def verify_grant(plan: dict, policy: dict, grant: dict, action: str) -> None:
    fail_if(grant.get("contract") != AUTH_CONTRACT or
            grant.get("action") != action or
            grant.get("plan_sha256") != plan["plan_sha256"] or
            grant.get("company") != plan["company"] or
            grant.get("environment") != plan["environment"] or
            grant.get("site_uuid") != plan["site_uuid"],
            "BROKER_GRANT_NOT_BOUND_TO_RELEASE")
    fail_if(grant.get("issuer") != "mad4b-company-broker" or
            not isinstance(grant.get("nonce"), str) or
            not NONCE.fullmatch(grant["nonce"]), "BROKER_GRANT_INVALID")
    try:
        expiry = dt.datetime.fromisoformat(grant["expires_at"])
        now = dt.datetime.now(dt.timezone.utc)
        if expiry.tzinfo is None or not now < expiry <= now + dt.timedelta(minutes=30):
            raise ValueError("expiry out of range")
        key = Ed25519PublicKey.from_public_bytes(
            base64.b64decode(policy["broker_public_key_b64"], validate=True)
        )
        key.verify(base64.b64decode(grant["signature"], validate=True),
                   grant_message(grant))
    except (KeyError, ValueError, TypeError, Exception) as exc:
        raise Denied("BROKER_SIGNATURE_OR_EXPIRY_INVALID") from exc


def atomic_write(path: Path, data: bytes, mode: int = 0o644) -> None:
    no_link(path)
    fail_if(not path.parent.is_dir(), "TARGET_PARENT_NOT_PRESENT")
    fd, name = tempfile.mkstemp(prefix=".mad4b-code-release-", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as stream:
            os.fchmod(stream.fileno(), mode)
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def native_target_readback(company: str, environment: str) -> dict:
    """Use the existing root-owned FAORE dynamic resolver: no ad hoc aliases."""
    import importlib.util
    import sys
    root = Path("/opt/mad4b-company-transport/native-operator-v1")
    module = root / "native_operator_target.py"
    no_link(module)
    stat_info = module.stat()
    fail_if(stat_info.st_uid != 0 or stat.S_IMODE(stat_info.st_mode) & 0o022,
            "ORIGINAL_NATIVE_ADAPTER_NOT_TRUSTED")
    fail_if(not module.is_file(), "ORIGINAL_NATIVE_ADAPTER_MISSING")
    # The adapter validates the root-owned original target registry, the
    # Coolify service identity, exact WP site profile, and its database bind.
    previous = list(sys.path)
    try:
        sys.path.insert(0, str(root))
        spec = importlib.util.spec_from_file_location(
            "_mad4b_native_code_release_target", str(module)
        )
        fail_if(spec is None or spec.loader is None,
                "ORIGINAL_NATIVE_ADAPTER_LOAD_FAILED")
        adapter = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(adapter)
        return adapter.target(company, environment)
    finally:
        sys.path[:] = previous


def assert_runtime_binding(
    plan: dict, policy: dict, *,
    read_registry: Callable[[], dict] | None = None,
    read_original_target: Callable[[str, str], dict] = native_target_readback,
    read_mounts: Callable[[str], list] | None = None,
) -> None:
    """Re-verify original identity and Coolify runtime without granting writes."""
    if read_registry is None:
        file = Path(policy["runtime_registry"])
        no_link(file)
        info = file.stat()
        fail_if(info.st_uid != 0 or stat.S_IMODE(info.st_mode) & 0o077,
                "ORIGINAL_REGISTRY_PERMISSIONS_INVALID")
        registry = json.loads(safe_regular(file, max_size=100_000))
    else:
        registry = read_registry()
    fail_if(not isinstance(registry, dict) or
            registry.get("contract") !=
            "mad4b.company-native-target-runtime-bindings.v1",
            "ORIGINAL_REGISTRY_NOT_VALID")
    entry = registry.get("targets", {}).get(
        plan["company"] + ":" + plan["environment"]
    )
    fail_if(not isinstance(entry, dict) or
            entry.get("site_uuid") != plan["site_uuid"] or
            entry.get("canonical_origin") != plan["origin"],
            "ORIGINAL_TARGET_BINDING_DRIFT")
    try:
        observed = read_original_target(plan["company"], plan["environment"])
    except (RuntimeError, ValueError, KeyError, OSError,
            subprocess.TimeoutExpired) as exc:
        raise Denied("ORIGINAL_DYNAMIC_TARGET_NOT_VERIFIED") from exc
    fail_if(not isinstance(observed, dict) or
            observed.get("contract") !=
            "mad4b.faore.native-operator-dynamic-target.v1" or
            observed.get("company_id") != plan["company"] or
            observed.get("environment") != plan["environment"] or
            observed.get("site_uuid") != plan["site_uuid"] or
            observed.get("canonical_origin") != plan["origin"] or
            observed.get("read_only") is not True or
            observed.get("authorization_created") is not False or
            observed.get("native_execution_performed") is not False,
            "ORIGINAL_DYNAMIC_TARGET_IDENTITY_INVALID")
    fail_if(entry.get("coolify_service_uuid") not in
            (None, "", observed.get("coolify_service_uuid")),
            "ORIGINAL_COOLIFY_SERVICE_DRIFT")
    # The proposed plan must be regenerated after a valid container rotation:
    # an old plan can never be silently rebound to another container.
    fail_if(observed.get("target_container") != plan["container"],
            "DYNAMIC_CONTAINER_RELEASE_PLAN_DRIFT")
    if read_mounts is None:
        mounts = json.loads(run(["docker", "inspect", plan["container"],
                                 "--format", "{{json .Mounts}}"], timeout=20))
    else:
        mounts = read_mounts(plan["container"])
    matching = [row for row in mounts
                if row.get("Type") == "bind" and row.get("RW") is True
                and row.get("Destination") == "/var/www/html"]
    fail_if(len(matching) != 1 or
            str(Path(matching[0]["Source"]).resolve()) != plan["target_root"],
            "TARGET_CONTAINER_MOUNT_MISMATCH")


def php_lint(plan: dict) -> None:
    # Bound PHP syntax and WordPress bootstrap acceptance (no mutation).
    for row in plan["files"]:
        if row["target"].endswith(".php"):
            output = run(["docker", "exec", plan["container"], "php", "-l",
                          "/var/www/html/" + row["target"]], timeout=25)
            fail_if(b"No syntax errors detected" not in output, "PHP_LINT_NOT_PROVEN")
    output = run(["docker", "exec", "-u", "www-data", plan["container"],
                  "wp", "--path=/var/www/html", "--skip-themes", "eval",
                  "echo 'MAD4B_CODE_RELEASE_WP_BOOT_OK';"], timeout=50)
    fail_if(b"MAD4B_CODE_RELEASE_WP_BOOT_OK" not in output,
            "WORDPRESS_BOOT_NOT_PROVEN")
    if "copy_coverage_readiness" in plan.get("acceptance_checks", []):
        # Static read-only runtime acceptance, never supplied from a request.
        # An incomplete source fails release QA and triggers atomic rollback.
        code = (
            'if(!function_exists("mad4b_copy_coverage_readiness_advisories")'
            '||!has_filter("mad4b/content_readiness/external_issues",'
            '"mad4b_copy_coverage_readiness_advisories")'
            '||!class_exists("MAD4B_SCP_Content_Readiness_Alerts"))exit(2);'
            '$r=MAD4B_SCP_Content_Readiness_Alerts::discover();'
            'if(!is_array($r)||($r["contract"]??"")!=='
            '"mad4b.content-readiness.discovery.v1"'
            '||!is_array($r["issues"]??null))exit(3);'
            # The two-argument Content Readiness hook is shared by Gallery,
            # Pricing, Localization, Media Origin and Style Parity. Use an
            # Elementor-only policy to avoid duplicate full-tour scans here.
            '$advisories=apply_filters("mad4b/content_readiness/external_issues",'
            '[],["post_types"=>["elementor_library"]]);'
            'if(!is_array($advisories))exit(4);'
            'foreach($advisories as $a){'
            'if(($a["source"]??"")!=="mad4b-elementor-copy-coverage")continue;'
            'if(($a["post_type"]??"")!=="elementor_library"'
            '||!preg_match("/^elm_copy_(?:hero_static_locale|catalog_fallback_en|same_as_source|filter_week_typo)_(?:en|es|fr|de|it|ar)_[a-f0-9]{12}$/D",'
            '(string)($a["code"]??"")))exit(5);'
            '}'
            'echo "MAD4B_CODE_RELEASE_COPY_READINESS_PASS";'
        )
        outcome = run(["docker", "exec", "-u", "www-data",
                       plan["container"], "wp", "--path=/var/www/html",
                       "--skip-themes", "eval", code], timeout=60)
        fail_if(b"MAD4B_CODE_RELEASE_COPY_READINESS_PASS" not in outcome,
                "COPY_COVERAGE_RUNTIME_ACCEPTANCE_NOT_PROVEN")


def assert_pinned_receipt_directory(policy: dict, receipt_dir: Path) -> None:
    """Single root-owned receipt root fences nonce replay across CLI invocations."""
    bound = policy.get("receipt_dir")
    fail_if(not isinstance(bound, str) or not bound.startswith("/"),
            "RECEIPT_ROOT_NOT_CONFIGURED")
    exact = Path(bound)
    no_link(exact)
    no_link(receipt_dir)
    fail_if(receipt_dir.resolve() != exact.resolve(),
            "RECEIPT_DIRECTORY_NOT_PINNED")
    fail_if(not receipt_dir.is_dir() or receipt_dir.stat().st_uid != 0 or
            stat.S_IMODE(receipt_dir.stat().st_mode) & 0o077,
            "RECEIPTS_MUST_BE_ROOT_PRIVATE")


def backup_path(receipt_dir: Path, plan: dict) -> Path:
    return receipt_dir / (plan["plan_sha256"] + ".backup.json")


def prepare_backup(plan: dict, policy: dict, receipt_dir: Path,
                   *, runtime_check: Callable[[dict, dict], None] = assert_runtime_binding) -> dict:
    """Private durable recovery evidence, before any target changes."""
    assert_pinned_receipt_directory(policy, receipt_dir)
    confirm_plan(plan, policy, expect_preimage=True)
    runtime_check(plan, policy)
    backup = {"contract": "mad4b.company-code-release.backup.v1",
              "plan_sha256": plan["plan_sha256"], "files": []}
    root = Path(plan["target_root"])
    for row in plan["files"]:
        target = root / row["target"]
        old = target.read_bytes() if target.is_file() else None
        entry = {"target": row["target"], "sha256": row["expected_before_sha256"],
                 "mode": stat.S_IMODE(target.stat().st_mode) if old is not None else None,
                 "bytes_b64": base64.b64encode(old).decode() if old is not None else None}
        fail_if((digest(old) if old is not None else None) != entry["sha256"],
                "PREPARE_PREIMAGE_CHANGED")
        backup["files"].append(entry)
    blob = canonical(backup) + b"\n"
    path = backup_path(receipt_dir, plan)
    if path.exists():
        fail_if(safe_regular(path, max_size=100_000_000) != blob,
                "PREPARED_BACKUP_MISMATCH")
    else:
        atomic_write(path, blob, 0o600)
    return {"contract": RECEIPT_CONTRACT, "state": "PREPARED",
            "plan_sha256": plan["plan_sha256"], "backup_sha256": digest(blob),
            "files": len(plan["files"]), "target_mutated": False}


def load_backup(plan: dict, receipt_dir: Path) -> dict:
    path = backup_path(receipt_dir, plan)
    contents = safe_regular(path, max_size=100_000_000)
    backup = json.loads(contents)
    fail_if(backup.get("contract") != "mad4b.company-code-release.backup.v1" or
            backup.get("plan_sha256") != plan["plan_sha256"],
            "BACKUP_PLAN_MISMATCH")
    found = {item["target"]: item for item in backup["files"]}
    fail_if(len(found) != len(plan["files"]) or
            set(found) != {x["target"] for x in plan["files"]},
            "BACKUP_FILE_SET_MISMATCH")
    for row in plan["files"]:
        b = found[row["target"]]
        original = (base64.b64decode(b["bytes_b64"], validate=True)
                    if b["bytes_b64"] is not None else None)
        fail_if((digest(original) if original is not None else None) !=
                row["expected_before_sha256"], "BACKUP_DIGEST_INVALID")
    return backup


def restore_backup(plan: dict, backup: dict) -> None:
    root = Path(plan["target_root"])
    records = {entry["target"]: entry for entry in backup["files"]}
    for row in plan["files"]:
        path = root / row["target"]
        no_link(path)
        actual = digest(path.read_bytes()) if path.is_file() else None
        fail_if(actual not in (row["source_sha256"],
                               row["expected_before_sha256"]),
                "ROLLBACK_CONCURRENT_DRIFT:" + row["target"])
    for row in reversed(plan["files"]):
        backup_item = records[row["target"]]
        path = root / row["target"]
        content = (base64.b64decode(backup_item["bytes_b64"], validate=True)
                   if backup_item["bytes_b64"] is not None else None)
        if content is None:
            if path.is_file():
                path.unlink()
        else:
            atomic_write(path, content, backup_item["mode"])

def apply_release(plan: dict, policy: dict, grant: dict, receipt_dir: Path,
                  *, runtime_check: Callable[[dict, dict], None] = assert_runtime_binding,
                  validate_code: Callable[[dict], None] = php_lint) -> dict:
    """Apply only after external authority. Roll back automatically on any failure."""
    verify_grant(plan, policy, grant, "apply")
    assert_pinned_receipt_directory(policy, receipt_dir)
    lock = receipt_dir / ".company-code-release.lock"
    with open(lock, "a+") as lockfile:
        fcntl.flock(lockfile, fcntl.LOCK_EX | fcntl.LOCK_NB)
        nonce_path = receipt_dir / (grant["nonce"] + ".json")
        fail_if(nonce_path.exists(), "GRANT_ALREADY_USED")
        confirm_plan(plan, policy, expect_preimage=True)
        runtime_check(plan, policy)
        prepare_backup(plan, policy, receipt_dir, runtime_check=runtime_check)
        backup = load_backup(plan, receipt_dir)
        root = Path(plan["target_root"])
        changed = []
        state, failure = "APPLIED_VERIFIED", None
        try:
            for row in plan["files"]:
                data = git_bytes(Path(plan["git_root"]), plan["git_commit"], row["source"])
                path = root / row["target"]
                atomic_write(path, data, row["source_mode"])
                changed.append(row["target"])
            confirm_plan(plan, policy, expect_preimage=False)
            validate_code(plan)
        except Exception as exc:
            state, failure = "ROLLED_BACK", type(exc).__name__ + ":" + str(exc)[:160]
            restore_backup(plan, backup)
            confirm_plan(plan, policy, expect_preimage=True)
        receipt = {
            "contract": RECEIPT_CONTRACT, "state": state,
            "company": plan["company"], "environment": plan["environment"],
            "site_uuid": plan["site_uuid"], "git_commit": plan["git_commit"],
            "plan_sha256": plan["plan_sha256"], "nonce": grant["nonce"],
            "files": [x["target"] for x in plan["files"]],
            "failed_reason": failure,
            # Signature verification is NOT an original Broker/313 receipt.
            "broker_grant_signature_verified": True,
            "broker_receipt_verified": False,
            "original_313_receipt": False,
            "at": dt.datetime.now(dt.timezone.utc).isoformat(),
        }
        atomic_write(nonce_path, canonical(receipt) + b"\n", 0o600)
        return receipt



def rollback_release(plan: dict, policy: dict, grant: dict, receipt_dir: Path,
                     *, runtime_check: Callable[[dict, dict], None] = assert_runtime_binding) -> dict:
    """Separate signed Broker rollback. Reject a conflicting live edit."""
    verify_grant(plan, policy, grant, "rollback")
    assert_pinned_receipt_directory(policy, receipt_dir)
    with open(receipt_dir / ".company-code-release.lock", "a+") as lockfile:
        fcntl.flock(lockfile, fcntl.LOCK_EX | fcntl.LOCK_NB)
        nonce_path = receipt_dir / (grant["nonce"] + ".json")
        fail_if(nonce_path.exists(), "GRANT_ALREADY_USED")
        # Rollback is still a governed write: recheck source SHA, immutable
        # plan, current file catalog and live applied content before restoring.
        # Do not let an old grant override a subsequently revoked target scope.
        found_previous = False
        for path in receipt_dir.glob("*.json"):
            if path.name.endswith(".backup.json"):
                continue
            receipt = json.loads(safe_regular(path, max_size=100_000))
            if (receipt.get("plan_sha256") == plan["plan_sha256"] and
                    receipt.get("state") == "APPLIED_VERIFIED"):
                found_previous = True
                break
        fail_if(not found_previous, "NO_VERIFIED_APPLY_TO_ROLLBACK")
        confirm_plan(plan, policy, expect_preimage=False)
        runtime_check(plan, policy)
        backup = load_backup(plan, receipt_dir)
        restore_backup(plan, backup)
        confirm_plan(plan, policy, expect_preimage=True)
        result = {"contract": RECEIPT_CONTRACT, "state": "ROLLBACK_VERIFIED",
                  "company": plan["company"], "environment": plan["environment"],
                  "site_uuid": plan["site_uuid"],
                  "git_commit": plan["git_commit"],
                  "plan_sha256": plan["plan_sha256"], "nonce": grant["nonce"],
                  "files": [x["target"] for x in plan["files"]],
                  "original_313_receipt": False,
                  "at": dt.datetime.now(dt.timezone.utc).isoformat()}
        atomic_write(nonce_path, canonical(result) + b"\n", 0o600)
        return result

def check_sha_readback(plan: dict, policy: dict) -> dict:
    confirm_plan(plan, policy, expect_preimage=False)
    return {"contract": RECEIPT_CONTRACT,
            "state": "READBACK_VERIFIED", "files": len(plan["files"]),
            "plan_sha256": plan["plan_sha256"],
            "company": plan["company"], "environment": plan["environment"]}



def release_admission_doctor(
    plan: dict,
    preview_policy: dict,
    *,
    fixed_policy_path: Path = POLICY_PATH,
    runtime_check: Callable[[dict, dict], None] = assert_runtime_binding,
    original_broker_status: dict | None = None,
) -> dict:
    """Read-only admission evidence; cannot issue grants or authorize Apply."""
    state = {
        "contract": "mad4b.company-code-release.admission-diagnostic.v1",
        "scope": str(plan.get("company", "")) + ":" +
                 str(plan.get("environment", "")),
        "git_commit": plan.get("git_commit"),
        "plan_sha256": plan.get("plan_sha256"),
        "git_plan_verified": False,
        "original_runtime_binding_verified": False,
        "host_policy_installed_and_pinned": False,
        "broker_dispatch_registration": "not_independently_verified",
        "broker_signed_grant_verified": False,
        "original_313_receipt_verified": False,
        "production_execution_authorized": False,
        "production_mutated": False,
        "state": "HOLD_ORIGINAL_BROKER_ENROLLMENT",
        "blockers": [],
    }
    try:
        confirm_plan(plan, preview_policy, expect_preimage=True)
        state["git_plan_verified"] = True
    except (Denied, OSError, KeyError, ValueError, subprocess.TimeoutExpired) as exc:
        state["blockers"].append("GIT_OR_TARGET_PREIMAGE_NOT_VERIFIED:" +
                                 type(exc).__name__)
    if state["git_plan_verified"]:
        try:
            runtime_check(plan, preview_policy)
            state["original_runtime_binding_verified"] = True
        except (Denied, OSError, ValueError, KeyError,
                subprocess.TimeoutExpired) as exc:
            state["blockers"].append("ORIGINAL_RUNTIME_BINDING_UNVERIFIED:" +
                                     type(exc).__name__)
    if fixed_policy_path.is_file():
        try:
            if fixed_policy_path == POLICY_PATH:
                live_policy = trusted_config(POLICY_PATH)
                # Diagnostics do not treat arbitrary caller-supplied policies
                # or pending/unregistered grants as execution authority.
                binding = target_binding(live_policy,
                                         plan["company"], plan["environment"])
                state["host_policy_installed_and_pinned"] = (
                    binding["site_uuid"] == plan.get("site_uuid") and
                    binding["root"] == plan.get("target_root")
                )
        except (Denied, OSError, KeyError, ValueError):
            pass
    if not state["host_policy_installed_and_pinned"]:
        state["blockers"].append("PINNED_HOST_RELEASE_POLICY_NOT_ENROLLED")
    if original_broker_status is not None:
        row = original_broker_status
        bound = (row.get("contract") ==
                 "mad4b.company-code-release.lifecycle-bridge.v1"
                 and row.get("company") == plan.get("company")
                 and row.get("environment") == plan.get("environment")
                 and row.get("site_uuid") == plan.get("site_uuid"))
        fail_if(not bound, "ORIGINAL_BROKER_STATUS_SCOPE_INVALID")
        state["original_305_standing_wpcli_verified"] = (
            row.get("original_305_standing_wpcli_verified") is True)
        state["original_312_verifier_registered"] = (
            row.get("original_312_verifier_registered") is True)
        state["original_313_code_release_handler_registered"] = (
            row.get("original_313_code_release_handler_registered") is True)
        state["dynamic_registry_enrolled"] = (
            row.get("dynamic_registry_enrolled") is True)
        state["dynamic_306_digest_registered"] = (
            row.get("dynamic_306_digest_registered") is True)
        state["dynamic_313_independent_verifier_registered"] = (
            row.get("dynamic_313_independent_verifier_registered") is True)
        state["dynamic_313_finalizer_connected"] = (
            row.get("dynamic_313_finalizer_connected") is True)
        state["broker_dispatch_registration"] = (
            "registered_not_execution_authority" if
            row.get("company_code_release_dispatch_ability_registered") is True
            else "not_registered")
        state["legacy_staging_shell_is_release_authority"] = False
        state["blockers"].extend(row.get("blockers", []))
        if (state["original_305_standing_wpcli_verified"] and
                state["original_312_verifier_registered"] and
                state["git_plan_verified"] and
                state["original_runtime_binding_verified"]):
            state["state"] = (
                "HOLD_DYNAMIC_ORIGINAL_313_FINALIZER"
                if (state.get("dynamic_306_digest_registered") is True and
                    state.get("dynamic_313_finalizer_connected") is not True)
                else ("AWAIT_INDEPENDENT_SIGNED_JOB_AND_313_RECEIPT"
                      if (state.get("dynamic_306_digest_registered") is True and
                          state.get("dynamic_313_finalizer_connected") is True)
                      else ("HOLD_CODE_RELEASE_OPERATION_ENROLLMENT"
                            if not state["original_313_code_release_handler_registered"] or
                            state["broker_dispatch_registration"] == "not_registered"
                            else "AWAIT_INDEPENDENT_SIGNED_JOB_AND_313_RECEIPT"))
            )
    else:
        state["blockers"].append(
            "ORIGINAL_305_312_313_LIFECYCLE_STATUS_NOT_READ")
    state["blockers"].extend((
        "EXACT_SCOPE_SIGNED_BROKER_APPLY_GRANT_NOT_PROVIDED",
        "ORIGINAL_313_RECEIPT_NOT_PROVIDED",
    ))
    # An immutable plan with stale Git or target-file preimages must take
    # precedence over provider registration/313 status. No automatic
    # replacement of concurrently changed WordPress code is permitted.
    if not state["git_plan_verified"]:
        state["state"] = "HOLD_GIT_PLAN_OR_TARGET_DRIFT"
    elif not state["original_runtime_binding_verified"]:
        state["state"] = "HOLD_ORIGINAL_RUNTIME_BINDING_DRIFT"
    return state

def parse_json(file: Path, max_size: int = 2_000_000) -> dict:
    data = json.loads(safe_regular(file, max_size=max_size))
    fail_if(not isinstance(data, dict), "EXPECTED_JSON_OBJECT")
    return data


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("plan", "doctor", "prepare", "readback", "apply", "rollback"))
    parser.add_argument("--request", type=Path)
    parser.add_argument("--plan", type=Path)
    parser.add_argument("--policy", type=Path, default=POLICY_PATH)
    parser.add_argument("--grant", type=Path)
    parser.add_argument("--receipts", type=Path)
    args = parser.parse_args()

    if args.action == "plan":
        # A non-authorizing preview may use a supplied policy; never grants
        # write ability and does not install or alter target code.
        policy = parse_json(args.policy)
        plan = make_plan(parse_json(args.request), policy)
        print(json.dumps(plan, ensure_ascii=False, indent=2))
        return 0
    if args.action == "doctor":
        # Supply a candidate plan and preview policy for non-authorizing
        # diagnostics. This mode never calls Apply or issues a signature.
        plan = parse_json(args.plan)
        import company_lifecycle_broker
        original = company_lifecycle_broker.live_reconcile(
            plan["company"], plan["environment"], plan["plan_sha256"]
        )
        report = release_admission_doctor(
            plan, parse_json(args.policy), original_broker_status=original
        )
        print(json.dumps(report, ensure_ascii=False))
        return 0 if report["git_plan_verified"] else 3
    # Write path must always load the pinned root-owned policy.
    policy = trusted_config(POLICY_PATH)
    plan = parse_json(args.plan)
    if args.action == "readback":
        print(json.dumps(check_sha_readback(plan, policy)))
        return 0
    fail_if(os.geteuid() != 0, "HOST_OPERATOR_ROOT_REQUIRED")
    fail_if(args.receipts is None, "PRIVATE_RECEIPT_DIRECTORY_REQUIRED")
    if args.action == "prepare":
        result = prepare_backup(plan, policy, args.receipts)
        print(json.dumps(result))
        return 0
    fail_if(args.grant is None, "BROKER_GRANT_REQUIRED")
    grant = parse_json(args.grant)
    result = (apply_release(plan, policy, grant, args.receipts)
              if args.action == "apply" else
              rollback_release(plan, policy, grant, args.receipts))
    print(json.dumps(result, ensure_ascii=False))
    return 0 if result["state"] in ("APPLIED_VERIFIED", "ROLLBACK_VERIFIED") else 3


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (Denied, OSError, ValueError, KeyError, subprocess.TimeoutExpired) as exc:
        print(json.dumps({"contract": RECEIPT_CONTRACT, "state": "DENIED",
                          "error": type(exc).__name__ + ":" + str(exc)[:180]}))
        raise SystemExit(2)
