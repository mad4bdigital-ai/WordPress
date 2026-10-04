#!/usr/bin/env python3
from pathlib import Path
import json
import re

ROOT=Path("specs/007-content-intelligence-workflow-platform")
PHP=Path("wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-adaptive-search-acceptance.php")
TEST=Path("wp-content/plugins/mad4b-site-control-plane/tests/adaptive-search-cross-fault-runtime.php")

spec=json.loads((ROOT/"adaptive-search-intelligence.json").read_text(encoding="utf-8"))
review=json.loads((ROOT/"adaptive-search-intelligence-review.json").read_text(encoding="utf-8"))
source=PHP.read_text(encoding="utf-8")
test_source=TEST.read_text(encoding="utf-8")

required=set(spec.get("required_gates",[]))
declared=set(re.findall(r"'([A-Z][A-Z0-9_]+_PASS)'\s*=>",source))
missing=sorted(required-declared)
if missing:
    raise SystemExit("ADAPTIVE_SEARCH_ACCEPTANCE_MISSING_PHASE_GATES:"+",".join(missing))
if len(required)!=15:
    raise SystemExit(f"ADAPTIVE_SEARCH_PHASE_GATE_COUNT_INVALID:{len(required)}")
if "public static function phase_gates()" not in source:
    raise SystemExit("ADAPTIVE_SEARCH_PHASE_GATE_REGISTRY_MISSING")
for token in ["fixtures","assertion_count_min","evidence_class","exact_head_bound"]:
    if token not in source:
        raise SystemExit("ADAPTIVE_SEARCH_ACCEPTANCE_DESCRIPTOR_INCOMPLETE:"+token)

p0=[x for x in review.get("findings",[]) if x.get("severity")=="P0"]
if len(p0)!=12:
    raise SystemExit(f"ADAPTIVE_SEARCH_REVIEW_P0_COUNT_INVALID:{len(p0)}")
selected_tasks={t for row in p0 for t in row.get("tasks",[])}
expected={"T3861","T3862","T3863","T3864","T3865","T3866","T3867","T3868","T3869","T3870","T3873","T3874","T3877","T3880"}
if not expected.issubset(selected_tasks):
    raise SystemExit("ADAPTIVE_SEARCH_P0_TASK_MAPPING_INCOMPLETE:"+",".join(sorted(expected-selected_tasks)))

for fixture in [
    "profile_drift","provider_drift","language_drift","surface_drift","budget_race",
    "quota_cycle_drift","lease_loss","partial_capture","cache_incomparable",
    "uncertain_effect_reconcile","adaptive_experience_state"
]:
    if fixture not in source:
        raise SystemExit("ADAPTIVE_SEARCH_CROSS_FAULT_DESCRIPTOR_MISSING:"+fixture)

for behavior in [
    "profile_generation_match","provider_generation_match","surface_fingerprint_match",
    "budget_cycle_match","budget_reservation_valid","lease_valid","provider_effect_state",
    "cache_context_comparable","capture_complete"
]:
    if behavior not in test_source:
        raise SystemExit("ADAPTIVE_SEARCH_CROSS_FAULT_RUNTIME_MISSING:"+behavior)

runtime_files=[
 "class-mad4b-scp-search-measurement.php",
 "class-mad4b-scp-search-eligibility.php",
 "class-mad4b-scp-search-evidence-policy.php",
 "class-mad4b-scp-provider-account-budget-authority.php",
 "class-mad4b-scp-search-decision-policy.php",
 "class-mad4b-scp-adaptive-search-acceptance.php",
 "class-mad4b-scp-adaptive-search-fault-guard.php",
]
inc=Path("wp-content/plugins/mad4b-site-control-plane/includes")
for name in runtime_files:
    if not (inc/name).is_file():
        raise SystemExit("ADAPTIVE_SEARCH_RUNTIME_FILE_MISSING:"+name)

print("mad4b.adaptive-search-p0-acceptance-contract.v1: PASS")
print(f"phase_gates={len(required)} declared_gates={len(declared)} p0_findings={len(p0)}")
