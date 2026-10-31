#!/usr/bin/env python3
"""Run existing targeted PHP contracts as one mandatory CI-only readiness batch."""
import os
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("MAD4B_PHP", "php")
SUITES = {
    "cross-plugin-lifecycle": "cross-plugin-lifecycle-gates-runtime.php",
    "market-profiles": "market-profiles-language-policy-runtime.php",
    "runtime-obstacle-resolution": "runtime-obstacle-resolution-runtime.php",
    "restore-epoch": "restore-epoch-runtime.php",
    "recovery-route-resolver": "recovery-route-resolver-runtime.php",
    "provider-transport-eligibility": "provider-transport-eligibility-runtime.php",
    "scheduler-admission": "scheduler-admission-contract.php",
    "provider-setup-coverage": "provider-setup-coverage-runtime.php",
    "content-completeness": "context-content-completeness-runtime.php",
}
for source_name, suite_name in SUITES.items():
    source = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes" / ("class-mad4b-scp-" + source_name + ".php")
    suite = ROOT / "wp-content/plugins/mad4b-site-control-plane/tests" / suite_name
    assert source.is_file() and suite.is_file(), (source_name, suite_name)
    result = subprocess.run([PHP, str(suite)], cwd=ROOT, capture_output=True, text=True, timeout=120)
    silent_assertions = source_name == "market-profiles" and suite.read_text().count("$check(") >= 10 and "exit($fail?1:0)" in suite.read_text()
    assert result.returncode == 0 and ("PASS" in result.stdout or silent_assertions), (
        source_name, result.returncode, result.stdout[-400:], result.stderr[-400:]
    )
    print("READINESS_EXISTING_PHP_PASS:", source_name)
print("READINESS_EXISTING_PHP_BATCH: PASS", len(SUITES))
