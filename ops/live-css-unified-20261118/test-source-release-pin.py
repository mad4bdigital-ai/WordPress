#!/usr/bin/env python3
"""Fail-closed original Source 422 Git SHA pin, not a temporary worktree."""
from pathlib import Path
import hashlib,re
p=Path(__file__).resolve().parents[1]/'dynamic-critical-css-20261116/run-public-route.py'
s=p.read_text()
m=re.search(r"approved_stage_422\s*=\s*'([a-f0-9]{64})'",s)
assert m, 'source pin missing'
source=Path(__file__).resolve().parents[2]/'wp-content/mu-plugins/422-mad4b-public-critical-css-runtime.php'
assert hashlib.sha256(source.read_bytes()).hexdigest()==m.group(1),'pin not matching Git source'
assert 'site==\'source\' and name==\'422-mad4b-public-critical-css-runtime.php\'' in s,'source scope missing'
assert 'hashlib.sha256(blob).hexdigest()!=approved_stage_422' in s,'missing exact failure'
assert "SOURCE_LIVE_NATIVE_COMPONENT_DRIFT:'+name" in s,'drift refusal missing'
assert "source.read_bytes()!=blob" in s,'Wayfa/421 source equality missing'
assert 'live-css-unified-source-20261118/wp-content/mu-plugins' not in s,'temporary checkout dependency retained'
assert 'def approved_native_runtime_sha(site,wp):' in s,'shared checker missing'
assert 'verified_runtime_sha=approved_native_runtime_sha(site,wp)' in s,'worker checker not used'
print('PASS_SOURCE_RELEASE_PIN_MATERIALIZES_EXACT_GIT_422_SHA')
print('PASS_SITE_SCOPED_ORIGINAL_WAYFA_AND_421_BYTE_COMPARISON_PRESERVED')
print('PASS_EPHEMERAL_WORKTREE_PATH_NOT_REQUIRED_FOR_LIVE_EXECUTION')
