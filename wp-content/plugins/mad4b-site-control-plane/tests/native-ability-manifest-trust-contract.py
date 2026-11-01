#!/usr/bin/env python3
"""Hermetic regression matrix for native owner-pinned read-source discovery.

The PHP fixtures use a temporary synthetic WordPress Ability. This script is
independent of the live tenant, any owner manifests, and all Production services.
"""
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[4]
FIXTURE = ROOT / "ops/native-capability-manifest-v1/test-native-ability-trust.php"
CASES = (
    "valid", "source_drift", "schema_drift", "scope_write",
    "wrong_host", "wrong_callback", "pin_mismatch",
    "manifest_inside_content", "pagination",
)
assert FIXTURE.is_file(), "Trusted native ability source fixture missing"
for name in CASES:
    completed = subprocess.run(
        ["php", str(FIXTURE), name], cwd=ROOT, capture_output=True,
        text=True, timeout=20, check=False,
    )
    if completed.returncode != 0 or f"{name} PASS" not in completed.stdout:
        raise AssertionError(
            f"Native Ability Trust fixture {name} failed: "
            f"{completed.stdout[-800:]} {completed.stderr[-800:]}"
        )
print(f"NATIVE_TRUST_MATRIX: PASS {len(CASES)} security fixtures")
