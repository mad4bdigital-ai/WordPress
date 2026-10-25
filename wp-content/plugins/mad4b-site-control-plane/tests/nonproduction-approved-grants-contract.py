#!/usr/bin/env python3
"""Execute nonproduction previously approved grant reuse regressions without WordPress mutation."""
from pathlib import Path
import subprocess

PLUGIN = Path(__file__).resolve().parents[1]
SOURCE = PLUGIN / "includes/class-mad4b-scp-nonproduction-approved-grants.php"
FIXTURE = PLUGIN / "tests/nonproduction-approved-grants-runtime.php"
code = SOURCE.read_text(encoding="utf-8")
for marker in (
    "MAD4B_SCP_Nonproduction_Approved_Grants",
    "'seal_verified'",
    "'captured_before_update'",
    "'audit_verified'",
    "'exact_profile_bound'",
    "'manifest_verified'",
    "'agent_verified'",
    "'write_inventory_verified'",
    "'approved_update_from_sha'",
    "grant_not_previously_approved",
    "invalid_or_duplicate_grant_pairs",
    "'mutation_performed' => false",
    "'production_allowed' => false",
    "'new_grants_allowed' => false",
):
    assert marker in code, "Missing fail-closed grant gate marker: " + marker

for command in (["php", "-l", str(FIXTURE)], ["php", str(FIXTURE)]):
    result = subprocess.run(
        command, cwd=PLUGIN, text=True, capture_output=True, timeout=45,
    )
    if result.returncode != 0:
        raise SystemExit("NONPRODUCTION_GRANT_FIXTURE_FAILED: " + result.stdout + result.stderr)
    if len(command) == 2:
        assert "NONPRODUCTION_APPROVED_GRANTS: PASS (7 cases)" in result.stdout, result.stdout

print("NONPRODUCTION_APPROVED_GRANTS_CONTRACT: PASS")
