# Repository baseline technical impact review — 2026-10-26

Scope: **non-authorizing, repository-source-only engineering review** for PR #516. This document does not grant deployment, live execution, reviewer approval, or owner attestation.

## Exact source and ancestry

- Prior reviewed Feature 007 parent: `17a447839bd091f95d964153814bc4382677a8c8`
- New PR base: `e7f30d944c354c3a4f7346330ad401d995e768c5` (merged master after PR #517).
- PR integration source at review: `021e8cea8f186d31bcec01644872ebcaa2e01561`.
- Read-only Git inspection: prior parent is ancestor of the new base; new base is ancestor of the integration head.
- Prior parent → new base: **96 changed files, +4,555/-273 lines**, checked with `git diff --check` (PASS); this includes 12 existing Feature 007 files, 21 Control Plane source include files and 32 Control Plane tests.
- Base → PR integration had **142 exact changed files** before this evidence-only synchronization. Change Slices must be recalculated and validated for the entire new head.

## Behavioral impact and boundaries

| Family | Review target and exercised independent regression |
| --- | --- |
| Environment admission and scope | Environment convergence policy (9 cases), nonproduction exact-grant policy (7), and cross-environment site scopes (9). No unconditional production grant claimed. |
| Agent and credentials | Unified agent connection, Passwordless MCP (26), Application Password bridge (16), and RS256 OAuth resource signature (23). No live token issuance or real client authorization performed. |
| Policy, authority and recovery | Context Authority runtime, post-update approved-grant recovery, and Staging write postcondition hermetic test. No staging/production privilege provisioned. |
| Transport and file permissions | Connector resilience, MCP connection stages, secure ChatGPT file-transfer permission, and route resolution fixtures. No content mutation or external provider certification. |
| Authorization routing negatives | AI-only early authentication route and path spoof denials (26). Local and Staging were exercised under test fixtures, not used as a requirement for the reusable core platform. |
| Feature 007 inherited records | Historical G2–G9 delivery fingerprints and closed task evidence remain unchanged. Only the current reviewed **repository parent** pointer is advanced; previously reviewed fingerprints are not re-signed. |

## Executed evidence

- Exact-head GitHub Production Readiness Matrix: mapped source regressions **65/65 PASS**, `PASS_COVERAGE_ONLY`, zero uncovered source coverage (non-authorizing).
- This VPS's exact committed source, network-isolated Docker PHP 7.4 and 8.3: the **five** credential/security fixtures above and the **ten** parent-delta runtime fixtures all exited **0** for both PHP versions. Those are **30 isolated fixture executions**, not 30 independent CI jobs.
- Branch-local Feature 007 specification, maintainability, readiness and release-set gates must rerun on the resulting new exact head. All policies, negative paths, immutable hashes and owner release gate remain intact.
- The runtime release source is `540d5db4be521297de673c8a4d14974c23b67a6a`; package identity remains **RECAPTURE_REQUIRED**. Do not label this technical metadata change a deployable package.

## Residual controls

- No independent live WordPress Authentication/Authorization, JWKS/RS256 provider, original-owner receipt, approved rollback or Production promotion has been performed in this review.
- A genuine final-head `OWNER_ATTEST_SINGLE_OWNER`, required CI completion, protected release verdict and separately authorized deployment remain mandatory.
- Evidence is restricted to known source files and controlled fixtures. It neither automatically authorizes external connections nor certifies every live runtime behavior.

## Additional fail-closed finding — inherited delivery source

Running the original unchanged `validate_spec.py` after staged parent metadata synchronization still fails:
`COMPETITIVE_EXPERIENCE_SPEC: FAIL: g6_delivery_source_evidence_drift:wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php`.

The immutable G6/G8/G9 bootstrap record binds SHA-256 `08b706dc15256d56bc33ec94082e2744fec84deee2f2dcaaabf4b2569a317212`, 55,074 bytes. The exact historic bytes were found in master ancestor `68ce3a12bf1bcc476c7ca8ab58c696a7cdadb504`. Current integration HEAD contains a separate CI-reviewed successor, 58,760 bytes; local source review is not permission to rewrite the historical fingerprint. Original G6 admin-page fixture is also historically bound. The existing extension validator is strict-current-source and does not yet consult the separately established historical successor contract.

The exploratory patch was discarded. Its replacement is a versioned exact-historical-to-reviewed-source contract: the validator and bootstrap must match archival bytes, exact Git checkout blobs and nonauthorizing review rows with negative regression evidence. Immutable G8 validator fingerprints and G6/G9 source archives remain untouched.

**Release gate:** the successor bridge and parent sync remain a local candidate until the unchanged original Feature 007 validator, negative regressions, CI on exact head, and owner review pass. No authority or runtime-release identity is updated.

The bridge additionally checks older UI/G1–G5/G7 manifests which retained SHA-only historical bindings; absence of an archived byte field never relaxes the historic SHA match or current Git blob check. The G5 service uses existing CI-only exact-source review; G6 legacy admin smoke requires its known historical SHA and byte length.


## Follow-up: Feature 007 cumulative-fingerprint CI agreement

The old read-only cumulative delivery checker incorrectly required all 227
historical fingerprints to equal current file bytes, contradicting the
unchanged original extension validator's reviewed-successor policy and
failing the original CI gate ahead of the specification check. Its read-only
mode now consults that **same existing** closed-scope verifier only for
reviewed historical successors and reports zero uncovered drift when the
current exact Git blobs, immutable archive SHA/byte records, and source
reviews all match. Negative tests reject a tampered Git checkout, historic
digest/size edits, unexpected paths, and synthetic workspaces; the original
ten manifests are neither changed nor re-signed. `--write` is explicitly
forbidden for the real archive-bearing repository but remains testable in
disposable fixtures. This is non-authorizing CI repair, not deployment
certification.


## GitHub-hosted live read-only runner network diagnosis

Original GitHub workflow job `121421329724` failed on the *first*
public home GET with `OSError [Errno 101] Network is unreachable`, before
WordPress/MCP assertions. DNS advertises A and AAAA; a read-only VPS run of
the **unchanged complete script** returned all expected anonymous OAuth
challenges and HTTP codes. The source-level runner now tries reachable IPv4
DNS answers first with IPv6 fallback, retaining the original TLS host
verification, redirect denial and strict status/body checks. A four-case
offline unit regression guards the address sorter. CI must independently
confirm GitHub-hosted network admission; no inaccessible Staging site may
receive a fabricated PASS.
