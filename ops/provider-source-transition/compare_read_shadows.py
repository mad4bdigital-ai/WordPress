#!/usr/bin/env python3
"""Offline read-only DFSB shadow canary; no WordPress or provider execution."""
import argparse
import datetime as dt
import json
import re
from pathlib import Path

SHA64 = re.compile(r"[0-9a-f]{64}\Z")
ALLOWED = {"profiles", "runtime_inventory", "content_catalog"}
REQUIRED = {"profiles", "runtime_inventory", "content_catalog"}
CONTRACT = "mad4b.provider-shadow-read-comparison.v1"


def assert_digest(value, what):
    if not isinstance(value, str) or not SHA64.fullmatch(value):
        raise ValueError("missing/invalid digest: " + what)


def capture_timestamp(value):
    if not isinstance(value, str):
        raise ValueError("capture timestamp missing")
    try:
        stamp = dt.datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise ValueError("capture timestamp malformed") from exc
    if stamp.tzinfo is None:
        raise ValueError("capture timestamp requires timezone")
    return stamp


def validate(snapshot, expected_source):
    if not isinstance(snapshot, dict):
        raise ValueError("snapshot must be object")
    if snapshot.get("contract") != "mad4b.provider-read-fingerprint.v1":
        raise ValueError("unsupported native read fingerprint envelope")
    if snapshot.get("source_id") != expected_source:
        raise ValueError("unexpected source in snapshot")
    if snapshot.get("read_only") is not True or snapshot.get("capture_authorized") is not True:
        raise ValueError("read-only, authorized capture required")
    if snapshot.get("environment") not in ("local", "development", "staging"):
        raise ValueError("only eligible test environments may shadow compare")
    for key in ("site_uuid", "site_origin", "site_profile_revision", "locale", "wpml_language"):
        if not isinstance(snapshot.get(key), str) or not snapshot[key]:
            raise ValueError("missing site-scoped identity: " + key)
    if not snapshot["site_origin"].startswith("https://"):
        raise ValueError("site origin must be HTTPS")
    for key in ("control_plane_runtime_sha256", "provider_package_sha256"):
        assert_digest(snapshot.get(key), key)
    t = capture_timestamp(snapshot.get("captured_at"))
    samples = snapshot.get("sections")
    if not isinstance(samples, dict) or set(samples) != REQUIRED:
        raise ValueError("all required bounded read sections must exist")
    for name, sample in samples.items():
        if name not in ALLOWED or not isinstance(sample, dict):
            raise ValueError("invalid section")
        if sample.get("read_only") is not True:
            raise ValueError("section not read-only")
        if sample.get("canonicalization_contract") != "mad4b.dfsb-semantic-read.v1":
            raise ValueError("semantic normalization contract differs")
        assert_digest(sample.get("semantic_sha256"), name)
    return t


def compare(legacy, canonical, max_skew_seconds=120):
    result = {
        "contract": CONTRACT,
        "state": "blocked",
        "matches": False,
        "read_canary_eligible": False,
        "cutover_authorized": False,
        "provider_activation_authorized": False,
        "write_authorized": False,
        "publication_authorized": False,
        "site_mutated": False,
        "differences": [],
    }
    try:
        old_ts = validate(legacy, "etg-dynamic-filter-seo-bridge")
        new_ts = validate(canonical, "dynamic-filter-seo-bridge")
    except ValueError as e:
        result["differences"] = ["invalid_envelope:" + str(e)]
        return result

    if max_skew_seconds < 0 or abs((new_ts - old_ts).total_seconds()) > max_skew_seconds:
        result["differences"].append("capture_window_mismatch")
    for name in ("site_uuid", "site_origin", "site_profile_revision", "environment",
                 "locale", "wpml_language", "control_plane_runtime_sha256"):
        if legacy[name] != canonical[name]:
            result["differences"].append("context_mismatch:" + name)
    if legacy["provider_package_sha256"] == canonical["provider_package_sha256"]:
        result["differences"].append("provider_artifacts_not_distinct")
    for key in sorted(REQUIRED):
        if legacy["sections"][key]["semantic_sha256"] != canonical["sections"][key]["semantic_sha256"]:
            result["differences"].append("semantic_read_mismatch:" + key)
    if not result["differences"]:
        result["state"] = "shadow_read_match_requires_certification"
        result["matches"] = True
        result["read_canary_eligible"] = True
    return result


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--legacy", required=True, type=Path)
    p.add_argument("--canonical", required=True, type=Path)
    args = p.parse_args()
    try:
        old = json.loads(args.legacy.read_text(encoding="utf-8"))
        new = json.loads(args.canonical.read_text(encoding="utf-8"))
        report = compare(old, new)
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        p.error(str(exc))
    print(json.dumps(report, indent=2, sort_keys=True))
    return 0 if report["matches"] else 2


if __name__ == "__main__":
    raise SystemExit(main())
