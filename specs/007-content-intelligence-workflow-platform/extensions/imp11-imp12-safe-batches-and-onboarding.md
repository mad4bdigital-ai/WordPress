# IMP11 + IMP12 — Practical Source Safety and Zero-Profile First-Run

**Feature 007 · PR #366 Draft · October 10, 2026 · exact source changes, native Staging acceptance not certified.**

## IMP11 — Batch concurrency, crash states, and durable cleanup

Previously an exact Profile could receive an append while an earlier approval/archive was modifying independent WordPress option records. Immutable chunk index uniqueness alone does not serialize batch changes. All four source-affecting actions (`begin`, `append`, `approve`, `archive`) now enter a site/Profile-scoped INSERT-ONLY SQL reservation, check lock ownership and release using a BINARY token-constrained DELETE. The export handler uses the same reservation through the end of streaming. **The earlier add_option() claim was incorrect**: WordPress may update an existing option during a race. See IMP14 for the corrected atomic protocol and real MySQL acceptance gate. Each operation remains **manual source review only**, not a background job or external provider writer.

**Fail-closed behavior:** A concurrent contender receives `mad4b_batch_mutation_locked`. A worker that throws an unexpected exception or cannot prove its own lock remains locked for explicit incident investigation, not automatically reclaimed after a timeout. The read-only `mad4b/business-activity-import-batch-mutation-status` ability reports age, current operation, redacted lock/batch identity SHA and whether encrypted manifest and archival tombstone remain. Age by itself never proves an unattended writer has stopped.

**Policy-change cleanup:** Earlier, disabling the Profile intake Mode or changing the mapping policy could make a previously staged batch impossible to archive. Cleanup now has a dedicated **Staging administrator-only** path independent of whether a new source is permitted. It verifies the exact active batch ID, enrolled site UUID, saved Profile slug, immutable manifest contract, bounded chunk count and archive audit before deleting old encrypted pages. It does not restore deprecated commercial approval, edit a WordPress post or retry a provider operation.

**Why the lock is not a global write fence:** WP All Import, WPML, JetEngine, native WordPress editors, other installed plugins, Google Sheets editors and MSR02 do not necessarily consult the same WordPress option lock. Certification of a universal writer lease requires that *every writing actor* participates or is fenced by an atomic lower-level gateway; merely adding an option lock cannot force third-party writers to honor it. No automatic writer permit was added.

### Negative acceptance cases

| Scenario | Expected outcome | Current evidence |
|---|---|---|
| Two WordPress processes append to the same Profile concurrently | One obtains the unique lock; other is denied | Source guard and isolated fixture; real DB stress NOT_RUN |
| Crash after adding lock but before returning a result | Lock remains held for inspection | Source guard; native fault injection NOT_RUN |
| Owner disables new CSV intake while a batch is staged | New intake is denied; exact archived cleanup remains permitted | Negative fixture added; native Staging NOT_RUN |
| Multiple archives after the same crash | Exact audited tombstone and cleanup verification; no blind release | Source recovery logic; MySQL fault injection NOT_RUN |
| Mapping proposal removes source external ID or a required language | Reject safety downgrade, not an automatic migration | Runtime negative fixture added; PHP execution NOT_RUN |
| Currency allowlist or required field silently shrinks | Reject until independent business migration is approved | Runtime negative fixture added; PHP execution NOT_RUN |
| A third-party importer runs simultaneously with MSR02 | Do not claim this internal mutex fences the external writer | **Global transaction fence NOT_DELIVERED** |
| A WordPress editor modifies a value after a source approval | Run-level pre-image and post-write CAS required | **External writer CAS/rollback NOT_DELIVERED** |

## IMP12 — First-run schema onboarding when a site has no Profile

The live All Royal Egypt site reported **zero** Content Experience Profiles on its installed rc.96 runtime. A guided import cannot legitimately ask the user to select an imaginary governed destination.

New read-only `mad4b/business-activity-import-schema-onboarding-plan` exposes two modes:

1. Without `post_type`: lists bounded real WordPress post types, excluding internal attachment/revision/navigation types and showing whether the requesting operator can edit each. Nothing is created.
2. With an exact registered post type: inspects its `get_registered_meta_keys('post', post_type)` entries, limiting to bounded identifier keys explicitly exposed through REST and excluding protected/private Meta; inspects declared taxonomies and capability signals; matches optional source headers **lexically only** to currently registered safe Meta candidates; emits deterministic schema hash plus explicit decisions needed to create the site's governing Content Experience Profile.

