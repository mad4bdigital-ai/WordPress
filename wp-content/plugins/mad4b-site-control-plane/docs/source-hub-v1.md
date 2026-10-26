# MAD4B Source Hub v1 — native, extensible source acquisition

## Intent

Own public and operator-authorized external data acquisition **inside MAD4B**. It is one engine for country holidays, tourism news, RSS/Atom, JSON Feed, and reviewed local-provider JSON contracts. This is **not** a separate cloud gateway, independent OAuth app, social publication automation or WordPress content authority.

## Components

- `MAD4B_SCP_Source_Hub`: site-bound registry, explicit draft/approve lifecycle, per-source data type, URL, ISO country, *currently visible* WPML language, revision and source integrity. `mad4b/source-hub-status` is a read-only permission-checked WordPress Ability. Source Hub admin page is at `wp-admin/admin.php?page=mad4b-source-hub`.
- Adapters: `public_ical`, `nager_holidays`, `rss_atom`, `json_feed` (JSON Feed standard), and `provider_json` (explicit `mad4b.source-provider.v1` envelope, no executable code or implicit rate updates).
- `bin/source-hub-worker.py`: native MAD4B companion transport on a network-authorized host. It can fetch only the exact URL listed in the Site Profile-bound, privately stored approved source manifest. HTTPS only; TLS certificate validation; no redirect; bounded response; DNS excludes non-public addresses; no extra passwords or bearer tokens. It writes a body hash, URL hash, source revision, timestamp and source/site identifiers into a private site spool. It **does not** write to WordPress.
- `bin/source-hub-batch.py`: the MAD4B companion's bounded multi-source runner. For ongoing fetch, schedule this under the site's authorized server scheduler only after source approval.
- `Source_Hub::ingest_snapshot($id)` validates a private site-bound local receipt against the **current approved** source and exact revision and normalizes into `pending_review` only. On a site with permitted outbound access, `fetch_wordpress($id)` supports WordPress safe HTTP as an alternate transport. Current ETG Lab explicitly blocks this second transport by site policy.
- Optional `mad4b_scp_source_hub_import` WP-Cron action imports existing validated inbox receipts hourly **only when explicitly enabled**; no network fetch, approval, season publication or pricing mutation.

## Registering additional RSS or local provider sources

1. Review whether an external publisher has an actual public RSS/Atom feed, stable HTTPS hostname, legal right to access and reasonable polling rate; do not invent feed URLs.
2. Operator reviews the public hostname using MAD4B Source Hub's **Trusted provider host** admin action; the host worker still rejects private IP addresses and redirects.
3. Create one source draft with adapter, exact URL, country, visible WPML language, title and revision. Approve that source explicitly. **Approval authorizes fetching, not publication.** A saved revision invalidates approval and removes the source from the worker manifest until reviewed again.
4. Host worker fetches to an OS-private directory. MAD4B imports for review; each item carries the source identity, source revision, country/language, category, content hash, article reference where available, and `pending_review` state.
5. Editors or delegated AI content reviewers subsequently classify, deduplicate and approve content using their own policies. Source Hub does not convert RSS articles into SEO blogs, Elementor banners, season terms, discounts or WhatsApp campaigns automatically.

## Country holidays and commercial seasons

Source Hub's raw event/news inbox is intentionally separate from `MAD4B_SCP_Season_Calendar` and from Context Authority's governed Brand Assets. A proposed holiday must pass Market Profile eligibility (country + visible WPML language) and Season Calendar's date review before it becomes an **inactive** `season` term. Content and offer activation remain separately controlled.

## Remote operation in isolated ETG WooCommerce Lab

The source worker runs on the **same authorized MAD4B host** as ETG; no additional managed feed gateway exists. The private worker manifest/spool is shared with the WordPress container under `/run/mad4b-local-vault/source-hub`, backed by the authorized Lab runtime directory. No changes were made to Lab HTTP sandbox configuration. The worker must not be run against other site vaults using these paths without separately enrolling each site.

Example read-only fetch after deliberate admin approval:

```sh
python3 wp-content/plugins/mad4b-site-control-plane/bin/source-hub-batch.py --vault <site-private-source-hub-vault>
```

WP admin can then import a validated receipt via **MAD4B → Source Hub → Import downloaded receipt**, or enable hourly *inbox import*. A reliable host-side scheduler is a separate operational prerequisite for unattended external fetching. No host scheduler has been activated for the three example drafts.

## Local acceptance, 2026-10-24

Real remote host-side fetching was exercised end-to-end for three providers:

- Nager.Date country API: 10 Egypt 2027 national-holiday records staged.
- `en.unwto-ap.org/feed/` tourism RSS: 10 tourism news entries staged.
- Google Public iCalendar Egypt Arabic: 89 date events staged.

The 109 test items and `qa-*` approved source records were then **removed**. Three recognizable example source configurations are retained as drafts (not approved): `egypt-holiday-ical`, `egypt-holiday-api`, `tourism-regional-rss`. No markets or seasonal offers were approved, no content was published, and no recurring transport or import automation was activated.

Local regression: XML XXE/DOCTYPE rejected, Atom and RSS2 parsed, structured local-provider JSON parsed, schema mismatch rejected, hidden WPML language excluded, unapproved source refused, repeated receipt re-import returns `new_or_changed=0`, corrupted body hash is rejected, PHP lint succeeds.

## Release governance not yet certified

- Existing source-host operator approval and bounded WordPress option event journal are **not** the MAD4B production append-only audit, governed agent write grant, or CI approval.
- The host spool hash and private file permissions establish local integrity checks and local custody; they are **not cryptographically signed attestations by a remote trusted authority**. Formal production transport needs explicit runtime identity attestation, signed receipts, replay controls, and complete provenance.
- Host DNS pre-check with `requests` does not fully eliminate DNS-rebinding risk. Enforce pinned-address transport or a hardened egress proxy before admitting arbitrary third-party hosts to production.
- Credentialed APIs, localization/translation, licensing and republishing rights, article-level deduplication, HTML sanitization policy, rate limits, load monitoring and content review must be certified separately.
- Plugin changes are local only in `feature/mad4b-market-profiles-v1-20261024`, based on the branch's recorded origin/master. They are not merged or deployed to Staging/Production.
