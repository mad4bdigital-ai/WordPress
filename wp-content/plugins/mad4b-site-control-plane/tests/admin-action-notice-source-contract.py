#!/usr/bin/env python3
"""Regression: admin-action feedback cannot outlive current signed state.

Source contract only. Runtime and browser verification remain mandatory.
Runs without WordPress/PHP and performs no filesystem mutation.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "includes"


def verify(name, *required):
    source = (ROOT / name).read_text(encoding="utf-8")
    missing = [mark for mark in required if mark not in source]
    if missing:
        raise AssertionError(f"ADMIN_NOTICE_CONTRACT_DRIFT:{name}:{missing[0]}")


verify(
    "class-mad4b-scp-site-profile-admin.php",
    "site_profile_notice_binding()",
    "'site_profile.' . $state",
    "MAD4B_SCP_Admin_Experience::notice_receipt(",
    "MAD4B_SCP_Admin_Experience::notice_verified(",
    "MAD4B_SCP_Site_Profile::reset_cache();",
)
verify(
    "class-mad4b-scp-dynamic-content-pipeline-admin.php",
    "private static function error_redirect(",
    "'pipeline_error.' . $code",
    "'pipeline_error.'.$error",
    "MAD4B_SCP_Admin_Experience::notice_receipt(",
    "MAD4B_SCP_Admin_Experience::notice_verified(",
)
verify(
    "class-mad4b-scp-skills-admin-ui.php",
    "'skill.export_error.' . sanitize_key( (string) $code )",
    "'skill.export_error.' . $code",
    "MAD4B_SCP_Admin_Experience::notice_receipt(",
    "MAD4B_SCP_Admin_Experience::notice_verified(",
)
verify(
    "class-mad4b-scp-context-admin-ui.php",
    "'error:' . $error",
    "'error:' . $args['mad4b_error']",
    "MAD4B_SCP_Admin_Experience::notice_receipt(",
    "MAD4B_SCP_Admin_Experience::notice_verified(",
    "render_live_context_reconciliation()",
)
# Control pages with stronger existing state-bound notices must not regress.
verify(
    "class-mad4b-scp-approval-decision-admin.php",
    "MAD4B_SCP_Admin_Experience::notice_verified(",
    "MAD4B_SCP_Admin_Experience::notice_receipt(",
)
verify(
    "class-mad4b-scp-browser-acceptance-admin-ui.php",
    "MAD4B_SCP_Admin_Experience::notice_verified(",
    "MAD4B_SCP_Admin_Experience::notice_receipt(",
)
verify(
    "class-mad4b-scp-runtime-components-admin-ui.php",
    "stale_release_pointer",
    "version_compare( $target_version, $current_version, '<' )",
    "Update channel points to an older Control Plane release.",
)
verify(
    "class-mad4b-scp-runtime-components-admin-ui.php",
    "expected_release_set_sha256",
    "expected_current_source_commit_sha",
    "missing_release_target",
)
verify(
    "class-mad4b-scp-runtime-release-set.php",
    "admin_form_preflight(",
    "mad4b_runtime_admin_stale_form",
    "mad4b_runtime_admin_downgrade_blocked",
    "mad4b_runtime_admin_transition_in_progress",
)
verify(
    "class-mad4b-scp-browser-acceptance-admin-ui.php",
    "Browser acceptance route declaration is always discoverable",
    "MAD4B_SCP_Admin_Route_Registry::register( MAD4B_SCP_Browser_Acceptance_Admin_UI::PAGE_SLUG",
)
verify(
    "class-mad4b-scp-admin-workspace.php",
    "'mad4b-ai-knowledge-workspace' =>",
    "'mad4b-assistant-workspace' =>",
    "'mad4b-growth-providers' =>",
    "'mad4b-operational-remediation' =>",
)
print("ADMIN_ACTION_NOTICE_SOURCE_CONTRACT: PASS (10 unique source files; browser/runtime not implied)")
