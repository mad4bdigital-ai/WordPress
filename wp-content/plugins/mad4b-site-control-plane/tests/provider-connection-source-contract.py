#!/usr/bin/env python3
"""Fail-closed source contract for provider-neutral connection maintenance.

This is a no-network source test, not PHP/browser/release certification.
"""
from pathlib import Path

PLUGIN = Path(__file__).resolve().parents[1]
INC = PLUGIN / "includes"


def check(file, *markers):
    text = file.read_text(encoding="utf-8")
    for marker in markers:
        if marker not in text:
            raise AssertionError(f"PROVIDER_CONNECTION_CONTRACT_MISSING:{file.name}:{marker}")


check(
    INC / "class-mad4b-scp-google-drive-context.php",
    "public static function refresh_existing_connection(",
    "public static function encrypted_grant_identity_sha256()",
    "self::refresh_retry_blocker(",
    "self::access_token()",
    "mad4b_google_refresh_readback_failed",
    "no_scope_widening",
)
check(
    INC / "class-mad4b-scp-context-admin-ui.php",
    "ACTION_REFRESH_GOOGLE",
    "handle_refresh_google()",
    "expected_google_grant_sha256",
    "confirm_google_refresh",
    "Refresh Connection",
    "Reconnect with Google",
    "google_reauthorization_action",
    "mad4b_google_reconnect_stale_or_unconfirmed",
    "revocation_pending",
)
check(
    INC / "class-mad4b-scp-provider-connection-center.php",
    "mad4b/provider-connection-discover",
    "mad4b/provider-connection-plan",
    "mad4b/provider-connection-refresh",
    "can_refresh(",
    "claim_mutation(",
    "verified_bearer_client_is(",
    "_mad4b_approval_ticket_id",
    "remote_refresh_reviewed",
    "production_mutation_allowed' => false",
    "owner_handoff_url",
)
check(
    INC / "class-mad4b-scp-provider-connections-admin-ui.php",
    "class MAD4B_SCP_Provider_Connections_Admin_UI",
    "schedule_submenu(",
    "Open connection controls",
    "exact one-time approval",
)
check(
    INC / "class-mad4b-scp-staging-write-grant-reconciliation.php",
    "'mad4b/provider-connection-refresh' => 'core'",
)
check(
    PLUGIN / "tests/staging-write-grant-reviewed-inventory-runtime.php",
    "Provider token refresh must not be automatically granted on Production.",
)
check(
    PLUGIN / "mad4b-site-control-plane.php",
    "class-mad4b-scp-provider-connection-center.php",
    "class-mad4b-scp-provider-connections-admin-ui.php",
)
print("PASS: provider connection authorization, staged grant and UI source invariants")
