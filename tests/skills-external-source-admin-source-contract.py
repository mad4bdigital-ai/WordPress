#!/usr/bin/env python3
"""Source-bound and behavioral regression for external Skill source administration.

The PHP backend runs only synthetic WordPress options and fake provider HTTP.
No live WordPress, user, database, or external network resource is touched.
"""
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"
admin_path = PLUGIN / "includes/class-mad4b-scp-skills-admin-ui.php"
handler_path = PLUGIN / "includes/class-external-skill-sources.php"
fixture_path = PLUGIN / "tests/external-skill-sources-runtime.php"

def ensure(value, detail):
    if not value:
        raise AssertionError(detail)

for path in (admin_path, handler_path, fixture_path):
    ensure(path.is_file() and not path.is_symlink(), "Missing or symlink test input: " + path.name)
admin = admin_path.read_text(encoding="utf-8")
native = handler_path.read_text(encoding="utf-8")
# Verify that all externally sourced text remains confined to the
# Administrator-only original editor, not the company read workspace.
ensure("public static function render_page() {\n\t\tif ( true !== current_user_can( 'manage_options' ) ) wp_die(" in admin,
       "Skills authoring page no longer needs affirmative Administrator permission")
for method, action in (("source_add", "add"), ("source_remove", "remove")):
    pattern = (
        r"if\s*\(\s*'" + method + r"'\s*===\s*\$action\s*&&\s*"
        r"class_exists\(\s*'SCP_External_Skill_Sources',\s*false\s*\)\s*\)"
        r"\s*\$message\s*=\s*SCP_External_Skill_Sources::handle_admin"
        r"\(\s*'" + action + r"',\s*\$_POST\s*\);"
    )
    ensure(re.search(pattern, admin), "Source operation is not routed to original handler: " + method)
ensure("SCP_External_Skill_Sources::render_admin();" in admin
       and "if ( class_exists( 'SCP_External_Skill_Sources', false ) )" in admin,
       "Native sources UI must remain conditionally rendered")
ensure("check_admin_referer( 'external_skill_sources', 'external_skill_sources_nonce' );" in native,
       "Original source-management nonce guard missing")
ensure("public static function handle_admin( $action, $input )" in native
       and "if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'skill_source_access_denied'" in native,
       "Original source-management actor permission guard missing")
ensure("public static function render_admin() {\n        if ( ! current_user_can( 'manage_options' ) ) return;" in native,
       "Original source-editor visibility guard missing")
# Execute original source backend's real code under its no-network fake WP.
run = subprocess.run(["php", str(fixture_path)], cwd=ROOT, text=True,
                     capture_output=True, timeout=25, check=False)
ensure(run.returncode == 0 and "External Skill Source fixtures: PASS" in run.stdout,
       "Original PHP backend behavioral fixture failed: " + (run.stdout + run.stderr)[-1200:])
print("PASS: Source callbacks retain nonce/admin-only ceiling; original native fake-WP behavioral checks executed")
