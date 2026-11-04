# Firecrawl transport research — Staging source capture

This folder preserves the **read-back verified** repair of the existing MAD4B Firecrawl research bridge on Central Staging. It is a source review artifact, **not** an independently installable WordPress plugin, a production deployment, or a Broker 313 receipt.

## Files and scope

- `staging-bridge-20261104.patch` is the actual unified diff from the pre-repair, VPS-backed copies to the live Staging files. The two deploy targets are `wp-content/plugins/mad4b-platform/modules/services/289-mad4b-firecrawl-research-bridge-v1.php` and `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-firecrawl-provider.php`.
- The GitHub repository's `master` does **not currently include** these two exact deployment files. Do not apply this patch blindly or create a second runtime-owned copy. Reconcile against the enrolled canonical source/package first.

## Observed Staging verification (2026-11-04)

- Host: authorized VPS, WordPress origin `https://tours.dev.mad4b.com`, WordPress environment `staging`.
- The shared parser's `MAD4B_Hub_Capture_Parser::MARKDOWN_LIMIT` is **900,000 bytes**; transport research was previously hard-capped at 60,000. The patch aligns the provider and bridge limits and raises the provider response cap to 1.5 MB.
- Three draft transportation candidates (airport car rental/limousine category, Go Bus intercity coach and Air Cairo route map) had persisted, hash-verified `scraped_page` Artifacts. The Air Cairo capture produced **87,948 Markdown bytes** and re-execution reused the same Artifact without duplication.
- The fourth original source (Cairo railway information) remained unreachable from WordPress; no Artifact was fabricated.
- Captures remain **reference review**, without live timetables, fares, supplier validation, bookable status, publication, or `313` receipt. A locale mismatch advisory was detected for Go Bus.
- Both PHP files passed `php -l`; Staging returned HTTP 200 but GET latency remained a performance concern.

## Release and reconciliation

1. Identify the genuine source owner for the two runtime PHP files; compare actual source SHA and deployed SHA before incorporating the patch into the canonical component.
2. Reconcile all intervening edits. Keep the exact `source_url`, Content Job, draft, and Artifact provenance checks; preserve idempotence and read-only quality UI.
3. Exercise PHP lint, authorized Staging capture, replay/idempotence, Markdown size limits, challenge-page rejection, WPML locale mismatch detection, and persisted Artifact SHA-256 readback.
4. Do not treat WordPress CLI capture as signed Broker execution. The `305 → Native Executor → 313` workflow needs its own valid original admission and receipt.
5. Never merge or promote this source package automatically from a staging patch. Production remains unchanged.

**VPS backup and report path:** `/opt/mad4b-company-provisioning/reports/provider-onboarding-20261104/`. These operational files are not part of this public repository.

## Follow-up: railway references and exact source-only candidate

The railway draft remains bound to the original source `https://cairo.gov.eg/en/governorate-services/transportation-services/railway/`. The Staging research-quality meta now records two **additional review-only official Cairo Governorate references** and the WordPress editor renders their links. The updated patch includes the rendering; this is **not** a replacement of the original ContentJob URL, an approved timetable, or a new `scraped_page` Artifact.

A root-owned VPS source-only candidate was also created at `/opt/mad4b-company-provisioning/source-candidates/firecrawl-transport-research-20261104/`. It contains the two exact current Staging PHP source files, a hashed manifest and a source-only ZIP, and it does not install or deploy them anywhere. See `source-candidate-manifest.json` for SHA-256 hashes and `runtime_auto_apply=false`. Both runtime PHP files are owned by the live Central Staging plugin tree; **GitHub master is not that tree**.

Original agent exact grant and principal ledger were read back as valid, but the original signed transport scope remains disabled and expired. The original signed 305/313 workflow is unresolved and cannot be completed by claiming a WordPress CLI capture as a Broker receipt.
