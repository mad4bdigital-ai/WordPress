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
import posixpath
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import tempfile
from typing import Any, Callable

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey

# File-backed companion module: also works under Original 313's pinned
# importlib file-loader (which does not put this directory on sys.path).
import importlib.util

_acceptance_path = Path(__file__).with_name("capability_acceptance.py")
_acceptance_spec = importlib.util.spec_from_file_location(
    "_mad4b_company_capability_acceptance_v1", _acceptance_path)
if _acceptance_spec is None or _acceptance_spec.loader is None:
    raise RuntimeError("CAPABILITY_ACCEPTANCE_MODULE_UNAVAILABLE")
capability_checks = importlib.util.module_from_spec(_acceptance_spec)
_acceptance_spec.loader.exec_module(capability_checks)

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
    registered_components: set[str] = set()
    catalog_files: set[str] = set()
    portable_plugins: dict[str, dict] = {}
    for component in components:
        fail_if(not isinstance(component, dict) or
                not isinstance(component.get("id"), str) or
                not re.fullmatch(r"mad4b-[a-z0-9-]{3,90}", component["id"]),
                "RELEASE_COMPONENT_INVALID")
        fail_if(component["id"] in registered_components,
                "DUPLICATE_RELEASE_COMPONENT")
        registered_components.add(component["id"])
        files = component.get("files")
        fail_if(not isinstance(files, list) or not 1 <= len(files) <= 24,
                "RELEASE_COMPONENT_FILES_INVALID")
        capabilities = component.get("capabilities")
        plugin_ids: set[str] = set()
        for value in files:
            fail_if(not isinstance(value, str), "RELEASE_COMPONENT_PATH_INVALID")
            target = exact_relative(value)
            fail_if(not permitted_target(target) or target in catalog_files,
                    "RELEASE_COMPONENT_SCOPE_OR_DUPLICATE_INVALID")
            parts = PurePosixPath(target).parts
            if parts[1] == "mu-plugins":
                fail_if(not target.startswith("wp-content/mu-plugins/mad4b-") or
                        not target.endswith(".php"),
                        "RELEASE_COMPONENT_MU_SCOPE_INVALID")
            else:
                plugin = parts[2]
                fail_if(not re.fullmatch(r"mad4b-[a-z0-9-]{3,90}", plugin) or
                        not target.endswith((".php", ".css", ".js", ".json", ".md", ".txt")) or
                        any(part in ("vendor", "node_modules", "tests", ".git", "uploads")
                            for part in parts[3:]),
                        "RELEASE_COMPONENT_PLUGIN_SCOPE_INVALID")
                plugin_ids.add(plugin)
            git_bytes(Path(policy["source_git_root"]), commit, target)
            catalog_files.add(target)
        if plugin_ids:
            fail_if(len(plugin_ids) != 1,
                    "RELEASE_COMPONENT_CROSSES_PLUGIN_OWNERS")
            fail_if(set(component) != {"id", "files", "capabilities"} or
                    not isinstance(capabilities, list) or not 1 <= len(capabilities) <= 40 or
                    len(capabilities) != len(set(map(str,capabilities))) or
                    any(not isinstance(x,str) or
                        not re.fullmatch(r"[a-z][a-z0-9-]{1,70}", x)
                        for x in capabilities),
                    "RELEASE_PLUGIN_CAPABILITY_CONTRACT_REQUIRED")
            for plugin in sorted(plugin_ids):
                if plugin not in portable_plugins:
                    manifest_path=("wp-content/plugins/"+plugin+
                                   "/mad4b-portability.json")
                    try:
                        profile=json.loads(git_bytes(
                            Path(policy["source_git_root"]), commit, manifest_path))
                    except (ValueError, TypeError) as exc:
                        raise Denied("PORTABLE_PLUGIN_PROFILE_INVALID") from exc
                    fail_if(not isinstance(profile,dict) or
                            profile.get("contract")!="mad4b.plugin-portability-profile.v1" or
                            profile.get("plugin")!=plugin or
                            profile.get("scope")!="tenant-portable" or
                            profile.get("sensitive_central_authority_not_included") is not True or
                            profile.get("financial_or_customer_data_automatic_write") is not False or
                            not isinstance(profile.get("features"),list) or
                            any(not isinstance(f, str) or
                                not re.fullmatch(r"[a-z][a-z0-9-]{1,70}", f)
                                for f in profile.get("features", [])),
                            "PORTABLE_PLUGIN_PROFILE_NOT_ELIGIBLE")
                    portable_plugins[plugin]=profile
                fail_if(not set(capabilities).issubset(
                        set(portable_plugins[plugin]["features"])),
                        "RELEASE_PLUGIN_CAPABILITY_NOT_REGISTERED")
        else:
            fail_if(set(component) != {"id", "files"},
                    "RELEASE_MU_COMPONENT_SCHEMA_INVALID")
        static.update(files)
    return static


