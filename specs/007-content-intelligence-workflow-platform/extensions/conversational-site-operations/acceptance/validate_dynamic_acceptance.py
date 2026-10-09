#!/usr/bin/env python3
"""Offline extensible CSO01 acceptance configuration validator.

Never connects to WordPress or certifies evidence. A valid specification is not
a PASS receipt; only an independent approved verifier can close a gate.
"""
import argparse
import copy
import json
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parent.parent
ALLOWED_TYPES = {"native_php", "privacy_audit", "provider_contract", "performance",
                 "runtime_mcp", "host_attestation", "client_browser",
                 "independent_review", "source_review"}
PARENT_GATES = {"CSO-G" + str(n) for n in range(11)}

def read_json(path):
    return json.loads(Path(path).read_text(encoding="utf-8"))

def _cycle(graph):
    visiting, visited = set(), set()
    def walk(node):
        if node in visiting:
            return True
        if node in visited:
            return False
        visiting.add(node)
        if any(walk(dep) for dep in graph.get(node, ()) if dep in graph):
            return True
        visiting.remove(node)
        visited.add(node)
        return False
    return any(walk(node) for node in graph)

def validate_bundle(registry, profiles):
    faults = []
    if registry.get("contract") != "mad4b.cso01.dynamic-acceptance.registry.v1":
        faults.append("dynamic_registry_contract")
    if profiles.get("contract") != "mad4b.cso01.dynamic-acceptance.profiles.v1":
        faults.append("dynamic_profiles_contract")
    for label, obj in (("registry", registry), ("profiles", profiles)):
        if obj.get("status") != "SPEC_ONLY_OPEN":
            faults.append("dynamic_status_must_remain_open:" + label)
    if registry.get("release_authorizing") is not False or registry.get("allow_site_override_disable") is not False:
        faults.append("dynamic_authority_bypass")
    if registry.get("external_acceptance_required") is not True or registry.get("acceptance_result_source") != "independent_approved_verifier_only":
        faults.append("dynamic_independent_evidence_bypass")
    if not set(("exact_head","artifact_sha256","site_uuid","environment","source_generation",
                "observed_at","verifier_identity","signature_validation")).issubset(set(registry.get("required_provenance", []))):
        faults.append("dynamic_provenance_missing")
    if set(registry.get("allowed_evidence_types", [])) != ALLOWED_TYPES:
        faults.append("dynamic_evidence_type_contract")
    defs = registry.get("threshold_definitions")
    if not isinstance(defs, dict) or not defs:
        faults.append("dynamic_threshold_definitions_invalid")
        defs = {}
    for key, rule in defs.items():
        if not isinstance(rule, dict) or rule.get("direction") not in ("min", "max") or not isinstance(rule.get("default"), (int, float)) or isinstance(rule.get("default"), bool) or rule["default"] <= 0:
            faults.append("dynamic_threshold_invalid:" + str(key))
    suites = registry.get("suites")
    if not isinstance(suites, list) or len(suites) < 7:
        faults.append("dynamic_suite_inventory_invalid")
        suites = []
    names = [s.get("id") for s in suites if isinstance(s, dict)]
    if len(names) != len(suites) or len(names) != len(set(names)) or any(not isinstance(n, str) or not re.fullmatch(r"CSO-A[0-9]{2,}", n) for n in names):
        faults.append("dynamic_duplicate_suite")
    required_ids = {"CSO-A0" + str(n) for n in range(1, 8)}
    if not required_ids.issubset(set(names)):
        faults.append("dynamic_missing_initial_suite")
    graph, available_checks = {}, {}
    for row in suites:
        if not isinstance(row, dict):
            faults.append("dynamic_suite_invalid")
            continue
        key = row.get("id")
        deps = row.get("depends_on")
        if not isinstance(deps, list) or key in deps or not set(deps).issubset(set(names)):
            faults.append("dynamic_suite_dependency:" + str(key))
            deps = []
        graph[key] = deps
        if row.get("status") != "OPEN" or row.get("claim_complete") is not False or row.get("authorizing") is not False or row.get("required") is not True:
            faults.append("dynamic_suite_false_pass:" + str(key))
        parents = row.get("parent_gates")
        if not isinstance(parents, list) or not parents or not set(parents).issubset(PARENT_GATES):
            faults.append("dynamic_parent_gate_invalid:" + str(key))
        checks = row.get("checks")
        if not isinstance(checks, list) or len(checks) < 3:
            faults.append("dynamic_suite_checks_missing:" + str(key))
            continue
        for check in checks:
            if not isinstance(check, dict):
                faults.append("dynamic_check_invalid:" + str(key))
                continue
            cid = check.get("id")
            if not isinstance(cid, str) or not re.fullmatch(r"[a-z][a-z0-9_]{4,90}", cid) or cid in available_checks:
                faults.append("dynamic_check_id_invalid:" + str(cid))
            available_checks[cid] = check
            if check.get("polarity") not in ("positive", "negative", "performance") or check.get("evidence_type") not in ALLOWED_TYPES:
                faults.append("dynamic_check_evidence_invalid:" + str(cid))
            if not isinstance(check.get("required_by_default"), bool) or check.get("accepted") is not False:
                faults.append("dynamic_check_false_pass:" + str(cid))
    if _cycle(graph):
        faults.append("dynamic_suite_cycle")
    if not all(any(c.get("required_by_default") is True for c in s.get("checks", []) if isinstance(c, dict)) for s in suites if isinstance(s, dict)):
        faults.append("dynamic_required_checks_missing")
    pitems = profiles.get("profiles")
    if not isinstance(pitems, list):
        faults.append("dynamic_profile_inventory_invalid")
        pitems = []
    pmap = {p.get("id"): p for p in pitems if isinstance(p, dict)}
    if len(pmap) != len(pitems) or "baseline" not in pmap:
        faults.append("dynamic_profile_duplicates_or_missing_baseline")
    if pmap.get("baseline", {}).get("extends") is not None or pmap.get("baseline", {}).get("thresholds") != {} or pmap.get("baseline", {}).get("additional_checks") != []:
        faults.append("dynamic_baseline_must_be_immutable")
    pgraph = {}
    for name, profile in pmap.items():
        parent = profile.get("extends")
        pgraph[name] = [parent] if isinstance(parent, str) else []
        if name != "baseline" and parent not in pmap:
            faults.append("dynamic_profile_parent_unknown:" + str(name))
        extra = profile.get("additional_checks")
        if not isinstance(extra, list) or len(extra) != len(set(extra)) or not set(extra).issubset(set(available_checks)):
            faults.append("dynamic_profile_checks_invalid:" + str(name))
        if not isinstance(profile.get("thresholds"), dict):
            faults.append("dynamic_profile_thresholds_invalid:" + str(name))
        else:
            for key, value in profile["thresholds"].items():
                rule = defs.get(key)
                if not rule or not isinstance(value, (int, float)) or isinstance(value, bool) or value <= 0:
                    faults.append("dynamic_profile_threshold_unknown:" + str(name) + ":" + str(key))
    if _cycle(pgraph):
        faults.append("dynamic_profile_cycle")
    if not faults:
        for name in pmap:
            try:
                resolve_profile(registry, profiles, name)
            except (ValueError, KeyError) as exc:
                faults.append("dynamic_profile_relaxation:" + name + ":" + str(exc))
    return sorted(set(faults))

