# Non-authorizing integrated source candidate: master Host v124 + rc129 review

**Scope:** preserve current `master` Host Authority v124 and transportation/pricing commits, while including Draft PR #974 (itself containing #972 standalone Tour Media Host Bridge, #969 immutable conflict provenance, #964 email preview, #961 and the earlier rc129 source chain). This is a test/review candidate only. No site installation, database write, owner approval, mail dispatch or release merge.

**Exact parents:** master `1ed359a52ecd1c94574ddfd31c9327de5779b856` and source review `0db20cb1e1dfa29bde37bb4a65930b309656869f`. Local merge tree before adding review artifacts: `811d1491320f1507469ce8ff3a17523982e8b1b7`.

## Resolution of sole textual conflict

`deploy/host-authority/v81/current_revision_gate.py` existed independently on both branches. The current master v124 code **must be retained**: it verifies signed Broker `ledger.event_id == job.job_id` with a 32-hex ID, rather than trusting mutable WordPress `original_reserved_job_id`. The retained blob is `e02834573bd061ddbb8bd10629b7de13d9ff8e30`. No Host authority grants, Anchor issuance, runtime services, job claims or Bootstrap steps are executed by this candidate.

## Remaining PR942 collision source reconciliation

The previous PR #974 checked 11 combined/revised paths and kept release gates closed. The other **46 paths** are byte-identical to PR #920, **not** automatically signed off. They include **36 Control Plane `includes/` PHP modules**. The companion JSON contains exact path blobs and a read-only comparison against the running container's mounted plugin directory as observed on 20 Nov 2026. Values for unrelated repository workflows absent on the WordPress plugin mounts count as `absent`, not runtime failures.

- Tours.dev Staging: of 46 paths, 33 mounted file blobs differ, 3 match exactly and 10 are absent at the mounted path.
- Wayfatours Production: 34 differ, 2 match and 10 are absent at the mounted path.
- The highest-risk group remains Execution Authority, Background Agent Dispatch, Brand Integrity and Content Readiness; Live Source behavior is more nuanced than file digests. Some candidate methods are new hardened guards; Content Readiness's 13 apparently missing direct methods were actually moved unchanged into two traits. Full PHP-token normalization compared all 37 old direct methods with the candidate class plus traits; **37/37 method bodies match** for Tours.dev. This does not grant runtime acceptance for Wayfatours.

## Tests actually run (source-only)

- Original exact source PHP execution authority: 15 authorization scenarios PASS.
- Brand Quality original hermetic and destination Term Meta guard: PASS; original Readiness Workflow Options (18 cases), scopes/events (12 cases), draft-only safeguards (30 cases), native cron ownership PASS.
- Content Readiness trait parity source check PASS; Brand Lifecycle source contract PASS.
- WordPress-requiring Brand Integrity end-to-end suites were **not** run against production; isolated bare PHP correctly returned `WordPress required`.
- Historic `tours-dev-readiness-preactivation-source-only-runtime.php` rejects its old exact original digest on this newer tree. Its protected original assertion was **not edited or bypassed**; exact-head recapture and cross-version regression remain required.
- Host v124 narrow source-only `test_candidate_diff_contains_only_source_review_and_gate` deliberately rejects the combined 2,803-path integration when run against the new merged tree; the same original Host v124 test passes on its own bounded master branch. Do not weaken the source-only test or label the integrated tree release-ready.

## Acceptance gates (open)

1. Per-company non-mutating WordPress Runtime and staged UI acceptance for authority, brand links in Term Meta, Readiness cron/REST, Fluent Forms/SMTP feed bindings, four optional Media provider classes and signed Host capabilities.
2. Human/owner semantic reviews for all 57 original collisions, separately from this automated evidence. Confirm no runtime-only provider operations were dropped.
3. QA31/Feature007 exact-head recapture, original Host v124 scope-specific tests, complete CI on the new combined SHA and explicit rollback.
4. Final exact-head owner approval; no reuse of earlier PR #882 attestation for this different tree. The PR must stay Draft until these items are satisfied.
