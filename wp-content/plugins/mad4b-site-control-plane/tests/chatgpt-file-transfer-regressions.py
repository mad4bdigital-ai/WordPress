#!/usr/bin/env python3
"""Execute scoped transfer hermetic PHP regressions in CI with no WordPress site."""
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]
TESTS = (
    ("chatgpt-file-transfer-permission-runtime.php", "CHATGPT_TRANSFER_PERMISSION_RUNTIME: PASS"),
    ("chatgpt-file-transfer-roundtrip-runtime.php", "CHATGPT_TRANSFER_ROUNDTRIP: PASS"),
    ("staging-write-grant-reviewed-inventory-runtime.php", "PASS: 38 Feature 007 plus 3 native Staging pairs"),
)

for name, marker in TESTS:
    path = ROOT / "tests" / name
    for command in (["php", "-l", str(path)], ["php", str(path)]):
        result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, timeout=60, check=False)
        if result.returncode != 0 or (len(command) == 2 and marker not in result.stdout):
            raise SystemExit("TRANSFER_REGRESSION_FAILED:" + name + "\n" + result.stdout + result.stderr)
print("CHATGPT_TRANSFER_BEHAVIORAL_REGRESSIONS: PASS (3 hermetic PHP fixtures)")
