# MAD4B Control Plane — Permanent Catalog Intent Branching Policy

**Contract**: `mad4b.catalog-intent-policy.v1`  
**Native WordPress read Ability**: `mad4b/catalog-intent-plan`  
**Plugin implementation**: `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-catalog-intent-policy.php`, loaded from the canonical Research Intelligence plugin module.

## Purpose

A page listing many tours, cruise vessels, hotels, products, or destinations is a **discovery source**, not a single individual product ready for publication. When extracting catalog links, retain potentially useful non-product URLs and route them by their **relationship to the requested research intent**, rather than blindly dropping links that do not look like product detail pages.

Each observed link must carry explicit `entity_type`, `discovery_context`, and `intent_relation` from the extraction evidence. The policy never infers authoritative trip identity, commercial pricing or rights from a URL. If that context is missing or ambiguous, hold the candidate in the review lane. Source HTML acquisition/verification (Firecrawl or another approved provider) is external to this read Ability and continues to require existing credentials, exact source allowlists, governance and rights checks.

## Deterministic routing contract

| Lane | Admission evidence | Proposed tasks |
| --- | --- | --- |
| `primary` | Directly relevant catalog `listing_card`, typed as individual tour/package; for destination-guide intent, an attraction/destination listing card | Independent research task per main entity |
| `complementary` | Contextually relevant editorial guide, destination, attraction, comparison, recommendation, related offer or collection with explicit supporting/exploratory/direct relationship | Independent research task, optional exact parent links; use to enhance main itinerary and related editorial content |
| `discovery_expansion` | Relevant filters and pagination links; preserve real filter query arguments | Bounded follow-up enumeration; classify new results again; do not create a false product |
| `review` | Unknown/unrelated/weak context, unrelated footer/nav, ambiguous entity type | Explain why, collect additional evidence, defer automatic task creation |

**Links are never discarded solely because their entity type is not `individual_tour`.** Even an unrelated/unknown URL is retained for review unless the caller deliberately chooses a later governed exclusion. A link in a `recommendation` section is not automatically promoted to a main cruise just because its title includes "Nile cruise". The caller's `intent` changes the routing; e.g. an attraction can be a primary card of a *destination guide* but a complementary reference for a *Nile cruise catalog*.

The `related_primary_urls` field is accepted only if it references a primary task URL explicitly present in the same plan. Unresolved relationships are reported for review; the policy never assumes all cruise ships visit all attractions.

Canonical URLs strip common ad trackers while retaining actual page and filter parameters. If two links claim the same `entity_key` the policy treats that claim as an alias, reports the duplicate, and preserves its URL for explicit dependency resolution. Existing local URLs are signaled as a review/update path rather than creating a duplicate; this is only a caller-provided inventory hint, **not a database duplicate check**. Real title, WPML translation, post ID, slug and commercial product identity checks remain a separate governed content preflight.

## Output and independent workflow

`mad4b/catalog-intent-plan` is private, `mad4b-read`, `readonly=true`; input requires `catalog_url`, `intent` and 1–200 candidate links. Every task includes the catalog URL, source URL, source title, evidence type, intent relationship, reason code, stable task ID, review steps, and optional main-task associations. The plan explicitly reports counts and duplicate evidence. It never calls an external provider, creates a post, inserts a task into a journal, records an artifact, manipulates taxes/prices or publishes.

For task execution, continue through the **existing** `mad4b/research-provider-plan` (provider authority), `mad4b/research-competitor-select` and approved `mad4b/research-scrape-record` (research governance), `mad4b/research-coverage-build` and `mad4b/research-information-gain-build` (analysis), `mad4b/content-orchestration-plan` (independent content planning), and the existing separately approved write path. No auto-replay of a blocked MCP request, no automatic journal mutation, and no silent downgrade of source quality.

If Firecrawl or SerpAPI is unavailable or MCP returns `mad4b_mcp_runtime_restart_grace`, **retain the classified task plan**, preserve source and missing-evidence notes, and wait for the existing operator convergence/authority gate. Never treat incomplete evidence as successful page research or verified Google ranking.

## Acceptance scenario: Memphis Tours Nile cruise catalog

Source: `https://www.memphistours.com/egypt/cruises/river-nile-cruises`. Recorded extraction: 13 distinct vessel cards. They are 13 **primary research candidates**, never 13 auto-published tours. Links to Philae Temple, Kom Ombo, Karnak, Luxor, Aswan and Abu Simbel can become **complementary research** when the research intent supports them. Nile cruise filters, luxury/dahabiya category links, and pagination can become **discovery expansion** tasks; they are not skipped as navigation-only if they genuinely expand the goal. Related Cairo/Nile tour packages can be complementary contextual offers without being mistaken for the individual cruise inventory. Duplicated legacy product URLs are aliases or review-required identity conflicts.

## Mandatory tests and governance

- `php -d zend.assertions=1 -d assert.exception=1 tests/catalog-intent-policy-contract.php` — read permission, URL safety and tracking vs filter preservation, stable outputs, aliases, relationship binding, unknown/weak context, existing-inventory review and 13-card sample.
- CI via `.github/workflows/mad4b-firecrawl-woo-lab-bridge.yml`, including plugin syntax.
- The reviewed canonical Skill `ops/integrations/firecrawl/skills/workflow/research-intelligence/research-provider-routing/SKILL.md` instructs agent consumers to call this Ability **before** per-item Firecrawl collection. Registered runtime Skills must be updated through the existing Skill Registry after approved source promotion.
- No change to public Docker, credentials, service networks, scheduled tasks or WooCommerce pricing. Woo Lab historical files are not a deployment target; **Tours Commerce** staging is the target.