def capability_owner_snapshot(
    root: Path, proposed_targets: set[str], *,
    repo: Path | None = None, commit: str | None = None,
    preimages: dict[str, str | None] | None = None,
) -> dict:
    """Reconcile functional ownership from target and immutable source profiles.

    A physical MU file is NOT a permanent owner. Likewise, a replacement PHP
    file does not prove that its hooks are active or behavior is equivalent.
    The portability profile describes lineage; a separate independently
    verified owner-transition receipt is required to switch active owners.
    """
    plugins = root / "wp-content" / "plugins"
    profile_inputs: list[tuple[str, str, bytes]] = []
    if plugins.is_dir():
        no_link(plugins)
        target_profiles = sorted(plugins.glob("mad4b-*/mad4b-portability.json"))
        fail_if(len(target_profiles) > 250, "PORTABILITY_PROFILE_COUNT_EXCEEDED")
        for profile in target_profiles:
            no_link(profile)
            profile_inputs.append((
                "target", profile.parent.name,
                safe_regular(profile, max_size=200_000)))
    if repo is not None and commit is not None:
        fail_if(not GIT_SHA.fullmatch(commit), "COMMIT_MUST_BE_FULL_SHA")
        # The source profile is read from the exact Git tree, never from a
        # mutable Tours.dev working tree. Git is an input for *candidate*
        # ownership only; it is not authority to detach tenant legacy hooks.
        names = run(["git", "-C", str(repo), "ls-tree", "-r", "--name-only",
                     commit, "--", "wp-content/plugins"], timeout=35).decode().splitlines()
        source_profiles = sorted(path for path in names if re.fullmatch(
            r"wp-content/plugins/(mad4b-[a-z0-9-]+)/mad4b-portability\.json", path))
        fail_if(len(source_profiles) > 250, "SOURCE_PORTABILITY_PROFILE_COUNT_EXCEEDED")
        for path in source_profiles:
            data = git_bytes(repo, commit, path)
            fail_if(len(data) > 200_000, "SOURCE_PORTABILITY_PROFILE_TOO_LARGE")
            profile_inputs.append(("source", PurePosixPath(path).parent.name, data))
    owners: dict[tuple[str, str], dict] = {}
    for origin, plugin, raw in profile_inputs:
        try:
            meta = json.loads(raw)
        except (TypeError, ValueError) as exc:
            raise Denied("INVALID_PORTABILITY_MANIFEST") from exc
        fail_if(not isinstance(meta, dict) or
                meta.get("contract") != "mad4b.plugin-portability-profile.v1" or
                meta.get("plugin") != plugin,
                "PORTABILITY_PLUGIN_IDENTITY_INVALID")
        migrations = meta.get("company_specific_legacy_mu_replacements", [])
        fail_if(not isinstance(migrations, list) or len(migrations) > 100,
                "PORTABILITY_MIGRATION_SHAPE_INVALID")
        for migration in migrations:
            fail_if(not isinstance(migration, dict),
                    "PORTABILITY_MIGRATION_ENTRY_INVALID")
            legacy = migration.get("legacy_file")
            replacement = migration.get("replacement")
            fail_if(not isinstance(legacy, str) or not isinstance(replacement, str) or
                    not re.fullmatch(r"[a-zA-Z0-9_-]{1,150}\.php", legacy),
                    "PORTABILITY_LEGACY_PATH_INVALID")
            replace_path = exact_relative(replacement)
            fail_if(not replace_path.startswith("src/") or
                    not replace_path.endswith(".php"),
                    "PORTABILITY_REPLACEMENT_PATH_INVALID")
            legacy_target = "wp-content/mu-plugins/" + legacy
            replacement_target = "wp-content/plugins/" + plugin + "/" + replace_path
            key = (legacy_target, replacement_target)
            row = owners.setdefault(key, {
                "component": plugin,
                "migration_contract": str(migration.get("reconcile_contract", "")),
                "legacy_target": legacy_target,
                "replacement_target": replacement_target,
                "source_manifest_sha256": None,
                "target_manifest_sha256": None,
                "source_migration_sha256": None,
                "target_migration_sha256": None,
            })
            marker = origin + "_manifest_sha256"
            fail_if(row["component"] != plugin or row[marker] is not None,
                    "MULTIPLE_CAPABILITY_OWNERS_IN_PORTABILITY")
            fail_if(row["migration_contract"] !=
                    str(migration.get("reconcile_contract", "")),
                    "PORTABILITY_CONTRACT_LINEAGE_CONFLICT")
            row[marker] = digest(raw)
            row[origin + "_migration_sha256"] = digest(canonical(migration))
    # Do not miss a second successor just because *its* file isn't selected.
    # One legacy owner with two canonical claimants is an ambiguous transition
    # even if the request happens to modify only one canonical PHP module.
    by_legacy: dict[str, set[str]] = {}
    by_replacement: dict[str, set[str]] = {}
    for legacy, replacement in owners:
        by_legacy.setdefault(legacy, set()).add(replacement)
        by_replacement.setdefault(replacement, set()).add(legacy)
    # Only an explicitly proposed migration relation is an admission gate;
    # no global hardcoded list of files to preserve is ever materialized.
    rows: list[dict] = []
    selected_legacy: dict[str, str] = {}
    selected_canonical: dict[str, str] = {}
    for (legacy_target, replacement_target), relation in sorted(owners.items()):
        touched = {legacy_target, replacement_target} & proposed_targets
        manifest_target = ("wp-content/plugins/" + relation["component"] +
                           "/mad4b-portability.json")
        profile_selected = manifest_target in proposed_targets
        if not touched and not profile_selected:
            continue
        fail_if(len(by_legacy[legacy_target]) != 1 or
                len(by_replacement[replacement_target]) != 1,
                "PORTABILITY_OWNER_MIGRATION_CONFLICT")
        if legacy_target in selected_legacy and selected_legacy[legacy_target] != replacement_target:
            raise Denied("PORTABILITY_OWNER_MIGRATION_CONFLICT")
        if replacement_target in selected_canonical and selected_canonical[replacement_target] != legacy_target:
            raise Denied("PORTABILITY_OWNER_MIGRATION_CONFLICT")
        selected_legacy[legacy_target] = replacement_target
        selected_canonical[replacement_target] = legacy_target
        legacy_path = root / legacy_target
        new_path = root / replacement_target
        no_link(legacy_path)
        no_link(new_path)
        fail_if((legacy_path.exists() and not legacy_path.is_file()) or
                (new_path.exists() and not new_path.is_file()),
                "OWNER_ARTIFACT_NOT_REGULAR")
        legacy_exists = legacy_path.is_file()
        replacement_exists = new_path.is_file()
        # A signed source release must never resurrect a retired MU owner.
        # Rollback of an existing one is handled by its separate lifecycle.
        fail_if(legacy_target in touched and not legacy_exists and
                (preimages is None or preimages.get(legacy_target) is None),
                "LEGACY_OWNER_REINTRODUCTION_NOT_CERTIFIED")
        if (profile_selected and legacy_exists and
                relation["source_migration_sha256"] !=
                relation["target_migration_sha256"]):
            # A new portability profile can silently reassign a live MU
            # capability just as easily as a PHP file replacement. Require
            # the original independent owner-transition certification.
            raise Denied("OWNER_PROFILE_MIGRATION_REQUIRES_PROOF")
        if touched == {legacy_target, replacement_target}:
            raise Denied("DUAL_OWNER_MIGRATION_NOT_CERTIFIED")
        if replacement_target in touched and legacy_exists:
            raise Denied("OWNER_TRANSITION_RUNTIME_PROOF_REQUIRED")
        rows.append({**relation,
            # The selected profile file itself is checked by source Git and
            # exact CAS. Do not pin its mutable *old* digest across Apply.
            "target_manifest_sha256": (None if profile_selected else
                                       relation["target_manifest_sha256"]),
            "target_migration_sha256": (None if profile_selected else
                                        relation["target_migration_sha256"]),
            "manifest_target_selected": profile_selected,
            # Selected file postimages may be new, but its pinned CAS preimage
            # remains the same evidence at plan, apply and final readback.
            "legacy_present": (preimages.get(legacy_target) is not None
                               if preimages is not None and legacy_target in touched
                               else legacy_exists),
            "replacement_present": (preimages.get(replacement_target) is not None
                                    if preimages is not None and replacement_target in touched
                                    else replacement_exists),
            "legacy_other_sha256": (digest(safe_regular(legacy_path))
                if legacy_exists and legacy_target not in proposed_targets else None),
            "replacement_other_sha256": (digest(safe_regular(new_path))
                if replacement_exists and replacement_target not in proposed_targets else None),
            "selected_role": ("legacy" if legacy_target in touched else
                              "canonical" if replacement_target in touched else
                              "profile"),
            "runtime_owner_inferred": False,
            "retirement_authorized": False,
        })
    return {"contract": "mad4b.company-capability-owner-snapshot.v1", "relations": rows}


