#!/usr/bin/env python3
"""Run the focused original WP App Password bridge denial suite, without changing its security contract."""
import os
import subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1]
suite=root/"wp-content/plugins/mad4b-site-control-plane/tests/application-password-agent-bridge-hermetic.php"
result=subprocess.run([os.getenv("MAD4B_PHP","php"),str(suite)],cwd=root,capture_output=True,text=True,timeout=120)
assert result.returncode==0 and "SUMMARY pass=16 fail=0" in result.stdout, (result.returncode,result.stdout[-500:],result.stderr[-500:])
for marker in ("foreign_route_denied","non_basic_auth_header_denied","revoked_exact_agent_grant_denied","production_mcp_bridge_denied","unenrolled_origin_denied"):
    assert "PASS "+marker in result.stdout, marker
print("APPLICATION_PASSWORD_BRIDGE_EXISTING_RUNTIME: PASS 16 original-auth boundary checks")
