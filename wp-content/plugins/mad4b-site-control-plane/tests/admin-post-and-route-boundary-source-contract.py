#!/usr/bin/env python3
"""Fail-closed source contract for owned admin UI action boundaries.

This is a *source* regression guard, NOT proof that WordPress served the
page, that user authority is valid, or that live button clicks succeeded.
No network requests, mutations or OAuth operations occur here.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "includes"


def source(relative: str) -> str:
    data = (ROOT / relative).read_text(encoding="utf-8")
    if not data.startswith("<?php"):
        raise AssertionError("ADMIN_BOUNDARY_NOT_PHP:" + relative)
    return data


def handler_boundary(relative: str, declaration: str, required: str = "POST required.") -> None:
    text = source(relative)
    pos = text.find(declaration)
    if pos < 0:
        raise AssertionError("ADMIN_BOUNDARY_HANDLER_MISSING:" + relative + ":" + declaration)
    # Admission must happen before any potentially effectful method call.
    prefix = text[pos:pos + 540]
    if "REQUEST_METHOD" not in prefix or required not in prefix:
        raise AssertionError("ADMIN_BOUNDARY_POST_MISSING:" + relative + ":" + declaration)


handler_boundary("class-mad4b-scp-site-profile-admin.php", "public static function handle_disable()")
handler_boundary("class-mad4b-scp-site-profile-admin.php", "public static function handle_legacy_migrate()")
handler_boundary("class-mad4b-scp-context-admin-ui.php", "private static function require_admin_request(")
handler_boundary("class-mad4b-scp-context-admin-ui.php", "private static function require_admin(")
handler_boundary("class-mad4b-scp-dynamic-content-pipeline-admin.php", "public static function save(){")
handler_boundary("class-mad4b-scp-browser-acceptance-admin-ui.php", "public static function save()")
handler_boundary("search/class-mad4b-scp-search-profile-admin.php", "public static function post()")
handler_boundary("search/class-mad4b-scp-search-experience.php", "public static function control_post()")

profile = source("class-mad4b-scp-site-profile-admin.php")
if "disable_authority_revision_missing" not in profile or "(int) $posted_revision" not in profile:
    raise AssertionError("ADMIN_BOUNDARY_PROFILE_CAS_MISSING")

context = source("class-mad4b-scp-context-admin-ui.php")
if "check_ajax_referer" not in context or "check_admin_referer" not in context:
    raise AssertionError("ADMIN_BOUNDARY_CONTEXT_NONCE_MISSING")
# Google OAuth callbacks are intentionally separate GET state/PKCE redirects,
# and must not be forced through the mutation-form POST-only helper.
if "public static function handle_google_callback()" not in context:
    raise AssertionError("ADMIN_BOUNDARY_GOOGLE_CALLBACK_MISSING")

browser = source("class-mad4b-scp-browser-acceptance-admin-ui.php")
if "Browser acceptance route declaration is always discoverable" not in browser:
    raise AssertionError("ADMIN_BOUNDARY_ROUTE_MISSING")
if "MAD4B_SCP_Admin_Route_Registry::register( MAD4B_SCP_Browser_Acceptance_Admin_UI::PAGE_SLUG, 'manage_options' )" not in browser:
    raise AssertionError("ADMIN_BOUNDARY_ROUTE_CAPABILITY_MISSING")
if "revision_guard( $expected, $stored )" not in browser:
    raise AssertionError("ADMIN_BOUNDARY_BROWSER_CAS_MISSING")

runtime = source("class-mad4b-scp-runtime-components-admin-ui.php")
for token in ("$stale_release_pointer", "$missing_release_target",
              "Certified runtime update target is missing or incomplete.",
              "version_compare( $target_version, $current_version, '<' )"):
    if token not in runtime:
        raise AssertionError("ADMIN_BOUNDARY_UPDATE_ADMISSION_MISSING:" + token)

workspace = source("class-mad4b-scp-admin-workspace.php")
for slug in ("mad4b-ai-knowledge-workspace", "mad4b-assistant-workspace",
             "mad4b-growth-providers", "mad4b-operational-remediation",
             "mad4b-browser-acceptance"):
    if f"'{slug}' => array(" not in workspace:
        raise AssertionError("ADMIN_BOUNDARY_WORKSPACE_PAGE_METADATA_MISSING:" + slug)

print("ADMIN_POST_AND_ROUTE_BOUNDARY_SOURCE_CONTRACT: PASS (8 POST handlers, 5 page declarations, independent live acceptance required)")
