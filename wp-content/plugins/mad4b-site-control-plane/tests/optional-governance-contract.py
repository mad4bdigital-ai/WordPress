#!/usr/bin/env python3
"""Trusted CI regression runner for optional-governance profiles.

Executes hermetic PHP behavioral tests, not a php -l-only or static assertion.
Runs from the source tree, never a live WordPress connection.
"""
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
FILES = (
    ROOT / "includes/class-mad4b-scp-optional-governance.php",
    ROOT / "includes/class-mad4b-scp-optional-governance-remote.php",
    ROOT / "tests/optional-governance-runtime.php",
)


def check(command: list[str]) -> str:
    proc = subprocess.run(
        command, cwd=ROOT, capture_output=True, text=True,
        check=False, timeout=120,
    )
    if proc.returncode:
        sys.stderr.write(proc.stdout + proc.stderr)
        raise RuntimeError(f"optional governance regression failed: {command[1]}")
    return proc.stdout


def main() -> None:
    for path in FILES:
        check(["php", "-l", str(path)])
    output = check(["php", str(FILES[-1])])
    if "OPTIONAL_GOVERNANCE: PASS" not in output:
        raise RuntimeError("optional governance PHP behavioral pass marker missing")
    print(output.strip())
    print("OPTIONAL_GOVERNANCE_CONTRACT: PASS (PHP behavioral fixture executed)")


if __name__ == "__main__":
    main()
