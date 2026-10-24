#!/usr/bin/env python3
"""Trusted executable gate for read-only Site Profile convergence policy."""
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]
POLICY = ROOT / "includes/class-mad4b-scp-environment-convergence-policy.php"
CONTINUATION = ROOT / "includes/class-mad4b-scp-post-update-continuation.php"
TEST = ROOT / "tests/environment-convergence-policy-runtime.php"

policy = POLICY.read_text(encoding="utf-8")
continuation = CONTINUATION.read_text(encoding="utf-8")
assert "profile_environment_authoritative" in policy
assert "wordpress_profile_mismatch" in policy
assert "automatic_grant_mutation_authorized' => false" in policy
assert "'mutation_performed' => false" in policy
assert "MAD4B_SCP_Environment_Convergence_Policy::current()" in continuation
assert "'exact_nonproduction_environment_authority_required'" in continuation
for command in (["php", "-l", str(TEST)], ["php", str(TEST)]):
    p = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, timeout=45)
    if p.returncode != 0:
        raise SystemExit("ENVIRONMENT_POLICY_PHP_FAILED: " + p.stdout + p.stderr)
    if len(command) == 2:
        assert "ENVIRONMENT_CONVERGENCE_POLICY: PASS (9 cases)" in p.stdout, p.stdout
print("ENVIRONMENT_CONVERGENCE_POLICY_CONTRACT: PASS")
