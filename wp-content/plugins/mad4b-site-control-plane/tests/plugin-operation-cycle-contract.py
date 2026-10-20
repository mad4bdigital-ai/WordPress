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
    ("object-cache-diagnostics-runtime.php", "PASS Memcached/LSMCD passive diagnostics and staging-only ephemeral acceptance"),
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
# CONTENT_JOB_SCOPE_METADATA: runtime integration remains in the disposable WP-CLI
# fixture; lint and assert the nonauthorizing scope contract here as well.
source = HERE.parent / "includes" / "class-mad4b-scp-content-jobs.php"
if source.is_symlink() or not source.is_file():
    raise SystemExit("PLUGIN_OPERATION_CYCLE: scoped ContentJob source missing")
code = source.read_text(encoding="utf-8")
for literal in (
    "'read_scope' => 'site_uuid_brand_id_tenant_id'",
    "'site_wide_inventory_evaluated' => false",
    "'zero_rows_clear_site_wide_doctor' => false",
    "'cross_scope_repair_allowed' => false",
    "brand_id=%s', 'tenant_id=%s",
):
    if literal not in code:
        raise SystemExit("PLUGIN_OPERATION_CYCLE: ContentJob exact read scope contract drift: " + literal)
lint = subprocess.run([php, "-l", str(source)], cwd=HERE, capture_output=True,
                      text=True, timeout=30, check=False)
if lint.returncode or lint.stderr or "No syntax errors detected" not in lint.stdout:
    raise SystemExit("PLUGIN_OPERATION_CYCLE: ContentJob PHP lint failed: " + (lint.stdout + lint.stderr)[-1200:])
print("CONTENT_JOB_SCOPE_METADATA: PASS; no site-wide clearance or cross-tenant repair authority")

print("PLUGIN_OPERATION_CYCLE: PASS; empty-object reads, permission/pins, optional visibility, journal recovery; not live acceptance")
