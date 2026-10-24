---
name: wordpress-interface-release-qa
description: End-to-end UI/code/config acceptance, cleanup and governed handoff for WordPress component changes.
---

1. Freeze intended scope, Lab/Staging/Production identities, source HEAD and versioned evidence. Never assume browser success implies production authorization.
2. Run PHP lint and static scans for unauthorized brand-specific names, hardcoded post/term/query IDs, unsafe REST mutations, obsolete hooks and temporary artifacts.
3. Check Elementor templates in both Cards and Accordion forms, including nested items and active-kit typography, all responsive breakpoints and keyboard/touch behavior.
4. Verify WordPress SSR, JetSmartFilters AJAX and referrer/URL parity, signed context and no-results handling. Observe actual interactions, not only click() injected from JavaScript.
5. Validate pricing and cart semantics without enabling purchasing or payments. Inspect errors/warnings and distinguish fixture errors from product defects.
6. Remove temporary acceptance endpoints, cookie files, synthetic content and ad-hoc MU plugins after verification. Preserve existing legitimate acceptance guards.
7. Read back draft/published status, WP environment guard, product purchasability and available gateways. Require zero unintended writes.
8. Produce a handoff with file changes, exact template/field identities, test matrix, known gaps, rollback and GO/NO-GO; release to Production only under its own authorization.
