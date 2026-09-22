#!/usr/bin/env python3
import argparse, json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CERTIFIED = ROOT / "wp-content/plugins/mad4b-site-control-plane/config/certified-providers.json"

def load(path):
    return json.loads(Path(path).read_text("utf-8"))

def plugin_versions(runtime, family):
    return sorted({
        str(row.get("version", ""))
        for row in runtime.get("families", {}).get(family, [])
        if row.get("active") and row.get("version")
    })

def active_plugins(runtime, family):
    return [row for row in runtime.get("families", {}).get(family, []) if row.get("active")]

def tree_matches(repo_family, runtime_rows):
    artifact_trees = {
        row.get("package_tree", {}).get("tree_sha256"): {
            "archive": row.get("archive", ""),
            "version": next((h.get("version","") for h in row.get("plugin_headers",[]) if h.get("version")), ""),
        }
        for row in repo_family.get("artifacts", [])
        if len(row.get("package_tree", {}).get("tree_sha256", "")) == 64
    }
    matches=[]
    for plugin in runtime_rows:
        digest=plugin.get("plugin_tree",{}).get("tree_sha256","")
        if digest in artifact_trees:
            matches.append({
                "plugin_file":plugin.get("plugin_file",""),
                "version":plugin.get("version",""),
                "tree_sha256":digest,
                "repository_archive":artifact_trees[digest]["archive"],
                "repository_version":artifact_trees[digest]["version"],
            })
    return matches

def route_methods(runtime):
    return {row.get("route",""): set(row.get("methods",[])) for row in runtime.get("rest_routes",[])}

