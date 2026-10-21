#!/usr/bin/env python3
"""Run real PHP denial evidence fixture via trusted Python readiness runner."""
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]
SOURCES = [
    ROOT / "includes/class-mad4b-scp-mcp-transport-admission.php",
    ROOT / "includes/class-mad4b-scp-oauth-resource-bridge.php",
    ROOT / "includes/class-mad4b-scp-native-runtime-diagnostics.php",
    ROOT / "includes/class-mad4b-scp-runtime-components-admin-ui.php",
]
TEST = ROOT / "tests/mcp-oauth-rejection-evidence-runtime.php"


def run(*args):
    p = subprocess.run(args, cwd=ROOT, timeout=30, capture_output=True, text=True, check=False)
    assert p.returncode == 0, f"Failed {args[0]} validation: {p.stderr[-1800:]}{p.stdout[-800:]}"
    return p.stdout


for source in SOURCES + [TEST]:
    assert source.is_file(), f"Missing source: {source.name}"
    assert "No syntax errors detected" in run("php", "-l", str(source))
oauth = SOURCES[1].read_text(encoding="utf-8")
admission = SOURCES[0].read_text(encoding="utf-8")
native = SOURCES[2].read_text(encoding="utf-8")
ui = SOURCES[3].read_text(encoding="utf-8")
for marker in ("function authenticate_rest_request_impl(", "mad4b_oauth_evaluation_exception",
               "self::reset_verified_bearer_context( true )",
               "observe_rejection( 'oauth', (int) $status, (string) $code )",
               "'WWW-Authenticate'", "'Cache-Control'"):
    assert marker in oauth, f"OAuth wire/fail-closed contract drift: {marker}"
for marker in ("observe_rejection( 'transport', $status, $code )",
               "observe_rejection( 'jsonrpc', 200, 'mcp_json_rpc_error' )",
               "REJECTION_MAX_EVENTS = 12", "REJECTION_OPTION",
               "'staging' !== wp_get_environment_type()"):
    assert marker in admission, f"Transport evidence contract drift: {marker}"
assert "'mcp_rejections'" in native, "Governed Diagnostics lacks denial readback"
for marker in (
    "MCP OAuth and transport rejection evidence (Staging only)",
    "No bounded OAuth/MCP rejection evidence available",
    "A locally registered MCP route is not proof of external OAuth acceptance",
    "esc_html( (string) ( $denial['stage'] ?? '' ) )",
    "esc_html( (string) ( $denial['code'] ?? '' ) )",
    "esc_html( (string) ( $denial['correlation_id'] ?? '' ) )",
):
    assert marker in ui, f"Protected admin denial evidence missing or not escaped: {marker}"

assert "MCP_OAUTH_REJECTION_EVIDENCE_RUNTIME: PASS" in run("php", str(TEST))
print("MCP_OAUTH_REJECTION_EVIDENCE_CONTRACT: PASS")
