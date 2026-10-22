#!/usr/bin/env python3
"""Execute, never merely lint, the migrated-checkpoint PHP regression fixture.

This Python wrapper is used by the repository's exact-head readiness runner,
which intentionally accepts Python executable contracts and rejects untrusted
standalone PHP paths without an installed PHPUnit runner.
"""
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / "includes" / "class-mad4b-scp-runtime-convergence.php"
CASES = {
    "runtime-superseded-restart-checkpoint-runtime.php":
        "RUNTIME_SUPERSEDED_RESTART_CHECKPOINT: PASS",
    "runtime-restart-grace-readonly-runtime.php":
        "RUNTIME_RESTART_GRACE_READONLY: PASS",
    "guided-runtime-convergence-runtime.php":
        "PASS guided-convergence v1",
}
subprocess.run(["php", "-l", str(RUNTIME)], check=True, timeout=15, capture_output=True, text=True)
for name, expected in CASES.items():
    result = subprocess.run(
        ["php", str(ROOT / "tests" / name)],
        check=True, timeout=35, capture_output=True, text=True,
    )
    assert expected in result.stdout, f"{name}: expected substantive PHP regression marker missing"
    assert not result.stderr.strip(), f"{name}: unexpected diagnostics from PHP fixture"
print("MCP_MIGRATED_CHECKPOINT_EXECUTED_REGRESSIONS: PASS")
