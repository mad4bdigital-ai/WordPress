# Feature 007: Staging Preview Evidence Matrix

Source: `tools/mad4b-preview-matrix.py`; on-host WordPress observer: `tools/mad4b-preview-native-probe.php`.

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
