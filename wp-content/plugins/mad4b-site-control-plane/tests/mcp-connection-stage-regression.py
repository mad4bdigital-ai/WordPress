#!/usr/bin/env python3
"""Execute offline OAuth connection evidence and route classification regressions."""
from pathlib import Path
import subprocess
import sys
ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"
TESTS = PLUGIN / "tests"

def checked(args, expected=""):
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, timeout=65)
    if result.returncode or (expected and expected not in result.stdout):
        sys.stderr.write(result.stdout[-3000:] + result.stderr[-3000:])
        raise RuntimeError("MCP regression command failed: " + repr(args[:2]))
for name in ("includes/class-mad4b-scp-servers.php",
             "includes/class-mad4b-scp-mcp-connection-stage-diagnostics.php",
             "includes/class-mad4b-scp-chatgpt-connection-admin-ui.php",
             "mad4b-site-control-plane.php",
             "tests/mcp-connection-stage-diagnostics-runtime.php"):
    checked(["php", "-l", str(PLUGIN / name)], "No syntax errors detected")
checked(["php", str(TESTS / "mcp-connection-stage-diagnostics-runtime.php")], "MCP_CONNECTION_STAGES: PASS")
checked([sys.executable, str(TESTS / "mcp-client-compatibility-contract.py")])
server = (PLUGIN / "includes/class-mad4b-scp-servers.php").read_text(encoding="utf-8")
part = server.split("private static function current_request_server_id()", 1)[1].split(
    "private static function should_materialize_server_tools", 1)[0]
assert "'__mad4b_oauth_metadata_only__'" in part
assert "MAD4B_SCP_MCP_Client_Compatibility::is_well_known_path" in part
assert part.index("is_well_known_path") < part.index("endpoint_diagnostic_server_id")
assert "return '' === (string) $target_server_id ||" in server
print("MCP_CONNECTION_STAGE_REGRESSION: PASS (typed evidence, no eager metadata tools, PHP behavior)")
