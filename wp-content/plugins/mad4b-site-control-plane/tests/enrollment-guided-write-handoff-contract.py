#!/usr/bin/env python3
"""Run the exact one-read owner journey PHP regression in a bounded subprocess."""
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parent
TEST = ROOT / "enrollment-guided-write-handoff-runtime.php"
if not TEST.is_file() or TEST.resolve().parent != ROOT:
    raise SystemExit("MAD4B_GUIDED_WRITE: MISSING_TRUSTED_TEST")
try:
    result = subprocess.run(["php", str(TEST.resolve())], cwd=ROOT,
                            capture_output=True, text=True, timeout=35, check=False)
except (OSError, subprocess.TimeoutExpired):
    raise SystemExit("MAD4B_GUIDED_WRITE: PHP_RUNTIME_UNAVAILABLE_OR_TIMEOUT")
if result.returncode != 0 or "MAD4B_ENROLLMENT_GUIDED_WRITE_HANDOFF: PASS" not in result.stdout:
    print((result.stderr + result.stdout)[-1600:], file=sys.stderr)
    raise SystemExit("MAD4B_GUIDED_WRITE: FAIL")
source = (ROOT.parent / "includes" / "class-mad4b-scp-enrollment-dispatch.php").read_text(encoding="utf-8")
for marker in (
    "$row['guided_handoff'] = self::guided_write_handoff( $row );",
    "'guided_handoff' => 'staging_write_authority_convergence' === $operation_id",
    "'method' => 'mad4b/enrollment-execute'",
    "'automatic_execution' => false",
    "'blind_retry_allowed' => false",
    "'owner_decision_required' => true",
    "'authority_scope' => 'write_only'",
    "'expected_registration_digest' => $row['registration_digest']",
    "'expected_dispatch_policy_digest' => $row['dispatch_policy_digest']",
    "'expected_input_schema_sha256' => $row['input_schema_sha256']",
    "MAD4B_SCP_Staging_Operation_Journal::inspect_plan",
    "MAD4B_SCP_Staging_Operation_Journal::latest",
):
    if marker not in source:
        raise SystemExit(f"MAD4B_GUIDED_WRITE: MISSING_ROUTE_GUARD {marker}")
print("MAD4B_GUIDED_WRITE: PASS (single-read discover/info, no mutation, exact owner step-up and journal fences)")
