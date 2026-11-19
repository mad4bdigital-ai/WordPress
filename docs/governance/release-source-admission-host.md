# Source Admission: signed reconciliation prerequisite for MAD4B code release

## Workflow

1. An enrolled site administrator opens **MAD4B → Runtime Components → Runtime Update → Release Source & Reconciliation Preflight**, chooses one of four source modes and (for a branch) supplies its exact Git branch. A nonce-protected request stores only the source **intent** and current Site Profile. It never grants a write capability or deploys code.
2. A first-party host Cron worker (every five minutes) reads that site's request through WP-CLI, runs the audited `source_preflight.py` against the target, and saves a root-owned complete per-file diff report. It never runs code from the selected Git source, changes Git refs, or publishes WordPress content. Root-owned logs and the full report stay outside the WordPress web root.
3. Only when a source is an immutable exact Git commit, its release manifest is internally complete and agrees with that commit, and **all runtime conflicts on the exact target are reconciled**, can the Host worker sign a short-lived, *nonauthorizing* RSA/SHA-256 qualification receipt. Root-only private key resides at `/opt/mad4b-company-provisioning/secrets/release-source-preflight-rsa3072-private.pem`; the public key ships with the Control Plane MU module. Current live/Git sources do not meet these requirements yet, so no signed admission receipt is issued.
4. The `MAD4B_Release_Source_Admission` WordPress verifier requires a valid host signature, exact site UUID, profile digest, canonical origin, selected source type/reference, a pinned source SHA identical to the candidate package, and a recomputed current target inventory digest. The receipt expires within ten minutes. Any mismatch fails closed; a candidate can be reselected anytime.
5. Existing **Selected Head Update** plans and applies and **Self Update** upload/native plans include this prerequisite. More importantly, the shared `Self_Update::apply_verified_archive` final file replacement boundary checks it again even for bootstrap or internal callers. All original release certification, exact owner/OAuth, package hash, ticket, rollback and admission guards still apply. A valid source Preflight **never becomes a release approval**.
6. The `Company Code Release` WordPress read-only readiness reports the source gate status. **Its actual host Original313 release handler is independently pinned/signed, not controlled by WordPress, and is not modified in this release.** A separate Host provider admission step and re-enrollment are required before claiming this is mandatory at the Original313 boundary. No unsafe rewrite of that trusted handler was made.

## Supported source modes

- Local Git integration/master, specified by exact local branch
- Live Tours.dev (snapshot for reconciliation; not directly an immutable releasable commit)
- GitHub `master`, pinned to exact HEAD
- Any branch within the approved GitHub repository, pinned to exact HEAD

No branch can change the identity of another site; no user-supplied package URL is accepted.

## Operator evidence and rollback

Root host config, selection pointer, full reconciliation receipts and private signing key remain outside Git. There is no selected source by default, and no certificate is produced from an unresolved diff. Site-facing files (`status.json` and optional `attestation.json`) contain non-secret status and signed hashes only. Runtime PHP/PEM live installation is backed up under `/opt/mad4b-backups/release-source-admission-20261119/` before live mutation. Preserve the WordPress Site Profiles and all company-specific functionality; rollback only the modified PHP files and remove newly added MU module/data directory as needed, then recheck `wp eval` and HTTP 200.

## Acceptance tests

- Signed proof verification: nine hermetic tests for exact signature, SHA mismatch, target drift, altered signature, request change, expiry, and invalid branch.
- Host worker test suite: missing/stale manifest, unresolved source/target conflicts and moving source all deny a signed certificate.
- Live verification: on Tours.dev Staging and Wayfatours Production, `current_site_inventory()` matches the Host collector byte-for-byte; `Selected Head`/`Self Update` final replacement denies deployment without a signed source qualification; source status Ability is mounted on read-only MCP; both public sites respond with HTTP 200.

### Not yet complete

The original external `company_code_release` Host313 dispatcher is not gated by this WordPress module. Full multi-site signed release admission still requires an independently authorized Original313 enrollment update and actual source reconciliation into certified releases. Existing write grants/candidate bindings/Production mutation gate remain separate. Do not claim end-to-end production write enablement.
