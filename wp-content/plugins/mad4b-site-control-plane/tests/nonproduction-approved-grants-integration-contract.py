#!/usr/bin/env python3
"""Checks the sealed baseline and existing rollback-capable grant reconciler."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]/"includes"
baseline=(root/"class-mad4b-scp-post-update-continuation.php").read_text()
reconcile=(root/"class-mad4b-scp-staging-write-grant-reconciliation.php").read_text()
for fragment in (
    "private static function approved_grant_pairs(",
    "get_agent_by_public_id(",
    "grants_for_agent(",
    "'approved_grants' => $approved_grants",
    "$baseline['seal'] = self::baseline_seal( $baseline )",
    "public static function approved_grant_baseline()",
    "self::baseline_valid( $baseline )",
    "self::integrity_valid( $permit )",
    "self::continuation_release_valid( $permit )",
):
    assert fragment in baseline, f"baseline missing: {fragment}"
for fragment in (
    "'nonproduction_approved_auto' => array( 'type' => 'boolean'",
    "MAD4B_SCP_Post_Update_Continuation::approved_grant_baseline()",
    "MAD4B_SCP_Nonproduction_Approved_Grants::evaluate(",
    "'mad4b_approved_auto_production_denied'",
    "'mad4b_approved_auto_grants_blocked'",
    "'stale_grants_require_review'",
    "'approval_basis' => $auto_approved",
    "MAD4B_SCP_Agent_Registry::grant_ability(",
    "MAD4B_SCP_Audit::record(",
):
    assert fragment in reconcile, f"reconciler missing: {fragment}"
schema=reconcile.split("'input_schema' => array(",1)[1].split("'output_schema' => array(",1)[0]
required=schema.split("'required' => array(",1)[1].split("'additionalProperties'",1)[0]
assert "'confirmation'," not in required, "automatic mode must not require human confirmation"
assert "if ( ! $auto_approved && ! hash_equals(" in reconcile
assert reconcile.index("MAD4B_SCP_Nonproduction_Approved_Grants::evaluate(") < reconcile.index("MAD4B_SCP_Audit::record( 'mad4b/staging-write-grant-reconciliation-authorized'")
assert reconcile.index("MAD4B_SCP_Nonproduction_Approved_Grants::evaluate(") < reconcile.index("MAD4B_SCP_Agent_Registry::grant_ability(",reconcile.index("public static function reconcile("))
assert "private static function rollback_transaction(" in reconcile and "self::rollback_transaction(" in reconcile
print("NONPRODUCTION_APPROVED_GRANTS_INTEGRATION: PASS")
