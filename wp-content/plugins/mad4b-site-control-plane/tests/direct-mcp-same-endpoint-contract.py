#!/usr/bin/env python3
"""No-tenth-server Direct/Governed MCP contract with behavioral PHP execution.

This is an offline regression. It does NOT certify live ChatGPT OAuth or MCP.
"""
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
SOURCES = (
    ROOT / "includes/class-mad4b-scp-direct-profile.php",
    ROOT / "includes/class-mad4b-scp-servers.php",
    ROOT / "mad4b-site-control-plane.php",
    ROOT / "tests/direct-mcp-same-endpoint-runtime.php",
)


def run(args: list[str]) -> str:
    outcome = subprocess.run(args, cwd=ROOT, capture_output=True, text=True, timeout=120)
    if outcome.returncode:
        sys.stderr.write(outcome.stdout + outcome.stderr)
        raise RuntimeError("Direct profile regression command failed: " + args[1])
    return outcome.stdout


def main() -> None:
    for item in SOURCES:
        run(["php", "-l", str(item)])
    php_output = run(["php", str(SOURCES[-1])])
    assert "DIRECT_MCP_RUNTIME: PASS" in php_output

    direct = SOURCES[0].read_text(encoding="utf-8")
    servers = SOURCES[1].read_text(encoding="utf-8")
    main_plugin = SOURCES[2].read_text(encoding="utf-8")
    assert "MAD4B_SCP_Direct_Profile::boot()" in main_plugin
    assert "self::chatgpt_direct_read_transport_tools()" in servers
    assert "MAD4B_SCP_Direct_Profile::catalog_tools()" in servers
    assert "MAD4B_SCP_Direct_Profile::write_tools()" in servers
    assert "MAD4B_SCP_Direct_Profile::read_tools()" in servers
    assert "$management, $profile_tools, $dynamic_optional" in servers
    assert "'mad4b-direct', 'MAD4B Direct Authenticated MCP'" not in servers
    assert "'mad4b-direct'" not in servers
    assert "const SERVER = 'mad4b-chatgpt'" in direct
    assert "MAD4B_SCP_Policy::can_connect_user" in direct
    assert "verified_bearer_active()" in direct
    assert "current_user_can( 'edit_post', $id )" in direct
    assert "mad4b_execution_boundary" not in direct
    assert "MAD4B_SCP_Authorization::claim_mutation" not in direct
    print(php_output.strip())
    print("DIRECT_SAME_ENDPOINT_CONTRACT: PASS (PHP behavior, nine-server invariant, strict ability mount)")


if __name__ == "__main__":
    main()
