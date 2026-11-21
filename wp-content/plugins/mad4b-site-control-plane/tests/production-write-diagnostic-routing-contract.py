#!/usr/bin/env python3
"""Require live diagnostics to choose the enrolled site's authority path.

Read-only regression only. Does not grant Production or impersonate OAuth.
"""
from pathlib import Path
src = (Path(__file__).resolve().parent.parent / "includes" / "class-mad4b-scp-read-consistency.php").read_text()
assert "'production' === MAD4B_SCP_Site_Profile::current_environment()" in src
assert "$actions[] = $write_authority_action;" in src
assert "review_exact_production_write_authority" in src
assert "'action' => 'inspect_exact_production_write_authority_policy'" in src
assert "'ability' => 'mad4b/write-authority-reconciliation-plan'" in src
assert "'explicit_authority_required' => true" in src
assert "'automatic_apply_allowed' => false" in src
assert "reconcile_exact_staging_write_authority" in src
assert "'subject_blockers[] = 'write_authority_not_effective'" not in src  # typo guard only
assert "$subject_blockers[] = 'write_authority_not_effective';" in src
print("PRODUCTION_WRITE_DIAGNOSTIC_ROUTING_PASS")
