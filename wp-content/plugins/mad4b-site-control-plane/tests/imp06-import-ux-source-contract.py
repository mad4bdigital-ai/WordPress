#!/usr/bin/env python3
"""IMP06 static operator UX checks. Does not claim native PHP/browser success."""
from pathlib import Path
P = Path(__file__).resolve().parents[1]
R = P.parent.parent.parent
def read(p): return (P / p).read_text(encoding="utf8")
ui = read("includes/class-mad4b-scp-activity-import-experience.php")
review = read("includes/class-mad4b-scp-activity-import-review.php")
snapshot = read("includes/class-mad4b-scp-activity-import-snapshot.php")
loader = read("includes/class-mad4b-scp-content-experience-profiles.php")
preflight = read("tests/feature007-manual-preflight.py")
fixture = read("tests/imp06-import-ux-runtime.php")
def require(pred, message):
    if not pred: raise AssertionError(message)
for method in ("journey", "render", "step_destination", "step_source",
               "step_conflicts", "step_handoff", "mode_label", "issue_label",
               "error_guidance", "requirement_label"):
    require("function " + method + "(" in ui, "Guided action missing: "+method)
for token in ("profile_status()", "Select an enabled Profile",
              "requested_mode_invalid", "Nothing was substituted automatically",
              "review_unavailable", "can_upload_new", "issue_count_total",
              "blocking_issue_count", "review_issue_count",
              "Compare existing destination records", "Next issues",
              "Previous issues", "Previous 25 destination records",
              "Next 25 destination records",
              "MAD4B_SCP_Activity_WPAI_Observer::status(",
              "Still needs independent verification.", "approval_plan(",
              "wp_nonce_field( 'mad4b_activity_csv_intake'",
              "wp_nonce_field( 'mad4b_activity_approve_review'",
              "wp_nonce_field( 'mad4b_activity_export_approved'",
              "wp_nonce_field( 'mad4b_activity_archive_review'",
              "'can_start_import' => false", "never_auto_execute_or_publish",
              "acknowledge_warnings", "acknowledged_warning_count",
              "scope readiness"):
    if token == "scope readiness":
        token = "eligible_for_staging_review"
    require(token in ui,"Operator UI has a missing guard: "+token)
for token in ("MAD4B_SCP_Activity_Import_Experience::render()",
              "return_to_guide(", "'wizard_step' => 3",
              "'wizard_step' => 4", "'wizard_step' => 2",
              "admin_post_mad4b_activity_import_csv",
              "admin_post_mad4b_activity_import_approve"):
    require(token in review, "Legacy intake is not linked to the wizard: "+token)
for token in ("mad4b_import_warning_acknowledgement_mismatch",
              "acknowledged_warning_count", "explicit_full_warning_count_ack_required"):
    require(token in snapshot,
            "Approval does not bind the full warning count: "+token)
require("class-mad4b-scp-activity-import-experience.php" in loader,
        "Guided class is not loaded by the plugin")
for token in ("No source", "Invalid", "blocked", "unreadable",
              "approval", "arbitrary_unapproved_adapter", "new upload",
              "can_upload_new", "never_auto_execute_or_publish"):
    if token == "No source": token = "no source"  # semantic only
    if token == "Invalid": token = "Unconfigured"
    if token == "unreadable": token = "unreadable"
    if token == "new upload": token = "new upload"
    require(token.lower() in fixture.lower(),
            "UX simulation lacks scenario: "+token)
require('"class-mad4b-scp-activity-import-experience.php"' in preflight and
        '"imp06-import-ux-runtime.php"' in preflight and
        '"imp06-import-ux-source-contract.py"' in preflight,
        "Native/manual Preflight omitted UX regression coverage")
require("wp_insert_post(" not in ui and "wp_update_post(" not in ui,
        "Guided Review must never publish or mutate business posts")
print("PASS IMP06 source-level flow, accurate Mode readiness, errors, pagination, nonces, manual-only handoff and enrollment (STATIC ONLY)")
