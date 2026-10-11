# Feature 007: Staging Preview Evidence Matrix

Source: `tools/mad4b-preview-matrix.py`; on-host WordPress observer: `tools/mad4b-preview-native-probe.php`.

The plugin also registers **`mad4b/preview-matrix-plan`** as a
read-only MCP Ability in the normal full bootstrap. It refuses missing Site
Profile enrollment, origin/environment mismatch, malformed exact source
provenance and unsafe paths. It returns the exact package identity, current
minimum Frontend sample gap, optional mode-specific requirements and
non-authorizing execution instructions. This plan neither queues jobs nor
runs a browser, WP-CLI, REST request or Customizer mutation.

Responsive browser observations support `--viewport desktop`, `tablet`
or `mobile`. To compare devices, run separate bounded commands and
retain separate evidence files. Browser/Customizer results also include
bounded hashed `data-post-id` identities and advisory dataset comparisons,
without storing page content or accepting a matching dataset as a release
receipt. The plan does not manufacture missing Query Monitor attribution.

## Existing managed browser execution (authoritative acceptance)

The browser lane must use **MAD4B Managed Browser Execution Providers v2** as
the authoritative executor; do not create an additional independent browser
acceptance authority in this Preview Matrix. The canonical source is
`docs/MAD4B-MANAGED-BROWSER-PROVIDERS.md` and its external runner is
`tools/browser-acceptance/run-live-site-browser-acceptance.mjs`. The provider
scheduler supports Cloudflare, Browserbase, Browserless and Steel, subject to
actual credentials, quotas, session budgets, dual OAuth/challenge deadline
and approved site capability driver. Run the no-secret
`node tools/browser-acceptance/provider-preflight.mjs` before requesting a
live provider session. A provider's presence in source does not establish
credential availability, acceptance readiness or free quota.

A read-only GitHub Actions workflow check is available in
`.github/workflows/mad4b-managed-browser-providers.yml`: manually run
`workflow_dispatch` on the selected source branch with `live=false`,
`provider=auto`, and credit fallback disabled. The independent
`readiness` job reads configured provider credentials only inside its
single preflight step, produces `managed-browser-readiness.json`, and
does not request an MCP plan or open a browser session. The report lists
credential names and provider eligibility, **never secret values**.
Its result is a capacity/configuration hint, NOT an authenticated
site signer check, provider quota certificate, or Live Acceptance PASS.

For a fully governed live run, `live=true` requires a fresh short-lived
`MAD4B_MCP_ACCESS_TOKEN`, a configured provider, and for native site
provider plans a matching RSA key pair: the WordPress host requires the
public PEM constant `MAD4B_BROWSER_ATTESTATION_PUBLIC_KEY_PEM`,
while the GitHub Actions live runner needs
`MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64` as a secret.
The private key must never be committed, entered in a conversation,
or exposed as job-wide environment. Both secrets and provider API
credentials are now limited to the live execution step (and, where
necessary, credential-name-only readiness checks) to protect npm
installation and GitHub Actions artifact upload from secret exposure.
Neither absence of a signer nor a mismatched RSA SPKI digest may be
treated as a warning or bypassed.

The authoritative sequence is Site Capability Discovery ->
`mad4b/browser-acceptance-capabilities` -> fresh
`mad4b/browser-acceptance-plan` -> provider-neutral bounded browser runner ->
`mad4b/browser-acceptance-result` -> server reducer -> tamper-evident receipt.
Do not substitute a locally generated JSON record or caller-supplied URL.
The current All Royal Egypt live registration advertises
`mad4b-native-public` as its site provider; external provider credentials,
required signing identity and provider-specific result submission must be
validated separately. Discovery alone is not certification.

The Python Chromium implementation in this child branch is a **manual,
diagnostic-only** alternative, not another managed acceptance engine. It
cannot sign an existing job, assert an externally observed probe, or replace
the canonical signed-plan/result reducer. In particular, the currently
approved generic site driver checks only `browser.canonical_path`, whereas
the ETG driver validates richer JetSmartFilters/filter-identity oracles.
Do not claim that one generic browser run covers both providers.

`customizer` and `native` remain complementary read-only diagnostic
lanes. Before adding them to the managed-provider runner, define distinct
review-owned, signed-plan oracles and preserve authentication isolation;
Customizer credentials cannot be injected via an arbitrary user-supplied URL
or JavaScript payload. No attempt is made here to modify provider worker
authority or force execution from the WordPress server.

Three independent lanes:

* `browser`: actual Chromium navigation with browser timing, JavaScript error counts and AJAX/fetch status counts.
* `customizer`: authenticated classic Theme Customizer iframe. Requires a locally held same-origin Playwright session file. No settings are saved.
* `native`: on-host WP-CLI read-only theme inspection and optional allowlisted core REST GET. PHP/SQL figures start after WP-CLI bootstrap and must not be presented as visitor HTTP measurements.

All runs must target Staging with an exact installed MAD4B source SHA. Output JSON is evidence only, never a signed browser-work receipt or release authorization. Real browser and Customizer observations must remain separate. A block theme might not expose the classic Customizer.

## Browser command (Windows, from repository root)

Install `playwright` Python package and Chromium using `py -m pip install playwright` and `py -m playwright install chromium`.

```powershell
py tools/mad4b-preview-matrix.py --origin https://staging.allroyalegypt.com --expected-source-sha 0b52e7995f1b317b8947f27083f1c0ab4ce58959 --modes browser --paths / --samples 3 --scroll --output browser-evidence.json
```

For Customizer use `--modes customizer --storage-state <local-auth-state.json>`. Keep authenticated state private and outside Git. The script never stores screenshots, full URLs with query parameters, page HTML, raw console messages, or HTTP bodies.

For a previously queued external job only, `--probe-url` supports the same-origin UUID-bound `mad4b_frontend_probe` URL. This does not claim or sign the job; the server must independently verify a genuine external executor and its telemetry.

## Native WP-CLI command (on Staging host)

```bash
python3 tools/mad4b-preview-matrix.py --origin https://staging.allroyalegypt.com --expected-source-sha 0b52e7995f1b317b8947f27083f1c0ab4ce58959 --modes native --wp-root /path/to/staging/wordpress --native-rest --output native-evidence.json
```

A same-origin customizer preview is not equivalent to public traffic; WP-CLI timing is not HTTP server time. For real Frontend acceptance continue using MAD4B's claimed external browser-work queue and independent signed completion receipt. An in-process core REST GET does not validate the WPML external route.

Tests: `python3 -m unittest discover -s tools -p 'test_mad4b_preview_matrix.py' -v` and `php -l tools/mad4b-preview-native-probe.php`.

The existing performance policy uses at least three Frontend telemetry samples and the maximum number of SQL queries across a rolling five-sample window. Current historic observations exceed the SQL-query budget; diagnose Query Monitor attribution before claiming improvement. No Production mutation, release certification, or policy bypass is authorized.
