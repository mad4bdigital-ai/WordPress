#!/usr/bin/env python3
"""Execute the original non-PHPUnit Cross Plugin PHP fixture in the trusted Python CI lane.

This is not a mock: always invokes the source-owned PHP test directly, enforces
exit-on-failure and its exact terminal assertion marker. It cannot grant deploy
authority or substitute a live WordPress/Staging certification.
"""
from pathlib import Path
import shutil
import subprocess
import sys

folder = Path(__file__).resolve().parent
test = folder / "cross-plugin-deployment-runtime.php"
php = shutil.which("php")
if not php:
    raise SystemExit("CROSS_PLUGIN_DEPLOYMENT_RUNNER: FAIL PHP CLI missing")
if test.is_symlink() or not test.is_file():
    raise SystemExit("CROSS_PLUGIN_DEPLOYMENT_RUNNER: FAIL source test unavailable")
try:
    outcome = subprocess.run(
        [php, str(test)], cwd=folder, text=True, capture_output=True,
        timeout=45, check=False,
    )
except (OSError, subprocess.TimeoutExpired) as error:
    raise SystemExit("CROSS_PLUGIN_DEPLOYMENT_RUNNER: FAIL " + str(error))
if outcome.returncode != 0 or outcome.stdout.strip() != "CROSS_PLUGIN_DEPLOYMENT: PASS":
    if outcome.stdout:
        sys.stderr.write(outcome.stdout[-2000:])
    if outcome.stderr:
        sys.stderr.write(outcome.stderr[-2000:])
    raise SystemExit("CROSS_PLUGIN_DEPLOYMENT_RUNNER: FAIL original PHP assertions did not pass")
print("CROSS_PLUGIN_DEPLOYMENT_RUNNER: PASS original PHP assertions executed")
