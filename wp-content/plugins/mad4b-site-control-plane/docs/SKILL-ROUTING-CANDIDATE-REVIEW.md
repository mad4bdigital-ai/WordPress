# Read-only Skill Routing — Candidate Review Evidence

**Status: PENDING REVIEW.** This document prepares evidence for repository governance. It **does not** attest owner approval, production readiness, complete CI, or the right to execute remote Skill text.

- Stacked PR: [#525](https://github.com/mad4bdigital-ai/WordPress/pull/525) based on [#522](https://github.com/mad4bdigital-ai/WordPress/pull/522).
- Source review scope: integration PR #528 at its live HEAD. The blob identities below identify the pending-review bytes independently of documentation-only commits; this record does not attest or authorize them.
- Immutable dependency: #522 head `cf909fb32cfbdfbfcad754708370e32904299e3a`.
- Scope: read-only task matching and non-authorizing skill composition. External Skills are never installed, executed, granted permissions or treated as trusted code.

## New capabilities and proposed existing controls

| Capability or service | Risk | Suggested existing controls | Review status |
| --- | --- | --- | --- |
| `ability:mad4b/skill-match-task` | P0 | MCP-01, PROVIDERS-01, EXECUTION-01 | PENDING |
| `ability:mad4b/skill-composition-plan` | P0 | MCP-01, PROVIDERS-01, EXECUTION-01 | PENDING |
| `service:wp-content/plugins/mad4b-site-control-plane/includes/class-skill-task-composition.php` | P1 | AUTOMATION-01, PERFORMANCE-01 | PENDING |

The owner/reviewer must decide whether existing controls are sufficient or a new control is required. Listing a control here is not approval.

## Exact source blob candidates

| File (relative to plugin root) | Git blob SHA | Review state |
| --- | --- | --- |
| `includes/adapters/class-mad4b-scp-skills-adapter.php` | `064937e152bbbff3ffa6b8ef4548d4d77b9d2ccd` | PENDING |
| `includes/class-mad4b-scp-skill-abilities.php` | `81ab601de559d9ba57991dffb226e129c4553a44` | PENDING |
| `includes/class-skill-task-composition.php` | `929ab0a06ad81ac015a279ec08f8db69ba665a17` | PENDING |
| `mad4b-site-control-plane.php` | `4c8d21033f3b479c038a2b010164bb17a9fca80f` | BASELINE — same blob as master |

The three modified skill source files require exact source review. The plugin bootstrap now matches master byte-for-byte and retains its immutable G6/G9 archive identity. Neither archive evidence nor review status was re-signed.

## Proposed regression evidence (non-authorizing)

| Test | Exact Git blob SHA | What it tests |
| --- | --- | --- |
| `tests/skill-task-composition-runtime.php` | `bd06234cd8f9efb9bb2b517c47c0ba8135d92b95` | input bounds, denied unknown sources, bilingual matching, pagination, non-authorizing plans, stage order, stable digest, partial discovery and rate-limit errors |
| `tests/skill-task-abilities-runtime.php` | `9fdab67df3c01b2d27e7c0160fa5adb72ffd0e79` | registration, existing read callback, no public REST/MCP, constrained schema, denied read, no execution ability |
| `tests/runtime-dynamic-skills-smoke.php` | `8628a0f5dc8ee5fa2a98b268339e8d1db3c2efba` | native WordPress registration and execution of read-only task matching/planning in disposable Staging |

Additional acceptance still required: current-head PHP 7.4 / 8.3 tests, native WordPress 6.9/latest Abilities, authenticated read-denial and MCP tools/list exposure, external GitHub egress/rate-limit, managed Skill reconciliation and snapshot identity.

## Findings before this review

At the previous exact head `da1d3f1a48febf525affe82eb4fe4d5011de79e1`, the Production Readiness artifact reported seven uncovered changes: three capability/service reviews and four source-blob reviews. The prior #522 head already had its own Feature 007/G9/Release Set fingerprint failures. This document does **not** resolve or waive those gates.

## Governance disposition

- No mutation of `governance/production-readiness/capability-coverage.json` has been made in this PR.
- An authorized owner/reviewer must examine these source blobs and actual test results before any `reviewed_for_ci_only` entry can be created by the prescribed governance workflow.
- `reviewed_for_ci_only` would still not grant a Production deployment, site authority, an MCP write surface, or execution of third-party scripts.
- PR #525 remains Draft until CI, live MCP acceptance, explicit reviewer evidence and #522 dependency are reconciled.

> Snapshot note: the candidate commit shown above identifies the code-state inspected for these blobs. Later documentation-only commits do not change the listed code identities. No `reviewed_for_ci_only` attestation or Production permission is implied.

## Candidate-specific review delta

The latest source update prefers enrolled managed Skills over untrusted external name collisions, without modifying permissions or external source reads. The matcher emits `external_name_collisions_suppressed` for audit, and corresponding fixture coverage was added. All source candidates remain **PENDING REVIEW** until the specified CI, exact-source, and owner governance checks are performed.

## Smart composition candidate delta

This candidate now retains external Skill descriptions/headings as **untrusted advisory context** for same-name managed matches, with verified blob identities, bounded reads and explicit fail-closed context status. Local Skill execution and site authority stay canonical. Semantic contradictions are not claimed resolved; the pending reviewer must examine the new content-fetch path, prompt-injection exposure, time budgets, review provenance, and absence of write grants before any governance review entry is issued.

## Integration repair for archived source identity

At integration candidate `f6b877b6c78437f9a54f09f32a92913ed49d2d84`, bootstrap `mad4b-site-control-plane.php` was restored to master-exact Git blob `4c8d21033f3b479c038a2b010164bb17a9fca80f`. The two Skills dependency requires moved to `includes/class-mad4b-scp-skill-abilities.php`, the existing owner of the read-only abilities. This preserves the archived bootstrap fingerprint without relaxing the G9 contract. The abilities file has changed and remains **PENDING REVIEW**. This note documents provenance, not acceptance, merge approval or authority.

## Additional pending review: source-family provenance continuity

The composition reader now checks the exact `repository`, `ref`, and `tree_sha` reported by the source reader against the previously selected external companion, **in addition to** source ID, path, Git blob SHA, document SHA-256, size, and untrusted status. The existing `skill_companion_provenance_unverified` failure remains non-authorizing. A regression test reproduces all three mismatches and proves the previously accepted tree mismatch is now denied, using network-disabled PHP 7.4 and 8.3 containers. The corresponding service and test Git blob identities appear above. This is technical evidence for future reviewer assessment, not `reviewed_for_ci_only` acceptance.

## Shared network budget security/performance review — PENDING

Two new source-blob candidates are `includes/class-external-skill-sources.php` at `6fce21a3b030992f01cca470ffe6caf94f52b183` and `includes/class-skill-task-composition.php` at `929ab0a06ad81ac015a279ec08f8db69ba665a17`. New tests use blobs `3e3672002ce5f685e04eb69c2e0314f21e21b1c1` and `bd06234cd8f9efb9bb2b517c47c0ba8135d92b95`. They first demonstrate that old 12-second per-call timeouts and expired source scans failed the new assertions, then pass for PHP 7.4/8.3 in network-disabled read-only containers. All requests in one composition share a bounded deadline; exhausted scans disclose incomplete results. This does **not** establish a hard real-time limit or waive the required independent performance, egress, security, and source-blob reviews. No owner attestation, new execution authority, or Production certification is granted.


## Advisory enrichment timeout reporting — PENDING REVIEW

A follow-up isolated RED/GREEN regression covers source discovery finishing before advisory context enrichment hits `skill_source_budget_exhausted`. The new composer keeps `execution_ready=false` and marks `source_page.budget_exhausted=true`, `page_complete=false`, `complete=false`; the external context returns `not_loaded_budget` instead of a generic unavailable state. PHP 7.4/8.3 network-disabled, read-only fixture and syntax checks passed in temporary containers. The updated exact Git blobs listed above supersede the previous composer/fixture blobs. This remains technical evidence only; no approval or Production authority is implied.


## Skill source egress policy regression — PENDING REVIEW

The new `skill_source_discovery` egress purpose is covered by real policy runtime
assertions (positive HTTPS provider authority, negative HTTP/TLS opt-out/redirect
and host drift, public versus private DNS) in
`tests/egress-policy-runtime.php`. The separate
`tests/egress-policy-contract.py` asserts the exact policy profile and
binds the source reader to `mark_request`. The integration Dynamic Skills
contract workflow executes both tests explicitly. These are isolated tests;
a live enrolled WordPress MCP/real networking acceptance remains outstanding.
No control or source approval is granted by adding this evidence.


## Git tree cardinality / memory hardening — PENDING REVIEW

A 4 MiB JSON response body can expand into tens of thousands of decoded PHP
objects and an additional indexed `files` array. On PHP memory-limited hosts
that is a separate resource risk from the existing response-size and MAX_SKILLS
bounds. External discovery now rejects any complete upstream tree above **10,000
entries** with `skill_source_tree_entry_limit` before it constructs the second
file-index array; a tree of exactly 10,000 entries remains supported.

Isolated RED/GREEN: a deterministic 10,001-entry Git tree fixture was accepted
by the original class and failed the new expectation on PHP 7.4 and 8.3.
After the guard, the same fixture correctly fails closed; 10,000 entries passes
on both versions, with `memory_limit=120M` inside an isolated 128 MiB,
network-disabled, read-only container. No registered source, cache, site or
Production environment was mutated. This is bounded technical evidence only;
actual WordPress memory pressure and live MCP acceptance remain independent.
The exact new class and fixture Git blob hashes above supersede prior ones.


## Exact public GitHub provider pinning — PENDING REVIEW

Security review identified that the previous `github_origin()` check validated
generic HTTPS syntax but allowed the externally configured GitHub provider to
switch to a different hostname. The reader now fails closed unless the
provider entry is exactly `https://api.github.com`, public, read-only and
Git Trees API v3. The isolated provider policy fixture exercises valid config
and tampered HTTPS hostname, lookalike host, HTTP, port, path, visibility,
write mode and API version. Red/green observed with PHP 7.4 and 8.3. This
hardening adds no provider installation or write surface and requires
independent security/release review before merge.

Provider policy fixture Git blob: `08060c1d64ae9e763c722c29f5517bfbfca2f060`.


## Site-neutrality source exception — PENDING INDEPENDENT REVIEW

The literal provider identity guard requires `https://api.github.com`
inside the external source-reader implementation. Its exact source path and
only the GitHub public API hostname have been added to the existing
`tests/site-neutrality-policy.json.reviewed_literal_endpoints` registry,
following the repository's established third-party service exceptions.
This is a read-only host classification, NOT a site or tenant default and
NOT a new egress authorization. Other hostnames remain rejected by the
site-neutrality scanner; explicit negative checks confirm this.
