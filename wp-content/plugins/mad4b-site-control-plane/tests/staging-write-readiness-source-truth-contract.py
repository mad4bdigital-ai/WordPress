#!/usr/bin/env python3
"""Read-only package versus grant drift diagnostics must remain independent."""
from pathlib import Path
s=(Path(__file__).resolve().parents[1]/"includes"/"class-mad4b-scp-staging-write-authority.php").read_text()
a=s.index("public static function current_execution_readiness(")
b=s.index("public static function persisted_status()",a)
part=s[a:b]
for marker in ("'package_binding_drift' => ! empty( $binding['required'] ) && empty( $binding['match'] )",
               "'write_grant_drift' => empty( $plan['current_ready'] )",
               "'review_exact_installed_candidate_before_binding'",
               "'review_exact_live_write_grants'",
               "'ready' => (bool) $ready",
               "'mutation_performed' => false"):
    assert marker in part, marker
assert "self::candidate_binding_status()" in part
assert "self::reconciliation_plan()" in part
print("STAGING_WRITE_READINESS_SOURCE_TRUTH: PASS")
