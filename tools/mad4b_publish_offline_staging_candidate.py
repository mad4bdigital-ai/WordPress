#!/usr/bin/env python3
"""Offline CI-outage Staging publisher. Never uses GitHub CI status as a gate.

Requires a trusted owner-reviewed native test bundle, exact canonical package
receipt, locally held Ed25519 private key, exact PR HEAD and optional explicit
GitHub Release upload. It never creates/updates the default master pointer.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
from urllib.parse import urlparse

REPO = "mad4bdigital-ai/WordPress"
TAG = "mad4b-site-control-plane-update-channel"
CONTRACT = "mad4b.staging-ci-outage-attestation.v1"
GATES = ("g9_delivery_contract", "php83_tree_syntax", "canonical_package_receipt",
         "isolated_zip_runtime_integrity", "exact_source_verification")
HEX40 = re.compile(r"^[a-f0-9]{40}$")
HEX64 = re.compile(r"^[a-f0-9]{64}$")


def fail(message: str) -> None:
    raise SystemExit("BLOCKED: " + message)


def sha(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as reader:
        for block in iter(lambda: reader.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def read_json(path: Path) -> dict:
    if not path.is_file() or path.is_symlink():
        fail("Missing or symlinked input: " + path.name)
    if path.stat().st_size > 4 * 1024 * 1024:
        fail("Input too large: " + path.name)
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        fail("Expected object in " + path.name)
    return value


def run_gh(*argv: str) -> str:
    p = subprocess.run(["gh", *argv], text=True, capture_output=True, timeout=60, check=False)
    if p.returncode != 0:
        fail("GitHub publish/verification failed at: " + " ".join(argv[:4]))
    return p.stdout


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--source-sha", required=True)
    ap.add_argument("--pull-request", type=int, default=258)
    ap.add_argument("--site-uuid", required=True)
    ap.add_argument("--site-origin", required=True)
    ap.add_argument("--zip", type=Path, required=True)
    ap.add_argument("--canonical-receipt", type=Path, required=True)
    ap.add_argument("--native-test-bundle", type=Path, required=True)
    ap.add_argument("--gate-results", type=Path, required=True)
    ap.add_argument("--signer-private-key-pem", type=Path, required=True)
    ap.add_argument("--out-dir", type=Path, required=True)
    ap.add_argument("--owner-reviewed", required=True,
                    choices=["I REVIEWED THE EXACT STAGING PACKAGE AND NATIVE TESTS"])
    ap.add_argument("--publish-to-github", action="store_true",
                    help="Publish immutable assets to the fixed existing GitHub Release tag")
    args = ap.parse_args()
    source = args.source_sha.lower()
    if not HEX40.fullmatch(source):
        fail("Exact 40-character source SHA required.")
    if args.pull_request < 1 or args.pull_request > 1000000:
        fail("Invalid PR number.")
    if not re.fullmatch(r"[a-zA-Z0-9-]{8,64}", args.site_uuid):
        fail("Staging site UUID invalid.")
    u = urlparse(args.site_origin)
    if u.scheme != "https" or not u.hostname or u.path not in ("", "/") or u.query or u.fragment or u.username:
        fail("Exact HTTPS site origin without path/query required.")
    origin = args.site_origin.rstrip("/")
    for src in (args.zip, args.native_test_bundle, args.signer_private_key_pem):
        if not src.is_file() or src.is_symlink():
            fail("Missing or symlinked private/evidence input: " + src.name)
    zipsize = args.zip.stat().st_size
    if zipsize <= 0 or zipsize > 16 * 1024 * 1024:
        fail("Plugin ZIP size outside selected-HEAD WordPress limit.")
    receipt = read_json(args.canonical_receipt)
    evidence = read_json(args.gate_results)
    if receipt.get("contract") != "mad4b.deterministic-control-plane-package.v1":
        fail("Canonical receipt contract differs.")
    if receipt.get("source_commit_sha") != source:
        fail("Canonical package receipt does not match exact reviewed PR SHA.")
    ziphash = sha(args.zip)
    if (receipt.get("archive_sha256") != ziphash or
            receipt.get("archive_bytes") != zipsize or
            receipt.get("archive_compression") != "stored"):
        fail("Canonical package SHA-256, bytes or compression drift.")
    for field in ("build_fingerprint", "package_manifest_digest"):
        if not HEX64.fullmatch(str(receipt.get(field, ""))):
            fail("Canonical package build identity invalid: " + field)
    version = str(receipt.get("control_plane_version", ""))
    if not version or len(version) > 64:
        fail("Canonical package version invalid.")
    if set(evidence) != set(GATES) or any(evidence.get(k) != "PASS" for k in GATES):
        fail("Offline evidence must contain five exact native PASS gates, reviewed independently.")
    # The signed SHA commits to the bytes of the complete evidence bundle,
    # not merely the names of five asserted PASS gates.
    evidencehash = sha(args.native_test_bundle)
    if not HEX64.fullmatch(evidencehash):
        fail("Invalid test evidence archive hash.")
    keyfile = args.signer_private_key_pem
    if os.name != "nt" and (keyfile.stat().st_mode & 0o077):
        fail("Signer key file must not be readable by group/others (chmod 600).")
    try:
        from cryptography.hazmat.primitives import serialization
        from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
        private = serialization.load_pem_private_key(keyfile.read_bytes(), password=None)
        if not isinstance(private, Ed25519PrivateKey):
            fail("Only Ed25519 is supported.")
    except ImportError:
        fail("Python cryptography package required; do not weaken signer verification.")
    issued = int(time.time())
    claims = dict(
        contract=CONTRACT, repository=REPO, source_commit_sha=source,
        archive_sha256=ziphash, build_fingerprint=receipt["build_fingerprint"],
        package_manifest_digest=receipt["package_manifest_digest"],
        size_bytes=zipsize, site_uuid=args.site_uuid, site_origin=origin,
        environment="staging", issued_at=issued, expires_at=issued + 86400,
        evidence_bundle_sha256=evidencehash, test_gates={k: "PASS" for k in GATES},
        owner_reviewed=True,
    )
    raw = json.dumps(claims, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    sig = private.sign(raw)
    public = private.public_key()
    public.verify(sig, raw)
    public_bytes = public.public_bytes(
        encoding=serialization.Encoding.Raw, format=serialization.PublicFormat.Raw,
    )
    manifest = {
        "contract": "mad4b.control-plane-update-channel.v1",
        "repository": REPO, "release_tag": TAG, "version": version,
        "source_commit_sha": source, "archive_sha256": ziphash,
        "build_fingerprint": receipt["build_fingerprint"],
        "package_manifest_digest": receipt["package_manifest_digest"],
        "size_bytes": zipsize,
        "package_url": f"https://github.com/{REPO}/releases/download/{TAG}/mad4b-site-control-plane-{source}.zip",
        "staging_offline_candidate": True, "staging_candidate_certified": False,
        "release_verdict_success": False, "release_verdict_run_id": 0,
        "release_root_trust_verified": False, "published_from_master": False,
        "production_authorized": False, "production_auto_update_enabled": False,
        "evidence_bundle_sha256": evidencehash,
        "ci_outage_attestation": {
            "claims_b64": base64.b64encode(raw).decode("ascii"),
            "signature_b64": base64.b64encode(sig).decode("ascii"),
        },
    }
    args.out_dir.mkdir(parents=True, exist_ok=True)
    if args.out_dir.is_symlink():
        fail("Output may not be a symlink.")
    zippath = args.out_dir / f"mad4b-site-control-plane-{source}.zip"
    mpath = args.out_dir / f"mad4b-site-control-plane-update-{source}.json"
    if zippath.exists() and sha(zippath) != ziphash:
        fail("Existing immutable ZIP differs; refuse overwrite.")
    if not zippath.exists():
        import shutil
        shutil.copyfile(args.zip, zippath)
    mdata = (json.dumps(manifest, ensure_ascii=False, sort_keys=True, indent=2) + "\n").encode()
    if mpath.exists() and mpath.read_bytes() != mdata:
        fail("Existing immutable signed manifest differs; refuse overwrite.")
    if not mpath.exists():
        mpath.write_bytes(mdata)
    print("VERIFIED EXACT STAGING ZIP:", ziphash)
    print("OFFLINE RECEIPT:", hashlib.sha256(raw).hexdigest())
    print("ATTESTOR PUBLIC KEY (enroll via WordPress MCP):", base64.b64encode(public_bytes).decode())
    print("GitHub CI terminal success was not used; signed source-bound native tests required.")
    print("No Production or master update pointer changes.")

    if args.publish_to_github:
        pr = json.loads(run_gh("api", f"repos/{REPO}/pulls/{args.pull_request}"))
        if (pr.get("state") != "open" or pr.get("head", {}).get("sha") != source
                or pr.get("head", {}).get("repo", {}).get("full_name") != REPO):
            fail("PR moved or source ownership differs. Regenerate from new exact HEAD.")
        run_gh("release", "view", TAG, "--repo", REPO)
        assets = json.loads(run_gh("release", "view", TAG, "--repo", REPO, "--json", "assets"))
        existing = {a.get("name") for a in assets.get("assets", [])}
        for local in (zippath, mpath):
            if local.name in existing:
                fail("Immutable GitHub asset already exists; refuse overwrite: " + local.name)
        # Publish immutable exact-SHA artifacts only; never create/move the
        # signed master update pointer, never use --clobber.
        for local in (zippath, mpath):
            run_gh("release", "upload", TAG, str(local), "--repo", REPO)
        print("Published Staging-only immutable ZIP and manifest; no master pointer changed.")


if __name__ == "__main__":
    main()
