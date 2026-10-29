# MAD4B Control Plane: Firecrawl research & competitor collection lanes

## First deployment
Firecrawl is a **shared internal research provider**. Woo Lab Control Plane is its first admitted consumer. The rest of MAD4B sites do not inherit this connection. The exact protected API remains behind `gateway:8080`, as configured by the separate Firecrawl bridge.

### Existing MAD4B modules (do not replace)
| Stage | Existing MAD4B ability/module | Firecrawl role | Authority |
| --- | --- | --- | --- |
| Discover keywords/SERP | `mad4b/research-serp-record`, Search Intelligence providers | None: Firecrawl page scraping **does not establish Google ranking** | Certified SERP provider + approved job |
| Competitor selection | `mad4b/research-competitor-select`, Market Growth Policies competitor registry | A source to fetch after enrollment | Registered source/authority + job |
| Page observation | **new** `mad4b/research-firecrawl-cycle-observe` | Bounded public HTTPS page preview, source digest and tentative findings | WP admin; Control Plane read permission; competitor profile; bridge allowlist |
| Research retention | `mad4b/research-scrape-record` | Input evidence only. **Not auto-invoked** | Separate job, provider execution binding, data-governance, legal and robots rights, write approval |
| Content gap | `mad4b/research-coverage-build`, Content Intelligence Pipeline | Observed outlines/questions | Approved research artifact and editorial governance |
| SEO plan | `mad4b/research-information-gain-build`, Search Intelligence | Heading/FAQ candidates; meta title/description/rank **not verified** from Markdown preview | SEO/SERP review |
| Market & price | `mad4b/competitor-research-plan`, `mad4b/market-growth-evaluate` | **Price mentions only**; not binding quotes or normalized sale prices | Independent currency/date/party/tax/availability validation, commercial approval |
| Draft/review | `mad4b/blueprint-build`, `mad4b/blueprint-qa`, `mad4b/content-orchestration-plan` | Research context only, never copy competitor prose | Human-approved original text and publish authority |

## New private abilities
- `mad4b/research-firecrawl-cycle-plan`: no network, lists active competitor IDs and hostname-only source enrollment, plus existing stage/Ability map. It does **not** certify robots/rights or the Firecrawl allowlist.
- `mad4b/research-firecrawl-cycle-observe`: one page per call. Input `url`, `competitor_id` and optional `lane=all|content|seo|pricing`. A registered active competitor with an identical HTTPS hostname is mandatory. Technical readiness in the planning output requires the source host to appear in the bridge's independent allowlist; rights review remains mandatory. The shared Firecrawl bridge enforces the independently configured exact domain allowlist and credentials.
- For acceptance only: `{"url":"https://example.com","demo":true}` uses the bridge's existing default example.com permit; the demo is **not a competitor observation** and never creates an approved competitor account.
- Returned observations include source URL, digest of the bounded Markdown excerpt, collection time, headings/questions, approximate word count and unverified literal price mentions. Firecrawl-supplied title, meta description and language are bounded **unverified candidates**; HTML canonical and search ranking remain **not verified**. The excerpt is capped at 6000 characters by the existing bridge. Nothing is saved to WordPress or Artifact Registry.

## Governance
- Active competitor profiles must first be configured under MAD4B Market Growth Policies by an authorized owner. At review time Woo Lab had zero competitors and zero pricing rules configured; no third-party competitor collection can run until enrollment.
- The exact competitor hostname must also be independently added to the bridge's `MAD4B_FIRECRAWL_ALLOWED_HOSTS` in the consuming site's configuration. Never substitute arbitrary user-provided URLs for that allowlist. Only public HTTPS pages, no credentials, no private IPs or custom ports.
- Respect robots.txt, publisher restrictions and third-party content rights. A successful page preview does **not** constitute rights clearance. Human-approved source and rights evidence are mandatory before a retained research artifact is created.
- Extracted prices are clues, not validated price intelligence. Check currency, market, geography, duration, date, inclusions, taxes and stock before any price recommendation. No WooCommerce product pricing, Meta, SEO metadata or posts are changed by these new abilities.
- No recurring jobs/crawlers, LLM inference, email sending, media copying, SERP scraping or automatic publication. Add scheduling only through the existing governed queue once source consent, budget, frequency caps and data retention have been reviewed.
- Data isolation: each MAD4B site retains its own competitor registry; no cross-site extraction or credential reuse. A centralized research gateway can later aggregate *approved* normalized observations by tenant only after consent/authority review.

## Woo Lab testing
`php -d zend.assertions=1 -d assert.exception=1 tests/firecrawl-research-workflow-test.php` exercises permission, source enrollment, exact host, demo-only gate, price candidate non-authority and lack of writes. Confirm live WordPress abilities and demo preview after deploy; leave unrelated companies and production sites unchanged.
