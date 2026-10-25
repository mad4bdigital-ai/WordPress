# MAD4B — five original-owner AI review receipt lanes

## Scope and authoritative boundaries
Implemented per-owner content-quality review methods in the original classes for Content Jobs, Product Catalog, SEO Metadata, Localization and Design Templates. This is **quality review evidence only**, not an authorization to publish, modify prices or stock, link WPML translations, write Rank Math metadata, approve Elementor/browser runtime, issue developer grants, or deploy anything.

The shared review adapter is source-file SHA-256-pinned and operator-enrolled only in **Local Lab**. It must use the **exact real owner class**. The adapter's own assertion can never satisfy completion: the executor independently invokes `ai_original_review_verify_receipt` on the owner, rechecks current source hash/revision, and reads the original append-only MAD4B audit chain by an exact event ID. Missing events, wrong source/revision, altered chain, forged receipt and changed draft state all fail closed.

### Five canonical source contracts
| Domain | Native source | QA scope |
| --- | --- | --- |
| content_job | MAD4B Content Jobs `get_job` plus original WAITING_REVIEW and job_revision | Editorial draft QA; native ContentJob mutation authority required for test fixture |
| product_catalog | WooCommerce draft `product` | Descriptive catalog QA; no price or inventory operation |
| seo_metadata | WordPress draft and Rank Math source metadata | Source/meta editorial QA; provider-native SEO apply remains separate |
| localization | WPML/Polylang Translation Bridge `translation_get_post` | Language and translation text QA; no translation linking |
| design_template | Elementor draft `elementor_library` and `_elementor_data` | Template content QA; not browser acceptance or release |

### Mandatory authenticated route and exact grants
The newly restricted `POST /wp-json/mad4b-local-ai/v1/domain-review` reuses the existing short-lived, bound original WordPress Application Password Agent. It is not an admin role and never trusts a model-generated bearer or caller-supplied endpoint. Beyond `mad4b/context-ai-review`, **each domain additionally requires a distinct explicitly owner-approved Local Lab exact ability**:

- `mad4b/ai-content-job-review`
- `mad4b/ai-product-catalog-review`
- `mad4b/ai-seo-metadata-review`
- `mad4b/ai-localization-review`
- `mad4b/ai-design-template-review`

These WordPress Abilities are **non-executable and not MCP mounted**. They are only entitlement markers for the strictly scoped Agent REST receipt operation; they are never general grants to a provider. Original MAD4B grant verification rejects unknown abilities, wildcard grants, non-local grants or any Production operation. No additional grant was created by installing the code.

### Local Lab readback evidence — 25 Oct 2026
The five code-pinned adapters registered successfully; WordPress boots and REST root returns HTTP 200. Real unpublished test drafts, grounded in the free public Egypt reference from `https://countries.dev/alpha/EG`, were created and kept under a distinctly labeled QA fixture option. The original sources read successfully through WooCommerce, Rank Math/WP draft, WPML Translation Bridge and Elementor **4/5**. The fifth source, ContentJob, has no reviewable WAITING_REVIEW item: original creation returned `mad4b_content_job_mutation_not_authorized`. Its separate governance authority was preserved. Do not create a shadow ContentJob row.

The native scope and mock-audit regression passed **40/40**, including five source hash/revision checks, five independent chain receipt reads, five invalid-chain refusals, five stale-source denials, explicit refusal of direct CLI issuance, and five WordPress Ability registration gates. The original adapter reducer passed **24/24**, including independent owner readback refusal when adapter returns true without a trusted owner receipt. CI also includes a Python cross-file security wiring contract.

**Operational status: five original receipt lanes implemented and SHA-pinned, four original source fixtures read, zero original approval receipts issued.** No user-facing or background Agent grant was switched on, the common AI model's automatic review remains disabled after prior model tests, and Staging/Production are untouched. The remaining two requirements for five actual receipts are (1) original permitted ContentJob creation/transition into WAITING_REVIEW, and (2) enrolled-owner opt-in to the five exact content-QA Agent grants with live authenticated REST acceptance. Those cannot be silently manufactured via a root shell or an untrusted model verdict.

### QA vs. authority semantics
A successful QA receipt must contain the original content hash and revision, original owner ID, independent audit event hash and verified chain, and authentic Agent identity. It explicitly states `publication_authorized=false`. It does not upgrade browser observations into release receipts or create new rights to modify underlying provider-owned content.
