# PR #814 integration of PR #815 — Dynamic Provider-Owned Capability Acceptance

**Verdict: SOURCE-ONLY VERIFIED SUBSET / RELEASE HOLD.**

Source branch: `feature/company-dynamic-capability-acceptance-20261113`, exact
Git head `4c04a91ff79cd1f3507aae1f15ae51aa7648ed9a` (PR #815). Integrates
its original 364-line dynamic capability acceptance Provider contract,
independent Original313 verification and first-party registered producer
preflight into the reviewed Company Release Crash-safe CAS source.

The old branch conflicts in one *source-only Provider Manifest*, not in an
active/installed Runtime Registry. Rather than substituting an older
`company_code_release.py` or `company_lifecycle_broker.py`, the merged source
retains newer source, Company Tenant/Profile and Host CAS implementations and
regenerates **exactly two** dependency SHA256 entries in the reviewed v4
`company.code.release` manifest. Its handler, verifier, evidence schema and
other dependencies remain exact first-party byte matches. The
`index.preview-NOT-ENROLLED.json` digest is repinned to the resulting exact
manifest. The Native Source `source.code.release` candidate manifest is
regenerated because its source dependency points to that same integrated core.

No hardcoded operation allowlist was added. A Catalog/Provider declaration
alone cannot run arbitrary Python/PHP/shell callbacks or generate a Grant:
selected checks are pin-bound to the Git plan, company/site identity,
original first-party root-owned Registry + signed Producer manifest,
postimage digest, unpredictable challenge, fresh Apply nonce and independent
Original313 evidence validation. Missing/revoked/stale unsigned evidence must
fail closed. A Provider's source-only registration is **not** an enrolled
runtime Provider or authority to mutate Production.

## Independent checks completed on local source candidate

- 18/18 dynamic-capability tests, including signed attestation, refused
  unknown Producer, missing coverage, revocation and post-write rollback.
- 3/3 exact Provider component-manifest digest checks.
- 94/94 Dynamic Registry tests, with a revised version-aware test that
  validates the current v4 manifest rather than expecting historical v3.
- 203/203 Native Source tests, including freshly rebuilt source-only Manifest.
- 9/9 unified non-regression tests including a dynamic iteration through all
  declared providers, pinned dependencies, and no installed Registry claim.
- 294 full-root tests executed; 14 pre-existing historical Original313
  TARGET_CONTENT_DRIFT errors, zero other failures.
- 226 broad Company Release tests executed; four historical Original313 plan
  enrollment errors/failures caused by the same signed postimage drift.

The reviewed GitHub pull request stays Draft and **must not** be promoted,
merged to master, deployed to a WordPress site, enrolled in first-party
Original306/313 Registry, or used to issue Grants/Jobs until independent
original signed authority, live postimage readback and Code Release verdict
are complete. A local/CI test success cannot substitute signed execution
receipts or current Target CAS readback.
