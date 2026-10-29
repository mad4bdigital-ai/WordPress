# Shared Research Providers: Firecrawl + SerpAPI, Woo Lab first consumer

## Credential form and custody

Woo Lab WordPress admin: **MAD4B Control Plane → Research Providers**, route `admin.php?page=mad4b-firecrawl-provider`. New Firecrawl form includes **read-only Consumer Username**, **Password (replace only)**, **Verify and save credentials**, and **Test saved connection**. It never displays an existing password. Saving calls the fixed private Firecrawl gateway with a bounded `https://example.com` scrape to verify the entered credentials BEFORE replacing the vault file. Failed verification does not modify the saved credential. New credential file is atomically replaced with mode 0600 in the existing private vault (outside wp-admin public webroot), not written to WordPress options or versioned source. Password rotation on the Firecrawl account itself remains a separate server-side identity task; this form only saves an account credential already accepted by the gateway.

The existing SerpAPI form remains in **MAD4B Search Intelligence → Search Providers**, route `admin.php?page=mad4b-search-intelligence&section=providers`. It uses its own encrypted, revision-bound storage, account tests, provider certification and budget authority. **Never copy a SerpAPI API key into Firecrawl**, or treat Firecrawl's private Basic Auth password as a public Firecrawl Cloud API key.

## Routing without false substitutability

Private Ability `mad4b/research-provider-route-plan`, with `intent` enum:

| Intent | Preferred provider | Execution condition |
| --- | --- | --- |
| page_content | firecrawl | Woo Lab's private gateway and credentials healthy; separately approved source domain |
| page_seo | firecrawl | source rights and on-page observation; no Google ranking inference |
| competitor_pricing | firecrawl | literal price clues only; external price/date/tax and market validation |
| google_serp | serpapi | existing Search provider is verified AND certified for this exact purpose |
| keyword_discovery | serpapi | existing SERP/search authority; Firecrawl Search may be added only after independent contract and quality verification |

Routing is **a decision/diagnostic Ability**, not an automatic fallback executor and never silently substitutes rankings from Firecrawl. It creates no scheduled job or data mutation. Real Search capture must still pass the existing Search Worker, provider certification, account budget, scope and circuit breaker. The Firecrawl observation Ability remains under the separate Research Intelligence rights/robots/allowlist guards. At first-consumer acceptance, Woo Lab had SerpAPI **NOT_CONFIGURED**, so exact Google SERP execution is correctly **not ready**.

## Skills paths audited and published

Native MAD4B Skill registry is **`wp-content/mad4b-skills`**, with file-backed `<level>/<target>/<slug>/SKILL.md` and SHA-bound `.mad4b.json`. Two new skills are backed by reviewed GitHub files and authored using the official Skill Registry API (with admin rights, audit and atomic writes):

| Skill | GitHub source | Native runtime | Portable export |
| --- | --- | --- | --- |
| firecrawl-competitive-research | `ops/integrations/firecrawl/skills/provider/firecrawl/firecrawl-competitive-research/SKILL.md` | `wp-content/mad4b-skills/provider/firecrawl/firecrawl-competitive-research/SKILL.md` | `skills/firecrawl-competitive-research/SKILL.md` |
| research-provider-routing | `ops/integrations/firecrawl/skills/workflow/research-intelligence/research-provider-routing/SKILL.md` | `wp-content/mad4b-skills/workflow/research-intelligence/research-provider-routing/SKILL.md` | `skills/research-provider-routing/SKILL.md` |

`skill-seeds/<name>/SKILL.md` in the Control Plane plugin is a *separate canonical seed source*; do not mistake it for active runtime Skills. The source-path CI check is `python3 scripts/ci/verify-firecrawl-skill-paths.py`. Audit on Woo Lab before adding these two Skills found **32/32 existing skill documents available, enabled, and with matching SHA metadata**; after add, 34 enabled Skills. The runtime `MAD4B_OPENAI_PLUGIN_APP_ID` was **not configured** during the audit; portable export availability was not certified. Review app binding before claiming that the new Skills are discoverable in a published ChatGPT portable plugin.

## Rollback and boundaries

Only Woo Lab receives the selected branch runtime changes. To roll back this step, remove `wp-content/mu-plugins/mad4b-research-provider-connection.php`, restore the previously backed-up `mad4b-research-firecrawl.php.before-provider-routing-20261029` and remove the two Skills using their governed registry controls if needed. Leave the original shared Firecrawl service, existing validated Woo Lab credential, Search provider adapters and other companies untouched. No Production site migration or business pricing change is authorized by adding these read-only connection/routing facilities.
