#!/usr/bin/env python3
"""Executes authenticated-direct MCP PHP behavior, with structural registration checks."""
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
PHP = [
    ROOT / "includes/class-mad4b-scp-direct-mode.php",
    ROOT / "includes/class-mad4b-scp-servers.php",
    ROOT / "mad4b-site-control-plane.php",
    ROOT / "tests/direct-mcp-runtime.php",
]


def run(args: list[str]) -> str:
    p = subprocess.run(args, cwd=ROOT, capture_output=True, text=True, timeout=120)
    if p.returncode:
        sys.stderr.write(p.stdout + p.stderr)
        raise RuntimeError(f"Direct MCP test failed: {args}")
    return p.stdout


def main() -> None:
    for source in PHP:
        run(["php", "-l", str(source)])
    runtime = run(["php", str(PHP[-1])])
    assert "DIRECT_MCP_RUNTIME: PASS" in runtime
    registration = PHP[1].read_text(encoding="utf-8")
    bootstrap = PHP[2].read_text(encoding="utf-8")
    direct = PHP[0].read_text(encoding="utf-8")
    assert "self::transport_permission( 'mad4b-direct'" in registration
    assert "'mad4b-direct', 'MAD4B Direct Authenticated MCP'" in registration
    assert "MAD4B_SCP_Direct_Mode::boot()" in bootstrap
    assert "'mad4b-direct'" in registration
    assert "function_exists( 'wp_register_ability' )" in direct
    assert "MAD4B_SCP_Policy::can_connect_user" in direct
    assert "current_user_can( 'edit_post', $id )" in direct
    assert "mad4b_governed_write_authority" not in direct
    assert "ABSPATH" in direct
    print(runtime.strip())
    print("DIRECT_MCP_CONTRACT: PASS (four PHP lint checks and hermetic behavioral fixture)")


if __name__ == "__main__":
    main()