def candidate(family, state, reason, **extra):
    out={"family":family,"state":state,"reason":reason}
    out.update(extra)
    return out

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--repository-evidence",required=True)
    ap.add_argument("--runtime-diagnostic",required=True)
    ap.add_argument("--output",required=True)
    args=ap.parse_args()

    repo=load(args.repository_evidence)
    runtime=load(args.runtime_diagnostic)
    certified=load(CERTIFIED)

    if repo.get("contract")!="mad4b.functional-gap-contract-evidence.v1":
        raise SystemExit("repository evidence contract mismatch")
    if runtime.get("contract")!="mad4b.runtime-functional-gap-diagnostic.v1":
        raise SystemExit("runtime diagnostic contract mismatch")
    safety=runtime.get("safety",{})
    if safety != {
        "mutation_performed": False,
        "remote_request_performed": False,
        "secret_values_returned": False,
        "raw_sql_performed": False,
    }:
        raise SystemExit("runtime diagnostic safety declaration mismatch")

    decisions=[]
    routes=route_methods(runtime)

    # Bulk taxonomy editor: exact tree + documented GET endpoints may graduate
    # to a read-only provider contract. POST/write endpoints remain blocked.
    family="bulk-taxonomy-editor"
    rows=active_plugins(runtime,family)
    matches=tree_matches(repo["families"][family],rows)
    required={
        "/bulk-taxonomy-editor/v1/posts",
        "/bulk-taxonomy-editor/v1/taxonomies",
        "/bulk-taxonomy-editor/v1/terms",
    }
    missing=sorted(route for route in required if "GET" not in routes.get(route,set()))
    if rows and len(matches)==len(rows) and not missing:
        decisions.append(candidate(
            family,"read_contract_candidate",
            "exact_runtime_tree_and_required_get_routes_verified",
            exact_tree_matches=matches,
            safe_now=["plugin_status_read","posts_read","taxonomies_read","terms_read"],
            blocked=["post_create_or_update","bulk_taxonomy_write","term_relationship_mutation","taxonomy_delete_or_merge"],
        ))
    else:
        decisions.append(candidate(
            family,"contract_discovery_required",
            "exact_runtime_tree_or_required_get_routes_unverified",
            exact_tree_matches=matches,missing_get_routes=missing,
        ))

    # WPL may expose only redacted/local verification status when the exact code
    # tree matches. Credentials, installs and outbound operations remain denied.
    family="wpl-client"
    rows=active_plugins(runtime,family)
    matches=tree_matches(repo["families"][family],rows)
    options=runtime.get("option_presence",{})
    secret_ok=all(options.get(k,{}).get("redacted") is True for k in ("wpl_access_token","wpl_api_key"))
    status_keys=("wpl_serial_verified","wpl_verified_order_number","wpl_verified_order_numbers","wpl_verified_wpl_ids")
    if rows and len(matches)==len(rows) and secret_ok and all(k in options for k in status_keys):
        decisions.append(candidate(
            family,"redacted_read_contract_candidate",
            "exact_runtime_tree_and_secret_redaction_verified",
            exact_tree_matches=matches,
            safe_now=["plugin_status_read","license_verification_state_read_redacted"],
            blocked=["credential_read","remote_execution","plugin_or_theme_install","plugin_toggle","filesystem_write","external_write"],
        ))
    else:
        decisions.append(candidate(
            family,"contract_discovery_required",
            "exact_runtime_tree_or_redacted_status_model_unverified",
            exact_tree_matches=matches,secret_redaction_verified=secret_ok,
        ))

    # Exact package identity is required before repository-derived semantics may
    # be applied to these families.
    for family in ("custom-mega-menu","google-tag-manager","meta-catalog-feed-mapper","rank-math"):
        rows=active_plugins(runtime,family)
        matches=tree_matches(repo["families"][family],rows)
        if rows and len(matches)==len(rows):
            state="contract_evidence_review"
            reason="exact_runtime_tree_verified_but_semantic_contract_not_yet_promoted"
        else:
            state="runtime_alignment_required"
            reason="live_runtime_tree_does_not_match_repository_evidence"
        decisions.append(candidate(
            family,state,reason,
            runtime_versions=plugin_versions(runtime,family),
            exact_tree_matches=matches,
        ))

    # Runtime-only families stay non-authorizing. The diagnostic provides
    # identity/tree evidence but cannot by itself certify business semantics.
    for family in ("duplicator","elementskit","heic-support","hostinger-ai","hostinger-onboarding","hostinger-reach","wordpress-importer"):
        rows=active_plugins(runtime,family)
        decisions.append(candidate(
            family,
            "runtime_contract_evidence_captured" if rows else "not_active",
            "runtime_only_provider_requires_semantic_contract_before_specialized_surface",
            runtime_versions=plugin_versions(runtime,family),
            plugin_files=[r.get("plugin_file","") for r in rows],
            tree_sha256=[r.get("plugin_tree",{}).get("tree_sha256","") for r in rows],
        ))

    # Specialized safety blockers.
    for family,key in (("jetengine","jetengine"),("jetsmartfilters","jetsmartfilters")):
        rows=active_plugins(runtime,family)
        decisions.append(candidate(
            family,"semantic_attestation_required",
            "premium_provider_exact_runtime_and_semantic_review_gate_remains_authoritative",
            runtime_versions=plugin_versions(runtime,family),
            certified_version=certified.get("providers",{}).get(key,{}).get("version",""),
        ))

    composite=certified.get("providers",{}).get("wp-import-export",{}).get("components",{})
    expected_versions=sorted(v.get("version","") for v in composite.values() if v.get("version"))
    observed=plugin_versions(runtime,"wp-import-export")
    decisions.append(candidate(
        "wp-import-export",
        "behavioral_recertification_required" if observed==expected_versions else "runtime_alignment_required",
        "composite_provider_requires_exact_component_versions_and_behavioral_execution_contract",
        runtime_versions=observed,
        certified_component_versions=expected_versions,
    ))

    output={
        "contract":"mad4b.functional-gap-promotion-evaluation.v1",
        "repository_source_commit_sha":repo.get("source_commit_sha",""),
        "runtime_generated_at":runtime.get("generated_at",""),
        "decisions":decisions,
        "promotion_authorized":False,
        "production_mutation":False,
    }
    Path(args.output).write_text(json.dumps(output,indent=2,sort_keys=True)+"\n","utf-8")
    print(json.dumps(output,indent=2,sort_keys=True))

if __name__=="__main__":
    main()
