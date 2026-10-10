#!/usr/bin/env python3
"""IMP09 readiness evidence invariants; static only, not a runtime certificate."""
from pathlib import Path
P=Path(__file__).resolve().parents[1]
get=lambda p:(P/"includes"/p).read_text(encoding="utf8")
gates=get("class-mad4b-scp-import-acceptance-gates.php")
profiles=get("class-mad4b-scp-content-experience-profiles.php")
fixture=(P/"tests"/"imp09-import-acceptance-runtime.php").read_text(encoding="utf8")
preflight=(P/"tests"/"feature007-manual-preflight.py").read_text(encoding="utf8")
def require(predicate,message):
    if not predicate: raise AssertionError(message)
for s in (
    "class MAD4B_SCP_Import_Acceptance_Gates",
    "origin_enrolled()", "site_urls_match_enrollment()",
    "environment_allowed( array( 'staging' ) )",
    "MAD4B_ACTIVITY_IMPORT_DATA_KEY",
    "MAD4B_SCP_Activity_Import_Xlsx::available()",
    "wp_all_import_plugin_detected", "wpml_runtime_detected",
    "jetengine_runtime_detected", "google_sheets_multi_editor_conditional_cas",
    "third_party_wp_all_import_msr02_shared_write_fence",
    "provider_write_transaction_and_compensating_rollback",
    "brand_core", "missing_required_context_count",
    "'runtime_probe_executed' => true",
    "'provider_presence_not_certification' => true",
    "'ready_for_automatic_import' => false",
    "'production_promotion_authorized' => false",
    "'read_only' => true", "'mutation_performed' => false",
):
    require(s in gates, "Missing provider certification caveat: "+s)
for s in ("business-activity-import-acceptance-gates",
          "class-mad4b-scp-import-acceptance-gates.php"):
    require(s in profiles, "Missing connected readiness ability: "+s)
for s in ("source_mode_allowlist_nonempty", "notFound",
          "ready_for_automatic_import", "production_promotion_authorized"):
    require(s in fixture, "Native fixture missing denial: "+s)
for s in ("class-mad4b-scp-import-acceptance-gates.php",
          "imp09-import-acceptance-runtime.php",
          "imp09-import-acceptance-source-contract.py"):
    require(s in preflight, "Offline preflight missing gate: "+s)
require("wp_insert_post(" not in gates and "update_option(" not in gates,
        "Readiness inspection must not mutate WordPress")
print("PASS IMP09 read-only independent Staging acceptance gating source checks (STATIC ONLY)")
