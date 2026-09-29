# Contract — Existing Site Bootstrap, Content Inventory and Intent Registry

Contract: mad4b.site-content-bootstrap.v1

## Purpose

Bring an existing WordPress site under Content Intelligence governance before autonomous creation starts.

## Bootstrap snapshot

A SiteBootstrapSnapshot captures:
- site_uuid;
- source/build/profile identities;
- WordPress version;
- active languages;
- content types;
- taxonomies;
- posts/pages/CPT objects;
- canonical/public URLs;
- post status;
- SEO metadata;
- structured-data identity where available;
- media references;
- internal/outbound links;
- redirects where discoverable;
- author/editor identity;
- update timestamps;
- provider-specific normalized observations;
- snapshot_sha256.

Bootstrap is read-only unless a separate reconciliation plan is approved.

### Remote transport safety and pagination

The bootstrap inventory is consumable through bounded remote MCP transports and therefore MUST NOT assume that an entire existing site fits in one response.

- `after_id` is a stable ascending object-id continuation cursor.
- the runtime may cap the effective page size below the caller's requested `max_items` to respect the transport byte budget;
- a page that does not cover the full inventory is truthful partial evidence and returns `inventory_pagination_required_or_active`;
- `next_after_id` advances only through material represented by the returned page, so transport truncation cannot silently skip content;
- verbose link arrays and provider/structured observations may be compacted for transport while their counts/digests and the full content fingerprint remain evidence-bearing;
- collision analysis on a partial page is explicitly scoped to `returned_page_only` and MUST NOT be presented as site-global collision truth;
- stale database error state from unrelated requests MUST NOT be mistaken for a bootstrap query failure;
- non-critical observation callbacks are exception-isolated and surface bounded snapshot errors instead of terminating the entire read.

The ChatGPT transport projection targets a response budget below the enclosing MCP transport maximum so wrapper/envelope metadata cannot push a valid page over the wire limit.

## ContentInventoryItem

Normalized item:
- content_id;
- site_uuid;
- locale/language;
- object type/id;
- public URL;
- canonical URL;
- title;
- status;
- content fingerprint;
- topic/entity signals;
- target/search intent;
- primary/secondary keywords when known;
- taxonomy assignments;
- inbound/outbound link counts;
- SEO/indexability signals;
- last observed/published/modified;
- source of each derived field.

## Intent Registry

An IntentClaim expresses:
- intent_id;
- site_uuid;
- locale/market;
- intent/query/topic;
- canonical owner content_id;
- ownership strength;
- supporting evidence;
- allowed supporting pages;
- conflict/cannibalization status;
- revision.

Before a new job claims an intent, the planner checks existing claims and inventory.

## Collision outcomes

- NO_CONFLICT
- SUPPORT_EXISTING
- UPDATE_EXISTING
- CONSOLIDATE
- CREATE_NEW
- HUMAN_REVIEW

A new page is not automatically created when an equivalent canonical owner already exists.

## Backfill

Existing content MAY be converted into artifacts:
- ExistingContentSnapshot;
- SEOStateSnapshot;
- LinkGraphSnapshot;
- MediaStateSnapshot;
- IntentClaim.

Backfill does not pretend historical content was produced by Feature 007.

## Incremental inventory

After initial bootstrap, use changed-since/event/provider signals where reliable, with periodic reconciliation to detect missed drift.

## Acceptance

- complete bounded inventory for configured site scope;
- duplicate URL/canonical detection;
- multilingual ownership does not collapse locales;
- no write occurs during bootstrap;
- intent collision can block a new ContentJob;
- historical source attribution remains clear.


## Intent cardinality

The Bootstrap contract discovers candidate intent relations but does not enforce a one-intent/one-page model.

Normalized intent relationships follow mad4b.intent-ownership.v1:
- many-to-many;
- role-based;
- confidence/evidence-backed;
- versioned over time.

Cannibalization is a derived analysis, not an automatic conclusion from multiple pages sharing a topic.
