# Original305 Host Provider V8 — SHA-pinned source reconciliation (2026-11-16)

The enrolled Host Provider V8 manifest on MAD4B's original root-owned provider
contained **27 unique SHA-256 pinned source components** (24 dependency records
and additional handler/verifier/finalizer/evidence references).

The source-only candidate in this Git branch is byte-identical to that
manifest (SHA-256 be9457e6ea2a93f7b6ccc1083e9c68aa2cc9cd29832a7754ea23503570c248bc).

We initially restored **25 missing pinned source components**, unchanged, from local
`integration/market-cache-css-cron-20261115`. No Production files, WordPress
site data, runtime registry or broker state were modified. We deliberately
did not import active `host.source.release/manifest.json` or activate
the provider through the source preview.

After a subsequent exact-version reconciliation, the canonical
`ops/native-source-publisher-v1/native_source_publisher.py` is now identical
to the existing enrolled V8 version, restoring the first-party Host312,
Master Admin and Broker proof checks. The native publisher offline fixture
was updated to include the actual pinned first-party dependency closure,
and the read-only CLI surface is tested without assuming the policy file
must be absent on an enrolled host. All 14 publisher and 3 V8 pin tests pass.

One newer canonical source remains intentionally different:
`ops/company-code-release-v1/company_code_release.py`. It contains reviewed
support for numbered first-party MAD4B MU loaders and stricter dynamic plan
readiness diagnostics. Replacing it with the old V8 pinned version would
regress those improvements. It requires a separately approved successor
manifest and independently signed release before the enrolled V8 pin can
change; this source review does not grant that authority.
The attached JSON records the exact old-pinned and current source identities;
the CI regression requires these disparities to remain explicit.

**Verdict:** 25 restored exact source files; 2 unresolved canonical
fingerprints. Production release still held: no fresh Master Admin approval,
Host312 signed proof, signed Readiness or Original313 receipt was issued.
The tests are source-only and must never be used as a release grant.

The associated **source-only** V8 upgrade planner, V6 Supervisor candidate
service and original 305 signed-delivery invariants were also imported from
the same local branch. The additional 4-case postwrite and 2-case Supervisor
tests passed; no systemd unit, timer, root-owned registry index or signer was
changed. The V7 candidate manifest is retained only as an immutable
transition reference, not an active provider manifest.
