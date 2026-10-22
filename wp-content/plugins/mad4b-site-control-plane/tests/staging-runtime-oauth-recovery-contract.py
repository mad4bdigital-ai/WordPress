#!/usr/bin/env python3
"""Executed regression for ARE Staging runtime, OAuth metadata and read-only isolation."""
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[1]
inc = root / "includes"
sources = {
    name: (inc / ("class-mad4b-scp-" + name + ".php")).read_text(encoding="utf-8")
    for name in (
        "schema-lifecycle",
        "runtime-convergence",
        "reconnect-hardening",
        "mcp-client-compatibility",
        "oauth-resource-bridge",
    )
}
schema = sources["schema-lifecycle"]
schema_init = schema.split("public static function maybe_schedule()", 1)[1].split(
    "public static function reconcile_scheduled()", 1
)[0]
assert "MAD4B_SCP_Runtime_Convergence::CHECKPOINT_OPTION" in schema_init
assert "'pending_manual_resume' === ( $checkpoint['state'] ?? '' )" in schema_init
assert schema_init.index("get_option(") < schema_init.index("$grace = self::restart_grace();")
runtime = sources["runtime-convergence"]
assert "private static function superseded_checkpoint_mcp_read_safe(" in runtime
assert "'historical_mcp_read_safe' => $historical_read_safe && $active" in runtime
assert "array( 'authority_binding:owner_gate' ) !== ( $graph['required_blockers'] ?? null )" in runtime
assert "'mad4b_automation_automation_kill_switch' !== ( $checkpoint['resume_blocker'] ?? '' )" in runtime
assert "'production' !== ( $graph['environment'] ?? '' )" not in runtime.split(
    "private static function superseded_checkpoint_mcp_read_safe(", 1
)[1].split("private static function superseded_cached_release_only(", 1)[0]
reconnect = sources["reconnect-hardening"]
assert "'/mcp/mad4b-read' === $route" in reconnect
assert "&& ! empty( $restart_grace['historical_mcp_read_safe'] )" in reconnect
assert "if ( ! empty( $restart_grace['active'] ) && ! $historical_read_admission )" in reconnect
client = sources["mcp-client-compatibility"]
metadata = client.split("public static function metadata_for_path(", 1)[1].split(
    "public static function serve_well_known_metadata()", 1
)[0]
assert "MAD4B_SCP_OAuth_Resource_Bridge::runtime_identity_status()" in metadata
assert "MAD4B_SCP_OAuth_Resource_Bridge::status()" not in metadata
assert "if ( ! self::oauth_discovery_ready( $status ) )" in metadata
oauth = sources["oauth-resource-bridge"]
assert "$protected_resource_discovery = is_string( $request_path )" in oauth
assert "MAD4B_SCP_MCP_Client_Compatibility::is_well_known_path( $request_path )" in oauth
stepup = oauth.split("public static function authority_step_up_scope_available()", 1)[1].split(
    "public static function resource_identifier(", 1
)[0]
assert "if ( $write_reconciliation ) return true;" in stepup
assert stepup.index("if ( $write_reconciliation ) return true;") < stepup.index(
    "MAD4B_SCP_Staging_Write_Candidate_Binding::chatgpt_step_up_tools()"
)
for name in sources:
    subprocess.run(
        ["php", "-l", str(inc / ("class-mad4b-scp-" + name + ".php"))],
        check=True, timeout=12, capture_output=True, text=True
    )
for fixture, marker in (
    ("staging-schema-manual-resume-runtime.php", "SCHEMA_MANUAL_PAUSE: PASS"),
    ("oauth-stepup-short-circuit-runtime.php", "OAUTH_STEPUP_SHORT_CIRCUIT: PASS"),
    ("runtime-historical-read-isolation-runtime.php", "RUNTIME_HISTORICAL_READ_ISOLATION: PASS"),
):
    p = subprocess.run(
        ["php", str(root / "tests" / fixture)],
        check=True, timeout=30, capture_output=True, text=True
    )
    assert marker in p.stdout, f"{fixture}: expected marker absent"
    assert not p.stderr.strip(), f"{fixture}: unexpected PHP warnings"
print("STAGING_RUNTIME_OAUTH_RECOVERY: PASS")
