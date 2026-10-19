#!/usr/bin/env python3
"""Execute the native diagnostics and legacy recovery behavioral regressions.

These are standalone PHP harnesses, including an actual fatal subprocess;
executing them proves behavior rather than treating PHP lint as a test.
No WordPress site, network connection or live authority is involved.
"""
from pathlib import Path
import shutil
import subprocess

ROOT = Path(__file__).resolve().parents[1]
TESTS = ROOT / "wp-content/plugins/mad4b-site-control-plane/tests"
PHP = shutil.which("php")
if PHP is None:
    raise SystemExit("PHP runtime is required; regression execution cannot be skipped")

for name in (
    "frontend-origin-diagnostic-runtime.php",
    "native-incident-mu-runtime.php",
    "native-incident-early-runtime.php",
    "native-recovery-status-runtime.php",
    "native-recovery-runtime.php",
    "brand-core-remote-recovery-runtime.php",
):
    result = subprocess.run(
        [PHP, str(TESTS / name)], cwd=ROOT, capture_output=True,
        text=True, timeout=30, check=False,
    )
    if result.returncode != 0:
        raise SystemExit(f"{name}: FAIL\n{result.stdout}\n{result.stderr}")
    print(f"{name}: PASS")

print("NATIVE_RUNTIME_DIAGNOSTICS_EXECUTED_CONTRACT: PASS (6 behavioral harnesses)")
