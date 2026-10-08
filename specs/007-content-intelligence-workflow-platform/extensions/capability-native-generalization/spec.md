# Capability-native generalization — Feature 007
Status: implemented source slice; NOT a live functional certification.

## Problem statement
Building one browser Driver per named WordPress website (ETG, All Royal Egypt, or any future brand) creates a never-ending special-case matrix. Distinguish four independently checkable concepts:
1. **Observed source**: plugin/theme/core/registered CPT/API/ability; what exists, not what works.
2. **Functional capability**: vendor-independent behavior identifier (e.g. browser.dom_result_count, SEO canonical, content listing, translation parity).
3. **Evidence oracle**: a trusted WordPress-native expected outcome + exact build/origin/profile/version binding. Plugin availability never creates an oracle.
4. **Execution primitive**: reviewed test action (read-only GET/DOM observation, specific API test, sandbox transaction) bound to scope/effect policy. Only an independently verified reducer can certify.

## Implementation principles
- Native WordPress `mad4b/capability-atlas` combines the existing `mad4b/provider-functional-coverage` plugin-family inventory and all registered Browser Acceptance provider capability declarations. It is a separate authorized-read-only ability; no new transport, installs, code loading or writes.
- Multiple Providers advertising a capability are normal, not an error. Atlas lists each as unverified, independent candidates. Nothing in the atlas selects an executor.
- `family.<functional_family_key>` is a *plugin-family inventory node*, **not** proof that any business feature works.
- `tools/browser-acceptance/declarative-capability-driver.mjs` is one reusable, review-owned external adapter for **bounded read-only probe vocabulary**. It does not import scripts or selectors from sites. Separate specialized adapters remain possible for inherently specialized semantics.
- Generic driver requires the WordPress Provider to produce a strictly bounded plan with `mad4b.capability-browser-provider.v1` and an independent signed semantic reducer for `mad4b.capability-browser-evidence.v1`. Registry entry alone never creates a Provider, oracle, cryptographic verification or PASS.

## Proof vocabulary implemented
- `public.document_title_digest`: compare observed SHA256 of document title with server oracle.
- `public.canonical_path`: compare observed same-origin canonical pathname to server oracle.
- `public.semantic_marker_count`: compare bounded DOM count of standardized `data-mad4b-capability-key` attributes to server oracle.
Cases may navigate only exactly declared HTTPS origin and public paths, no admin, REST, dynamic query, arbitrary XPath/CSS/JS, scripts from providers, click, submission, payment or login. No redirect target changes.

## Acceptance boundaries
Every match is `declared_not_certified` or `inventory_only`, `execution_allowed=false`. Generic browser observations return `matches_expected` only; `certification_issued=false` always. Signed plan validation by the external runner is **shape/freshness only**, NOT a server signature verification. Trust and final result must come from the independently implemented WordPress Provider result reducer with exact build/challenge/evidence binding. Current ETG driver keeps its specialist tests; no silent fallback to it for other sites.

## Expansion to any capability within MAD4B
The same decomposition applies to plugin lifecycle, SEO, languages, media, CRM, booking, automation and operations:
  Source observation → family / capability claim → policy-aware provider candidate → oracle/effect classification → reviewed primitive → reducer evidence → limited certificate.
Mutation-capable capabilities require separate audited transactional/sandbox execution (including reversibility and approval); never adapt a read-only Browser Driver to create authority.

## Site independence
No specific hostname, site name, ETG query ID or All Royal driver should occur in generic atlas or generic driver logic. The target WordPress site supplies its *native semantic oracle*, not executable browser code. Migration is additive: ETG's existing signed provider and specialized Driver remain supported while generalized probe contracts become reusable across any provider.

## Unresolved release dependencies
No native WordPress Provider currently advertises the generic contract and implements the required signed oracle/reducer. There is no live Staging screenshot/parity certificate. Plugin-version-only fingerprints are not full asset hashes; DNS-rebinding protection needs external provider IP egress control. CI may be queued. This slice must not cause Production mutation or merge #258 to master.
