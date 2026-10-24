#!/usr/bin/env python3
"""Execute the isolated PHP semantic bundle contract as a behavioral test.

No WordPress bootstrap, external access, credentials or mutation.
"""
from pathlib import Path
import shutil
import subprocess
import sys

root = Path(__file__).resolve().parent
fixture = root / "content-bundle-semantic-contract-runtime.php"
if not fixture.is_file() or fixture.resolve().parent != root:
    raise SystemExit("FAIL: exact semantic contract fixture missing")
php = shutil.which("php")
if not php:
    raise SystemExit("FAIL: PHP CLI unavailable")
try:
    result = subprocess.run(
        [php, str(fixture)], cwd=root.parent, capture_output=True,
        text=True, check=False, timeout=35,
    )
except (OSError, subprocess.TimeoutExpired) as exc:
    raise SystemExit("FAIL: semantic bundle runtime: " + str(exc))
if result.returncode != 0:
    raise SystemExit("FAIL: semantic bundle runtime exit=" + str(result.returncode)
                     + "\n" + (result.stdout + result.stderr)[-3000:])
print(result.stdout.rstrip())
print("CONTENT_BUNDLE_SEMANTIC_BEHAVIOR: PASS")
