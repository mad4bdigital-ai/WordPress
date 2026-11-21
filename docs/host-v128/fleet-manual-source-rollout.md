# Fleet v128 — one source, optional release qualification, immutable Host security

## Fleet defaults

- `persistent` is the default **for newly approved v3 enrollments**.
- `operation_scoped` stays selectable for any registered site.
- `release_qualification = optional` is the **repository-wide source policy**
  for manually reviewed Staging changes, not a new remote mutation right.
- An individual site may require release qualification, or select
  `operation_scoped`, without forking the verifier code.
- No hard-coded All Royal Egypt host path, company UUID or country/market.
  Source lookup uses the trusted dynamic registry, `company:environment`
  selectors, and site UUID/SSH/key/policy hashes.
- Unknown/disabled sites, UUID reuse, scope drift and unsigned/stale
  readback cannot become current connections.

## Exactly one global release qualification default

`.github/mad4b-release-qualification-mode.json` controls
`qualification_mode`, initially `optional`. `required` restores strict
exact-head release artifact qualification; both modes keep mandatory GitHub
owner/feature-boundary/merge governance. An optional result is an **advisory
release qualification**, never an approved, signed artifact or production
release. `ops/host-authority-v128/release_qualification_policy.json`
controls per-site host connection modes and per-site strictness overrides,
but **does not duplicate** the global release default.

In the root Release Verdict workflow, the artifact qualification check is
optional for manually operated Staging changes only, while the Owner
Attestation, critical repository checks and governance remain required.
Under optional mode, the governed Control Plane update channel **cannot
auto-publish**. This is deliberate: an unqualified candidate must be
selected, backed up and deployed through a separate authorized manual
Staging operation. Setting `required` re-enables the complete artifact gate.

**Never** remove source identity, signed Host Enrollment, independent
high-water, exact operation approval, WordPress Site Profile, rollback or
Production's separate approval on behalf of this optional setting.

## Integration lifecycle per site (source-first)

1. Discover trusted company registry entry for the exact environment,
   site UUID, host ID, SSH principal, host key SHA and policy digest.
2. Read the current Original318 v2/v3 signature, revision and independent
   high-water (not a cached dashboard value). Do not overwrite or revive an
   expired record. A v2 → v3 transition is a fresh, owner-approved,
   independently signed new enrollment with a newer monotonic revision.
3. Produce unsigned `v3` enrollment proposal using
   `plan_new_site_enrollment`; pass it to registered Original312 and
   Original313 signing backends under an exact Owner Request. Never sign
   on a source-only test runner.
4. Implement Original318 v3 site-side readback/atomic lifecycle storage
   against the *same* provider registry, key identities and independent
   high-water. All operations continue to demand separate exact-grant
   authorization and refreshed activity, even for persistent connections.
5. For **Staging** only, back up the exact live installed PHP/script files,
   compare source SHA and destination realpath to the enrolled site,
   install selectively, test public/read-only parity, issue fresh owner-
   approved enrollment, then test cancel/revoke, long-running operation and
   rollback. Never infer success from the signed source test fixtures.
6. Roll out to the remaining companies by selecting trusted registry rows,
   not copying a company's options, fixed key, UUID, or PHP files blindly.

## Truth of this source candidate

The general verifiers, fleet resolver, CI release option and tests are
**source-side only** until active Original312/313/318 and the approved
host-runner are versioned, deployed and accepted on each site. Changing
`qualification_mode` cannot activate those providers or create a Grant.
