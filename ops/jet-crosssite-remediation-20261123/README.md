# MAD4B cross-site Elementor / JetEngine remediation, 2026-11-23

**Status: REVIEW / NOT DEPLOYED.** A root-shell tool safety check rejected the requested WordPress write step. It did **not** establish authority to execute through another route. A readback after the rejection confirmed the target documents' original hashes were unchanged. This branch is an auditable candidate, not a Production claim.

## Reproduced defects

- Tours.dev native mobile home HTML (EN/IT) contains two `id=destination-featured-attraction-listing` widgets (Attractions and Locations) and eagerly renders 45/43 Attraction cards. Original layout browser evidence has CSS epoch `140` while live CSS epoch is `154`; the certified listing template/CSS digests still match, but other CSS dependencies have **not** been recertified. Never update original certificate epoch solely on that basis.
- Wayfatours home EN/ES has unique Attraction/Locations IDs and the 60s verified signed JetEngine cache already works. Do not copy or overwrite this working path.
- Both archive pages contain `id=tours_query_archive` on a JetEngine Listing **and** Elementor buttons (the popup button may load later). The JetSmartFilters `query_id=tours_query_archive` must remain unchanged. Target only Elementor `button_css_id` on affected button widgets.
- Tour/Attractions/Locations grids still have `fadeInUp` mobile animation on identified templates. Change responsive `_animation_mobile` to `none`, preserving desktop `_animation` and JetEngine query/listing IDs. Wayfatours Featured Tours is already fixed and therefore excluded.
- Tours.dev source `/tours-and-activities/` cold 11s timeout was observed once, then FastCGI HIT ~36ms. Other source locale home cold render 4.2–6.5s with later verified HIT ~20–25ms. Cache is **not globally off**. Source market cache key includes trusted client/IP and request variants. Do not relax private-commerce or geo admission.

## Change scope (not executed)

| Site | Elementor docs | Field changes | Details |
|---|---:|---:|---|
| Tours.dev | 27 | 31 | 9 archive button IDs; 18 responsive animations; 4 duplicate Locations IDs |
| Wayfatours | 9 | 9 | 5 archive button IDs; 4 responsive animations |

The files `source-plan.json`, `wayfa-plan.json`, and `apply-elementor-surgical.php` use exact per-document old SHA256 checks, exact widget node IDs, strict field allowlist, JSON and reversal checks, SQL CAS transactions, and post-write readback. A preflight completed for both sites, including backups of all original Elementor data and element HTML caches. Private backups stay **on the authorized VPS** under `/opt/mad4b-company-provisioning/reports/jet-crosssite-remediation-20261123/`; no backup payloads are committed to Git.

## Release gates before any live application

1. Obtain the required authority/admission for WordPress writes; do not bypass a rejected safety check.
2. Re-run read-only preflight and confirm SHA digests are still current; retain a verified, accessible private backup.
3. Run the approved change in a single DB transaction per site; invalidate the affected Elementor element cache only.
4. Test uncached HTML EN/ES/IT plus remaining source WPML languages. Validate unique IDs, query-provider parity and native responsive `animation_mobile=none`.
5. Browser-test 390px and at least one tablet/desktop width including mobile archive filters, popup, result and next-page URL, without disabling the global Chromium resource gate.
6. Only then invalidate home and selected archive FastCGI cache entries, **never globally purge pricing/booking caches**. Re-test FastCGI HIT pages.
7. Source Layout 140→154 requires local browser recertification and a source-specific continuity manifest for actual current dependencies; this is a **separate** gated release. The Wayfatours continuity manifest also needs a new verified source snapshot if its tracked Elementor documents change.
8. Record proof receipts and CI before authorizing Production promotion or merging.

No WooCommerce pricing, booking state, JetEngine query/provider IDs, CSS V2 certificates, server admission or cache safety policies are modified by this candidate.
