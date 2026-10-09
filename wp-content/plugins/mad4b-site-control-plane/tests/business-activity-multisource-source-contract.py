#!/usr/bin/env python3
"""MSR01 source contract: multi-source profile/Drive revisions are never implicit writes."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
read=lambda path:(root/path).read_text(encoding="utf-8")
facet=read("includes/class-mad4b-scp-business-activity-contracts.php")
reconcile=read("includes/class-mad4b-scp-activity-source-reconciliation.php")
experience=read("includes/class-mad4b-scp-content-experience-profiles.php")
runtime=read("tests/business-activity-source-reconciliation-runtime.php")
def ensure(flag,msg):
 if not flag: raise AssertionError(msg)
for key in ("field_owners","sync_identity_key","field_bindings","purpose","editorial_policy",
 "media_assets","reference","source_ref","resource_kind","mad4b_activity_duplicate_resource_binding",
 "mad4b_activity_field_owner_direction_invalid","mad4b_activity_drive_binding_required",
 "mad4b_activity_folder_not_row_source","provider_field","value_type","null_policy"):
 ensure(key in facet,"Missing field ownership/provenance/typed binding: "+key)
for key in ("function plan(", "function context_impact_plan(", "checkpoint_coverage_incomplete",
 "concurrent_source_edit", "non_owner_changed", "baseline_already_divergent",
 "snapshot_missing_or_stale","source_resource_duplicate","configured_source_resource_mismatch",
 "missing_or_deleted_requires_reconciliation","client_supplied_evidence_trusted' => false",
 "provider_receipts_independently_verified' => false","checkpoint_authoritatively_persisted' => false",
 "ready_for_automatic_apply' => false","requires_source_and_destination_revision_readback"):
 ensure(key in reconcile or (key=="requires_source_and_destination_revision_readback" and key in facet),
        "Missing N-source conflict gate: "+key)
for route in ("mad4b/business-activity-reconcile-plan",
  "mad4b/business-activity-context-impact-plan"):
 ensure(route in experience,"N-way source reconciliation not registered: "+route)
for label in ("Concurrent edits merged silently","Nonowner edit silently won",
 "Changed editorial guidelines caused auto-publication","Missing source treated as blank",
 "Drive file identity swap accepted","Already-divergent baseline incorrectly trusted",
 "Client-provided verification flag was accepted"):
 ensure(label in runtime,"Missing adversarial native runtime case: "+label)
ensure("'dmcs'" not in facet and "'drivers'" not in facet and "'guides'" not in facet,
 "Reusable engine should not hardcode tourism post types")
ensure("https://docs.google.com" not in facet and "15Fxpm" not in facet,
 "Private Drive resource IDs or URLs should not enter plugin source")
print("PASS MSR01 field ownership, Drive policy role, N-way snapshots, anti-latest-wins and safe provider boundaries")
