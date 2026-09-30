#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
drive = (ROOT / "includes/class-mad4b-scp-google-drive-context.php").read_text(encoding="utf-8")
ui = (ROOT / "includes/class-mad4b-scp-context-admin-ui.php").read_text(encoding="utf-8")
live_truth = (ROOT / "includes/class-mad4b-scp-live-truth.php").read_text(encoding="utf-8")

def require(text: str, needle: str, label: str) -> None:
    assert needle in text, f"missing {label}: {needle}"

# Normal user path is Managed Google Sign-In, not site-local client credentials.
require(drive, "return self::AUTH_MODE_MANAGED;", "managed default authentication mode")
require(drive, "https://dev.mad4b.com", "non-production broker default")
require(drive, "https://auth.mad4b.com", "production broker default")
require(drive, "getenv( 'MAD4B_GOOGLE_MANAGED_OAUTH_SITE_SECRET' )", "server environment site signing secret")
require(drive, "getenv( 'MAD4B_GOOGLE_MANAGED_OAUTH_SITE_KEY_ID' )", "server environment site signing key id")
require(drive, "'key_source'", "non-secret key source diagnostic")
require(drive, "hash_hmac( 'sha256'", "HMAC request signing")
require(drive, "'secret_exposed' => false", "secret non-exposure")
require(drive, "'one_click_sign_in_ready' => $configured", "one-click readiness truth")

# Existing explicit Dedicated/Custom configurations remain backwards-compatible.
require(drive, "return self::AUTH_MODE_DEDICATED;", "dedicated config continuity")
require(drive, "return self::AUTH_MODE_CUSTOM;", "custom config continuity")
require(drive, "mad4b_google_drive_auth_mode_change_requires_disconnect", "mode-switch disconnect gate")

# Normal admin UX: one primary Google button; advanced modes are secondary.
require(ui, "mad4b-google-primary-signin", "primary sign-in panel")
require(ui, "Sign in with Google", "primary Google sign-in CTA")
require(ui, 'name="managed_signin" value="1"', "managed one-click intent")
require(ui, "mad4b-google-advanced", "advanced Google settings disclosure")
require(ui, "Dedicated Google OAuth", "Dedicated OAuth remains available")
require(ui, "Custom Google OAuth", "Custom OAuth remains available")
require(ui, "No Google Client ID or Client Secret is required", "non-technical managed setup message")

# Connection method and local credential/grant saves remain AJAX and bounded.
require(ui, "wp_ajax_", "WordPress AJAX handlers")
require(ui, "mad4b-context-ajax-form", "AJAX form contract")
require(ui, "mad4b-context-auth-mode-form", "auth-mode AJAX form")
require(ui, "fetch(window.ajaxurl", "admin-ajax transport")
require(ui, "requestSubmit", "radio change autosave")
require(ui, 'form.setAttribute("aria-busy","true")', "busy-state protection")
require(ui, 'controls.forEach(function(control){control.disabled=true;});', "duplicate-submit protection")
require(ui, 'input[type=password]', "password-field clearing")
# Connection Method is auto-save only; the legacy Save button must not exist.
assert "Save Connection Method" not in ui, "Save Connection Method must be removed from the admin UI source"

# One-click sign-in deliberately re-selects managed mode under the existing disconnect guard.
require(ui, "set_auth_mode( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_MANAGED )", "one-click managed mode selection")
require(ui, "managed_authorization_url", "managed authorization redirect")
require(ui, "Save Grant Expansion", "connected incremental grant expansion CTA")
require(ui, "Attempts to reduce currently granted authority remain blocked until provider revocation.", "connected scope-reduction safety copy")
assert "Grant selection is locked while a Google token exists." not in ui, "connected Google token must not block monotonic scope expansion in the admin UI"
assert "(! empty( $connection['connected'] ) || ! empty( $connection['revocation_pending'] ) || ! empty( $connection['token_unreadable'] ) ? ' disabled' : '')" not in ui, "connected state alone must not disable Workspace grant selectors"


