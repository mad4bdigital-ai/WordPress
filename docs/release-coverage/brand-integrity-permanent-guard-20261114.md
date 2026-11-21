# MAD4B Brand Integrity — permanent behavior review, 2026-11-14

## Authority and source ownership

Brand Context supplies site UUID, identity, approved aliases and trusted hosts.
Unknown company names are detected in promotional attribution context,
without a denylist of competitor names. Normal citations are not flagged.
Only promotional destinations demanding a site-owned URL are blocking.

The original Brand Integrity / Brand Experience / Content Readiness providers
remain the system owners. This is not a new external content pipeline.

## Source coverage

Read-only discovery inspects the post title/body/excerpt, Elementor customer
text settings, native JetEngine repeater leaves, textual descriptions, and
other non-sensitive textual postmeta regardless of field naming. CSS/script
fields use asset controls and are outside editorial text scope. Discovery
paginates in 500-post batches, with a 20-page maximum. All incomplete fields,
unexpectedly deep structures, hit limits, text-size limits and unfinished
pagination yield incomplete coverage, never a clean certificate.

Company-neutral baseline coverage includes published Posts, Pages,
Elementor templates and registered Products. Additional customer-facing CPTs
are selected dynamically from the company's existing Content Readiness policy
and the actual WordPress public/queryable/searchable type registry.
The site-scoped provider may ADD approved display types, not remove the
baseline. No fixed tourism business-model identifier belongs in the
shared Brand Integrity runtime.

The Brand Experience result now includes scannable types, coverage gap count,
scanned count, source site UUID and issue count. The original Content
Readiness audit persists a bounded coverage proof when reconciliation succeeds.
Closing an old Brand Integrity issue additionally requires complete current
coverage of the issue's original post type AND the same site UUID. Removing
a CPT from configuration cannot automatically resolve an issue from that CPT.

## Governed mutation lifecycle

The original Content Experience operation remains the only authorized writer:
- A potentially public create/update checks the proposed content before write.
- Existing public updates compare their final issues to a complete
  pre-update baseline, including source digests. New/changed high-confidence
  mismatches use the existing compensating rollback. Identical historical
  issues do not block an unrelated update.
- New Published content is staged as Draft through meta/media/helpers, and
  only published after Brand Core and Brand Integrity final preflight.
- Future and Private creates also undergo proposed and final content checks.
- No new global save_post hook, generic SQL mutation or alternate publisher.

Direct edits through uncontrolled WP editors, WPML, Elementor and DB writes
are NOT universally blocked synchronously. The native periodic scanner is
their eventual-detection path; all future writer integrations must re-use
the source-controlled guard rather than asserting global coverage.

## Native scheduling and Story V2 recovery

Native Readiness plan/run and site policy are the sole recurring scanner.
The host supervisor dynamically resolves eligible PHP WordPress containers,
rejects duplicate site UUIDs, uses OS-level flock to prevent overlap, honors
the configured due interval and exact plan SHA, and independently reads the
native plan after a successful run. Host files and cron syntax are source
tracked under tools/mad4b-content-readiness-supervisor.py and tools/cron/.

Story Observability V2 was observed as identical live MU code on both sites.
Its story_browser_popup_failure contract declares HIGH severity, but its
accepts_issue validator supplied MEDIUM and crashed Readiness. The live
one-rule fix preserves HIGH severity and allows Readiness to finish. That
exact patched MU file is now tracked in the review source; the release
reconciler must confirm the current owner path before applying and avoid
duplicate plugin or MU implementations.

## Independent evidence

| Site | Published Tours | Full editorial inventory | Coverage gaps | Confirmed Brand findings |
| --- | ---: | ---: | ---: | ---: |
| Tours.dev | 221 | 1,552 | 0 | 0 |
| Wayfatours | 84 | 515 | 0 | 0 |

An optional broader approved CPT scope was also tested read-only:
Tours.dev 1,665 items, Wayfatours 1,102; both had zero coverage gaps and
zero confirmed attribution findings. Operational and private CPTs remain
outside the default global baseline.

Generic welcome text is no longer treated as company attribution by itself:
"Welcome to WordPress" and "Welcome to Luxor" are insufficient evidence.
The optional contextual entity provider may raise ambiguous cases for
editorial review; explicit company-claim phrases still trigger first-party
detection. Large CSS source values do not count as an editorial-text
coverage failure.

- Candidate text/link cases: 35/35 passed on each site
- Candidate lifecycle cases: 22/22 passed on each site
- Ledger closure scope regression: 6/6 on each site
- Site Neutrality contract: PASS (829 runtime files, PHP 8.5 hermetic CLI)
- Live Story signal severity regression: 3/3 on each site
- Host supervisor pure unit tests: 6/6
- Original Readiness CLI plan/run succeeded on both sites after MU repair,
  with independent due=false post-run readbacks
- No tour, booking, media, payment or translation was modified by QA

## Release holds and remaining limits

PR #821 is a draft source review against PR #814 unified integration, not
an Original313 signed release or authorized Production deployment. Do not
merge into master, auto-enable unreviewed writer routes, or remove the
existing Release HOLD. Provider-specific release CI and independent
post-upgrade checks remain mandatory.

Ambiguous brand context and visual-only rendered strings still need editorial
or browser QA; ordinary outbound links and historical gallery media URLs
must not be blindly relabeled as company attribution failures.
