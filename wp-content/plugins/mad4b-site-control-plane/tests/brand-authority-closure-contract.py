#!/usr/bin/env python3
"""Execute the isolated Brand Authority Closure PHP reducer; never certify live sites."""
import os
from pathlib import Path
import shutil
import subprocess

ROOT = Path(__file__).resolve().parents[1]
fixture = ROOT / "tests/brand-authority-closure-runtime.php"
reducer = ROOT / "includes/class-mad4b-scp-brand-authority-closure.php"

# Static limits complement, but never replace, executing the behavioral fixture.
source = reducer.read_text(encoding="utf-8")
test_source = fixture.read_text(encoding="utf-8")
for forbidden in (
    "update_option(", "delete_option(", "wp_insert_post(",
    "wp_remote_post(", "file_put_contents(", "wp_register_ability(",
    "MAD4B_SCP_Staging_Write_Authority_Convergence::apply(",
):
    if forbidden in source:
        raise SystemExit("BRAND_CLOSURE: mutation forbidden in diagnostic reducer: " + forbidden)
for marker in (
    "'production_authorized' => false", "'approval_issued' => false",
    "'mutation_performed' => false", "'ready_for_publication' => false",
):
    if marker not in source:
        raise SystemExit("BRAND_CLOSURE: missing nonauthorizing guarantee: " + marker)
for marker in ("invalid_grant", "stale_grants", "exact_staging_required", "BRAND_AUTHORITY_CLOSURE: PASS"):
    if marker not in test_source:
        raise SystemExit("BRAND_CLOSURE: insufficient runtime fixture: " + marker)

php = os.environ.get("MAD4B_TEST_PHP_BIN") or shutil.which("php")
if not php:
    raise SystemExit("BRAND_CLOSURE: PHP runtime is required")
result = subprocess.run([php, str(fixture)], cwd=ROOT, capture_output=True, text=True, timeout=20, check=False)
if (result.returncode != 0 or result.stderr or
        result.stdout.strip() != "BRAND_AUTHORITY_CLOSURE: PASS"):
    raise SystemExit(
        "BRAND_CLOSURE: PHP behavioral regression failed: " +
        result.stdout[-1200:] + " " + result.stderr[-1200:]
    )
print("BRAND_CLOSURE_EXECUTED: PASS (isolated Staging scenarios; no live acceptance)")
