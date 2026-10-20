#!/usr/bin/env python3
"""Source-backed PHP guard: signed frontend probes must work before wp_salt exists."""
from pathlib import Path
import subprocess
import sys

fixture = Path(__file__).resolve().with_name("frontend-origin-early-marker-runtime.php")
if not fixture.is_file():
    raise SystemExit("FRONTEND_EARLY_MARKER: MISSING_EXACT_FIXTURE")
for mode in ("valid", "weak", "missing"):
    try:
        result = subprocess.run(
            ["php", str(fixture), mode], capture_output=True, text=True, timeout=15, check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise SystemExit(f"FRONTEND_EARLY_MARKER: BLOCKED_{mode}: {type(exc).__name__}")
    if result.returncode or f"MAD4B_FRONTEND_EARLY_MARKER: PASS ({mode})" not in result.stdout:
        print((result.stderr + result.stdout)[-1200:], file=sys.stderr)
        raise SystemExit(f"FRONTEND_EARLY_MARKER: FAIL_{mode}")
print("FRONTEND_EARLY_MARKER: PASS; pre-pluggable / fail-closed / expiry / forgery")