# Narrow, fail-closed recognition of literal __DIR__ PHP includes. Dynamic
# require expressions are explicitly outside this partial static guarantee;
# WordPress boot validation still runs after signed apply.
LITERAL_DIR_INCLUDE = re.compile(
    r"(?m)^\s*(?:require|require_once|include|include_once)\s*"
    r"\(?\s*__DIR__\s*\.\s*(['\"])([^'\"]+)\1\s*\)?\s*;"
)


def php_literal_dependency_snapshot(
    root: Path, repo: Path, commit: str, rows: list[dict],
) -> dict:
    """Pin non-release literal PHP includes and reject absent cross-file deps.

    Source code is read from Git but the resolution of __DIR__ uses the
    target tenant's path (source and target layouts need not be identical).
    """
    selected = {row["target"] for row in rows}
    dependencies: dict[str, dict] = {}
    for row in rows:
        if not row["target"].endswith(".php"):
            continue
        src = git_bytes(repo, commit, row["source"])
        # Only ASCII PHP include statements are parsed; ignoring other bytes
        # avoids treating an untrusted source blob as an executable program.
        source_text = src.decode("utf-8", errors="replace")
        for match in LITERAL_DIR_INCLUDE.finditer(source_text):
            fragment = match.group(2)
            fail_if("$" in fragment or "\\" in fragment or "\x00" in fragment,
                    "PHP_STATIC_INCLUDE_UNSAFE")
            path = posixpath.normpath(posixpath.join(
                posixpath.dirname(row["target"]), fragment.lstrip("/")))
            fail_if(not path.startswith("wp-content/") or
                    not path.endswith(".php") or not permitted_target(path),
                    "PHP_STATIC_INCLUDE_OUTSIDE_DEPLOY_SCOPE")
            if path in selected:
                # The included file is itself SHA-pinned by this same plan.
                continue
            dependency = root / path
            fail_if(not dependency.is_file(), "PHP_STATIC_INCLUDE_MISSING:" + path)
            dep_digest = digest(safe_regular(dependency))
            dependencies[path] = {"sha256": dep_digest}
    return {"contract": "mad4b.release-literal-php-dependencies.v1",
            "dependencies": [{"path": name, **data}
                             for name, data in sorted(dependencies.items())]}


