#!/usr/bin/env python3
"""Adversarial design-only ACI01 contract tests. Does not invoke live systems."""
from copy import deepcopy
from pathlib import Path
import importlib.util
import json

ROOT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("aci_design_closure", ROOT / "design_closure.py")
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

def read(name):
    return json.loads((ROOT / name).read_text(encoding="utf-8"))

bundle = {
    "effects": read("effect-contracts.json"),
    "trust": read("evidence-trust.json"),
    "release": read("release-profiles.json"),
    "ledgers": read("ledger-contracts.json"),
    "optimization": read("optimization-policy.json"),
    "recipes": read("content-recipes.json"),
    "facts": read("domain-fact-authority.json"),
    "cases": read("use-cases.json"),
    "rules": read("disposition-rules.json"),
    "policy": read("dynamic-policy.json"),
    "task": read("task-registry.json"),
    "gates": read("acceptance-gates.json"),
    "requirements": read("requirements.json"),
    "system": read("system-map.json"),
    "reuse": read("reuse-authority-crosswalk.json"),
}
count = 0

def check(value, message):
    global count
    assert value, message
    count += 1

def fails(action, expected):
    global count
    try:
        action()
    except m.DesignError as error:
        assert str(error).startswith(expected), (str(error), expected)
        count += 1
        return
    raise AssertionError("Expected explicit design denial: " + expected)

summary = m.check_contracts(bundle)
check(summary["scenarios"] == 30, "all declared scenarios covered")
check(summary["dispositions"] == 6, "six independently reachable states")
check(summary["effects"] == 5, "five effect classes")
check(summary["release_profiles"] == 6, "different release profiles")
check(summary["recipes"] == 6, "domain-specific content recipes")
for case in bundle["cases"]["use_cases"]:
    result = m.decide(case["observed_finding_codes"], bundle["rules"])
    check(result["status"] == case["expected_state"], "case_" + case["id"])
    check(result["authorizing"] is False and result["mutation_performed"] is False,
          "case_no_effect_" + case["id"])
    check(result["trusted_authority_verified"] is False,
          "case_not_provider_certified_" + case["id"])

unknown = m.decide(["UNKNOWN_EXTERNAL_PROVIDER_BEHAVIOR"], bundle["rules"])
check(unknown["status"] == "QUARANTINED", "unknown finding quarantine")
check(unknown["authorizing"] is False, "quarantine is not write authority")
fails(lambda: m.decide([], bundle["rules"]), "finding_list_invalid")

scenario = deepcopy(bundle)
scenario["cases"]["use_cases"][0]["expected_state"] = "DENIED" if scenario["cases"]["use_cases"][0]["expected_state"] != "DENIED" else "NEEDS_REVIEW"
fails(lambda: m.check_contracts(scenario), "scenario_unreachable")

scenario = deepcopy(bundle)
scenario["rules"]["unknown_finding_state"] = "READY_FOR_NON_AUTHORITATIVE_PLAN"
fails(lambda: m.check_contracts(scenario), "unknown_finding_fails_open")

scenario = deepcopy(bundle)
scenario["effects"]["effects"][2]["spec_planner_allowed"] = True
fails(lambda: m.check_contracts(scenario), "effect_guard_unbound")

scenario = deepcopy(bundle)
scenario["ledgers"]["ledgers"][1]["mutable_by"] = "OPEN_WRITE"
fails(lambda: m.check_contracts(scenario), "ledger_uncontrolled_write")

scenario = deepcopy(bundle)
scenario["release"]["profiles"][3]["required_gates"].append("ACI-G9")
fails(lambda: m.check_contracts(scenario), "premature_growth_dependency")

scenario = deepcopy(bundle)
scenario["release"]["growth_not_needed_to_certify_first_publish"] = False
fails(lambda: m.check_contracts(scenario), "growth_cycle_as_release_dependency")

scenario = deepcopy(bundle)
scenario["task"]["tasks"][-1]["execution_dependencies"].append("ACI-T0064")
fails(lambda: m.check_contracts(scenario), "first_release_hard_depends_on_growth")

scenario = deepcopy(bundle)
scenario["optimization"]["proposals_never_auto_apply"] = False
fails(lambda: m.check_contracts(scenario), "optimization_loop_unbounded")

