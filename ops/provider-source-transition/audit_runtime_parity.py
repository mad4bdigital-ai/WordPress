#!/usr/bin/env python3
"""Read-only exact build parity: metadata equality never certifies deployment."""
import argparse
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MAIN = ROOT / "wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php"
SHA = re.compile(r"[0-9a-f]{64}\Z")


def assess(source, observed_version=None, observed_file_sha256=None, site_uuid=None):
    header = re.search(r"(?m)^\s*\*\s*Version:\s*(\S+)\s*$", source)
    constant = re.search(r"define\(\s*'MAD4B_SCP_VERSION'\s*,\s*'([^']+)'\s*\)", source)
    if not header or not constant:
        raise ValueError("missing native Control Plane release identity")
    version = header.group(1)
    consistent = version == constant.group(1)
    sha = hashlib.sha256(source.encode("utf-8")).hexdigest()
    digest_ok = bool(observed_file_sha256 and SHA.fullmatch(observed_file_sha256))
    exact = bool(consistent and observed_version == version and digest_ok
                 and observed_file_sha256 == sha and site_uuid)
    return {
        "contract": "mad4b.control-plane-source-parity-preview.v1",
        "source_version": version,
        "source_header_constant_consistent": consistent,
        "source_file_sha256": sha,
        "observed_runtime_version": observed_version,
        "observed_runtime_file_sha256_supplied": digest_ok,
        "site_identity_present": bool(site_uuid),
        "exact_source_file_match": exact,
        "verified_deployment_authority": False,
        "runtime_adapter_migration_authorized": False,
        "required_next_gate": "signed_runtime_provenance_and_site_owner_approval",
    }


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--observed-runtime-version")
    p.add_argument("--observed-runtime-main-sha256")
    p.add_argument("--site-uuid")
    args = p.parse_args()
    if args.observed_runtime_main_sha256 and not SHA.fullmatch(args.observed_runtime_main_sha256):
        p.error("observed runtime SHA256 must be lowercase 64-character hex")
    text = MAIN.read_text(encoding="utf-8")
    report = assess(
        text,
        observed_version=args.observed_runtime_version,
        observed_file_sha256=args.observed_runtime_main_sha256,
        site_uuid=args.site_uuid,
    )
    print(json.dumps(report, indent=2, sort_keys=True))


if __name__ == "__main__":
    main()
