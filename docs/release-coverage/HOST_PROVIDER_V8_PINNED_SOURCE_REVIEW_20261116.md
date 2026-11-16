# Original305 Host Provider V8 — SHA-pinned source reconciliation (2026-11-16)

The enrolled Host Provider V8 manifest on MAD4B's original root-owned provider
contained **27 unique SHA-256 pinned source components** (24 dependency records
and additional handler/verifier/finalizer/evidence references).

The source-only candidate in this Git branch is byte-identical to that
manifest (SHA-256 be9457e6ea2a93f7b6ccc1083e9c68aa2cc9cd29832a7754ea23503570c248bc).

We restored **25 missing pinned source components**, unchanged, from local
`integration/market-cache-css-cron-20261115`. No Production files, WordPress
site data, runtime registry or broker state were modified. We deliberately
did not import active `host.source.release/manifest.json` or activate
the provider through the source preview.

Two paths are intentionally NOT overwritten:

1. `ops/native-source-publisher-v1/native_source_publisher.py`
2. `ops/company-code-release-v1/company_code_release.py`

Both have a different canonical source SHA-256 than the V8 manifest. Neither
old source nor current source may be declared equivalent without a semantic
diff, regression tests and a newly reviewed/signature-admitted host release.
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
