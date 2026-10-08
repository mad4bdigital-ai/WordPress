#!/usr/bin/env python3
"""ACI01 design-closure contract checker: pure synthetic evidence, never authority.

The trust boundary is deliberate: this module cannot validate signatures or
query live MAD4B grants. All outputs are TEST_ONLY and never executable.
"""
from __future__ import annotations
from collections import Counter
from datetime import datetime, timezone
import re

STATES = ("READY_FOR_NON_AUTHORITATIVE_PLAN", "WAITING_DEPENDENCIES",
          "NEEDS_EVIDENCE", "NEEDS_REVIEW", "QUARANTINED", "DENIED")
SEVERITY = {s: n for n, s in enumerate(STATES)}
class DesignError(ValueError):
    pass

def require(ok, reason):
    if not ok:
        raise DesignError(reason)

def unique(rows, key, category):
    require(isinstance(rows, list) and all(isinstance(x, dict) and
            isinstance(x.get(key), str) and x.get(key) for x in rows),
            category + "_invalid")
    keys = [x[key] for x in rows]
    require(len(keys) == len(set(keys)), category + "_duplicate")
    return {x[key]: x for x in rows}

def check_contracts(bundle):
    """Complete semantic cross-contract check, bounded and independent of live WordPress."""
    for key in ("effects", "trust", "release", "ledgers", "optimization", "recipes",
                "facts", "cases", "rules", "policy", "task", "gates", "requirements", "system"):
        require(isinstance(bundle.get(key), dict), "missing_registry:" + key)
        require(bundle[key].get("authorizing") is False, "registry_claimed_authority:" + key)
    effects = unique(bundle["effects"].get("effects"), "id", "effect")
    require(set(effects) == {"PURE_READ", "PAID_EXTERNAL", "WORDPRESS_MUTATION",
            "HOST_MUTATION", "IRREVERSIBLE_EXTERNAL"}, "effects_incomplete")
    require(all(x.get("spec_planner_allowed") is False and x.get("required_evidence")
                and x.get("authority_guard", "").startswith(("MAD4B_", "READ_ONLY_"))
                for x in effects.values()), "effect_guard_unbound")
    require(bundle["effects"].get("unknown_effect") == "DENIED", "unknown_effect_open")
    trust = bundle["trust"]
    require(trust.get("signature_validation_owner") == "existing_MAD4B_trust_plane"
            and trust.get("client_boolean_is_not_attestation") is True
            and trust.get("spec_preview_never_authorizes") is True, "trust_boundary_invalid")
    required_receipt = set(trust.get("authority_receipt_fields", []))
    require({"issuer_key_id", "signature_verification_result", "expires_at",
             "runtime_generation", "restore_epoch", "site_uuid", "policy_revision",
             "actor_subject", "source_digest"}.issubset(required_receipt),
            "missing_trusted_receipt_fields")
    recipes = unique(bundle["recipes"].get("recipes"), "id", "recipe")
    require(len(recipes) >= 6 and bundle["recipes"].get("default_content_recipe") is None,
            "recipe_default_unsafe")
    facts = unique(bundle["facts"].get("profiles"), "id", "fact_profile")
    require(all(x.get("site_uuid") is None and x.get("fields") for x in facts.values()),
            "fact_authority_incomplete")
    for recipe in recipes.values():
        require(type(recipe.get("requires_native_relations")) is bool
                and isinstance(recipe.get("requirements"), list)
                and len(recipe["requirements"]) == len(set(recipe["requirements"]))
                and len(recipe["requirements"]) >= 3, "recipe_requirements_invalid")
    releases = unique(bundle["release"].get("profiles"), "id", "release")
    require({"spec_review", "evidence_read", "draft_assisted", "staging_publish",
             "growth_observe", "production_promote"} <= set(releases), "release_profiles_missing")
    require(bundle["release"].get("growth_not_needed_to_certify_first_publish") is True,
            "growth_cycle_as_release_dependency")
    for p in releases.values():
        require(p.get("spec_preview_only") is True and
                p.get("release_authority") == "EXISTING_MAD4B_ONLY",
                "release_profile_can_authorize")
        require(all(e in effects for e in p.get("effects_allowed", [])),
                "release_unknown_effect")
        require("ACI-G9" not in releases["staging_publish"]["required_gates"],
                "premature_growth_dependency")
    ledgers = unique(bundle["ledgers"].get("ledgers"), "id", "ledger")
    require(set(ledgers) == {"spec_registry", "execution_ledger", "certification_ledger",
            "decision_ledger"} and ledgers["spec_registry"]["states"] == ["OPEN"],
            "ledger_lifecycle_conflated")
    require(all(v.get("mutable_by") == "GOVERNED_APPEND_ONLY" for k, v in
            ledgers.items() if k != "spec_registry"), "ledger_uncontrolled_write")
    opt = bundle["optimization"]
    require(opt.get("unknown_thresholds") == "DISABLE_AUTOMATED_OPTIMIZATION"
            and opt.get("proposals_never_auto_apply") is True
            and opt.get("causal_claim_requires_control") is True, "optimization_loop_unbounded")
    gates = unique(bundle["gates"].get("gates"), "id", "gate")
    task = unique(bundle["task"].get("tasks"), "id", "task")
    reqs = unique(bundle["requirements"].get("requirements"), "id", "requirement")
    for row in task.values():
        require(row.get("status") == "OPEN" and row.get("authorizing") is False,
                "runtime_state_mixed_with_spec")
        require(row.get("gate") in gates and all(r in reqs for r in row.get("requirement_ids", [])),
                "orphan_task")
    for row in releases.values():
        require(all(g in gates for g in row.get("required_gates", []))
                and all(c.get("gate") in gates and c.get("when") in PREDICATES
                        for c in row.get("conditional", [])), "release_gate_unresolved")
    rules = unique(bundle["rules"].get("rules"), "code", "finding")
    require(bundle["rules"].get("unknown_finding_state") == "QUARANTINED"
            and rules.get("NO_FINDING", {}).get("state") == STATES[0],
            "unknown_finding_fails_open")
    require(all(r.get("state") in STATES and r.get("owner") for r in rules.values()),
            "finding_rule_invalid")
    cases = unique(bundle["cases"].get("use_cases"), "id", "case")
    require(len(cases) >= 30, "cases_missing")
    for case in cases.values():
        require(case.get("recipe_id") in recipes and case.get("required_gate") in gates
                and case.get("trust_source") == "SYNTHETIC_NOT_CERTIFIED"
                and case.get("effect_class") == "NONE_SPEC_SIMULATION"
                and case.get("authorizing") is False
                and case.get("execution_state") == "NOT_RUN", "case_trust_or_contract_invalid:" + case["id"])
        findings = case.get("observed_finding_codes")
        require(isinstance(findings, list) and findings and all(x in rules for x in findings),
                "scenario_unclassified:" + case["id"])
        require(decide(findings, bundle["rules"])["status"] == case["expected_state"],
                "scenario_unreachable:" + case["id"])
        require(case.get("decision_owner") and case.get("required_evidence")
                and case.get("assertions"), "scenario_no_evidence_owner:" + case["id"])
    # Independently assert no cyclic release path through post-publication growth.
    require("ACI-T0064" not in task["ACI-T0071"]["execution_dependencies"],
            "first_release_hard_depends_on_growth")
    require("ACI-T0065" not in task["ACI-T0054"]["execution_dependencies"],
            "disposable_native_code_hard_depends_on_live_staging")
    require(bundle["system"].get("unknown_new_edges") == "DENY",
            "unknown_authority_edge_allowed")
    return {"contracts": len(bundle), "recipes": len(recipes), "effects": len(effects),
            "release_profiles": len(releases), "scenarios": len(cases), "dispositions": len(STATES)}