The response does not disclose raw source values, register arbitrary new Meta keys, create a Profile, infer JetEngine CCT tables or approve rights to use supplier data. Meta not present in the WordPress REST registry is explicitly unresolved pending a **provider-native schema driver**. CCT relations cannot be repaired by `post_meta` matching or unsafe PHP-serialized-value decoding.

### First-run guided sequence

1. **Discover WordPress content types** using `mad4b/business-activity-import-schema-onboarding-plan` with no post type.
2. Choose one exact CPT and inspect registered REST-visible Meta/taxonomy candidates; independently certify JetEngine CCT/relations if the destination is a CCT, not a CPT.
3. Gather stable supplier external ID, explicit price/currency/date rules, WPML language requirements, relationship ownership and source re-use rights.
4. Plan a Content Experience Profile with the existing `content-experience-profile-plan` ability, have the domain owner approve an exact revision, then apply/read back that Profile through its governed route.
5. Stage a strict CSV/XLSX source or 2–10 encrypted batch chunks. Use header-only Dynamic Mapping preflight before attempting invalid upload. No source field has authority to override site policy.
6. Independently validate actual native WPML `trid` and JetEngine data destinations. Only a certified installed-version-specific WP All Import adapter may later attempt an authorized disposable Staging job.

### External blockers confirmed by real All Royal Staging connector

- WordPress 7.1.3 / PHP 8.3.35, Control Plane rc.96; site Profile effective environment is **staging** via confirmed authoritative override while WordPress reports its core environment as **production**. Do not assume `wp_get_environment_type()` changed.
- Existing source branch PR #366 was **not installed**. Newly authored IMP11/12 abilities cannot be tested through rc.96 before deployment.
- WP All Import Pro installed 5.1.0, prior certified composite import 5.0.8, export 1.9.15 matches certified export. The import runner is **unmounted**: exact new package attestation, disposable Staging job, dry-run diff, trusted composite receipt and run-level rollback remain unverified.
- JetEngine installed 3.8.15.4 vs previous certified 3.8.11.2; CCT native driver and actual relations remain unattested. WPML 4.9.7 installed, external REST acceptance route absent; per-group independent readback not yet run.
- The live CPT/JetEngine complete schema discovery reads returned internal errors. IMP12 is an alternate bounded WordPress-native read path; it is a code candidate, not proof that the actual site has a particular `tour_rate` CPT or any specified Meta.
- Google Sheets multi-editor conditional CAS, shared WP All Import/MSR02/WordPress writer fence, full compensating rollback and Brand Core owner rights/approval are separately incomplete.
- GitHub Actions were still queued at latest observation. No native PHP 7.4/8.3, WordPress Staging Browser, MySQL fault injection or actual provider acceptance certificate has been produced by these code changes.

## Operational criteria for an actual release

| Acceptance gate | Minimum durable evidence |
|---|---|
| Exact artifact identity | Installed plugin zip SHA + Git HEAD + Site Profile UUID/revision readback |
| Source data policy | Exact approved mapping/ID/currency/language/rights contracts |
| PHP behavior | Native lint and regression fixtures at 7.4 and 8.3 with negative tests |
| Browser experience | New/disabled Profile, empty/error cases, mobile keyboard/RTL, CSRF refusal |
| Concurrent source inbox | Two simultaneous workers, interrupted page approval, crash cleanup, durable lock status |
| Import provider | Installed 5.1.0 exact certified adapter and reproducible disposable import result |
| WordPress/JetEngine/WPML | Native independent post/Meta/CCT and translation-link parity |
| Cross-provider consistency | Real conditional CAS across all writers, no writer excluded from fence |
| Recovery | Pre-image, partial failure, idempotent compensation, exact readback and audit |
| Promotion | Explicit owner Production authority, release policy and change window |

**Decision:** IMP11/12 improve safe source review and first-run usability in the branch. They do not close third-party execution certification, shared CAS or production release. No automatic source import, cross-editor writer bypass, merge or Production promotion is authorized.
