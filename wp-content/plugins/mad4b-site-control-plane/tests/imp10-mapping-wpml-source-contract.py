#!/usr/bin/env python3
"""IMP10 mapping maturation / WPML readback invariants, source-level only."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
src=lambda name:(root/"includes"/name).read_text(encoding="utf-8")
mapping=src("class-mad4b-scp-import-mapping-evolution.php")
wpml=src("class-mad4b-scp-import-wpml-readback.php")
profiles=src("class-mad4b-scp-content-experience-profiles.php")
reconciliation=src("class-mad4b-scp-activity-import-reconciliation.php")
review=src("class-mad4b-scp-activity-import-review.php")
experience=src("class-mad4b-scp-activity-import-experience.php")
preflight=(root/"tests"/"feature007-manual-preflight.py").read_text(encoding="utf-8")
fixtures=[(root/"tests"/f).read_text(encoding="utf-8") for f in (
    "imp10-mapping-evolution-runtime.php","imp10-wpml-readback-runtime.php")]
def must(cond,why):
    if not cond: raise AssertionError(why)
for name in ("plan","simulate","type_profile","classify","canonical","requirements"):
    must("function "+name+"(" in mapping, "Missing schema evolution guard "+name)
for word in ("header_only_unstaged","observed_headers",
             "expected_profile_authority_sha256","mapped_source_column_missing",
             "candidate_source_column_rename","normalized_header_name_match_only",
             "lexical_candidate_not_semantic_proof","current_approved_mapping_unchanged",
             "mutation_performed' => false","candidate_validation_sha256",
             "proposal_plan_sha256","mapping_mutation_authorized' => false",
             "mad4b_mapping_identity_registry_mutation_denied",
             "MAD4B_SCP_Activity_Import_Authority::normalize(",
             "source_evidence_kind","candidate_live_import_authorized' => false"):
    must(word in mapping,"Mapping mutation can bypass human approval: "+word)
for word in ("wpml_element_trid","wpml_get_element_translations",
             "wpml_element_language_details",
             "source_translations_not_in_same_trid",
             "missing_or_ambiguous_external_identity",
             "database_collation_identity_mismatch",
             "independent_wpml_link_audit_passed_for_this_group",
             "all_groups_audited",
             "'source_provenance_verified' => false",
             "'external_import_execution_authorized' => false",
             "'production_promotion_authorized' => false"):
    must(word in wpml, "Missing WPML postwrite proof guard "+word)
must("'suppress_filters' => true" in reconciliation,
     "WordPress Meta identity reconcile incorrectly narrows by current WPML language")
must("mad4b_import_intent" in review and
     "preview_mapping" in review and
     "observed_headers' => $headers" in review and
     "Check dynamic mapping before upload" in experience,
     "Header-only mapping preflight is not available in the guided CSV flow")
must("sampled_mapped_column_type_counts" in mapping and
     "commercial_price_semantic_type_drift" in mapping and
     "serialized_source_requires_certified_typed_driver" in mapping and
     "never_php_unserialize_from_untrusted_source" in mapping and
     "unserialize(" not in mapping,
     "Business-semantic drift or safe serialized relationship boundaries missing")
for word in ("class-mad4b-scp-import-mapping-evolution.php",
             "class-mad4b-scp-import-wpml-readback.php",
             "business-activity-import-mapping-evolution-plan",
             "business-activity-import-mapping-mutation-simulate",
             "business-activity-import-wpml-readback"):
    must(word in profiles, "MCP source class or ability not enrolled: "+word)
for word in ("imp10-mapping-evolution-runtime.php","imp10-wpml-readback-runtime.php",
             "imp10-mapping-wpml-source-contract.py",
             "class-mad4b-scp-import-mapping-evolution.php",
             "class-mad4b-scp-import-wpml-readback.php"):
    must(word in preflight, "Native preflight missing IMP10 "+word)
must("mapping_mutation_authorized' => true" not in mapping and
     "wp_insert_post(" not in mapping and
     "wp_update_post(" not in wpml and
     "wp_insert_post(" not in wpml,
     "Read-only Schema evolution or WPML audit unexpectedly writes WordPress")
must("header_only_unstaged" in fixtures[0] and
     "schema_preview_passed" in fixtures[0] and
     "mad4b_mapping_identity_registry_mutation_denied" in fixtures[0] and
     "source_translations_not_in_same_trid" in fixtures[1],
     "Tests missing drift / wrong WPML translation group negatives")
print("PASS IMP10 mapping drift / what-if / WPML provider readback guards (STATIC ONLY)")
