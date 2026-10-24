#!/usr/bin/env python3
"""Post-update grant recovery must run only through sealed, verified reconciliation."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]/"includes"
s=(root/"class-mad4b-scp-post-update-approved-grant-recovery.php").read_text()
r=(root/"class-mad4b-scp-runtime-convergence.php").read_text()
for value in (
    "MAD4B_SCP_Environment_Convergence_Policy::current()",
    "MAD4B_SCP_Post_Update_Continuation::approved_grant_baseline()",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation::can_execute()",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan::plan()",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation::reconcile( $input )",
    "'verified_oauth_step_up_required'",
    "'stale_grants_require_review'",
    "'nonproduction_approved_auto'] = true",
):
    assert value in s, value
assert "'production_allowed' => false" in s
assert r.index("MAD4B_SCP_Post_Update_Approved_Grant_Recovery::attempt()") < r.index("$baseline = class_exists( 'MAD4B_SCP_Post_Update_Continuation' )",r.index("MAD4B_SCP_Post_Update_Approved_Grant_Recovery::attempt()"))
assert "'approved_grant_recovery' => $approved_grant_recovery" in r
print("POST_UPDATE_APPROVED_GRANT_RECOVERY: PASS")
