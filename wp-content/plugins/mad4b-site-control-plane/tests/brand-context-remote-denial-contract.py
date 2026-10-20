#!/usr/bin/env python3
"""Fail-closed Brand Context remote dispatch checks (Issue #446)."""
from pathlib import Path
import os
import shutil
import subprocess

ROOT = Path(__file__).resolve().parents[1]
AUTH = (ROOT / "includes/class-mad4b-scp-authorization.php").read_text(encoding="utf-8")
ABILITIES = (ROOT / "includes/class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
CONTEXT = (ROOT / "includes/class-mad4b-scp-context-authority.php").read_text(encoding="utf-8")
for marker in (
    "remote_permission_denial_code( $ability_name )",
    "self::$remote_permission_denial_codes",
    "unset( self::$remote_permission_denial_codes[ $ability_name ] )",
):
    if marker not in AUTH:
        raise SystemExit(f"BRAND_CONTEXT: missing request-scoped permission invariant: {marker}")
for marker in (
    "inspect_exact_permission_denial_then_replan",
    "inspect_google_connection_then_replan",
    "pre_target_failure_code",
    "'target_execution_entered' => false",
    "'blind_retry_allowed' => false",
    "MAD4B_SCP_Authorization::remote_permission_denial_code",
):
    if marker not in ABILITIES:
        raise SystemExit(f"BRAND_CONTEXT: dispatch fail-closed invariant missing: {marker}")
for marker in (
    "'source_binding_diagnostics' => $source_diagnostics",
    "'google_connection_diagnostics' => $google_connection",
    "'diagnostics_authorizing' => false",
    "'root_identity_sha256' => '' === $root ? '' : hash( 'sha256', $root )",
    "reconnect_existing_google_authorization",
    "plan_existing_grant_refresh",
):
    if marker not in CONTEXT:
        raise SystemExit(f"BRAND_CONTEXT: missing safe source/connection diagnostic: {marker}")
section = CONTEXT.split("// Read-only, bounded diagnostics: site-owned governed sources only.", 1)[1].split("$ready = empty( $blockers );", 1)[0]
if "'external_root_id' =>" in section or "'access_token' =>" in section or "'refresh_token' =>" in section:
    raise SystemExit("BRAND_CONTEXT: diagnostics leak provider credentials or raw folder identifiers")
php = os.environ.get("MAD4B_TEST_PHP_BIN") or shutil.which("php")
if not php:
    raise SystemExit("BRAND_CONTEXT: PHP runtime required")
fixture = ROOT / "tests/brand-context-remote-denial-runtime.php"
r = subprocess.run([php, str(fixture)], text=True, capture_output=True, timeout=20, check=False)
if r.returncode or "BRAND_CONTEXT_REMOTE_DENIAL: PASS" not in r.stdout:
    raise SystemExit(f"BRAND_CONTEXT: runtime failure: {r.stdout[-1200:]} {r.stderr[-1200:]}")
print("BRAND_CONTEXT_REMOTE_DENIAL: PASS (no cross-target leak, no approval bypass, exact replan required)")
