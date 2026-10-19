#!/usr/bin/env python3
"""Execute standalone PHP assertions through the readiness runner's Python lane."""
from pathlib import Path
import shutil
import subprocess

tests = Path(__file__).resolve().parent
php = shutil.which('php')
if not php:
    raise SystemExit('PHP CLI is required; standalone ETG assertions were not executed')
for name in ('runtime-etg-dfsb-write-smoke.php', 'object-cache-diagnostics-runtime.php', 'undo-intent-fault-injection.php'):
    subprocess.run([php, str(tests / name)], check=True)
print('PASS actual standalone ETG write, cache, and journal/undo fault-injection assertions; no PHPUnit assumption or runtime certification')
