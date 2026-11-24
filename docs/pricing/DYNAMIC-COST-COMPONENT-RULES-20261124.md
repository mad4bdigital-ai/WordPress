# Dynamic cost component relations and policy rules

Additive extension of the existing Cost Source Registry and Product Cost Review Ledger.
Original native providers, approval gates, scheduler, rate generation and payment remain unchanged.

## Configuration

A mapping can now resolve native JetEngine relation ID (parent to children or child to parents), validating both post-type endpoints and edit access. Scalar/array native meta links and assigned taxonomy terms remain supported. Optional repeater field and stable row key resolve each source row independently; invalid duplicate row identities are blocked. Currency/basis can be read from row fields. The mapping key distinguishes relation, repeater and component group, including multiple mappings with the same amount field.

Component group, role (required/optional/conditional), selection (all required/cheapest eligible/priority/manual), inclusion (included/extra/upgrade), quantity source/fixed quantity, priority and weight are saved in the same tenant-local registry. The existing Dynamic Pricing Controls page renders the same configuration form and mapping table, not a parallel policy store. Cost Review Ledger displays the preview.

Reference comparison ranks comparable positive costs only: cheapest first or priority first, higher weight for ties, stable identity tie break. Ordered alternative references are NOT verified commercial fallbacks. Missing/incomparable/conflicting or globally truncated candidates prevent trustworthy ranking. Quantity configuration, date/availability eligibility, native receipts, FX, tax and profit remain future composition dependencies. No generic mapping qualifies a supplier receipt or emits a sale amount. Native providers retain full authority. No Query Builder, options-page resolver, or term-endpoint JetEngine relation support in this increment.

## Verification

22/22 new hermetic assertions, 23/23 prior registry tests, 7/7 history assertions. PHP lint. Real WP-CLI runtime checks against native relation 223 on Property 78032: three actual linked Units on each tenant, six test-only in-memory repeater rows. Native Controls panel and ledger renderer checked; no real mappings or cost fields written. Relation 359 registered, no linked children observed for sampled published Property Cost Rates; do not create or assume missing native links. Both live sites deployed matching SHA256 files and retained USD740 hotel reference for Package 60627, native authoring enabled and commercial approval false. No business records mutated. Authenticated browser and persisted operator save/readback still outstanding.