def resolve_profile(registry, profiles, profile_id, site_overlay=None):
    pmap = {row["id"]: row for row in profiles["profiles"]}
    if profile_id not in pmap:
        raise ValueError("unknown_profile")
    defs = registry["threshold_definitions"]
    values = {key: rule["default"] for key, rule in defs.items()}
    required = {c["id"] for suite in registry["suites"] for c in suite["checks"] if c["required_by_default"]}
    known = {c["id"] for suite in registry["suites"] for c in suite["checks"]}
    def apply_thresholds(changes):
        for key, new in changes.items():
            if key not in defs or not isinstance(new, (int, float)) or isinstance(new, bool) or new <= 0:
                raise ValueError("invalid_threshold:" + str(key))
            direction = defs[key]["direction"]
            old = values[key]
            if (direction == "min" and new < old) or (direction == "max" and new > old):
                raise ValueError("weaker_threshold:" + key)
            values[key] = new
    seen = set()
    def lineage(name):
        if name in seen:
            raise ValueError("profile_cycle")
        seen.add(name)
        node = pmap[name]
        parent = node.get("extends")
        before = lineage(parent) if parent else []
        return before + [node]
    for node in lineage(profile_id):
        apply_thresholds(node["thresholds"])
        extra = node["additional_checks"]
        if not set(extra).issubset(known):
            raise ValueError("unknown_additional_check")
        required.update(extra)
    scope = None
    if site_overlay is not None:
        if site_overlay.get("contract") != "mad4b.cso01.dynamic-acceptance.site-overlay.v1" or site_overlay.get("profile_id") != profile_id:
            raise ValueError("site_overlay_contract_or_profile")
        if set(site_overlay) - {"contract","profile_id","scope","thresholds","additional_checks","note"}:
            raise ValueError("site_overlay_unsafe_key")
        scope = site_overlay.get("scope")
        if not isinstance(scope, dict) or set(scope) != {"site_uuid","environment","blog_id"}:
            raise ValueError("site_overlay_scope")
        if not isinstance(scope["site_uuid"], str) or not scope["site_uuid"] or scope["environment"] not in ("local","development","staging","production") or type(scope["blog_id"]) is not int or scope["blog_id"] < 1:
            raise ValueError("site_overlay_scope_invalid")
        changes = site_overlay.get("thresholds", {})
        if not isinstance(changes, dict):
            raise ValueError("site_overlay_thresholds_invalid")
        apply_thresholds(changes)
        extra = site_overlay.get("additional_checks", [])
        if not isinstance(extra, list) or not set(extra).issubset(known):
            raise ValueError("site_overlay_checks_invalid")
        required.update(extra)
    return {"profile_id":profile_id, "scope":scope, "thresholds":values,
            "required_checks":sorted(required),
            "suite_states":{s["id"]:"OPEN" for s in registry["suites"]},
            "operational_acceptance":"NOT_RUN",
            "release_authorized":False,
            "warning":"CONFIG_VALID_ONLY — no runtime evidence or approval"}

