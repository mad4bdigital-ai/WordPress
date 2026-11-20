#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[2]
w=(root/'.github/workflows/mad4b-local-oauth-standalone.yml').read_text()
assert '  static-contract:' in w and '  runtime:' in w
static=w.split('  static-contract:',1)[1].split('  runtime:',1)[0]
runtime=w.split('  runtime:',1)[1]
assert 'runs-on: ubuntu-latest' in static
assert 'runs-on: ubuntu-24.04' in runtime
assert 'runs-on: ubuntu-latest' not in runtime
for item in ('npm install --no-save playwright@1.56.1',
             'npx playwright install --with-deps chromium',
             'node ./test.mjs',
             'oauth-live-authority-browser-e2e.mjs',
             'MAD4B_BROWSER_BASE_URL','MAD4B_BROWSER_USER','MAD4B_BROWSER_PASSWORD'):
    assert item in runtime, item
print('HOST_V126_PLAYWRIGHT_RUNNER: PASS checks=11 failures=0 browser_e2e_mandatory=true')