def tenant_plugin_profile_snapshot(root: Path, rows: list[dict]) -> dict:
    """Freeze tenant plugin manifest provenance without importing demo values.

    Plugin code ownership and runtime activation are separate. A target profile
    is a company-owned input: any concurrent change invalidates the plan, but
    absence of a profile does not fabricate an active plugin installation.
    """
    selected = {row["target"]: row["expected_before_sha256"] for row in rows}
    plugins = sorted({parts[2] for row in rows
                      if (parts := PurePosixPath(row["target"]).parts)
                      and len(parts) >= 4 and parts[:2] == ("wp-content", "plugins")
                      and parts[2].startswith("mad4b-")})
    records=[]
    for plugin in plugins:
        rel="wp-content/plugins/" + plugin + "/mad4b-portability.json"
        path=root/rel
        no_link(path)
        present=path.is_file()
        fail_if(path.exists() and not present,"TENANT_PORTABILITY_NOT_REGULAR")
        features=[]
        if present:
            raw=safe_regular(path,max_size=200_000)
            try:
                obj=json.loads(raw)
            except (ValueError,TypeError) as exc:
                raise Denied("TENANT_PORTABILITY_INVALID") from exc
            fail_if(not isinstance(obj,dict) or
                    obj.get("contract")!="mad4b.plugin-portability-profile.v1" or
                    obj.get("plugin")!=plugin or
                    obj.get("scope") not in (None,"tenant-portable") or
                    not isinstance(obj.get("features",[]),list),
                    "TENANT_PORTABILITY_IDENTITY_MISMATCH")
            # Older tenant profiles may predate the optional feature inventory.
            # Preserve their existing functions without pretending the newer
            # source's feature list has already been adopted at this site.
            features=sorted(set(str(f) for f in obj.get("features",[])))
        records.append({"plugin":plugin,"profile_target":rel,
                        "profile_pinned_sha256": (selected[rel] if rel in selected
                                                 else digest(raw) if present else None),
                        "profile_present_preimage": (selected[rel] is not None
                            if rel in selected else present),
                        "features_preimage": ([] if rel in selected else features),
                        "runtime_activation_inferred":False})
    return {"contract":"mad4b.tenant-plugin-profile-snapshot.v1",
            "profiles":records}


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
        baseline = dest.stat() if old is not None else None
        rows.append({
            "source": source, "target": target,
            "source_sha256": digest(src),
            "expected_before_sha256": before,
            # Preserve tenant-owned existing file metadata, including
            # Wayfatours' www-data ownership and group-writable mode.
            "expected_before_mode": (stat.S_IMODE(baseline.st_mode)
                                     if baseline is not None else None),
            "expected_before_uid": baseline.st_uid if baseline is not None else None,
            "expected_before_gid": baseline.st_gid if baseline is not None else None,
            "source_bytes": len(src),
            "source_mode": 0o644,  # executable scripts do not run as part of release
        })
    owner_snapshot = capability_owner_snapshot(
        root, seen, repo=repo, commit=spec["git_commit"],
        preimages={row["target"]: row["expected_before_sha256"] for row in rows})
    dependency_snapshot = php_literal_dependency_snapshot(
        root, repo, spec["git_commit"], rows)
    plugin_profiles = tenant_plugin_profile_snapshot(root, rows)
    dynamic_acceptance = capability_checks.snapshot(
        rows, policy, repo, spec["git_commit"], git_bytes, permitted_target)
    payload = {
        "capability_acceptance": dynamic_acceptance,
        "tenant_plugin_profiles": plugin_profiles,
        "dependency_snapshot": dependency_snapshot,
        "owner_reconciliation": owner_snapshot,
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
    if "capability_acceptance" in plan:
        expected_acceptance = capability_checks.snapshot(
            plan["files"], policy, Path(plan["git_root"]),
            plan["git_commit"], git_bytes, permitted_target)
        fail_if(plan["capability_acceptance"] != expected_acceptance,
                "CAPABILITY_ACCEPTANCE_REGISTRY_DRIFT")
    else:
        # Never allow a legacy plan to bypass newly enrolled acceptance.
        fail_if(policy.get("capability_acceptance") is not None,
                "CAPABILITY_ACCEPTANCE_SNAPSHOT_REQUIRED")
    catalog_at_plan = plan.get("release_catalog")
    # Plans signed before catalog enrollment remain limited to the explicit
    # original static paths. Never retroactively elevate a legacy plan.
    if catalog_at_plan is None:
        allowlist = set(entry["allowed_targets"])
    else:
        fail_if(catalog_at_plan != entry.get("release_catalog"),
                "RELEASE_CATALOG_BINDING_DRIFT")
        allowlist = registered_release_targets(policy, entry, plan["git_commit"])
    # Re-evaluate the current tenant/source functional ownership immediately
    # before execution, independently of the registered target allowlist.
    owners_now = capability_owner_snapshot(
        Path(plan["target_root"]), {row["target"] for row in plan["files"]},
        repo=Path(plan["git_root"]), commit=plan["git_commit"],
        preimages={row["target"]: row["expected_before_sha256"]
                   for row in plan["files"]})
    fail_if(plan.get("owner_reconciliation") != owners_now,
            "CAPABILITY_OWNER_STATE_DRIFT")
    dependencies_now = php_literal_dependency_snapshot(
        Path(plan["target_root"]), Path(plan["git_root"]),
        plan["git_commit"], plan["files"])
    fail_if(plan.get("dependency_snapshot") != dependencies_now,
            "PHP_LITERAL_DEPENDENCY_STATE_DRIFT")
    target_profiles_now = tenant_plugin_profile_snapshot(
        Path(plan["target_root"]), plan["files"])
    fail_if(plan.get("tenant_plugin_profiles") != target_profiles_now,
            "TENANT_PLUGIN_PROFILE_STATE_DRIFT")
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
        if expect_preimage is None:
            # Only a separately signed rollback may inspect a mixed state
            # recorded by a durable interrupted-apply intent.
            valid = actual in (row["expected_before_sha256"],
                               row["source_sha256"])
        else:
            expected = (row["expected_before_sha256"] if expect_preimage
                        else row["source_sha256"])
            valid = actual == expected
        fail_if(not valid, "TARGET_CONTENT_DRIFT:" + target)
        if row.get("expected_before_mode") is not None and target_file.is_file():
            metadata = target_file.stat()
            fail_if((stat.S_IMODE(metadata.st_mode), metadata.st_uid,
                     metadata.st_gid) !=
                    (row["expected_before_mode"], row["expected_before_uid"],
                     row["expected_before_gid"]),
                    "TARGET_METADATA_DRIFT:" + target)


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


def fsync_parent_directory(path: Path) -> None:
    """Make renames and newly created/deleted directory entries durable."""
    dir_fd = os.open(str(path.parent), os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(dir_fd)
    finally:
        os.close(dir_fd)


def atomic_write(path: Path, data: bytes, mode: int = 0o644,
                 *, preserve_existing_mode: bool = False) -> None:
    """Atomic replacement without silently changing tenant file ownership.

    Existing company-owned plugin files often belong to www-data, while the
    enrolled Host executor runs as root. A root-created temp file replacing
    one of those paths must inherit the original UID/GID. For source upgrades,
    the signed original mode is also preserved; backup restores explicitly
    pass their stored original mode. New files still use release policy mode.
    """
    no_link(path)
    fail_if(not path.parent.is_dir(), "TARGET_PARENT_NOT_PRESENT")
    fail_if(path.exists() and not path.is_file(), "NON_REGULAR_ATOMIC_TARGET")
    previous = path.stat() if path.is_file() else None
    desired_mode = (stat.S_IMODE(previous.st_mode)
                    if preserve_existing_mode and previous is not None else mode)
    fd, name = tempfile.mkstemp(prefix=".mad4b-code-release-", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as stream:
            if previous is not None:
                temporary = os.fstat(stream.fileno())
                if (temporary.st_uid != previous.st_uid or
                        temporary.st_gid != previous.st_gid):
                    os.fchown(stream.fileno(), previous.st_uid, previous.st_gid)
            os.fchmod(stream.fileno(), desired_mode)
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, path)
        fsync_parent_directory(path)
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


def preflight_candidate_php(plan: dict) -> None:
    """Lint exact immutable Git PHP bytes inside the bound PHP container.

    This runs *before any application write*. No temporary target file is
    created. WP bootstrap after apply is still mandatory, because lint does
    not prove include order, runtime dependencies or functional parity.
    """
    for row in plan["files"]:
        if not row["target"].endswith(".php"):
            continue
        data = git_bytes(Path(plan["git_root"]), plan["git_commit"], row["source"])
        fail_if(digest(data) != row["source_sha256"] or
                len(data) != row["source_bytes"],
                "CANDIDATE_SOURCE_DRIFT_BEFORE_LINT")
        result = subprocess.run(
            ["docker", "exec", "-i", plan["container"], "php", "-l"],
            input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=30, check=False,
        )
        fail_if(result.returncode != 0 or
                b"No syntax errors detected" not in result.stdout,
                "CANDIDATE_PHP_SYNTAX_NOT_PROVEN:" + row["target"])


def assert_target_preimage(root: Path, row: dict) -> None:
    """Recheck CAS immediately before each file write under the release lock.

    Original plan-level CAS alone cannot fence an independent writer between
    the first and second file of a multi-file release.
    """
    path = root / row["target"]
    no_link(path)
    fail_if(path.exists() and not path.is_file(), "JIT_TARGET_NOT_REGULAR")
    actual = digest(safe_regular(path)) if path.is_file() else None
    fail_if(actual != row["expected_before_sha256"],
            "TARGET_JIT_PREIMAGE_DRIFT:" + row["target"])
    if row.get("expected_before_mode") is not None and path.is_file():
        metadata = path.stat()
        fail_if((stat.S_IMODE(metadata.st_mode), metadata.st_uid,
                 metadata.st_gid) !=
                (row["expected_before_mode"], row["expected_before_uid"],
                 row["expected_before_gid"]),
                "TARGET_JIT_METADATA_DRIFT:" + row["target"])


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


def _verify_receipt_directory_owner(policy: dict, receipt_dir: Path,
                                    *, required_uid: int) -> None:
    """One invariant for both real root admission and hermetic CI fixtures."""
    bound = policy.get("receipt_dir")
    fail_if(not isinstance(bound, str) or not bound.startswith("/"),
            "RECEIPT_ROOT_NOT_CONFIGURED")
    exact = Path(bound)
    no_link(exact)
    no_link(receipt_dir)
    fail_if(receipt_dir.resolve() != exact.resolve(),
            "RECEIPT_DIRECTORY_NOT_PINNED")
    fail_if(not receipt_dir.is_dir() or
            receipt_dir.stat().st_uid != required_uid or
            stat.S_IMODE(receipt_dir.stat().st_mode) & 0o077,
            "RECEIPTS_MUST_BE_ROOT_PRIVATE")


def assert_pinned_receipt_directory(policy: dict, receipt_dir: Path) -> None:
    """Live executor always requires the original root-owned private ledger."""
    _verify_receipt_directory_owner(policy, receipt_dir, required_uid=0)


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
        no_link(path)
        current = digest(safe_regular(path)) if path.is_file() else None
        fail_if(current not in (row["source_sha256"],
                                row["expected_before_sha256"]),
                "ROLLBACK_JIT_CONCURRENT_DRIFT:" + row["target"])
        if path.is_file():
            current_meta = path.stat()
            expected_mode = (row["expected_before_mode"]
                             if row["expected_before_mode"] is not None
                             else row["source_mode"])
            fail_if(stat.S_IMODE(current_meta.st_mode) != expected_mode,
                    "ROLLBACK_JIT_MODE_DRIFT:" + row["target"])
            if row["expected_before_uid"] is not None:
                fail_if((current_meta.st_uid, current_meta.st_gid) !=
                        (row["expected_before_uid"], row["expected_before_gid"]),
                        "ROLLBACK_JIT_OWNER_DRIFT:" + row["target"])
        content = (base64.b64decode(backup_item["bytes_b64"], validate=True)
                   if backup_item["bytes_b64"] is not None else None)
        if content is None:
            if path.is_file():
                path.unlink()
                fsync_parent_directory(path)
        else:
            atomic_write(path, content, backup_item["mode"])

def apply_intent_path(receipt_dir: Path, nonce: str) -> Path:
    fail_if(not NONCE.fullmatch(nonce), "INVALID_APPLY_NONCE")
    return receipt_dir / (nonce + ".intent.json")


def open_apply_intents(plan: dict, receipt_dir: Path) -> list[dict]:
    """Read a root-private transaction journal without quadratic receipt scans.

    A terminal failure marked ROLLBACK_UNVERIFIED remains unresolved until a
    separately signed rollback succeeds. Existing successful/recovered
    receipts are never recycled as authorization for a new apply.
    """
    possible: list[dict] = []
    for path in sorted(receipt_dir.glob("*.intent.json")):
        try:
            item = json.loads(safe_regular(path, max_size=100_000))
        except (TypeError, ValueError) as exc:
            raise Denied("CORRUPT_APPLY_INTENT") from exc
        if item.get("plan_sha256") != plan["plan_sha256"]:
            continue
        fail_if(item.get("contract") != "mad4b.company-code-release.intent.v1" or
                item.get("state") != "APPLY_STARTED" or
                item.get("company") != plan["company"] or
                item.get("site_uuid") != plan["site_uuid"] or
                item.get("environment") != plan["environment"] or
                item.get("files") != [row["target"] for row in plan["files"]] or
                not isinstance(item.get("nonce"), str) or
                path != apply_intent_path(receipt_dir, item["nonce"]),
                "APPLY_INTENT_IDENTITY_DRIFT")
        terminal = receipt_dir / (item["nonce"] + ".json")
        if terminal.exists():
            status = json.loads(safe_regular(terminal, max_size=100_000))
            fail_if(status.get("contract") != RECEIPT_CONTRACT or
                    status.get("plan_sha256") != plan["plan_sha256"] or
                    status.get("nonce") != item["nonce"],
                    "APPLY_TERMINAL_RECEIPT_DRIFT")
            if status.get("state") != "ROLLBACK_UNVERIFIED":
                continue
        possible.append(item)
    if not possible:
        return []
    # One pass over receipts for all open intents, rather than one complete
    # directory scan per intent. No source code/secret material is logged.
    recovered = set()
    for receipt_file in receipt_dir.glob("*.json"):
        if receipt_file.name.endswith((".backup.json", ".intent.json")):
            continue
        report = json.loads(safe_regular(receipt_file, max_size=100_000))
        if (report.get("contract") == RECEIPT_CONTRACT and
                report.get("state") == "RECOVERY_ROLLBACK_VERIFIED" and
                report.get("plan_sha256") == plan["plan_sha256"]):
            recovered.add(report.get("recovered_apply_nonce"))
    return [item for item in possible if item["nonce"] not in recovered]


def rollback_intent_path(receipt_dir: Path, nonce: str) -> Path:
    fail_if(not NONCE.fullmatch(nonce), "INVALID_ROLLBACK_NONCE")
    return receipt_dir / (nonce + ".rollback-intent.json")


def open_rollback_intents(plan: dict, receipt_dir: Path) -> list[dict]:
    """Bound, root-private recovery evidence for interrupted signed rollback."""
    possible=[]
    for path in sorted(receipt_dir.glob("*.rollback-intent.json")):
        try:
            item=json.loads(safe_regular(path,max_size=100_000))
        except (TypeError,ValueError) as exc:
            raise Denied("CORRUPT_ROLLBACK_INTENT") from exc
        if item.get("plan_sha256") != plan["plan_sha256"]:
            continue
        fail_if(item.get("contract") != "mad4b.company-code-release.rollback-intent.v1" or
                item.get("state") != "ROLLBACK_STARTED" or
                item.get("company") != plan["company"] or
                item.get("site_uuid") != plan["site_uuid"] or
                item.get("environment") != plan["environment"] or
                item.get("files") != [row["target"] for row in plan["files"]] or
                not isinstance(item.get("nonce"),str) or
                path != rollback_intent_path(receipt_dir,item["nonce"]),
                "ROLLBACK_INTENT_IDENTITY_DRIFT")
        terminal=receipt_dir/(item["nonce"]+".json")
        if terminal.exists():
            record=json.loads(safe_regular(terminal,max_size=100_000))
            fail_if(record.get("contract")!=RECEIPT_CONTRACT or
                    record.get("nonce")!=item["nonce"] or
                    record.get("plan_sha256")!=plan["plan_sha256"],
                    "ROLLBACK_TERMINAL_RECEIPT_DRIFT")
            if record.get("state") in ("ROLLBACK_VERIFIED",
                                       "RECOVERY_ROLLBACK_VERIFIED"):
                continue
        possible.append(item)
    if not possible:
        return []
    completed=set()
    for receipt_file in receipt_dir.glob("*.json"):
        if receipt_file.name.endswith((".backup.json", ".intent.json")):
            continue
        record=json.loads(safe_regular(receipt_file,max_size=100_000))
        if (record.get("contract")==RECEIPT_CONTRACT and
                record.get("plan_sha256")==plan["plan_sha256"] and
                record.get("state") in ("ROLLBACK_VERIFIED",
                                        "RECOVERY_ROLLBACK_VERIFIED")):
            for nonce in record.get("recovered_rollback_nonces",[]):
                completed.add(nonce)
    open_entries=[item for item in possible if item["nonce"] not in completed]
    fail_if(len(open_entries)>64,"EXCESS_OPEN_ROLLBACK_INTENTS")
    return open_entries


def overlapping_unresolved_transactions(plan: dict, receipt_dir: Path) -> list[dict]:
    """Stop a new release from overwriting an older interrupted release.

    Unfinished Apply and Rollback journals are indexed across *all* plan
    hashes for the same registered company/site; comparing only the current
    plan allows a different signed plan to replace partially written code.
    Unrelated tenants and genuinely disjoint file sets remain independent.
    This read-only journal guard runs under the original receipt-root flock.
    """
    selected = {row["target"] for row in plan["files"]}
    receipts: dict[tuple[str, str], dict] = {}
    for path in receipt_dir.glob("*.json"):
        if path.name.endswith((".backup.json", ".intent.json",
                                ".rollback-intent.json")):
            continue
        record = json.loads(safe_regular(path, max_size=100_000))
        nonce = record.get("nonce")
        plan_sha = record.get("plan_sha256")
        fail_if(record.get("contract") != RECEIPT_CONTRACT or
                not isinstance(nonce, str) or not NONCE.fullmatch(nonce) or
                not isinstance(plan_sha, str) or not HEX_SHA.fullmatch(plan_sha) or
                path.name != nonce + ".json",
                "RELEASE_JOURNAL_TERMINAL_IDENTITY_INVALID")
        receipts[(plan_sha, nonce)] = record
    completed_apply: set[tuple[str, str]] = set()
    completed_rollback: set[tuple[str, str]] = set()
    for (plan_sha, _), record in receipts.items():
        if record.get("state") == "RECOVERY_ROLLBACK_VERIFIED":
            nonce = record.get("recovered_apply_nonce")
            if isinstance(nonce, str):
                completed_apply.add((plan_sha, nonce))
        if record.get("state") in ("ROLLBACK_VERIFIED",
                                    "RECOVERY_ROLLBACK_VERIFIED"):
            for nonce in record.get("recovered_rollback_nonces", []):
                if isinstance(nonce, str):
                    completed_rollback.add((plan_sha, nonce))
    conflicts = []
    for kind, suffix, contract, start_state in (
        ("apply", ".intent.json", "mad4b.company-code-release.intent.v1", "APPLY_STARTED"),
        ("rollback", ".rollback-intent.json",
         "mad4b.company-code-release.rollback-intent.v1", "ROLLBACK_STARTED"),
    ):
        for path in sorted(receipt_dir.glob("*" + suffix)):
            try:
                item = json.loads(safe_regular(path, max_size=100_000))
            except (TypeError, ValueError) as exc:
                raise Denied("RELEASE_JOURNAL_INTENT_CORRUPT") from exc
            nonce = item.get("nonce")
            sha = item.get("plan_sha256")
            files = item.get("files")
            fail_if(item.get("contract") != contract or
                    item.get("state") != start_state or
                    not isinstance(nonce, str) or not NONCE.fullmatch(nonce) or
                    not isinstance(sha, str) or not HEX_SHA.fullmatch(sha) or
                    path.name != nonce + suffix or
                    not isinstance(files, list) or
                    not 1 <= len(files) <= 24 or
                    len(files) != len(set(files)) or
                    any(not isinstance(f, str) or exact_relative(f) != f
                        for f in files),
                    "RELEASE_JOURNAL_INTENT_INVALID")
            key = (sha, nonce)
            terminal = receipts.get(key)
            if terminal is not None:
                fail_if(terminal.get("company") != item.get("company") or
                        terminal.get("environment") != item.get("environment") or
                        terminal.get("site_uuid") != item.get("site_uuid"),
                        "RELEASE_JOURNAL_TERMINAL_SCOPE_DRIFT")
                if kind == "apply" and terminal.get("state") != "ROLLBACK_UNVERIFIED":
                    continue
                if kind == "rollback" and terminal.get("state") in (
                        "ROLLBACK_VERIFIED", "RECOVERY_ROLLBACK_VERIFIED"):
                    continue
            if kind == "apply" and key in completed_apply:
                continue
            if kind == "rollback" and key in completed_rollback:
                continue
            # The native site UUID is the enrolled runtime identity.
            # Company/environment labels may be aliases for the same site;
            # never let an alias override a pending overlapping transaction.
            same_site = item.get("site_uuid") == plan["site_uuid"]
            shared = sorted(selected.intersection(files)) if same_site else []
            if shared:
                conflicts.append({"operation": kind, "plan_sha256": sha,
                                  "overlap": shared})
    return conflicts


def apply_release(plan: dict, policy: dict, grant: dict, receipt_dir: Path,
                  *, runtime_check: Callable[[dict, dict], None] = assert_runtime_binding,
                  validate_code: Callable[[dict], None] = php_lint,
                  preflight_code: Callable[[dict], None] = preflight_candidate_php,
                  validate_capabilities: Callable[[dict, dict, Path, str, str], dict] =
                      capability_checks.verify_evidence) -> dict:
    """Apply only after external authority. Roll back automatically on any failure."""
    verify_grant(plan, policy, grant, "apply")
    assert_pinned_receipt_directory(policy, receipt_dir)
    lock = receipt_dir / ".company-code-release.lock"
    with open(lock, "a+") as lockfile:
        fcntl.flock(lockfile, fcntl.LOCK_EX | fcntl.LOCK_NB)
        nonce_path = receipt_dir / (grant["nonce"] + ".json")
        intent_path = apply_intent_path(receipt_dir, grant["nonce"])
        fail_if(nonce_path.exists() or intent_path.exists(),
                "GRANT_ALREADY_USED")
        fail_if(bool(open_apply_intents(plan, receipt_dir)),
                "UNRESOLVED_APPLY_INTENT")
        fail_if(bool(open_rollback_intents(plan, receipt_dir)),
                "UNRESOLVED_ROLLBACK_INTENT")
        fail_if(bool(overlapping_unresolved_transactions(plan, receipt_dir)),
                "OVERLAPPING_UNRESOLVED_RELEASE_TRANSACTION")
        confirm_plan(plan, policy, expect_preimage=True)
        runtime_check(plan, policy)
        prepared = prepare_backup(plan, policy, receipt_dir,
                                  runtime_check=runtime_check)
        backup = load_backup(plan, receipt_dir)
        # A syntax error must never reach the first production target write.
        # This uses the tenant-bound PHP interpreter rather than host PHP.
        preflight_code(plan)
        # Durable write-ahead evidence exists *before* the first file change.
        # An interrupted process cannot be confused with a clean Apply.
        intent = {
            "contract": "mad4b.company-code-release.intent.v1",
            "state": "APPLY_STARTED", "plan_sha256": plan["plan_sha256"],
            "company": plan["company"], "environment": plan["environment"],
            "site_uuid": plan["site_uuid"], "nonce": grant["nonce"],
            "backup_sha256": prepared["backup_sha256"],
            "files": [row["target"] for row in plan["files"]],
            "at": dt.datetime.now(dt.timezone.utc).isoformat(),
        }
        atomic_write(intent_path, canonical(intent) + b"\n", 0o600)
        root = Path(plan["target_root"])
        changed = []
        state, failure = "APPLIED_VERIFIED", None
        try:
            for row in plan["files"]:
                data = git_bytes(Path(plan["git_root"]), plan["git_commit"], row["source"])
                path = root / row["target"]
                assert_target_preimage(root, row)
                # Record the write attempt *before* atomic replace. If the
                # rename succeeded but parent fsync raised, the file may
                # already contain the new bytes even though atomic_write did
                # not return. Recovery must inspect and restore that state;
                # never claim ABORTED_NO_MUTATION after an ambiguous rename.
                changed.append(row["target"])
                atomic_write(path, data, row["source_mode"],
                             preserve_existing_mode=True)
            confirm_plan(plan, policy, expect_preimage=False)
            validate_code(plan)
            # Do not issue a successful Host Apply receipt when mandatory
            # first-party capability evidence has not been independently signed.
            # This is after the write-ahead intent and before terminal success;
            # a missing/revoked/invalid proof causes the same CAS-protected
            # automatic Rollback as any other failed runtime acceptance.
            if plan.get("capability_acceptance", {}).get("checks"):
                validate_capabilities(
                    plan, policy, receipt_dir, grant["nonce"], intent["at"])
        except Exception as exc:
            # If the first target drifted before any of our writes, there is
            # nothing to roll back. Record the denial, not a false success.
            if not changed:
                state = "ABORTED_NO_MUTATION"
                failure = type(exc).__name__ + ":" + str(exc)[:160]
            else:
                state = "ROLLBACK_UNVERIFIED"
            # A backup's existence is not proof of a successful restore.
            # Concurrent edits can make rollback unsafe, so never overwrite
            # them and never claim ROLLED_BACK without exact CAS readback.
            if changed:
                failure = type(exc).__name__ + ":" + str(exc)[:160]
                try:
                    restore_backup(plan, backup)
                    confirm_plan(plan, policy, expect_preimage=True)
                    state = "ROLLED_BACK"
                except Exception as rollback_exc:
                    state = "ROLLBACK_UNVERIFIED"
                    failure += ";rollback=" + type(rollback_exc).__name__ + ":" + str(rollback_exc)[:120]
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
        intent_path = rollback_intent_path(receipt_dir, grant["nonce"])
        fail_if(nonce_path.exists() or intent_path.exists(), "GRANT_ALREADY_USED")
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
        pending = open_apply_intents(plan, receipt_dir)
        interrupted_rollbacks = open_rollback_intents(plan, receipt_dir)
        fail_if(not found_previous and not pending and not interrupted_rollbacks,
                "NO_VERIFIED_APPLY_TO_ROLLBACK")
        fail_if(len(pending) > 1, "MULTIPLE_OPEN_APPLY_INTENTS")
        if not pending and not interrupted_rollbacks:
            confirm_plan(plan, policy, expect_preimage=False)
        else:
            # The original Apply ended without a terminal success receipt.
            # only exact original/prepared or exact signed postimage bytes.
            # A foreign concurrent byte is never overwritten.
            confirm_plan(plan, policy, expect_preimage=None)
        runtime_check(plan, policy)
        backup = load_backup(plan, receipt_dir)
        if pending:
            blob = safe_regular(backup_path(receipt_dir, plan),
                                max_size=100_000_000)
            fail_if(pending[0].get("backup_sha256") != digest(blob),
                    "APPLY_INTENT_BACKUP_CHANGED")
        # The signed rollback itself can be interrupted while restoring a
        # multi-file release. Persist a private intent before modifying any
        # target so a *fresh separately signed rollback* can resume it.
        intent={
            "contract":"mad4b.company-code-release.rollback-intent.v1",
            "state":"ROLLBACK_STARTED","plan_sha256":plan["plan_sha256"],
            "company":plan["company"],"environment":plan["environment"],
            "site_uuid":plan["site_uuid"],"nonce":grant["nonce"],
            "backup_sha256":digest(safe_regular(
                backup_path(receipt_dir,plan),max_size=100_000_000)),
            "files":[row["target"] for row in plan["files"]],
            "at":dt.datetime.now(dt.timezone.utc).isoformat(),
        }
        for earlier in interrupted_rollbacks:
            fail_if(earlier.get("backup_sha256") != intent["backup_sha256"],
                    "ROLLBACK_INTENT_BACKUP_CHANGED")
        atomic_write(intent_path,canonical(intent)+b"\n",0o600)
        restore_backup(plan, backup)
        confirm_plan(plan, policy, expect_preimage=True)
        result = {"contract": RECEIPT_CONTRACT,
                  "state": ("RECOVERY_ROLLBACK_VERIFIED" if pending
                            else "ROLLBACK_VERIFIED"),
                  "recovered_apply_nonce": (pending[0]["nonce"] if pending else None),
                  "recovered_rollback_nonces": [x["nonce"] for x in interrupted_rollbacks],
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