def validate_bundle_from_path(root=ROOT):
    faults = []
    try:
        reg = read_json(Path(root) / "acceptance" / "registry.json")
        profiles = read_json(Path(root) / "acceptance" / "profiles.json")
    except (OSError, ValueError, TypeError):
        return ["dynamic_acceptance_missing_or_invalid"]
    return validate_bundle(reg, profiles)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--profile", default="baseline")
    parser.add_argument("--site-overlay", help="Optional scoped tightening-only overlay JSON")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()
    faults = validate_bundle_from_path()
    if faults:
        for issue in faults:
            print("FAIL", issue)
        return 1
    registry = read_json(ROOT / "acceptance" / "registry.json")
    profiles = read_json(ROOT / "acceptance" / "profiles.json")
    try:
        overlay = read_json(args.site_overlay) if args.site_overlay else None
        result = resolve_profile(registry, profiles, args.profile, overlay)
    except (ValueError, KeyError, OSError) as exc:
        print("CONFIG_INVALID", str(exc))
        return 1
    if args.json:
        print(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True))
    else:
        print("CONFIG_VALID_ONLY profile=" + args.profile + " suites=" + str(len(result["suite_states"])) + " gates_open=" + str(len(result["suite_states"])))
    return 0

if __name__ == "__main__":
    sys.exit(main())
