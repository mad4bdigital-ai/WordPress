#!/usr/bin/env python3
"""Read-only source-choice planning; never installs plugins or certifies providers."""
import argparse
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CATALOG = ROOT / "wp-content/plugins/mad4b-site-control-plane/config/cross-plugin-sources.json"
POLICY = Path(__file__).with_name("policy.json")
SHA40 = re.compile(r"[0-9a-f]{40}\Z")
SHA64 = re.compile(r"[0-9a-f]{64}\Z")


def load(path):
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError("object document required")
    return data


def indexes(policy, catalog):
    if policy.get("contract") != "mad4b.provider-source-transition.v1":
        raise ValueError("unsupported transition policy")
    if catalog.get("contract") != "mad4b.cross-plugin-sources.v1":
        raise ValueError("unsupported native source catalog")
    if policy.get("implicit_legacy_fallback") is not False or policy.get("execution_authority") != "never_derived_from_source_selection":
        raise ValueError("unsafe implicit authorization policy")
    sources = catalog.get("sources")
    families = policy.get("families")
    if not isinstance(sources, list) or not isinstance(families, list):
        raise ValueError("catalog sources and families must be lists")
    source_map = {}
    for record in sources:
        if not isinstance(record, dict) or not isinstance(record.get("id"), str):
            raise ValueError("invalid source descriptor")
        key = record["id"]
        if key in source_map:
            raise ValueError("duplicate source id: " + key)
        source_map[key] = record
    families_map = {}
    claimed = set()
    for family in families:
        if not isinstance(family, dict):
            raise ValueError("invalid family")
        name = family.get("family")
        canonical = family.get("canonical_source_id")
        legacy = family.get("legacy_source_ids")
        if not isinstance(name, str) or name in families_map or not isinstance(canonical, str):
            raise ValueError("invalid/duplicate family")
        if not isinstance(legacy, list) or not legacy or len(legacy) != len(set(legacy)):
            raise ValueError("invalid legacy ids")
        if family.get("mutation_policy") != "deny" or family.get("canonical_policy") != "candidate_until_exact_package_and_site_certification":
            raise ValueError("source selection must not grant execution")
        for source_id in [canonical] + legacy:
            if not isinstance(source_id, str) or source_id in claimed or source_id not in source_map:
                raise ValueError("unknown or multiply claimed source id: " + str(source_id))
            claimed.add(source_id)
        families_map[name] = family
    return families_map, source_map


def source_evidence(record):
    exact_source = bool(SHA40.fullmatch(str(record.get("source_commit_sha") or "")))
    exact_artifact = bool(SHA64.fullmatch(str(record.get("artifact_sha256") or "")))
    package_certified = record.get("package_certification") == "certified"
    return {
        "review_state": record.get("review_state", "unknown"),
        "package_certification": record.get("package_certification", "missing"),
        "exact_source_present": exact_source,
        "exact_artifact_present": exact_artifact,
        "package_evidence_complete": bool(package_certified and exact_source and exact_artifact
            and record.get("artifact_version")),
    }


def plan(policy, catalog, family_name, site_mode, existing_source=None):
    families, sources = indexes(policy, catalog)
    if family_name not in families:
        raise ValueError("unknown source family")
    if site_mode not in ("new_site", "existing_site"):
        raise ValueError("invalid site mode")
    group = families[family_name]
    canonical = group["canonical_source_id"]
    legacy = group["legacy_source_ids"]
    if site_mode == "new_site":
        if existing_source is not None:
            raise ValueError("new sites cannot adopt a legacy source implicitly")
        selected = canonical
        operation = "canonical_candidate_only"
    else:
        if existing_source is None or existing_source not in [canonical] + legacy:
            raise ValueError("existing source must match an enrolled source descriptor")
        selected = existing_source
        operation = "preserve_existing_pin" if selected in legacy else "canonical_candidate_only"
    record = sources[selected]
    return {
        "contract": policy["contract"],
        "family": family_name,
        "site_mode": site_mode,
        "source_id": selected,
        "selection": operation,
        "legacy_source": selected in legacy,
        "canonical_source_id": canonical,
        "native_provider_id": record.get("provider_id"),
        "catalog_evidence": source_evidence(record),
        "execution_allowed": False,
        "publication_allowed": False,
        "mutation_allowed": False,
        "writes_inherited_from_legacy": False,
        "legacy_fallback_used": False,
        "required_next_gate": "exact_package_certification_and_site_owner_approval",
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--family", default="dynamic-filter-seo-bridge")
    parser.add_argument("--mode", required=True, choices=["new_site", "existing_site"])
    parser.add_argument("--existing-source")
    args = parser.parse_args()
    try:
        report = plan(load(POLICY), load(CATALOG), args.family, args.mode, args.existing_source)
    except (ValueError, OSError, json.JSONDecodeError) as exc:
        parser.exit(2, "SOURCE_TRANSITION_DENIED: " + str(exc) + "\n")
    print(json.dumps(report, sort_keys=True, indent=2))


if __name__ == "__main__":
    main()
