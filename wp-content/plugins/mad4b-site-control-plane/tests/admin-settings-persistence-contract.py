#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
inc = root / "includes"

loader = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
persistence = (inc / "class-mad4b-scp-admin-settings-persistence.php").read_text(encoding="utf-8")
client = (root / "assets/admin-settings-persistence.js").read_text(encoding="utf-8")
site_profile = (inc / "class-mad4b-scp-site-profile.php").read_text(encoding="utf-8")
site_admin = (inc / "class-mad4b-scp-site-profile-admin.php").read_text(encoding="utf-8")
context = (inc / "class-mad4b-scp-context-authority.php").read_text(encoding="utf-8")
google = (inc / "class-mad4b-scp-google-drive-context.php").read_text(encoding="utf-8")
context_admin = (inc / "class-mad4b-scp-context-admin-ui.php").read_text(encoding="utf-8")
oauth = (inc / "class-mad4b-scp-staging-oauth-autoconfig.php").read_text(encoding="utf-8")
chatgpt_ui = (inc / "class-mad4b-scp-chatgpt-connection-admin-ui.php").read_text(encoding="utf-8")
enrollment = (inc / "class-mad4b-scp-site-profile-enrollment.php").read_text(encoding="utf-8")
write_enable = (inc / "class-mad4b-scp-site-profile-write-enablement.php").read_text(encoding="utf-8")

for marker in [
    "class-mad4b-scp-admin-settings-persistence.php",
]:
    assert marker in loader, marker

for marker in [
    "final class MAD4B_SCP_Admin_Settings_Persistence",
    "admin_enqueue_scripts",
    "current_user_can( 'manage_options' )",
    "0 !== strpos( $page, 'mad4b-control-plane' )",
    "assets/admin-settings-persistence.js",
]:
    assert marker in persistence, marker

for marker in [
    "$asset_path = MAD4B_SCP_DIR . 'assets/admin-settings-persistence.js';",
    "$asset_version = MAD4B_SCP_VERSION;",
    "hash_file( 'sha256', $asset_path )",
    "substr( $content_sha, 0, 12 )",
    "filemtime( $asset_path )",
]:
    assert marker in persistence, marker

for marker in [
    ".mad4b-settings-ajax-form",
    "persistence_verified !== true",
    "data-mad4b-one-time-confirm",
    'input[name="expected_revision"]',
    'input[name="expected_profile_digest"]',
    "cache: \"no-store\"",
]:
    assert marker in client, marker

# Settings AJAX actions must be registered immediately after the admin classes
# are loaded and before heavy runtime bootstrap begins. This prevents
# admin-ajax.php from returning WordPress's literal "0" sentinel when a later
# performance/security bootstrap short-circuits the request lifecycle.
early_context = loader.index("MAD4B_SCP_Context_Admin_UI::boot();")
early_site = loader.index("MAD4B_SCP_Site_Profile_Admin::boot();")
early_persistence = loader.index("MAD4B_SCP_Admin_Settings_Persistence::boot();")
early_oauth_actions = loader.index("MAD4B_SCP_Staging_OAuth_Autoconfig::boot_admin_actions_early();")
heavy_boot = loader.index("MAD4B_SCP_Site_Profile::bootstrap();")
assert early_context < heavy_boot
assert early_site < heavy_boot
assert early_persistence < heavy_boot
assert early_oauth_actions < heavy_boot
assert "public static function boot_admin_actions_early()" in oauth
assert "self::boot_admin_actions();" in oauth

# The browser client may fall back to the existing admin-post.php endpoint only
# for the exact unregistered-action sentinel. It must not hide real HTTP/JSON,
# nonce, governance, or readback failures.
for marker in [
    'response.status === 400 && trimmedResponse === "0"',
    "HTMLFormElement.prototype.submit.call(form)",
    "mad4b_settings_ajax_action_unregistered",
    "mad4b_settings_non_json_response",
    '"Accept": "application/json"',
]:
    assert marker in client, marker

sentinel = client.split('if (response.status === 400 && trimmedResponse === "0")', 1)[1].split("var payload;", 1)[0]
assert "HTMLFormElement.prototype.submit.call(form)" in sentinel
assert "payload.data" not in sentinel
assert 'trimmedResponse === "0"' in sentinel