PREDICATES = frozenset(("native_relation_in_scope", "opportunity_research_in_scope",
                         "new_content_publication_in_scope", "growth_learning_in_scope",
                         "paid_provider_in_scope", "publication_requested",
                         "browser_render_in_scope", "commercial_claim_in_scope",
                         "media_rights_in_scope"))

def decide(findings, rules):
    require(isinstance(findings, list) and findings and len(findings) <= 100,
            "finding_list_invalid")
    table = unique(rules.get("rules"), "code", "finding")
    result = []
    for code in findings:
        require(isinstance(code, str) and len(code) <= 128, "finding_code_invalid")
        item = table.get(code)
        if item is None:
            result.append((STATES.index("QUARANTINED"), code, "external_review_required"))
        else:
            result.append((SEVERITY[item["state"]], code, item["owner"]))
    severity, code, owner = max(result, key=lambda row: row[0])
    return {"contract": "mad4b.aci-os.disposition.v1", "status": STATES[severity],
            "primary_finding": code, "owner": owner, "all_findings": list(findings),
            "evidence_class": "SYNTHETIC_PREVIEW_ONLY", "trusted_authority_verified": False,
            "authorizing": False, "mutation_performed": False}

def recipe_requirements(recipe_id, recipes_registry, evidence, scope):
    """Spec-only evaluator: a claimed external receipt is not a verified receipt."""
    recipes = unique(recipes_registry.get("recipes"), "id", "recipe")
    require(isinstance(evidence, dict) and isinstance(scope, dict), "recipe_inputs_invalid")
    recipe = recipes.get(recipe_id)
    if recipe is None:
        return {"status": "DENIED", "missing": ["unknown_recipe"],
                "trusted_authority_verified": False, "authorizing": False}
    missing = []
    for field in recipe["requirements"]:
        receipt = evidence.get(field)
        if not isinstance(receipt, dict):
            missing.append(field + ":missing")
            continue
        if (receipt.get("site_uuid") != scope.get("site_uuid")
                or receipt.get("runtime_generation") != scope.get("runtime_generation")
                or receipt.get("restore_epoch") != scope.get("restore_epoch")
                or receipt.get("source_digest") is None
                or receipt.get("observed_at") is None
                or receipt.get("expires_at") is None):
            missing.append(field + ":stale_or_wrong_scope")
            continue
        # Never trust a boolean `certified`; MAD4B must supply signed receipts
        # through a separate adapter before anything can become operational.
        if receipt.get("trust_class") not in ("FIXTURE_SYNTHETIC",):
            missing.append(field + ":untrusted_external_attestation")
    return {"status": "NEEDS_EVIDENCE" if missing else "READY_FOR_NON_AUTHORITATIVE_PLAN",
            "missing": missing, "requires_native_relations": recipe["requires_native_relations"],
            "evidence_class": "FIXTURE_SYNTHETIC_ONLY", "trusted_authority_verified": False,
            "authorizing": False, "mutation_performed": False}

def release_candidate(profile_id, release_registry, gates, condition_facts, effect_class="NONE"):
    profiles = unique(release_registry.get("profiles"), "id", "release")
    require(isinstance(gates, (set, list)) and isinstance(condition_facts, dict),
            "release_inputs_invalid")
    profile = profiles.get(profile_id)
    if profile is None:
        return {"status": "DENIED", "reason": "unknown_release_profile",
                "authorizing": False, "production_authorized": False}
    required = set(profile["required_gates"])
    for c in profile.get("conditional", []):
        if condition_facts.get(c["when"]) is not False:
            # Unknown relevant evidence is never treated as a waived gate.
            required.add(c["gate"])
    missing = sorted(required - set(gates))
    if effect_class != "NONE" and effect_class not in profile["effects_allowed"]:
        missing.append("effect_scope_not_allowed")
    status = "DENIED" if profile_id == "production_promote" or "effect_scope_not_allowed" in missing else (
        "NEEDS_EVIDENCE" if missing else "READY_FOR_NON_AUTHORITATIVE_PLAN")
    return {"status": status, "profile": profile_id, "required_gates": sorted(required),
            "missing": missing, "trusted_authority_verified": False,
            "authorizing": False, "production_authorized": False}
