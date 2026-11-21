#!/usr/bin/env python3
"""Fail closed if the local OAuth job stops running a real browser assertion."""
from pathlib import Path

root = Path(__file__).resolve().parents[2]
workflow = (root / '.github/workflows/mad4b-local-oauth-standalone.yml').read_text()
start = workflow.index('      - name: Prove live authority dashboard in a real browser')
end = workflow.index('      - name: Prove browser canary is unavailable in Production', start)
browser = workflow[start:end]

assert 'runs-on: ubuntu-24.04' in workflow
assert "if: matrix.wordpress == 'latest'" in browser
assert 'npm install --no-save playwright@1.56.1' in browser
assert 'npx playwright install chromium' in browser
assert 'npx playwright install --with-deps' not in browser
assert 'node ./test.mjs' in browser
assert 'set -euo pipefail' in browser
assert '|| true' not in browser.split('npm install --no-save playwright@1.56.1',1)[1]
assert 'cp "$GITHUB_WORKSPACE/wp-content/plugins/mad4b-site-control-plane/tests/oauth-live-authority-browser-e2e.mjs" /tmp/mad4b-playwright/test.mjs' in browser
assert 'MAD4B_BROWSER_BASE_URL: http://127.0.0.1:8088' in browser
assert 'MAD4B_BROWSER_USER: mad4b-ci-admin' in browser
print('LOCAL_OAUTH_REAL_BROWSER_INSTALL_CONTRACT: PASS')
