#!/usr/bin/env python3
"""First-party browser settings source contract: exact JS blob, no live WordPress.
Runs established original settings truth-invariants and Node syntax check.
"""
from pathlib import Path
import subprocess,sys
ROOT=Path(__file__).resolve().parents[1]
SOURCE='wp-content/plugins/mad4b-site-control-plane/assets/admin-settings-persistence.js'
BLOB='93332505e987a31d245e0c0bb6a35bbc3bc5cba4'
current=subprocess.check_output(["git","hash-object",SOURCE],cwd=ROOT,text=True).strip()
assert current==BLOB,"Browser JS changed; new exact-source review required"
subprocess.run(["node","--check",SOURCE],cwd=ROOT,check=True,timeout=30)
result=subprocess.run([sys.executable,
 "wp-content/plugins/mad4b-site-control-plane/tests/admin-settings-persistence-contract.py"],
 cwd=ROOT,capture_output=True,text=True,timeout=75,check=False)
out=result.stdout+"\\n"+result.stderr
assert result.returncode==0 and 'mad4b.admin-settings-persistence.v7: PASS' in out,out[-1200:]
assert 'mad4b.truth-projection-invariants.v1: PASS' in out
print("PASS: ADMIN_SETTINGS_JS_SAME_PR exact persisted readback and JS source contract",current[:12])