# Dynamic Google consent and bottleneck controls stay explicit in the runtime contract.
require(drive, "'include_granted_scopes' => 'true'", "incremental Google OAuth consent")
require(drive, "REFRESH_FAILURE_COOLDOWN_SECONDS", "Google refresh retry cooldown")
require(drive, "mad4b_google_drive_reconnect_required", "terminal refresh fail-fast")
require(drive, "network_retry_suppressed", "refresh retry suppression evidence")
require(drive, "isset( $data['error']['details']['provider_code'] )", "nested broker provider error propagation")
require(drive, "'missing_requested_scopes'", "granular consent missing-scope persistence")
require(drive, "'complete_scope_grant'", "granular consent completeness state")
require(drive, "'current_granted_scope'", "managed refresh current granted-scope truth")
require(drive, "'managed_profile_reconsent_required'", "dynamic broker profile re-consent state")
require(drive, "'managed_profile_missing_scope_count'", "bounded dynamic profile missing-scope evidence")
require(drive, "MAX_SCAN_WALL_SECONDS", "bounded source scan wall clock")
require(drive, "private static $scan_deadline = 0.0;", "request-local source scan deadline")
require(drive, "private static function provider_timeout", "provider timeout deadline clamp")
require(drive, "mad4b_google_drive_scan_time_budget_exhausted", "expired scan budget fail-closed error")
require(drive, "if ( 'mad4b_google_drive_scan_time_budget_exhausted' === (string) $error->get_error_code() ) return false;", "scan budget never persisted as OAuth failure")
require(drive, "self::provider_timeout( 90 )", "Gemini extractor scan timeout clamp")
require(drive, "self::provider_timeout( 60 )", "generic extractor scan timeout clamp")
require(drive, "self::provider_timeout( 30 )", "Drive binary scan timeout clamp")
require(drive, "'reuse_existing' => $reuse_existing", "unchanged Drive asset reuse")
require(drive, "'reused_asset_count'", "scan reuse evidence")
require(drive, "MANAGED_SCOPE_PROFILE_FULL_OWNER = 'full_owner'", "managed dynamic full-owner profile")
require(drive, "'scope_profile' => $scope_profile", "managed broker profile request")
require(drive, "'broker_requested_scopes'", "broker scope provenance persistence")
require(drive, "mad4b_google_managed_scope_projection_invalid", "broker scope projection fail-closed")
require(drive, "mad4b_google_managed_granted_scope_unproven", "unproven managed scope rejection")
require(drive, "mad4b_google_workspace_grants_reduction_requires_revoke", "grant reduction revoke gate")
require(drive, "'incremental_consent_required'", "incremental expansion state")
require(drive, "mad4b_google_workspace_grants_reduction_requires_revoke", "scope reduction revoke gate")
require(drive, "incremental_consent_required", "incremental consent state")
require(drive, "scope_covers", "semantic Google scope implication")
require(drive, "Recovery path only", "cache-wide flush recovery-only path")
require(ui, "mad4b-google-incremental-consent-form", "incremental consent CTA form")
require(ui, "Authorize added Google access", "incremental consent CTA label")
require(ui, "Existing granted access stays active", "incremental consent continuity message")
require(ui, "MAD4B_SCP_Staging_Write_Authority::persisted_status()", "bounded persisted authority admin projection")
require(ui, "MAD4B_SCP_Staging_Write_Authority::candidate_binding_status()", "exact candidate admin fence")
require(ui, "MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability )", "targeted Context mount readback")
readiness_block = ui.split("private static function render_write_governance_readiness()", 1)[1].split("private static function governance_cell", 1)[0]
assert "MAD4B_SCP_Live_Truth::current_authority_status()" not in readiness_block, "Context Google admin render must not execute full Live Truth"
require(live_truth, "if ( 'mad4b-control-plane-context' === $page ) return;", "Context admin passive live-truth recovery bypass")
require(live_truth, "Explicit status/certification abilities still call current truth.", "explicit current-truth continuity")


print("MAD4B natural Managed Google Sign-In contract PASS")
