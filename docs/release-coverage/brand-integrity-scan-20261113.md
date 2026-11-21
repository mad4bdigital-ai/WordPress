# MAD4B Brand Integrity — live audit, 2026-11-13

## Scope

The existing Brand Experience Quality and Content Readiness modules own
diagnosis and issue reconciliation. The Content Experience Runtime
publication transition owns the publish-time guard and existing rollback.
There is no global save-post hook, competitor-name blacklist, frontend
request-time scan or automatic replacement of content.

Identity derives from the current Brand Context and WordPress site profile.
Site-scoped approved aliases and trusted domains are provider-extensible.
Unrecognized brand names are detected in promotional attribution context.
External editorial links remain advisory unless they are promotional
navigation requiring a site-owned destination.

## Verified site observations

| Site | Published tours scanned | Brand attribution findings | External promotional URL findings |
| --- | ---: | ---: | ---: |
| Wayfatours Production | 84 | 113 | 84 |
| Tours.dev source | 221 | 248 | 221 |
| Combined | 305 | 361 | 305 |

All 305 tour records examined share the same
tour_internal_linking_exploration_content value and an external promotional
link to staging.egypttourgates.com. The source field has the identical MD5
d590450e24879f95f69d84a1d6a59408 on both sites.

Examples of attributed entities are DONA, All Royal Egypt, Wo Vacation and
Egypt Tours Plus. These are observed evidence, NOT configured forbidden names.
Observations include EN/ES on Wayfa and EN/ES/FR/DE/IT/AR on the source site.

## Verification

- Site-aware PHP runtime tests: 19/19 Wayfatours and 19/19 Tours.dev.
- Publication preflight on published post 54877 returned
  mad4b_brand_integrity_publication_blocked with three high findings.
- A clean published page passed that preflight on both sites.
- PHP syntax checks succeeded for new and edited PHP code.
- Brand Experience discovery completed on each site.
- Content Readiness reconciled 197 Wayfa and 469 source findings.
- Source resolved history was archived when necessary to preserve the
  3,000 active-ledger ceiling; readback yielded 2,849 active and 616
  archived records, with no duplicate issue IDs between stores.
- Archival uses a non-autoload WordPress option with ordered write/readback
  before trimming the active ledger and preserves issue reopening history.

## Scope limits and remediation

1. Code is deployed as a live canary, NOT a signed Original313 release.
   Canonical release integration and post-update persistence remain open.
2. Contaminated published copy was not silently edited. Use revision-fenced
   governed Content Jobs, identify approved site-local target links,
   preserve translation relationships, and verify guest-visible output.
3. The guarded publication lane is MAD4B Content Experience. Other publishing
   lanes need auditing and explicit integration with the same provider.
4. Low-confidence entity classifications and ordinary outbound editorial
   links are review-only. High-confidence attribution mismatch blocks the
   controlled publication transition.

## Follow-up remediation and expanded coverage — November 14, 2026

A source-field CAS repair tool was added at
tools/brand-integrity-content-repair.php. It is CLI-only, requires exact
site identity, revision, an existing ContentJob review intake, and a
SHA-256-fenced source plan. It does not issue Original313 publisher grants.
Every changed meta row was independently read back.

### Corrected content

| Site | First repair | Orphan locale | Italian detector | Deep repeater/description | Confirmed field writes |
| --- | ---: | ---: | ---: | ---: | ---: |
| Wayfatours Production | 113 | 0 | 0 | 12 | 125 |
| Tours.dev | 243 | 5 | 13 | 5 | 266 |
| Combined | 356 | 5 | 13 | 17 | 391 |

All 391 source-field writes have a private before/after plan, exact-source
CAS, and per-row SHA-256 readback. Source-specific JetEngine repeaters were
parsed and reserialized without changing their item topology: seven
repeater records and 56 total itinerary stops retained every existing item.

Language handling was site-bound: EN/ES on Wayfatours, and EN/AR/FR/DE/ES/IT
on the source. A published translation with an unpublished or already-clean
canonical parent receives a separate Content Readiness review scope, which
does not publish the canonical post.

### Independent QA

- Expanded runtime detector covers native itinerary_item repeaters, textual
  _description, and multilingual promotional attribution phrases.
- Ordinary geographic references and words such as Donations / Donaciones
  are not treated as organization names.
- 30/30 text and link runtime tests passed on each site in candidate testing.
- Complete final scans: Wayfatours 84/84 published tours with zero current
  findings; Tours.dev 221/221 published tours with zero current findings.
- Content Readiness history: Wayfatours 209 resolved / zero open brand issues;
  Tours.dev 487 resolved / zero open brand issues.
- Chromium verified 12 published pages, including day tours and Nile cruise
  tours in EN/ES/FR/DE/IT, at 1440px desktop and 390px mobile. All returned
  HTTP 200, with zero checked legacy brand names or prohibited promotional
  staging links in page HTML or visible text and zero horizontal overflow.
- Independent raw metadata checks identify legitimate non-brand substrings
  such as Donations, plus one stale historical oEmbed cache record per site.
  Neither stale embed was present in verified public HTML.
- Separate source-gallery media URLs refer to an external staging host;
  those require media-origin review rather than blind text URL replacement.

### Release caveat

Verified live canary and local source modifications are not a signed
Original313 release. The content repair receipts explicitly record
original313_release_receipt=false and
approved_content_job_execution_receipt=false. A canonical signed release
and independent post-upgrade proof remain necessary for durable fleet parity.
