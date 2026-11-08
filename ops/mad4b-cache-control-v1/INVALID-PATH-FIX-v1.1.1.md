# Cache Control v1.1.1 — `invalid_path` correction

**Live incident:** On 2026-11-08, an authenticated administrator used
Tools → MAD4B Cache Control to apply a six-language FastCGI invalidation
to published tour `post:60629`. WordPress generated receipt
`619158e43e2c065c` with `status=invalid_path` and `versioned=0`.
No FastCGI route generation succeeded and no Redis flush occurred.

**Why:** The last-mile `socket_purge()` validation reconstituted every
already-previewed path through `home_url($path)`. In WordPress admin
contexts, language/market filters may alter `home_url()`. This made the
second validation sensitive to the current request's language, even though
the canonical URLs had already been vetted as same-origin on preview.
The locale/context variance is a likely explanation; the exact failing
PHP HTTP-context value was not captured.

**Fix:** Introduce a pure `is_safe_public_path()` validator shared by
`path_from_url()` and `socket_purge()`. Only the full permalink-to-path
extraction checks its same-origin host, scheme and port. The second,
path-only validation no longer calls `home_url()` or WPML. It still
rejects private endpoints, dot segments, double-slash paths, malformed
percent sequences, encoded ASCII/reserved characters and invalid UTF-8.
UTF-8 percent-encoded Arabic slugs remain supported.

**Proof:** The exact six public paths stored in the failed receipt were
read back and checked with the v1.1.1 pure validator. All six passed.
A PHP test simulating WPML language modification of `home_url()`
confirmed that the same six-path socket batch reaches only a
**nonexistent test socket**, safely returning `socket_unavailable`,
rather than `invalid_path`. Another eight unsafe routes remain denied.
Existing 5 allowed / 18 denied permalink tests and the preview ownership,
expiry and fingerprint regressions passed in both PHP site containers.

**Deployment:** Version 1.1.1 installed with identical SHA-256
`3ef0ea1dec4628547800572d18efca012b1b2bb2e2a847145f2e593e4e0bc6f3`
on Tours.dev Staging and Wayfatours Production. The root approval source
and passive 30-minute readiness policy were updated. Both sites returned
HTTP 200; readiness reported Tours.dev PASS, Wayfatours
DEGRADED_INHERITANCE (missing Registry v2), DRIFT 0.

**Remaining acceptance:** The administrator must create a **new**
preview from the live UI and confirm it. The old failed receipt remains
immutable and does not count as successful invalidation. No direct
real-tour socket purge or repeat of the previously blocked automated admin
POST was performed during this fix.

**VPS evidence:**
`/opt/mad4b-company-provisioning/reports/cache-control-admin-20261108/v1.1.1/`
