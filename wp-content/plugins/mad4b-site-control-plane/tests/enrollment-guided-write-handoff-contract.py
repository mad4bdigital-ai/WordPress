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
print("MAD4B_GUIDED_WRITE: PASS (no mutation, exact owner step-up and journal fences)")
