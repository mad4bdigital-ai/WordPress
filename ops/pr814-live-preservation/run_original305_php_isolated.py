#!/usr/bin/env python3
"""Execute exact source-only Original305 isolated PHP fixture with no WP runtime.

No live signing, grants, Host commands, database connection or user impersonation.
The fixture simulates nonce/WordPress tickets entirely in process memory.
"""
from pathlib import Path
import subprocess,sys

ROOT=Path(__file__).resolve().parents[2]/'wp-content/plugins/mad4b-site-control-plane'
source=(ROOT/'includes/class-mad4b-scp-original305-incident-review.php').read_text()
fixture=(ROOT/'tests/original305-incident-review-isolated.php').read_text()
needle='/*__ORIGINAL305_REVIEW_CLASS_SOURCE__*/'
if source.count('<?php')!=1 or fixture.count(needle)!=1:
    raise SystemExit('ORIGINAL305_HERMETIC_SOURCE_SHAPE_INVALID')
combined=fixture.replace(needle,source.removeprefix('<?php'))
try:
    r=subprocess.run(['php'],input=combined,text=True,capture_output=True,timeout=20)
except (OSError,subprocess.TimeoutExpired):
    raise SystemExit('ORIGINAL305_HERMETIC_PHP_EXECUTION_UNAVAILABLE') from None
if r.returncode or 'ALL_ORIGINAL305_ISOLATED_TESTS_PASSED' not in r.stdout:
    print(r.stdout[-2200:],file=sys.stderr)
    print(r.stderr[-1600:],file=sys.stderr)
    raise SystemExit('ORIGINAL305_HERMETIC_SOURCE_TEST_FAILED')
print('ORIGINAL305_HERMETIC_SOURCE_TEST_PASSED')
