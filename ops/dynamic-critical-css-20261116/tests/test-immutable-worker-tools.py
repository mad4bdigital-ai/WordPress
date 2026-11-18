#!/usr/bin/env python3
"""The active route runner cannot load QA or variant code from a mutable worktree."""
from pathlib import Path
import hashlib,importlib.util,sys
D=Path(sys.argv[1]).resolve()
orig=Path('/opt/mad4b-company-provisioning/worktrees/pr814-converged-local-20261115/ops/dynamic-critical-css-20261116')
text=(D/'run-public-route.py').read_text()
assert 'TOOLS=Path(__file__).resolve().parent' in text
assert 'TOOLS=ROOT/' not in text
spec=importlib.util.spec_from_file_location('css_runner',D/'run-public-route.py')
runner=importlib.util.module_from_spec(spec);spec.loader.exec_module(runner)
assert runner.TOOLS==D,runner.TOOLS
for file in ('public-template-variant-index.py','public-source-families.py',
             'public-route-queue.py','tools/capture.js','tools/verify.js',
             'tools/lcp-observation-window.js','tools/probe-public.js'):
 p=D/file
 assert p.is_file() and not p.is_symlink(),file
 print('PASS in immutable release',file)
for file in ('tools/capture.js','tools/verify.js','tools/lcp-observation-window.js',
             'tools/probe-public.js'):
 assert hashlib.sha256((orig/file).read_bytes()).digest()==hashlib.sha256((D/file).read_bytes()).digest()
print('PASS unchanged exact Chromium QA source')
# This original first-party native trust root is deliberately not redirected.
assert "original=ROOT/'worktrees/pr814-converged-local-20261115/wp-content/mu-plugins'" in text
print('PASS original WordPress authority source untouched')
print('IMMUTABLE_WORKER_TOOLS_PASS')

home=(D/'run-site.sh').read_text()
assert 'TOOLS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"' in home
assert 'TOOLS="$ROOT/worktrees/pr814-' not in home
print('PASS immutable first-fold runner also uses its own snapshot')
