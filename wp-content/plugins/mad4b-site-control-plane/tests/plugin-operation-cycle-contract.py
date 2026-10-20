#!/usr/bin/env python3
"""Execute nonauthorizing lifecycle regressions; no network or WordPress DB."""
import os
import re
from pathlib import Path
import shutil
import subprocess

HERE = Path(__file__).resolve().parent
php = os.environ.get("MAD4B_TEST_PHP_BIN") or shutil.which("php")
if not php:
    raise SystemExit("PLUGIN_OPERATION_CYCLE: PHP unavailable")
for name, marker in (
    ("provider-transport-eligibility-runtime.php", "mad4b.provider-transport-eligibility.runtime.v1: PASS"),
    ("operator-doctor-contract.php", "mad4b.operator-doctor.v1: PASS"),
    ("plugin-operation-readiness-runtime.php", "PLUGIN_OPERATION_READINESS: PASS"),
    ("dynamic-adapter-capability-graph-runtime.php", "DYNAMIC_ADAPTER_CAPABILITY_GRAPH: PASS"),
    ("admin-native-mcp-view-runtime.php", "Admin Native MCP view projection: PASS"),
    ("connector-resilience-runtime.php", "mad4b.connector-resilience.runtime.v1: PASS"),
    ("gateway-regression-runtime.php", "PASS gateway regressions"),
    ("enrollment-guided-write-handoff-runtime.php", "MAD4B_ENROLLMENT_GUIDED_WRITE_HANDOFF: PASS"),
):
    test = HERE / name
    if test.is_symlink() or test.resolve().parent != HERE:
        raise SystemExit("PLUGIN_OPERATION_CYCLE: invalid fixture")
    result = subprocess.run([php, str(test)], cwd=HERE, capture_output=True,
                            text=True, timeout=60, check=False)
    if (result.returncode or result.stderr or marker not in result.stdout
            or re.search(r"(?:Warning|Deprecated|Fatal error|Notice):", result.stdout)):
        raise SystemExit("PLUGIN_OPERATION_CYCLE: FAIL " + name + "\n" +
                         (result.stdout + result.stderr)[-2500:])
    print(name + ": PASS")
print("PLUGIN_OPERATION_CYCLE: PASS; empty-object reads, permission/pins, optional visibility, journal recovery; not live acceptance")
