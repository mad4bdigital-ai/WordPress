#!/usr/bin/env python3
"""Generate deterministic premium-provider attestation evidence from an exact ZIP.

This tool intentionally never inspects a live WordPress installation. It binds a
trusted package supplied by the owner/vendor to an exact provider version,
archive SHA256, byte count, and the critical-file manifest already governed by
certified-providers.json. Semantic review remains a separate human step.
"""

from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import pathlib
import re
import sys
import zipfile

REPO_ROOT = pathlib.Path(__file__).resolve().parents[1]
PLUGIN_ROOT = REPO_ROOT / "wp-content/plugins"
CONTROL_ROOT = PLUGIN_ROOT / "mad4b-site-control-plane"
CONTRACT_PATH = CONTROL_ROOT / "config/certified-providers.json"
ATTESTATION_POLICY_PATH = CONTROL_ROOT / "config/premium-provider-attestations.json"

PROVIDER_PLUGIN_ENTRIES = {
    "jetengine": "jet-engine/jet-engine.php",
    "jetsmartfilters": "jet-smart-filters/jet-smart-filters.php",
}


def canonical_manifest_digest(files: dict[str, str]) -> str:
    lines = [f"{path}={files[path].lower()}" for path in sorted(files)]
    payload = "\n".join(lines) + "\n"
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def load_json(path: pathlib.Path) -> dict:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:  # pragma: no cover - diagnostic boundary
        raise SystemExit(f"unable to read JSON {path}: {exc}") from exc


def inspect_package(provider: str, archive: pathlib.Path, expected_version: str) -> dict:
    contracts = load_json(CONTRACT_PATH).get("providers", {})
    contract = contracts.get(provider)
    if not isinstance(contract, dict):
        raise SystemExit(f"unknown provider contract: {provider}")

    plugin_entry = PROVIDER_PLUGIN_ENTRIES.get(provider)
    if not plugin_entry:
        raise SystemExit(f"unsupported premium provider: {provider}")
    if not archive.is_file():
        raise SystemExit(f"package not found: {archive}")

    raw = archive.read_bytes()
    try:
        zf = zipfile.ZipFile(archive)
    except zipfile.BadZipFile as exc:
        raise SystemExit(f"invalid ZIP: {archive}") from exc

    with zf:
        names = set(zf.namelist())
        if plugin_entry not in names:
            raise SystemExit(f"{provider}: missing plugin entry {plugin_entry}")
        header = zf.read(plugin_entry).decode("utf-8", errors="replace")[:32768]
        match = re.search(r"^\s*\*?\s*Version:\s*([^\r\n]+)", header, re.I | re.M)
        if not match:
            raise SystemExit(f"{provider}: Version header not found")
        version = match.group(1).strip()
        if version != expected_version:
            raise SystemExit(
                f"{provider}: exact version mismatch; expected={expected_version} observed={version}"
            )

        prefix = plugin_entry.rsplit("/", 1)[0] + "/"
        critical_files: dict[str, str] = {}
        missing: list[str] = []
        governed_paths = contract.get("critical_files", {})
        if not isinstance(governed_paths, dict) or not governed_paths:
            raise SystemExit(f"{provider}: governed critical-file list is empty")
        for relative in governed_paths:
            member = prefix + relative
            if member not in names:
                missing.append(relative)
                continue
            critical_files[relative] = hashlib.sha256(zf.read(member)).hexdigest()
        if missing:
            raise SystemExit(f"{provider}: exact package missing critical files: {missing}")

    return {
        "version": version,
        "archive_sha256": hashlib.sha256(raw).hexdigest(),
        "archive_bytes": len(raw),
        "plugin_entry": plugin_entry,
        "critical_files": critical_files,
        "critical_manifest_sha256": canonical_manifest_digest(critical_files),
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--provider", required=True, choices=sorted(PROVIDER_PLUGIN_ENTRIES))
    parser.add_argument("--package", required=True, type=pathlib.Path)
    parser.add_argument("--expected-version", required=True)
    parser.add_argument(
        "--source-kind",
        required=True,
        choices=["owner_supplied_exact_package", "vendor_exact_package"],
    )
    parser.add_argument("--source-locator", required=True)
    parser.add_argument("--attested-by", required=True)
    parser.add_argument("--output", required=True, type=pathlib.Path)
    args = parser.parse_args()

    if not re.fullmatch(r"[0-9A-Za-z._-]+", args.expected_version):
        raise SystemExit("invalid expected version")
    if not args.source_locator.strip():
        raise SystemExit("source locator is required")
    if not args.attested_by.strip():
        raise SystemExit("attested-by is required")

    policy = load_json(ATTESTATION_POLICY_PATH)
    allowed = set(policy.get("policy", {}).get("allowed_source_kinds", []))
    forbidden = set(policy.get("policy", {}).get("forbidden_source_kinds", []))
    if args.source_kind not in allowed or args.source_kind in forbidden:
        raise SystemExit("source kind is not permitted by the attestation policy")

    exact = inspect_package(args.provider, args.package.resolve(), args.expected_version)
    attestation_id = (
        f"{args.provider}-{args.expected_version}-{exact['archive_sha256'][:16]}"
    )
    record = {
        "attestation_id": attestation_id,
        "provider": args.provider,
        "version": args.expected_version,
        "source_kind": args.source_kind,
        "source_locator": args.source_locator.strip(),
        "independent_from_runtime": True,
        "archive_sha256": exact["archive_sha256"],
        "archive_bytes": exact["archive_bytes"],
        "plugin_entry": exact["plugin_entry"],
        "critical_files": exact["critical_files"],
        "critical_manifest_sha256": exact["critical_manifest_sha256"],
        "semantic_review": {
            "status": "pending",
            "approved": False,
            "review_id": "",
            "notes": "Human semantic review is required before this attestation may authorize a provider profile.",
        },
        "attested_by": args.attested_by.strip(),
        "generated_at": dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat(),
        "generator": "tools/premium-provider-attestation.py:v1",
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(record, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(record, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
