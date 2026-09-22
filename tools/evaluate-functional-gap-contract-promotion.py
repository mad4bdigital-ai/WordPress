#!/usr/bin/env python3
import argparse
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
POLICY_PATH = ROOT / "wp-content/plugins/mad4b-site-control-plane/config/functional-gap-policy.json"

def load(path):
    return json.loads(Path(path).read_text("utf-8"))

def sha256_file(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()

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

def route_methods(runtime):
    return {row.get("route", ""): set(row.get("methods", [])) for row in runtime.get("rest_routes", [])}

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
    options = runtime.get("option_presence", {})
    repo_families = repo.get("families", {})
    decisions = []
    blockers = []

    for family, rule in sorted(policy.get("families", {}).items()):
        mode = str(rule.get("evaluation_mode", ""))
        rows = active_plugins(runtime, family)
        versions = plugin_versions(runtime, family)
        matches = tree_matches(repo_families.get(family, {}), rows)
        base = {"runtime_versions": versions, "exact_tree_matches": matches}

        if not rows:
            decisions.append(decision(family, "not_active", "provider_not_active", mode, **base))
            continue
        if not rows_stable(rows):
            decisions.append(decision(family, "runtime_evidence_unstable", "runtime_tree_changed_or_scan_failed", mode, **base))
            continue

        if mode == "bounded_read_routes":
            required = list(rule.get("required_get_routes", []))
            missing = sorted(route for route in required if "GET" not in routes.get(route, set()))
            extra = dict(base)
            extra.update({
                "missing_get_routes": missing,
                "safe_now": list(rule.get("safe_now", [])),
                "blocked": list(rule.get("blocked", [])),
            })
            if len(matches) == len(rows) and not missing:
                decisions.append(decision(family, "read_contract_candidate", "exact_runtime_tree_and_required_get_routes_verified", mode, **extra))
            else:
                decisions.append(decision(family, "contract_discovery_required", "exact_runtime_tree_or_required_get_routes_unverified", mode, **extra))

        elif mode == "redacted_status":
            secret_keys = list(rule.get("redacted_secret_option_keys", []))
            status_keys = list(rule.get("required_status_option_keys", []))
            secret_ok = bool(secret_keys) and all(options.get(key, {}).get("redacted") is True for key in secret_keys)
            status_ok = bool(status_keys) and all(key in options for key in status_keys)
            extra = dict(base)
            extra.update({
                "secret_redaction_verified": secret_ok,
                "status_model_verified": status_ok,
                "safe_now": list(rule.get("safe_now", [])),
                "blocked": list(rule.get("blocked", [])),
            })
            if len(matches) == len(rows) and secret_ok and status_ok:
                decisions.append(decision(family, "redacted_read_contract_candidate", "exact_runtime_tree_and_secret_redaction_verified", mode, **extra))
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
            decisions.append(decision(
                family,
                "semantic_attestation_required",
                "premium_provider_exact_runtime_and_semantic_review_gate_remains_authoritative",
                mode,
                **base,
            ))

        elif mode == "composite_behavioral":
            decisions.append(decision(
                family,
                "runtime_alignment_or_behavioral_recertification_required",
                "composite_provider_requires_exact_component_versions_and_behavioral_execution_contract",
                mode,
                **base,
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
        "decisions": decisions,
    }
    fingerprint_json = json.dumps(
        fingerprint_payload,
        sort_keys=True,
        separators=(",", ":"),
        ensure_ascii=False,
    ).encode("utf-8")
    decision_fingerprint = hashlib.sha256(fingerprint_json).hexdigest()

    counts = {}
    for row in decisions:
        counts[row["state"]] = counts.get(row["state"], 0) + 1

    output = {
        "contract": "mad4b.functional-gap-promotion-evaluation.v2",
        "policy_contract": policy["contract"],
        "policy_sha256": policy_sha,
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
