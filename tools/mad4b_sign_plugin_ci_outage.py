#!/usr/bin/env python3
"""Create source-bound signed native-test evidence for one certified WP plugin.

Offline tool. Never communicates with WordPress, updates an installed plugin,
creates a GitHub release, generates a trusted PASS or changes a provider
contract. This script requires exact pre-existing source-owned provider
authority and owner-reviewed native test evidence.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import sys
import time

CONTRACT = "mad4b.plugin-update-native-evidence.v1"
REQUIRED = ("source_exact", "php_syntax", "package_integrity",
            "plugin_runtime", "rollback_readiness")
SHA40 = re.compile(r"^[a-f0-9]{40}$")
SHA64 = re.compile(r"^[a-f0-9]{64}$")
SLUG = re.compile(r"^[a-z0-9_-]{1,80}$")


def block(message: str) -> None:
    raise SystemExit("BLOCKED: " + message)


def sha256(path: Path) -> str:
    hasher = hashlib.sha256()
    with path.open("rb") as fp:
        for segment in iter(lambda: fp.read(1024 * 1024), b""):
            hasher.update(segment)
    return hasher.hexdigest()


def load_obj(path: Path) -> dict:
    if not path.is_file() or path.is_symlink() or path.stat().st_size > 4 * 1024 * 1024:
        block("Missing, oversized or symlinked input " + path.name)
    value = json.loads(path.read_text("utf-8"))
    if not isinstance(value, dict):
        block("Expected JSON object " + path.name)
    return value


def require_input(path: Path) -> None:
    if not path.is_file() or path.is_symlink():
        block("Input is not a regular non-symlink file: " + path.name)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--provider-contracts", type=Path, required=True)
    parser.add_argument("--provider-id", required=True)
    parser.add_argument("--component", default="")
    parser.add_argument("--source-commit-sha", required=True)
    parser.add_argument("--archive", type=Path, required=True)
    parser.add_argument("--native-evidence-bundle", type=Path, required=True)
    parser.add_argument("--test-report", type=Path, required=True)
    parser.add_argument("--site-uuid", required=True)
    parser.add_argument("--site-origin", required=True)
    parser.add_argument("--signer-private-key", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--confirmation", required=True,
                        choices=["I APPROVE THIS EXACT PLUGIN STAGING EVIDENCE"])
    args = parser.parse_args()
    if not SLUG.fullmatch(args.provider_id) or (args.component and not SLUG.fullmatch(args.component)):
        block("Invalid provider/component identity")
    if not SHA40.fullmatch(args.source_commit_sha):
        block("Exact 40-character source commit required")
    if not re.fullmatch(r"[0-9a-fA-F-]{36}", args.site_uuid):
        block("Staging UUID invalid")
    if not args.site_origin.startswith("https://") or args.site_origin.endswith("/") or any(
            symbol in args.site_origin for symbol in ("?", "#", "@", "\\", " ", "\t")):
        block("Exact HTTPS Staging origin required without credentials, path or query")
    from urllib.parse import urlsplit
    origin = urlsplit(args.site_origin)
    if not origin.hostname or origin.path not in ("", "/") or origin.port not in (None, 443):
        block("Staging origin must be a single HTTPS host without nonstandard port")
    for file in (args.archive, args.native_evidence_bundle, args.signer_private_key):
        require_input(file)
    if os.name != "nt" and args.signer_private_key.stat().st_mode & 0o077:
        block("Ed25519 private key must be group/other inaccessible (chmod 600)")
    contracts = load_obj(args.provider_contracts)
    p = (contracts.get("providers") or {}).get(args.provider_id)
    if not isinstance(p, dict):
        block("Provider not source-certified")
    authority = p if not args.component else (p.get("components") or {}).get(args.component)
    if not isinstance(authority, dict):
        block("Requested component is not source-certified")
    plugin_file = authority.get("plugin_file")
    version = authority.get("version")
    expected_hash = authority.get("archive_sha256")
    public_b64 = authority.get("offline_update_attestor_public_key")
    if (not isinstance(plugin_file, str) or not re.fullmatch(r"[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\.php", plugin_file)
            or not isinstance(version, str) or not version or not isinstance(expected_hash, str)
            or not SHA64.fullmatch(expected_hash) or not isinstance(public_b64, str)):
        block("Incomplete source-approved provider package or signer")
    if sha256(args.archive) != expected_hash:
        block("Local ZIP bytes do not match certified provider SHA-256")
    report = load_obj(args.test_report)
    if set(report.keys()) != {"source_commit_sha", "provider_id", "component", "plugin_file",
                              "archive_sha256", "evidence_bundle_sha256", "test_gates"}:
        block("Unexpected test report inventory")
    bundle = sha256(args.native_evidence_bundle)
    if (report["source_commit_sha"] != args.source_commit_sha or
            report["provider_id"] != args.provider_id or report["component"] != args.component or
            report["plugin_file"] != plugin_file or report["archive_sha256"] != expected_hash or
            report["evidence_bundle_sha256"] != bundle):
        block("Test report not bound to exact provider, source, ZIP and immutable evidence bundle")
    gates = report["test_gates"]
    if not isinstance(gates, dict) or list(gates.keys()) != list(REQUIRED) or any(
            gates.get(key) != "PASS" for key in REQUIRED):
        block("Native source, PHP, package, runtime and rollback tests must all PASS")
    try:
        from cryptography.hazmat.primitives import serialization
        from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
    except ImportError:
        block("cryptography package required for Ed25519")
    private = serialization.load_pem_private_key(args.signer_private_key.read_bytes(), password=None)
    if not isinstance(private, Ed25519PrivateKey):
        block("Ed25519 signing key required")
    public = private.public_key().public_bytes(
        encoding=serialization.Encoding.Raw,
        format=serialization.PublicFormat.Raw
    )
    if base64.b64encode(public).decode("ascii") != public_b64:
        block("Signer private key does not match source-approved public key")
    now = int(time.time())
    claims = dict(contract=CONTRACT, provider_id=args.provider_id, component=args.component,
                  plugin_file=plugin_file, version=version,
                  archive_sha256=expected_hash, source_commit_sha=args.source_commit_sha,
                  site_uuid=args.site_uuid, site_origin=args.site_origin,
                  environment="staging", issued_at=now, expires_at=now+86400,
                  evidence_bundle_sha256=bundle,
                  test_gates={key: "PASS" for key in REQUIRED}, owner_reviewed=True)
    raw = json.dumps(claims, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    signature = private.sign(raw)
    private.public_key().verify(signature, raw)
    result = dict(
        contract=CONTRACT + ".signed-envelope.v1", provider_id=args.provider_id,
        component=args.component, plugin_file=plugin_file, archive_sha256=expected_hash,
        claims_b64=base64.b64encode(raw).decode("ascii"),
        signature_b64=base64.b64encode(signature).decode("ascii"),
        evidence_sha256=hashlib.sha256(raw).hexdigest(),
        installation_authorized=False, production_authorized=False,
        github_ci_success_asserted=False,
    )
    args.output.parent.mkdir(parents=True, exist_ok=True)
    if args.output.is_symlink() or args.output.exists():
        block("Refuse overwriting an existing signed receipt")
    args.output.write_text(json.dumps(result, sort_keys=True, indent=2)+"\n", encoding="utf-8")
    print("SIGNED PLUGIN CI-OUTAGE EVIDENCE:", result["evidence_sha256"])
    print("PROVIDER:", args.provider_id, "PLUGIN:", plugin_file)
    print("No WordPress installation, master release change or Production grant performed")


if __name__ == "__main__":
    main()
