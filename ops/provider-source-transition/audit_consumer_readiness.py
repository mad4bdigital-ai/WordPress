#!/usr/bin/env python3
"""Non-mutating DFSB adapter consumer/version readiness audit."""
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"
NEUTRAL = ROOT / "wp-content/plugins/dynamic-filter-seo-bridge"
READ_ADAPTER = PLUGIN / "includes/adapters/class-mad4b-scp-etg-dfsb-adapter.php"
WRITE_ADAPTER = PLUGIN / "includes/adapters/class-mad4b-scp-etg-dfsb-write-adapter.php"
REGISTRY = PLUGIN / "config/adapter-registration-policy.json"
SKILLS = PLUGIN / "config/skill-provider-catalog.json"
SOURCES = PLUGIN / "config/cross-plugin-sources.json"
NEUTRAL_ENTRY = NEUTRAL / "dynamic-filter-seo-bridge.php"
MIRROR = NEUTRAL / "includes/Runtime/LegacyOptionMirror.php"


def matched(pattern, content, label):
    result = re.search(pattern, content)
    if not result:
        raise ValueError("expected native contract missing: " + label)
    return result.group(1)


def audit(read_source, write_source, plugin_source, registry, skills, mirror, sources_catalog):
    legacy_read_version = matched(
        r"const\s+SUPPORTED_VERSION\s*=\s*'([^']+)'", read_source, "read adapter version"
    )
    legacy_write_version = matched(
        r"['\"](0\.4\.0-alpha\.[^'\"]+)['\"]\s*===\s*ETG_DFSB_VERSION",
        write_source, "write adapter version",
    )
    canonical_version = matched(
        r"define\s*\(\s*'DFSB_VERSION'\s*,\s*'([^']+)'",
        plugin_source,
        "neutral plugin version",
    )
    aliases = bool(re.search(r"['\"]ETG_DFSB_VERSION['\"]\s*=>\s*DFSB_VERSION", plugin_source))
    if not isinstance(sources_catalog, dict) or not isinstance(sources_catalog.get("sources"), list):
        raise ValueError("native source registry must be an object with source list")
    canonical_descriptors = [
        row for row in sources_catalog["sources"]
        if isinstance(row, dict) and row.get("id") == "dynamic-filter-seo-bridge"
    ]
    if len(canonical_descriptors) != 1:
        raise ValueError("canonical source descriptor must exist exactly once")
    descriptor = canonical_descriptors[0]
    candidate_version = str(descriptor.get("candidate_artifact_version") or "")
    candidate_source_sha = str(descriptor.get("candidate_source_commit_sha") or "")
    candidate_matches_source = bool(candidate_version and candidate_version == canonical_version)
    exact_certified = bool(
        descriptor.get("package_certification") == "certified"
        and re.fullmatch(r"[0-9a-f]{40}", str(descriptor.get("source_commit_sha") or ""))
        and re.fullmatch(r"[0-9a-f]{64}", str(descriptor.get("artifact_sha256") or ""))
        and descriptor.get("artifact_version") == canonical_version
    )
    read_class = "MAD4B_SCP_ETG_DFSB_Adapter"
    write_class = "MAD4B_SCP_ETG_DFSB_Write_Adapter"
    registered = set(registry.get("approved_classes", []))
    read_registered = read_class in registered
    write_registered = write_class in registered
    legacy_skills = (skills.get("packs") or {}).get("etg-dfsb")
    legacy_shadow = ("'etg_dfsb_settings' => 'dfsb_settings'" in mirror)
    read_abilities = sorted(set(re.findall(r"'(etg-dfsb/[^']+)'", read_source)))
    write_abilities = sorted(set(re.findall(r"'(etg-dfsb/[^']+)'", write_source)))
    return {
        "contract": "mad4b.provider-consumer-readiness.v1",
        "canonical_provider_id": "dynamic-filter-seo-bridge",
        "legacy_provider_id": "etg-dfsb",
        "neutral_plugin_version": canonical_version,
        "registered_candidate_version": candidate_version,
        "registered_candidate_source_commit_sha": candidate_source_sha,
        "candidate_source_version_matches_checkout": candidate_matches_source,
        "candidate_version_drift": not candidate_matches_source,
        "native_package_certification": descriptor.get("package_certification", "missing"),
        "native_exact_package_evidence_present": exact_certified,
        "legacy_read_supported_version": legacy_read_version,
        "legacy_write_supported_version": legacy_write_version,
        "legacy_version_alias_present": aliases,
        "legacy_read_class_reviewed": read_registered,
        "legacy_write_class_reviewed": write_registered,
        "legacy_read_skill_present": bool(legacy_skills),
        "legacy_option_mirror_present": legacy_shadow,
        "legacy_read_abilities": read_abilities,
        "legacy_write_abilities": write_abilities,
        "legacy_read_version_compatible": bool(aliases and legacy_read_version == canonical_version),
        "legacy_write_version_compatible": bool(aliases and legacy_write_version == canonical_version),
        "safe_to_cutover": False,
        "new_write_authority": False,
        "source_only_audit": True,
        "next_gate": "reconcile_deployed_control_plane_and_certify_native_neutral_adapter",
    }


def current():
    return audit(
        READ_ADAPTER.read_text(encoding="utf-8"),
        WRITE_ADAPTER.read_text(encoding="utf-8"),
        NEUTRAL_ENTRY.read_text(encoding="utf-8"),
        json.loads(REGISTRY.read_text(encoding="utf-8")),
        json.loads(SKILLS.read_text(encoding="utf-8")),
        MIRROR.read_text(encoding="utf-8"),
        json.loads(SOURCES.read_text(encoding="utf-8")),
    )


if __name__ == "__main__":
    print(json.dumps(current(), indent=2, sort_keys=True))
