#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"

policy = (PLUGIN / "includes/class-mad4b-scp-provider-diagnostic-policy.php").read_text(encoding="utf-8")
plugin = (PLUGIN / "includes/class-mad4b-scp-plugin.php").read_text(encoding="utf-8")
main = (PLUGIN / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
observer = (PLUGIN / "includes/class-mad4b-scp-live-acceptance-observer.php").read_text(encoding="utf-8")
qm = (PLUGIN / "includes/class-mad4b-scp-query-monitor-evidence-bridge.php").read_text(encoding="utf-8")
convergence = (PLUGIN / "includes/class-mad4b-scp-runtime-convergence.php").read_text(encoding="utf-8")

for marker in (
    "current_request_is_foreign_rest",
    "current_request_is_wordpress_cron",
    "current_request_is_foreign_wp_admin",
    "current_request_is_wordpress_lifecycle_admin",
    "current_wp_admin_script",
    "current_request_is_zero_touch_surface",
    "zero_touch_reason",
    "foreign_rest_zero_touch",
    "wordpress_cron_zero_touch",
    "foreign_wp_admin_zero_touch",
    "wp_admin_default_zero_touch",
    "wordpress_lifecycle_admin_explicit_opt_in",
    "current_request_is_external_provider_rest",
):
    assert marker in policy, marker

boot = plugin.split("public static function boot()", 1)[1].split("public static function boot_oauth_transport_if_effective", 1)[0]
assert "current_request_is_zero_touch_surface()" in boot
assert boot.index("current_request_is_zero_touch_surface()") < boot.index("MAD4B_SCP_Staging_OAuth_Autoconfig::bootstrap()")

capture = qm.split("public static function capture_and_flush()", 1)[1].split("private static function request_sample_id", 1)[0]
assert "current_request_is_zero_touch_surface()" in capture
assert capture.index("current_request_is_zero_touch_surface()") < capture.index("request_build_fingerprint()")
assert capture.index("current_request_is_zero_touch_surface()") < capture.index("load_telemetry(")

staging_capture = observer.split("public static function staging_capture_allowed()", 1)[1]
assert "current_request_is_zero_touch_surface()" in staging_capture

gate = convergence.split("private static function convergence_trigger_allowed()", 1)[1].split("private static function detect_lightweight_runtime_drift", 1)[0]
assert "current_request_is_zero_touch_surface()" in gate
assert "wp_doing_cron() ) return false" in gate
assert "wp_doing_cron() ) return true" not in gate


# Request classification and zero-touch fencing must happen before the two
# direct pre-init bootstraps that can read Site Profile/authority state.
assert "MAD4B_SCP_MCP_Request_Scope::bootstrap();" in main
assert "$mad4b_zero_touch_request" in main
assert "MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface()" in main
assert main.index("MAD4B_SCP_MCP_Request_Scope::bootstrap();") < main.index("MAD4B_SCP_Skill_Autoconfig::bootstrap();")
assert main.index("$mad4b_zero_touch_request") < main.index("MAD4B_SCP_Skill_Autoconfig::bootstrap();")
assert main.index("$mad4b_zero_touch_request") < main.index("MAD4B_SCP_Staging_Write_Authority::bootstrap();")

print("request-serving zero-touch contract: PASS")
