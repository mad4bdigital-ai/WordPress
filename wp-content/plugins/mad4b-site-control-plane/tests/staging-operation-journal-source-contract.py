#!/usr/bin/env python3
"""Prevent accidentally detaching the site-wide deduplication gate from Apply."""
from pathlib import Path
import re
root=Path(__file__).resolve().parents[1]
journal=(root/"includes/class-mad4b-scp-staging-operation-journal.php").read_text(encoding="utf-8")
converge=(root/"includes/class-mad4b-scp-staging-write-authority-convergence.php").read_text(encoding="utf-8")
owner=(root/"includes/class-mad4b-scp-staging-write-owner-intent.php").read_text(encoding="utf-8")
workflow=(root.parents[2]/".github/workflows/feature-007-pre-staging-hybrid-audit.yml").read_text(encoding="utf-8")
assert "require_once __DIR__ . '/class-mad4b-scp-staging-operation-journal.php'" in converge
apply=converge.split("public static function apply( $input ) {",1)[1].split("public static function evaluate_postconditions",1)[0]
assert apply.index("self::match_expected_plan") < apply.index("inspect_plan( $plan )")
assert apply.index("authorize_reviewed_owner_intent( $plan, $input )") < apply.index("::reserve( $plan )")
assert apply.index("::reserve( $plan )") < apply.index("MAD4B_SCP_Site_Profile_Write_Enablement::enable_write")
assert apply.index("MAD4B_SCP_Audit::record( 'mad4b/staging-write-authority-convergence-verified'") < apply.index("::complete(")
assert "::suspend( $journal_plan )" in apply
assert apply.count("'operation_id' => $operation_id") >= 4, 'Audit and result share one operation ID'
assert "add_option( self::active_key( $plan['site_uuid'] )" in journal
assert "delete_option( self::active_key( $plan['site_uuid'] ) )" in journal
assert "function_exists( 'delete_option' )" in journal
assert "if ( ! add_option( self::key( $id )" in journal
assert "RECOVERY_REQUIRED" in journal and "EXECUTION_UNCERTAIN" in journal
assert "canonical_scope_does_not_authorize_apply" in owner
assert "oauth_step_up_still_required_for_apply" in owner
assert "latest_operation" in owner
assert "check_permissions( array() )" in (root/"includes/class-mad4b-scp-operational-remediation.php").read_text()
assert workflow.count("php \"$P/tests/staging-operation-journal-runtime.php\"") >= 1
assert "Recovery lifecycle and owner authority / PHP ${{ matrix.php }}" in workflow
print("STAGING_OPERATION_JOURNAL_SOURCE_CONTRACT_PASS")
