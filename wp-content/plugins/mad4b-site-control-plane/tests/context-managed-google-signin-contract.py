#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
drive = (ROOT / "includes/class-mad4b-scp-google-drive-context.php").read_text(encoding="utf-8")
ui = (ROOT / "includes/class-mad4b-scp-context-admin-ui.php").read_text(encoding="utf-8")

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

print("MAD4B natural Managed Google Sign-In contract PASS")
