# MAD4B Central + Wayfatours: target resource evidence and exact 312 admission
Date: 2026-11-12. Scope: Central tours.dev.mad4b.com + Wayfatours Production only.

## Goal and actual changes
The central Company Repair Jobs engine now consumes a real, administrator-authorized, read-only WordPress resource observation captured on Wayfatours, rather than a purely synthetic provider manifest. The target MU module can hash exact post/term/plugin identities, nonpublic content hashes, metadata and taxonomy membership, and a digest of selected company-level configuration without returning sensitive option or metadata values. It never executes a correction, changes a target, issues a grant, or signs a receipt.

Target reader compares two complete resource readbacks and before/after company-preservation digests. If any result differs or JSON serialization fails, it returns a HOLD rather than an apparently valid but misleading hash. The double-read consistency check is NOT a database snapshot, cryptographic target attestation or original 313 receipt. The full resource and company configuration digest is still unsigned; the operator-imported snapshot is marked as such.

An administrator first invokes the target WordPress WP-CLI read-only evidence command. The central CLI importer admits only the exact expected schema, current Site UUID, company environment and canonical HTTPS origin; it preserves the active source plan SHA and strict resource selector. Import is protected by a per-resource MySQL advisory lock and signed/unsiged authority claims are never elevated. The short-lived observation is 300 seconds and is never fetched from an arbitrary URL, customer-facing page or general HTML endpoint. Target values can change between capture and import; re-resolution/preflight freshness checks remain required. Only the center WP options journal is updated.

A first-party Original Native content.write provider now advertises source-owned resource resolution to the dynamic Registry. It can read the previously admitted unsigned target WP-CLI snapshot for the eligible_content_manifest check (incident draft_tour_rate_advisory); it is NOT a hardcoded map inside the generic Repair Jobs orchestrator. Other providers may register their own resource/check scope under the same source-owned contract; there is no global operation enumeration.

## Exact Original 312 versus generic transport
A live preflight initially showed a dangerous contradiction: generic Original transport readiness was TRUE for content.write, while the general provider readback was FALSE because it did not include company and environment. The first-party scoped provider now implements exact_company_312_readback(company,environment,operation), which delegates to the existing cryptographic Original 312 verifier (no new certificate, bypass or generated signature). The generic Repair preflight requires both the exact-site valid target observation and that source-owned signed 312 callback before showing native capability ready. A truthful exact 312 capability does not imply an Original 305 job was submitted or an Original 313 result exists.

An existing untouched, unexecuted resource journal may reconcile a newer provider capability descriptor only through a hash-chained RECONCILE_PROVIDER_CAPABILITY event preserving old/new hashes. This is prohibited after baseline, claimed job or verified receipt. The actual Wayfatours resource job ID is 22e60729e0e14bd6754139197b69d451f7c08dae, and its prior baseline-free journal was reconciled to revision 1 without a replay. (The event note never asserts a job was executed.)

## Real Wayfatours acceptance
- Exact production identity: Site UUID a3a7cc36-db7e-4a14-8456-b41857def05c, canonical origin https://wayfatours.com.
- One Tour Rate: wordpress.post:61152, a published tour-rates post, with 39 metadata keys and 1 taxonomy group in the sample observation.
- One independently read tour: wordpress.post:60661, 54 metadata keys and 18 taxonomy groups.
- WooCommerce plugin active entrypoint observation verified independently; source file hash only, not full plugin distribution and not plugin license evidence.
- Local target readback retained the same resource, manifest and tenant-preservation digests across the two-pass test.
- Center imported the exact Wayfatours resource snapshot with explicit unsigned provenance, without a target write.
- Source-backed dynamic provider discovered content.write with one matching candidate and returned EXACT_RESOURCE_OBSERVED_UNSIGNED for 61152. It was not a fabricated provider.
- The native capability Original 312 for this exact company/environment returned verified at the time of the tested readback. Such a signed readiness heartbeat is time-limited to 300 seconds and expires; do not interpret as permanent availability.
- One exact child job is persisted, state AWAITING_RESOURCE_BASELINE, valid immutable journal, hash-checked resource index. Central queue still has 19 incidents and zero integrity holds.
- Frontend/REST evidence access requires an administrator, and unauthenticated calls returned 401 on Wayfatours and Central. The admin resource-preview endpoint is no-store.

## Test and deployment coverage
Offline: 29/29 central snapshot store assertions, 19/19 scoped 312 preflight structural assertions, 52/52 resource resolver assertions, 28/28 resource lifecycle assertions, 67/67 dynamic registry/journal assertions, 17/17 structural Original job and receipt assertions, 15/15 queue assertions, 35/35 depth policy assertions and 16/16 target reader assertions: 278 pure PHP assertions. Exact real cryptographic 312 verification was independently observed on center, but the 19 mock assertions are not signature tests. WordPress regression suite most recently returned 21/21 before the final hardening; attempting to rerun during this increment was blocked by STAGING_WPCLI_BUSY and that rerun MUST NOT be marked successful. Admin asset JS parsed with Node and contains no innerHTML.

All changed current source bytes should match central live installed plugin include files; target MU bytes should match both Central and Wayfatours files. All deployment code is root-owned, not group/world writable, and the root-owned source copies are in Git. Prior V2 provenance is pinned to its original Git commit a7ea8620e1; it is not rewritten as current live V3.

## Gates that remain open
1. No Baseline accepted for resource 61152. No expected postimage or rollback artifacts have been authorized.
2. No Original 305 execution for the resource. No independent 313 readback. No actual repair or change to its tour price.
3. Original 318/313 signed central release certification stays HOLD, despite live source file parity.
4. Signed target-origin attestation / independent proof of target readback still requires an Original authority flow; a privileged WP-CLI snapshot import is useful evidence but cannot substitute.
5. Rates/children/infants, cruise Program / itinerary, WPML cross-locale links, filter AJAX, checkout, Stripe, SEO and browser/mobile acceptance are not closed by these resource readback tests.
6. The per-incident exact-resource index remains bounded to 64 children pending a pagination/sharding implementation.
7. Other company sites have not been updated or reconciled. Full Clone was not run.

## Rollback
Center plugin preimages are under /opt/mad4b-company-provisioning/reports/company-target-evidence-deep-v1-20261111/, with source preimage snapshots for each release stage. Prior target-MU preimages are in preimage-second-read and preimage-encoding-guard folders; for a full pre-V1 rollback remove the new target MU file from Central and Wayfatours after disabling dependent collection workflows. The exact center resource journal and imported source observation are audit evidence; retire through a governed process rather than silently removing. There are no target content or price edits to undo.