# Site Profile writes must survive stale persistent Options caches and prove exact
# readback before returning success.
for marker in [
    "public static function persist_record_exact( array $record )",
    "wp_cache_delete( self::OPTION, 'options' )",
    "wp_cache_delete( 'notoptions', 'options' )",
    "wp_cache_delete( 'alloptions', 'options' )",
    "wp_cache_flush_group( 'options' )",
    "self::option_values_equal( $readback, $record )",
    "could not be persisted and verified by readback",
]:
    assert marker in site_profile, marker

for marker in [
    "add_action( 'wp_ajax_' . self::ACTION_SAVE",
    "self::require_save_request();",
    "'persistence_verified' => true",
    "mad4b_site_profile_readback_mismatch",
    'class="mad4b-settings-ajax-form"',
    "data-mad4b-settings-feedback",
]:
    assert marker in site_admin, marker

# Disable/revoke/governance actions remain explicit and outside the shared AJAX
# settings form contract.
disable_section = site_admin.split("public static function handle_disable()", 1)[1].split("private static function is_ajax_request()", 1)[0]
assert "disable_authority" in disable_section
assert "wp_send_json_success" not in disable_section

# Context ordinary settings get verified AJAX readback.
for marker in [
    "self::ACTION_SAVE_PROFILE",
    "self::ACTION_UPDATE_SOURCE_POLICY",
    "mad4b_context_profile_readback_mismatch",
    "mad4b_context_source_policy_readback_mismatch",
    "mad4b_context_review_policy_readback_mismatch",
    "'persistence_verified' => true",
    "mad4b-settings-ajax-form",
    "data-mad4b-settings-feedback",
    "data-mad4b-one-time-confirm",
    "intentionally resets after save/reload",
]:
    assert marker in context_admin, marker

for marker in [
    "private static function clear_option_read_cache( $name, $aggressive = false )",
    "wp_cache_delete( 'notoptions', 'options' )",
    "wp_cache_delete( 'alloptions', 'options' )",
    "wp_cache_flush_group( 'options' )",
    "self::option_values_equal( get_option( $name, false ), $value )",
]:
    assert marker in context, marker

# Google settings already had cache/readback hardening before this change and
# remain on their specialized AJAX UI while sharing the verified response
# semantics.
for marker in [
    "private static function clear_option_read_cache( $name, $aggressive = false )",
    "private static function write_option( $name, $value )",
    "wp_cache_flush_group( 'options' )",
]:
    assert marker in google, marker
assert "'persistence_verified' => true" in context_admin

# An already-persisted AI delegation is idempotent after reload; confirmation is
# required only for a state change.
policy = context.split("public static function set_review_policy", 1)[1].split("public static function sources()", 1)[0]
assert "Persisted state is authoritative" in policy
assert policy.index("self::review_policy()") < policy.index("if ( 'human_and_ai' === $mode )")
assert "current['persistence_verified'] = true" in policy
assert "Changing delegated AI Agent review requires explicit administrator confirmation." in policy

# Re-enrollment and write enablement must use the same verified Site Profile
# persistence primitive rather than raw update_option semantics.
assert "MAD4B_SCP_Site_Profile::persist_record_exact( $next )" in enrollment
assert "MAD4B_SCP_Site_Profile::persist_record_exact( $next )" in write_enable

# Production read-only OAuth is a setting, not authority. It may use AJAX but
# must verify persisted readback; normal Production write authority remains
# outside this path.
for marker in [
    "wp_ajax_mad4b_enable_production_readonly_oauth",
    "wp_ajax_mad4b_disable_production_readonly_oauth",
    "mad4b_production_readonly_oauth_readback_mismatch",
    "'persistence_verified' => true",
    "private static function persist_production_option( array $record )",
    "private static function delete_production_option_verified()",
    "wp_cache_delete( 'notoptions', 'options' )",
    "wp_cache_flush_group( 'options' )",
]:
    assert marker in oauth, marker
assert 'class="mad4b-settings-ajax-form"' in chatgpt_ui

print("mad4b.admin-settings-persistence.v6: PASS")
