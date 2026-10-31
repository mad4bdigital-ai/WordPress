#!/usr/bin/env python3
"""Prevent losing either PR's standalone CI admission tests during semantic merge."""
import subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1]
s=(root/'tools/readiness_test_execution.py').read_text()
files=(
 'wp-content/plugins/mad4b-site-control-plane/tests/qa31-company-stage-admission.php',
 'wp-content/plugins/mad4b-site-control-plane/tests/remote-work-queue-cancellation-runtime.php',
)
for f in files:
 assert s.count('"'+f+'"')==1,(f,'missing or duplicate CI registration')
 assert (root/f).is_file(),f
 r=subprocess.run(['php',str(root/f)],capture_output=True,text=True,timeout=45)
 assert r.returncode==0 and 'PASS' in r.stdout,(f,r.returncode,r.stdout[-250:],r.stderr[-250:])
 print('SEMANTIC_PR_TEST_UNION_PASS',f)
print('SEMANTIC_PR655_PR671_REGRESSION_UNION: PASS')
