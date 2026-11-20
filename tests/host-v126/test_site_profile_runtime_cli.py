#!/usr/bin/env python3
"""Host v126 reviewed behavioral runner: exact hermetic PHP fixtures, no WP DB.

Runner keeps PHP test execution constrained to these seven known source paths.
It never treats php -l or a file's mere presence as acceptance.
"""
from __future__ import annotations
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys

ROOT=Path(__file__).resolve().parents[2]
PHP=os.environ.get("MAD4B_TEST_PHP_BIN") or shutil.which("php")
if not PHP:
    raise SystemExit("HOST_V126_CI_PHP_BINARY_REQUIRED")
CASES=[
 ("tests/host-v126/test_site_profile_origin_contract.php",["http"],"http"),
 ("tests/host-v126/test_site_profile_origin_contract.php",["cli"],"cli"),
 ("tests/host-v126/test_reserved_uuid_attestation.php",[],"reserved_uuid"),
]
BASE="wp-content/plugins/mad4b-site-control-plane/tests/"
EXISTING=[
 "site-profile-lifecycle-matrix-runtime.php",
 "site-profile-feature-reenrollment-runtime.php",
 "site-profile-general-distribution-runtime.php",
 "site-profile-host-environment-reconcile-runtime.php",
 "site-profile-write-enablement-runtime.php",
]
total=0
for rel,args,kind in CASES:
 p=(ROOT/rel).resolve()
 assert p.is_file() and p.is_relative_to(ROOT) and not p.is_symlink()
 assert rel.startswith("tests/host-v126/") and p.name.endswith(".php")
 r=subprocess.run([PHP,str(p),*args],cwd=ROOT,timeout=35,
                  text=True,capture_output=True,check=False)
 if r.returncode!=0 or r.stderr.strip():
    raise SystemExit(f"HOST_V126_RUNTIME_FAIL:{kind}:return={r.returncode}:"+r.stderr[-300:])
 lines=[s for s in r.stdout.splitlines() if s.strip()]
 if len(lines)!=1:raise SystemExit(f"HOST_V126_INVALID_RESULT_LINES:{kind}")
 try: result=json.loads(lines[0])
 except json.JSONDecodeError as exc:raise SystemExit(f"HOST_V126_INVALID_RESULT:{kind}") from exc
 if kind in ("http","cli"):
  assert result["state"]=="PASS" and result["mode"]==kind
  assert result["checks"]>=5 and result["live_root_mutation"] is False
  assert result["wordpress_database"] is False
  total+=result["checks"]
 else:
  assert result["status"]=="PASS" and result["denied_cases"]>=4
  assert result["wordpress_persistent_writes"]==0
  assert result["host_signer_used"] is False
  total+=result["denied_cases"]
for name in EXISTING:
 rel=BASE+name
 p=(ROOT/rel).resolve()
 assert p.is_file() and p.is_relative_to(ROOT) and not p.is_symlink()
 result=subprocess.run([PHP,str(p)],cwd=ROOT,timeout=45,
                  text=True,capture_output=True,check=False)
 if result.returncode!=0 or result.stderr.strip() or "FAIL:" in result.stdout:
  raise SystemExit("HOST_V126_LEGACY_REGRESSION_FAIL:"+name+
      ":"+(result.stdout+result.stderr)[-450:])
 total+=1
assert total>=27
print(f"HOST_V126_EXECUTED_BEHAVIOR: PASS checks={total} failures=0 "
      "source_php_fixtures=8 network_required=false "
      "live_wordpress_write=false")
