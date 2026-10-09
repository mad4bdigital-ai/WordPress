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
print('mad4b.admin-live-readonly-browser-static-contract.v1: PASS')
