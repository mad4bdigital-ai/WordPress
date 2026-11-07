# MAD4B — Live Semantic Company Reconciliation (2026-11-07)

## Source of truth

Both Tours.dev staging and Wayfatours production were read from the **live VPS PHP containers and WordPress database** before implementing the observer. The running site is newer than Git; this draft branch is a review snapshot, not the authority to overwrite live runtime.

## Installed runtime

- `387-mad4b-archive-filter-reconciler.php` — existing audit CLI, now summarizes semantic evidence from the new observer
- `388-mad4b-live-semantic-discovery.php` — read-only portable WP-CLI discovery: `wp mad4b semantic-discovery scan`
- `company-live-semantic-discovery-v1.py` — host-side comparison of enrolled source/target snapshots, receipt reconciliation and Nginx policy readback
- `company-reconcile-inventory-refresh-v1.py` — existing Company Reconcile inventory service extended to include semantic discovery; **no new timer**
- `test_live_semantic_reconciliation_v1.py` — 8 isolated regression tests

The deployed site-container mapping lives in the root-owned VPS file `/opt/mad4b-company-provisioning/policies/live-semantic-discovery-sites-v1.json`, deliberately not committed as portable code. Future companies must be enrolled and bound explicitly.

## What it discovers

1. **JetSmartFilters:** enumerate published filters by `_query_var` and evaluate the actual effective Query Builder taxonomy. Detect `related_program_term_id → programs` and `related_package_term_id → package_levels` dynamically; distinguish legacy inactive fallback metadata from active mappings. Never assume Query ID 311 is a portable ID.
2. **Tour Rates:** all published/draft records, Package Level missing/invalid, orphan/draft Tour parents, Stop Sale, program-term references and candidate multi-program Cruises.
3. **WPML:** Rate/parent-language integrity and standalone non-English Rate groups. Cross-company TRIDs are *not* treated as portable identities.
4. **JSF/DFSB and runtime:** signed JSF, deep-link rewrites, active presentation facets, enabled DFSB slots, LiteSpeed inline/external combine, and Visitor State lean bootstrap.
5. **Media and code:** repeated Tour gallery and hero values, hashes of selected live MU plugin, Query/Rate adapters, DFSB JavaScript and PHP.
6. **Nginx:** source/target cache policy hashes, public FastCGI TTL and bypass rules. A configuration readback is never misrepresented as a Cache HIT or browser performance test.

Historical receipts are correlated **against current live data**, never accepted as proof by themselves. The observer checks:
- PROGRAM filter source/query repair receipts on both sites (Duration Query → Programs Query)
- 32 Rate Package FK insert receipts and the current 32 exact Rate identities
- WPML Rate 61052 sibling repair receipt and current translation identity
- The source/target JSF/DFSB, cache and visitor runtime controls

## Current evidence

Main read-only report: `/opt/mad4b-company-provisioning/reports/company-live-semantic-reconciliation-current.json`.

Recent live observation verified:
- PROGRAM effective Query Builder is `programs` on both sites
- 32/32 previously repaired Package FK records still match the receipt
- WPML Rate 61052 remains linked to the expected language group
- No currently missing Package Level on published Rates
- JSF signature and archive rewrites available, DFSB slots enabled on Wayfatours
- Wayfatours JS inline/external combine remains off; lean Visitor State bootstrap remains available

The observer also exposes remaining debt without auto-modifying it: 16 published Wayfatours Rates attached to Draft Tours; repeated galleries; old filter mappings needing human review; cold Elementor/PHP latency; no positive real-world multi-program Cruise fixture.

## Scheduler and safety

The already enabled `mad4b-company-reconcile-inventory-refresh.timer` runs every 15 minutes subject to the existing optional-work/CPU admission. Under CPU pressure it may defer the cycle; never bypass this gate merely to get a fresh report. The host observer can also be run manually when the workload is admitted:

```bash
python3 /opt/mad4b-company-provisioning/bin/company-live-semantic-discovery-v1.py
wp mad4b semantic-discovery scan
wp mad4b archive-runtime audit
```

The observer sets `read_only=true`, `auto_apply=false`, `writes_to_tenant=false`, `generated_jobs=false` and `browser_acceptance=PENDING_EXPLICIT_BROWSER_TEST`. It does not create Rates, publish Draft Tours, issue grants, write SQL, purge caches, or copy mismatched files.

## Release warning

This is a **draft review snapshot**, not a deployable canonical package. Re-check current VPS SHA256 hashes and do a three-way reconciliation before merging into a future release. Do not transfer older Git versions onto the live source or tenant.

QA: 8/8 isolated synthetic regression tests passed; PHP syntax validation passed on both site containers; CLI discovery readback succeeded on both. The semantic timer hook is deployed but actual scheduled execution can be deferred by the host CPU gate.
