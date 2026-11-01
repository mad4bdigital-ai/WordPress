#!/usr/bin/env python3
"""Trusted CI wrapper: execute the actual PHP normalization regression, not php -l."""
import re
import shutil
import subprocess
import sys
from pathlib import Path

test = Path(__file__).with_name("context-media-normalization-runtime.php")
php = shutil.which("php")
if not php or not test.is_file():
    raise SystemExit("PHP runtime or fixture unavailable")
try:
    result = subprocess.run([php, str(test)], capture_output=True, text=True,
                            timeout=45, check=False)
except (OSError, subprocess.TimeoutExpired) as exc:
    raise SystemExit(str(exc))
print(result.stdout, end="")
if result.stderr:
    print(result.stderr, file=sys.stderr, end="")
passed = re.findall(r"(?m)^[a-z][a-z0-9_]+: PASS$", result.stdout)
if result.returncode != 0 or len(passed) != 10 or not re.search(
        r"(?m)^SUMMARY pass=10 fail=0$", result.stdout):
    raise SystemExit("Context Media PHP functional regression failed")
print("MEDIA_NORMALIZATION_EXECUTED_REGRESSION: PASS")