scenario = deepcopy(bundle)
scenario["trust"]["signature_validation_owner"] = "LLM_VERDICT"
fails(lambda: m.check_contracts(scenario), "trust_boundary_invalid")

scope = {"site_uuid":"11111111-2222-3333-4444-555555555555",
         "runtime_generation":"f"*64, "restore_epoch":3}
article = m.recipe_requirements("article", bundle["recipes"], {}, scope)
check(article["status"] == "NEEDS_EVIDENCE" and article["missing"],
      "required article facts never silently omitted")
unknown_recipe = m.recipe_requirements("unregistered", bundle["recipes"], {}, scope)
check(unknown_recipe["status"] == "DENIED", "unknown recipe denied")
receipt = {"site_uuid":scope["site_uuid"],"runtime_generation":scope["runtime_generation"],
           "restore_epoch":scope["restore_epoch"],"observed_at":"2026-01-01T00:00:00Z",
           "expires_at":"2030-01-02T00:00:00Z","source_digest":"f"*64,
           "rights_scope":"synthetic_fixture",
           "trust_class":"FIXTURE_SYNTHETIC"}
facts = {p:dict(receipt) for p in bundle["recipes"]["recipes"][0]["requirements"]}
candidate = m.recipe_requirements("article",bundle["recipes"],facts,scope)
check(candidate["status"] == "READY_FOR_NON_AUTHORITATIVE_PLAN"
      and candidate["trusted_authority_verified"] is False
      and candidate["authorizing"] is False,"synthetic valid data is never authority")
facts["brand_context"]["site_uuid"] = "00000000-0000-0000-0000-000000000000"
bad = m.recipe_requirements("article",bundle["recipes"],facts,scope)
check(bad["status"] == "NEEDS_EVIDENCE", "cross-site fact denied")
facts["brand_context"] = dict(receipt, expires_at="2026-01-01T00:00:00Z")
stale = m.recipe_requirements("article",bundle["recipes"],facts,scope)
check(stale["status"] == "NEEDS_EVIDENCE"
      and any("expired_or_invalid_time" in item for item in stale["missing"]),
      "expiry actually checked")
facts["brand_context"] = dict(receipt, rights_scope="unlicensed")
rights = m.recipe_requirements("article",bundle["recipes"],facts,scope)
check(rights["status"] == "NEEDS_EVIDENCE", "rights requirement cannot be waived")
facts["brand_context"] = dict(receipt, trust_class="AUTHORITY_ATTESTED", certified=True)
bad = m.recipe_requirements("article",bundle["recipes"],facts,scope)
check(bad["status"] == "NEEDS_EVIDENCE", "unverified claimed signature denied")

spec = m.release_candidate("spec_review",bundle["release"],{"ACI-G0"},{})
check(spec["status"] == "READY_FOR_NON_AUTHORITATIVE_PLAN" and not spec["authorizing"],
      "spec review never grants runtime")
staging = m.release_candidate("staging_publish",bundle["release"],{"ACI-G0","ACI-G6","ACI-G7","ACI-G8"},
                               {"native_relation_in_scope":False})
check(staging["status"] == "READY_FOR_NON_AUTHORITATIVE_PLAN" and "ACI-G9" not in staging["required_gates"],
      "initial staging release does not require postpublication growth")
unknown_native = m.release_candidate("staging_publish",bundle["release"],{"ACI-G0","ACI-G6","ACI-G7","ACI-G8"}, {})
check(unknown_native["status"] == "NEEDS_EVIDENCE" and "ACI-G4" in unknown_native["required_gates"],
      "unknown relation usage cannot waive proof")
production = m.release_candidate("production_promote",bundle["release"],set(),{})
check(production["status"] == "DENIED" and production["production_authorized"] is False,
      "production remains separately governed")
effect = m.release_candidate("spec_review",bundle["release"],{"ACI-G0"},{},"WORDPRESS_MUTATION")
check(effect["status"] == "DENIED", "spec review is never a write grant")

scenario = deepcopy(bundle)
scenario["reuse"]["entries"][0]["runtime_certified"] = True
fails(lambda: m.check_contracts(scenario), "unverified_reuse_misrepresented")

print("mad4b.aci-os.design-closure.tests.v1: PASS", count,
      "(fixture-only; no WordPress, provider or release certification)")
