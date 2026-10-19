#!/usr/bin/env python3
"""Execute standalone PHP assertions through the readiness runner's Python lane."""
from pathlib import Path
import shutil
import subprocess

tests = Path(__file__).resolve().parent
php = shutil.which('php')
if not php:
    raise SystemExit('PHP CLI is required; standalone ETG assertions were not executed')
for name in ('runtime-etg-dfsb-write-smoke.php', 'object-cache-diagnostics-runtime.php'):
    subprocess.run([php, str(tests / name)], check=True)
print('PASS actual standalone ETG write/undo and cache assertions; no PHPUnit assumption or runtime certification')
