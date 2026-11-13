#!/usr/bin/env python3
"""Read-only provenance validation for MAD4B company-specific active PHP owners.

This is not the 305/312/313 release executor. No writes, grants or activation.
"""
from __future__ import annotations
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
from typing import Mapping

REGISTRY = "ops/company-code-release-v1/site-owner-sources/registry.json"
SOURCE_PREFIX = "ops/company-code-release-v1/site-owner-sources/files/"
CONTRACT = "mad4b.site-owner-source-registry.v1"
CAPABILITY = re.compile(r"^[a-z][a-z0-9_]{2,100}$")
SYMBOL = re.compile(r"^[a-zA-Z_][a-zA-Z0-9_]*$")
SITE = re.compile(r"^[a-zA-Z0-9][a-zA-Z0-9._-]{1,100}$")
ENVIRONMENT = re.compile(r"^[a-z][a-z0-9_-]{1,100}$")
DIGEST = re.compile(r"^[a-f0-9]{64}$")


class InvalidProvenance(ValueError):
    pass


def require(ok: bool, label: str) -> None:
    if not ok:
        raise InvalidProvenance(label)


def clean_relative(value: object, *, prefix: str) -> str:
    require(isinstance(value, str) and len(value) <= 240, "INVALID_RELATIVE_PATH")
    value = str(value)
    p = PurePosixPath(value)
    require(
        value.startswith(prefix) and not p.is_absolute()
        and p.as_posix() == value
        and ".." not in p.parts and "." not in p.parts
        and "\\" not in value and "//" not in value and "\x00" not in value,
        "UNSAFE_OR_UNSCOPED_PATH"
    )
    require(p.suffix == ".php", "NON_PHP_SOURCE_PATH")
    return value


def safe_bytes(root: Path, relative: str) -> bytes:
    p = root
    for part in PurePosixPath(relative).parts:
        p = p / part
        require(not p.is_symlink(), "SYMLINK_DENIED")
    require(p.is_file(), "MISSING_REGULAR_FILE")
    require(p.stat().st_size <= 2_000_000, "SOURCE_TOO_LARGE")
    return p.read_bytes()


def unique_object_pairs(pairs: list[tuple[str, object]]) -> dict:
    result = {}
    for key, value in pairs:
        require(key not in result, "DUPLICATE_JSON_FIELD")
        result[key] = value
    return result


