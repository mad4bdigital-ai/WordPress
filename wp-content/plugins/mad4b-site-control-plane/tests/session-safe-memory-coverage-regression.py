#!/usr/bin/env python3
"""Execute the exact hermetic PHP session-memory regression for readiness CI.

Only this repository-owned test is allowed; unlike WP-CLI integration tests it
requires no database, bearer token, site mutation or PHPunit. The 64MiB limit
is essential to catch pre-serialization provider-object amplification.
"""
from pathlib import Path
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parent
FIXTURE = (ROOT / "session-safe-memory-regression.php").resolve()
if FIXTURE.parent != ROOT or not FIXTURE.is_file() or not shutil.which("php"):
    raise SystemExit("MAD4B_SESSION_MEMORY_COVERAGE: missing trusted PHP fixture")
try:
    result = subprocess.run(
        ["php", "-d", "memory_limit=64M", str(FIXTURE)],
        cwd=ROOT, capture_output=True, text=True, timeout=40, check=False,
    )
except (OSError, subprocess.TimeoutExpired):
    raise SystemExit("MAD4B_SESSION_MEMORY_COVERAGE: runtime unavailable or timeout")
if result.returncode or "mad4b.session-safe-memory-regression: PASS" not in result.stdout:
    print("MAD4B_SESSION_MEMORY_COVERAGE: executable PHP assertion failed", file=sys.stderr)
    print((result.stderr + result.stdout)[-1000:], file=sys.stderr)
    raise SystemExit(1)
print("MAD4B_SESSION_MEMORY_COVERAGE: PASS (PHP 64MiB, exact fixture)")
