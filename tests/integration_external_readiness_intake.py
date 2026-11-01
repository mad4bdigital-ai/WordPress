#!/usr/bin/env python3
"""Reviewed external issue intake merge must preserve local readiness automation."""
from pathlib import Path
p=Path(__file__).resolve().parents[1]/"wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-readiness-alerts.php"
s=p.read_text()
for code in [
    "MAD4B_SCP_Readiness_Integrations::boot();",
    "MAD4B_SCP_Readiness_Scopes::boot();",
    "MAD4B_SCP_Readiness_PreActivation::boot();",
    "MAD4B_SCP_Readiness_Integrations::can_manage()",
    "MAD4B_SCP_Readiness_Integrations::record_changes($old,$new);",
    "mad4b_readiness_scheduler_unauthorized",
    "mad4b_readiness_ledger_readback_failed",
    "$source==='dynamic-filter-seo-bridge' && $type==='site' && $object_id===0",
    "$source==='mad4b-elementor-localization'",
    "$source==='mad4b-live-performance' && $type==='site' && $object_id===0",
    "loc_(?:layout|text|missing|h1|brand|binding|style)",
    "MAD4B_Live_Render_Blocking_Monitor_V1::report()['fresh']",
    "!isset($row['status']) && !isset($row['board_task_id'])",
    "!isset($externalIds[$id])",
    "hash_equals($expected,$id)",
    "'source'=>$source",
]:
    assert code in s, "missing reviewed guard: "+code
assert "apply_filters('mad4b/content_readiness/external_issues',array(),$cfg)" in s
assert "'production_mutation_authorized' => true" not in s
print("EXTERNAL_READINESS_INTAKE_SEMANTIC_MERGE: PASS")