def validate(repo: Path, manifest: Path, runtimes: Mapping[str, Path] | None = None) -> dict:
    require(not manifest.is_symlink(), "SYMLINKED_MANIFEST_DENIED")
    repo = repo.resolve(strict=True)
    manifest = manifest.resolve(strict=True)
    require(manifest.is_relative_to(repo) and manifest.relative_to(repo).as_posix() == REGISTRY,
            "REGISTRY_NOT_CANONICAL")
    record = json.loads(manifest.read_text(encoding="utf-8"), object_pairs_hook=unique_object_pairs)
    require(isinstance(record, dict) and set(record) == {
        "contract", "release_boundary", "capabilities"}, "REGISTRY_SHAPE_INVALID")
    require(record["contract"] == CONTRACT, "REGISTRY_CONTRACT_INVALID")
    boundary = record["release_boundary"]
    require(isinstance(boundary, dict) and boundary == {
        "deployable": False, "auto_apply": False, "ownership_transfer": False,
        "requires": "separately_authorized_company_code_release_and_independent_readback",
    }, "RELEASE_FENCE_MUST_REMAIN_SOURCE_ONLY")
    caps = record["capabilities"]
    require(isinstance(caps, list) and 1 <= len(caps) <= 250,
            "INVALID_COMPONENT_COUNT")
    runtimes = dict(runtimes or {})
    for site, root in runtimes.items():
        require(isinstance(site, str) and SITE.fullmatch(site) is not None, "INVALID_SITE_ALIAS")
        require(root.is_dir(), "RUNTIME_ROOT_MISSING")
    seen_capabilities: set[str] = set()
    seen_site_capabilities: set[tuple[str, str]] = set()
    seen_site_symbols: set[tuple[str, str]] = set()
    found_sites: set[str] = set()
    variants = 0
    checked_runtime = 0
    for row in caps:
        require(isinstance(row, dict) and set(row) == {
            "capability", "runtime_symbol", "variants"}, "CAPABILITY_SHAPE_INVALID")
        cap, symbol = row["capability"], row["runtime_symbol"]
        require(isinstance(cap, str) and CAPABILITY.fullmatch(cap) is not None,
                "CAPABILITY_ID_INVALID")
        require(cap not in seen_capabilities, "DUPLICATE_CAPABILITY")
        seen_capabilities.add(cap)
        require(isinstance(symbol, str) and SYMBOL.fullmatch(symbol) is not None,
                "RUNTIME_SYMBOL_INVALID")
        entries = row["variants"]
        require(isinstance(entries, list) and 1 <= len(entries) <= 250,
                "VARIANT_COUNT_INVALID")
        for item in entries:
            require(isinstance(item, dict) and set(item) == {
                "site", "environment", "runtime_path", "source_artifact",
                "sha256", "owner_kind"}, "VARIANT_SHAPE_INVALID")
            site = item["site"]
            require(isinstance(site, str) and SITE.fullmatch(site) is not None,
                    "INVALID_SITE_ALIAS")
            require(isinstance(item["environment"], str)
                    and ENVIRONMENT.fullmatch(item["environment"]) is not None,
                    "INVALID_ENVIRONMENT")
            kind = item["owner_kind"]
            require(kind in {"mu-plugin", "native-plugin"}, "OWNER_KIND_INVALID")
            target_prefix = ("wp-content/mu-plugins/" if kind == "mu-plugin"
                             else "wp-content/plugins/")
            relative = clean_relative(item["runtime_path"], prefix=target_prefix)
            if kind == "native-plugin":
                require(len(PurePosixPath(relative).parts) >= 4,
                        "PLUGIN_FILE_SCOPE_INVALID")
            source_relative = clean_relative(item["source_artifact"],
                                             prefix=SOURCE_PREFIX)
            require(isinstance(item["sha256"], str)
                    and DIGEST.fullmatch(item["sha256"]) is not None,
                    "SOURCE_DIGEST_INVALID")
            key = (site, cap)
            require(key not in seen_site_capabilities, "MULTIPLE_ACTIVE_CAPABILITY_OWNERS")
            seen_site_capabilities.add(key)
            sym_key = (site, symbol)
            require(sym_key not in seen_site_symbols, "DUPLICATE_FUNCTION_OWNER")
            seen_site_symbols.add(sym_key)
            source = safe_bytes(repo, source_relative)
            require(hashlib.sha256(source).hexdigest() == item["sha256"],
                    "SOURCE_BYTES_DRIFT")
            if site in runtimes:
                live = safe_bytes(runtimes[site], relative)
                require(hashlib.sha256(live).hexdigest() == item["sha256"],
                        "LIVE_OWNER_BYTES_DRIFT")
                checked_runtime += 1
            found_sites.add(site)
            variants += 1
    require(not (set(runtimes) - found_sites), "UNREGISTERED_RUNTIME_SITE")
    return {
        "status": "SOURCE_OWNER_PROVENANCE_PASS",
        "capabilities": len(caps), "variants": variants,
        "registered_sites": sorted(found_sites),
        "runtime_variants_checked": checked_runtime,
        "release_deployable": False
    }


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--repo-root", type=Path,
                   default=Path(__file__).resolve().parents[3])
    p.add_argument("--runtime", action="append", default=[],
                   help="RegisteredSiteAlias=/absolute/path/to/site-copy")
    args = p.parse_args()
    runtimes = {}
    for entry in args.runtime:
        if "=" not in entry:
            p.error("--runtime expects site=/absolute/site/root")
        site, root = entry.split("=", 1)
        if site in runtimes:
            p.error("duplicate runtime mapping")
        runtimes[site] = Path(root)
    try:
        result = validate(args.repo_root, args.repo_root / REGISTRY, runtimes)
    except (InvalidProvenance, OSError, ValueError) as exc:
        raise SystemExit("SOURCE_OWNER_PROVENANCE_DENIED: " + str(exc)) from exc
    print(json.dumps(result, sort_keys=True))


if __name__ == "__main__":
    main()
