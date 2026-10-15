#!/usr/bin/env python3
"""Static safety contract for the opt-in authenticated wp-admin survey.

This is a source test; it does not run the site or grant Browser Acceptance.
"""
from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
src = (root / 'tests/admin-live-readonly-browser.cjs').read_text(encoding='utf-8')
required = [
    'MAD4B_UI_STAGING_ORIGIN',
    'MAD4B_UI_ADMIN_PATH',
    'MAD4B_UI_AUTH_STATE_FILE',
    'MAD4B_UI_EXPECTED_COUNT',
    "serviceWorkers: 'block'",
    'page.route(',
    "req.method()",
    "['GET','HEAD']",
    "blocked.write++",
    "blocked.external++",
    "new URL(req.url()).origin === origin",
    ".mad4b-workspace",
    "effective !== 'staging'",
    "['page','tab','section','view']",
    "if (cases.size > 180)",
    "RAW_WORDPRESS_ENVIRONMENT_MISMATCH",
    "UNLABELED_CONTROLS",
    "DUPLICATE_IDS",
    "VIEWPORT_OVERFLOW",
    "JAVASCRIPT_ERRORS",
    "CROSS_ROUTE_REDIRECT",
    "source_manifest_verified: false",
    "browser_attestation_verified: false",
    "provider_mutations_verified: false",
    "release_certified: false",
]
for token in required:
    assert token in src, f'missing guarded browser contract: {token}'
for forbidden in (
    '.click(', '.fill(', '.press(', 'requestSubmit(', 'form.submit(',
    'localStorage', 'sessionStorage', 'document.cookie',
    'readFileSync(stateFile)', 'console.log(stateFile)',
    'release_certified: true', 'browser_attestation_verified: true',
):
    assert forbidden not in src, f'forbidden browser action: {forbidden}'
assert re.search(r'if \(!trusted\).*blocked.external\+\+', src, re.S)
assert re.search(r"!\['GET','HEAD'\]\.includes\(req\.method\(\)\)", src)
assert 'getAttribute' in src and 'data-mad4b-workspace-item' in src
assert 'mad4b-operator-control-center' in src
assert "data-mad4b-workspace-section-links" not in src
assert 'cases.set(destination.slug' in src
assert 'document.documentElement.scrollWidth > innerWidth + 1' in src
# Native Node syntax and deterministic reducer scenarios, executed without
# external browser services, credentials, network access or WordPress writes.
import shutil
import subprocess

for token in (
    'function surveyState(failures, countMatches, blocked, consoleErrors)',
    'state: surveyState(failures, countMatches, blocked, consoleErrors)',
    "blocked.external > priorExternalBlocked",
    "blocked.write > priorWriteBlocked",
    "consoleErrors > priorConsoleErrors",
    "EXTERNAL_RESOURCE_BLOCKED",
    "WRITE_ATTEMPT_BLOCKED",
    "BROWSER_CONSOLE_ERRORS",
):
    assert token in src, f'unproven fail-closed UI survey check: {token}'
node = shutil.which('node')
assert node, 'BLOCKED: native Node executable required to validate browser survey'
source_path = str(root / 'tests/admin-live-readonly-browser.cjs')
subprocess.run([node, '--check', source_path], check=True, capture_output=True, text=True)
reducer = r"""
const fs=require('node:fs');
const code=fs.readFileSync(process.argv[1],'utf8');
const match=code.match(/function surveyState\(failures, countMatches, blocked, consoleErrors\) \{[\s\S]*?\n\}/);
if(!match)throw Error('Missing actual survey reducer');
const surveyState=new Function(match[0]+'; return surveyState;')();
const cases=[
[0,true,{external:0,write:0},0,'OBSERVED_UNCERTIFIED'],
[1,true,{external:0,write:0},0,'BLOCKED'],
[0,false,{external:0,write:0},0,'BLOCKED'],
[0,true,{external:1,write:0},0,'BLOCKED'],
[0,true,{external:0,write:1},0,'BLOCKED'],
[0,true,{external:0,write:0},1,'BLOCKED'],
[2,false,{external:2,write:2},2,'BLOCKED'],
];
for(const [failed,countMatches,blocked,consoleErrors,expected] of cases){
  if(surveyState(failed,countMatches,blocked,consoleErrors)!==expected){
    throw Error('Survey reducer failed deterministic classification');
  }
}
process.stdout.write('MAD4B_ADMIN_BROWSER_REDUCER: 7/7 PASS\n');
"""
out = subprocess.run([node, '-e', reducer, source_path],
                     check=True, capture_output=True, text=True)
assert '7/7 PASS' in out.stdout, 'Native reducer scenarios did not pass'

print('mad4b.admin-live-readonly-browser-static-contract.v1: PASS')
