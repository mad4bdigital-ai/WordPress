#!/usr/bin/env python3
import argparse
import base64
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
POLICY_PATH = ROOT / "wp-content/plugins/mad4b-site-control-plane/config/functional-gap-policy.json"

def load(path):
    return json.loads(Path(path).read_text("utf-8"))

def sha256_file(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()

def canonical_digest_lines(value, path=""):
    if value is None:
        return [f"{path}\tn:"]
    if isinstance(value, bool):
        return [f"{path}\tb:{1 if value else 0}"]
    if isinstance(value, int):
        return [f"{path}\ti:{value}"]
    if isinstance(value, float):
        return [f"{path}\tf:{format(value, '.17g')}"]
    if isinstance(value, str):
        encoded = base64.b64encode(value.encode("utf-8")).decode("ascii")
        return [f"{path}\ts:{encoded}"]
    if isinstance(value, list):
        lines = [f"{path}\tl:{len(value)}"]
        for index, item in enumerate(value):
            lines.extend(canonical_digest_lines(item, f"{path}/i:{index}"))
        return lines
    if isinstance(value, dict):
        if not value:
            # PHP associative decoding cannot distinguish {} from [] once both
            # become an empty array. The cross-language protocol therefore
            # canonicalizes every empty container to the list token l:0.
            return [f"{path}\tl:0"]
        ordered = sorted(((str(key), item) for key, item in value.items()), key=lambda item: item[0])
        lines = [f"{path}\tm:{len(ordered)}"]
        for key, item in ordered:
            encoded_key = base64.b64encode(key.encode("utf-8")).decode("ascii")
            lines.extend(canonical_digest_lines(item, f"{path}/k:{encoded_key}"))
        return lines
    encoded = base64.b64encode(str(value).encode("utf-8")).decode("ascii")
    return [f"{path}\ts:{encoded}"]

def canonical_digest(value):
    material = ("\n".join(canonical_digest_lines(value)) + "\n").encode("utf-8")
    return hashlib.sha256(material).hexdigest()

def active_plugins(runtime, family):
    return [row for row in runtime.get("families", {}).get(family, []) if row.get("active")]

def plugin_versions(runtime, family):
    return sorted({str(row.get("version", "")) for row in active_plugins(runtime, family) if row.get("version")})

def rows_stable(rows):
    if not rows:
        return False
    for row in rows:
        tree = row.get("plugin_tree", {})
        if tree.get("scan_stable") is False:
            return False
        if tree.get("error"):
            return False
    return True

def tree_matches(repo_family, runtime_rows):
    artifact_trees = {}
    for row in repo_family.get("artifacts", []):
        digest = str(row.get("package_tree", {}).get("tree_sha256", "")).lower()
        if len(digest) != 64:
            continue
        version = next((str(h.get("version", "")) for h in row.get("plugin_headers", []) if h.get("version")), "")
        artifact_trees[digest] = {"archive": row.get("archive", ""), "version": version}
    matches = []
    for plugin in runtime_rows:
        tree = plugin.get("plugin_tree", {})
        if tree.get("scan_stable") is False or tree.get("error"):
            continue
        digest = str(tree.get("tree_sha256", "")).lower()
        if digest not in artifact_trees:
            continue
        matches.append({
            "plugin_file": plugin.get("plugin_file", ""),
            "version": plugin.get("version", ""),
            "tree_sha256": digest,
            "repository_archive": artifact_trees[digest]["archive"],
            "repository_version": artifact_trees[digest]["version"],
        })
    return matches

def normalize_plugin_file(value):
    return str(value or "").replace("\\", "/").lstrip("/").lower()

def runtime_tree_evidence(rows):
    out = []
    for row in rows:
        tree = row.get("plugin_tree", {}) if isinstance(row, dict) else {}
        out.append({
            "plugin_file": normalize_plugin_file(row.get("plugin_file", "")),
            "version": str(row.get("version", "")),
            "tree_sha256": str(tree.get("tree_sha256", "")),
            "file_count": int(tree.get("file_count", 0) or 0),
            "total_bytes": int(tree.get("total_bytes", 0) or 0),
            "scan_stable": bool(tree.get("scan_stable")),
            "comparison": str(tree.get("comparison", "")),
        })
    return sorted(out, key=lambda row: row["plugin_file"])

def runtime_evidence_fingerprint(runtime):
    families = {}
    for family, rows in sorted((runtime.get("families") or {}).items()):
        normalized = []
        for row in rows if isinstance(rows, list) else []:
            tree = row.get("plugin_tree", {}) if isinstance(row, dict) else {}
            normalized.append({
                "plugin_file": normalize_plugin_file(row.get("plugin_file", "")),
                "version": str(row.get("version", "")),
                "active": bool(row.get("active")),
                "tree_sha256": str(tree.get("tree_sha256", "")).lower(),
                "file_count": int(tree.get("file_count", 0) or 0),
                "total_bytes": int(tree.get("total_bytes", 0) or 0),
                "scan_stable": bool(tree.get("scan_stable")),
                "comparison": str(tree.get("comparison", "")),
                "error": str(tree.get("error", "")),
            })
        families[str(family)] = sorted(normalized, key=lambda row: row["plugin_file"])

    routes = []
    for row in runtime.get("rest_routes", []) or []:
        if not isinstance(row, dict) or not row.get("route"):
            continue
        routes.append({
            "route": str(row.get("route", "")),
            "methods": sorted({str(method).upper() for method in row.get("methods", [])}),
            "get_permission_callbacks": sorted({str(item) for item in row.get("get_permission_callbacks", [])}),
            "get_permission_missing": bool(row.get("get_permission_missing")),
            "get_permission_public": bool(row.get("get_permission_public")),
        })
    routes.sort(key=lambda row: row["route"])

    ajax = sorted({str(item) for item in (runtime.get("ajax_hooks") or [])})
    options = dict(sorted((runtime.get("option_presence") or {}).items()))
    constants = dict(sorted((runtime.get("constants") or {}).items()))

    cron = []
    for row in runtime.get("cron_hooks", []) or []:
        if not isinstance(row, dict) or not row.get("hook"):
            continue
        cron.append({
            "hook": str(row.get("hook", "")),
            "event_count": int(row.get("event_count", 0) or 0),
        })
    cron.sort(key=lambda row: (row["hook"], row["event_count"]))

    payload = {
        "contract": "mad4b.runtime-functional-gap-evidence.v2",
        "families": families,
        "rest_routes": routes,
        "ajax_hooks": ajax,
        "option_presence": options,
        "cron_hooks": cron,
        "constants": constants,
    }
    return canonical_digest(payload)

def route_methods(runtime):
    return {row.get("route", ""): set(row.get("methods", [])) for row in runtime.get("rest_routes", [])}

def route_security(runtime):
    out = {}
    for row in runtime.get("rest_routes", []) or []:
        if not isinstance(row, dict) or not row.get("route"):
            continue
        out[str(row.get("route", ""))] = {
            "callbacks": sorted({str(item) for item in row.get("get_permission_callbacks", [])}),
            "missing": bool(row.get("get_permission_missing")),
            "public": bool(row.get("get_permission_public")),
        }
    return out

def decision(family, state, reason, mode, **extra):
    out = {
        "family": family,
        "state": state,
        "reason": reason,
        "evaluation_mode": mode,
    }
    out.update(extra)
    return out

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--repository-evidence", required=True)
    ap.add_argument("--runtime-diagnostic", required=True)
    ap.add_argument("--output", required=True)
    args = ap.parse_args()

    repo = load(args.repository_evidence)
    runtime = load(args.runtime_diagnostic)
    policy = load(POLICY_PATH)

    if policy.get("contract") != "mad4b.functional-gap-policy.v1":
        raise SystemExit("functional-gap policy contract mismatch")
    if policy.get("default_mutation") != "deny" or policy.get("promotion_authorized") is not False:
        raise SystemExit("functional-gap policy mutation/promotion boundary weakened")
    supported_modes = {"bounded_read_routes","redacted_status","exact_tree_review","runtime_only","premium_semantic","composite_behavioral"}
    for family, rule in sorted((policy.get("families") or {}).items()):
        if not isinstance(rule, dict) or str(rule.get("evaluation_mode", "")) not in supported_modes:
            raise SystemExit(f"{family}: unsupported functional-gap evaluation mode")
        if rule.get("evaluation_mode") == "bounded_read_routes":
            if rule.get("require_non_public_permissions") is not True:
                raise SystemExit(f"{family}: bounded read permission boundary missing")
            required_routes = list(rule.get("required_get_routes", []))
            callback_map = rule.get("required_get_permission_callbacks") or {}
            if not required_routes or not isinstance(callback_map, dict):
                raise SystemExit(f"{family}: bounded read callback identity policy missing")
            for route in required_routes:
                callbacks = callback_map.get(route) or []
                if not isinstance(callbacks, list) or not [str(x).strip() for x in callbacks if str(x).strip()]:
                    raise SystemExit(f"{family}: bounded read callback identity missing for {route}")
        if rule.get("evaluation_mode") in {"premium_semantic","composite_behavioral"}:
            if rule.get("repository_evidence") is not True or not rule.get("repository_artifacts"):
                raise SystemExit(f"{family}: identity-first provider gate requires repository evidence")
    policy_sha = sha256_file(POLICY_PATH)

    if repo.get("contract") != "mad4b.functional-gap-contract-evidence.v1":
        raise SystemExit("repository evidence contract mismatch")
    if repo.get("policy_contract") != policy.get("contract"):
        raise SystemExit("repository evidence policy contract mismatch")
    if repo.get("policy_sha256") != policy_sha:
        raise SystemExit("repository evidence policy SHA mismatch")

    runtime_contract = runtime.get("contract", "")
    if runtime_contract not in {
        "mad4b.runtime-functional-gap-diagnostic.v1",
        "mad4b.runtime-functional-gap-evidence.v2",
    }:
        raise SystemExit("runtime diagnostic contract mismatch")

    safety = runtime.get("safety", {})
    if safety != {
        "mutation_performed": False,
        "remote_request_performed": False,
        "secret_values_returned": False,
        "raw_sql_performed": False,
    }:
        raise SystemExit("runtime diagnostic safety declaration mismatch")

    routes = route_methods(runtime)
    route_permissions = route_security(runtime)
    options = runtime.get("option_presence", {})
    repo_families = repo.get("families", {})
    decisions = []
    blockers = []
    runtime_fingerprint = runtime_evidence_fingerprint(runtime)
    if len(runtime_fingerprint) != 64:
        blockers.append("runtime_evidence_fingerprint_unavailable")

    for family, rule in sorted(policy.get("families", {}).items()):
        mode = str(rule.get("evaluation_mode", ""))
        rows = active_plugins(runtime, family)
        versions = plugin_versions(runtime, family)
        matches = tree_matches(repo_families.get(family, {}), rows)
        base = {
            "runtime_versions": versions,
            "runtime_tree_evidence": runtime_tree_evidence(rows),
            "exact_tree_matches": matches,
        }

        if not rows:
            decisions.append(decision(family, "not_active", "provider_not_active", mode, **base))
            continue
        if not rows_stable(rows):
            decisions.append(decision(family, "runtime_evidence_unstable", "runtime_tree_changed_or_scan_failed", mode, **base))
            continue

        if mode == "bounded_read_routes":
            required = list(rule.get("required_get_routes", []))
            missing = sorted(route for route in required if "GET" not in routes.get(route, set()))
            require_non_public = rule.get("require_non_public_permissions") is True
            callback_map = rule.get("required_get_permission_callbacks") or {}
            insecure = []
            callback_mismatch = []
            permission_evidence = {}
            for route in required:
                if route in missing:
                    continue
                security = dict(route_permissions.get(route, {"callbacks": [], "missing": True, "public": False}))
                actual_callbacks = sorted({str(x).strip().lower() for x in security.get("callbacks", []) if str(x).strip()})
                expected_callbacks = sorted({str(x).strip().lower() for x in callback_map.get(route, []) if str(x).strip()})
                security["expected_callbacks"] = expected_callbacks
                security["callback_identity_match"] = bool(expected_callbacks) and actual_callbacks == expected_callbacks
                permission_evidence[route] = security
                if require_non_public and (security.get("missing") or security.get("public") or not actual_callbacks):
                    insecure.append(route)
                if not expected_callbacks or actual_callbacks != expected_callbacks:
                    callback_mismatch.append(route)
            extra = dict(base)
            extra.update({
                "missing_get_routes": missing,
                "insecure_get_routes": sorted(insecure),
                "permission_callback_mismatch": sorted(callback_mismatch),
                "route_permission_evidence": dict(sorted(permission_evidence.items())),
                "safe_now": list(rule.get("safe_now", [])),
                "blocked": list(rule.get("blocked", [])),
            })
            if len(matches) == len(rows) and not missing and not insecure and not callback_mismatch:
                decisions.append(decision(family, "read_contract_candidate", "exact_runtime_tree_required_get_routes_and_permission_callbacks_verified", mode, **extra))
            else:
                decisions.append(decision(family, "contract_discovery_required", "exact_runtime_tree_routes_or_permission_callback_boundary_unverified", mode, **extra))

        elif mode == "redacted_status":
            secret_keys = list(rule.get("redacted_secret_option_keys", []))
            status_keys = list(rule.get("required_status_option_keys", []))
            secret_ok = bool(secret_keys) and all(
                options.get(key, {}).get("redacted") is True
                and options.get(key, {}).get("value_read") is False
                and options.get(key, {}).get("probe") == "omitted_secret_value"
                for key in secret_keys
            )
            status_ok = bool(status_keys) and all(key in options and options.get(key, {}).get("exists") is True for key in status_keys)
            extra = dict(base)
            extra.update({
                "secret_redaction_verified": secret_ok,
                "status_model_verified": status_ok,
                "safe_now": list(rule.get("safe_now", [])),
                "blocked": list(rule.get("blocked", [])),
            })
            if len(matches) == len(rows) and secret_ok and status_ok:
                decisions.append(decision(family, "redacted_read_contract_candidate", "exact_runtime_tree_and_secret_values_unread", mode, **extra))
            else:
                decisions.append(decision(family, "contract_discovery_required", "exact_runtime_tree_or_redacted_status_model_unverified", mode, **extra))

        elif mode == "exact_tree_review":
            if len(matches) == len(rows):
                decisions.append(decision(family, "contract_evidence_review", "exact_runtime_tree_verified_but_semantic_contract_not_yet_promoted", mode, **base))
            else:
                decisions.append(decision(family, "runtime_alignment_required", "live_runtime_tree_does_not_match_repository_evidence", mode, **base))

        elif mode == "runtime_only":
            decisions.append(decision(
                family,
                "runtime_contract_evidence_captured",
                "runtime_only_provider_requires_semantic_contract_before_specialized_surface",
                mode,
                **base,
                plugin_files=[row.get("plugin_file", "") for row in rows],
                tree_sha256=[row.get("plugin_tree", {}).get("tree_sha256", "") for row in rows],
            ))

        elif mode == "premium_semantic":
            if len(matches) != len(rows):
                decisions.append(decision(
                    family,
                    "runtime_alignment_required",
                    "premium_provider_runtime_tree_does_not_match_repository_identity",
                    mode,
                    **base,
                ))
            else:
                decisions.append(decision(
                    family,
                    "semantic_attestation_required",
                    "premium_provider_exact_repository_identity_verified_semantic_review_still_required",
                    mode,
                    **base,
                ))

        elif mode == "composite_behavioral":
            expected_artifacts = sorted(set(str(x) for x in rule.get("repository_artifacts", []) if x))
            matched_artifacts = sorted(set(
                str(match.get("repository_archive", ""))
                for match in matches
                if match.get("repository_archive")
            ))
            component_identity_exact = (
                len(matches) == len(rows)
                and matched_artifacts == expected_artifacts
            )
            extra = dict(base)
            extra.update({
                "expected_repository_artifacts": expected_artifacts,
                "matched_repository_artifacts": matched_artifacts,
                "component_identity_exact": component_identity_exact,
            })
            if not component_identity_exact:
                decisions.append(decision(
                    family,
                    "runtime_alignment_required",
                    "composite_provider_runtime_components_do_not_match_repository_identity",
                    mode,
                    **extra,
                ))
            else:
                decisions.append(decision(
                    family,
                    "behavioral_recertification_required",
                    "composite_provider_exact_component_identity_verified_behavioral_execution_contract_still_required",
                    mode,
                    **extra,
                ))

        else:
            blockers.append("unsupported_evaluation_mode_" + (mode or "missing"))
            decisions.append(decision(
                family,
                "evidence_unavailable",
                "functional_gap_policy_evaluation_mode_unsupported",
                mode,
                **base,
            ))

    decisions = sorted(decisions, key=lambda row: str(row.get("family", "")))
    fingerprint_payload = {
        "contract": "mad4b.functional-gap-promotion-evaluation.v2",
        "policy_sha256": policy_sha,
        "repository_evidence_sha256": sha256_file(args.repository_evidence),
        "repository_source_commit_sha": repo.get("source_commit_sha", ""),
        "runtime_evidence_fingerprint": runtime_fingerprint,
        "decisions": decisions,
    }
    decision_fingerprint = canonical_digest(fingerprint_payload)

    counts = {}
    for row in decisions:
        counts[row["state"]] = counts.get(row["state"], 0) + 1

    output = {
        "contract": "mad4b.functional-gap-promotion-evaluation.v2",
        "policy_contract": policy["contract"],
        "policy_sha256": policy_sha,
        "runtime_evidence_fingerprint": runtime_fingerprint,
        "decision_fingerprint": decision_fingerprint,
        "repository_source_commit_sha": repo.get("source_commit_sha", ""),
        "runtime_generated_at": runtime.get("generated_at", ""),
        "ready": not blockers,
        "counts": dict(sorted(counts.items())),
        "decisions": decisions,
        "blockers": sorted(set(blockers)),
        "promotion_authorized": False,
        "production_mutation": False,
    }
    Path(args.output).write_text(json.dumps(output, indent=2, sort_keys=True) + "\n", "utf-8")
    print(json.dumps(output, indent=2, sort_keys=True))

if __name__ == "__main__":
    main()
