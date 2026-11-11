# Company Reconcile Depth V3

Date: 2026-11-11. Source: tours.dev.mad4b.com. Target runtime evidence only: Wayfatours Production.

## Findings

The source policy originally contained 34 required stage checks and 18 incident scenarios. Another concurrent source update added the 19th scenario native_scoped_write_capability_freshness, which this implementation preserved. The current policy has 34 checks and 19 scenarios (7 P0, 11 P1, 1 P2). Only 16/34 checks have a dedicated incident playbook; 18 lack a named incident-specific case, but still remain required.

Added 34 source-policy dependency rows and 57 prerequisite edges. Acyclic topology yields 11 inspection waves. This orders diagnostics but DOES NOT grant executor authority, company lifecycle readiness, billing or publication. Unknown prerequisites, incomplete coverage, cycles, unsafe raw scenario autoapply and changed provider-ownership scope fail closed.

Incident evidence lanes: 12 target-local probes, 3 needed target probe definitions (neutral Tour Rate defaults, cruise program taxonomy, season/rate eligibility), 3 Original-provider proofs (including signed 312 capability freshness and company code release) and one company-owned pre/post reconciliation digest.

## Wayfatours exact-site local triage

Expected Site UUID a3a7cc36-db7e-4a14-8456-b41857def05c, canonical origin https://wayfatours.com, Production. Local probe contains 6 PASS_LOCAL, 2 ADVISORY and 2 UNVERIFIED among ten checks; no native Original 313 receipt. The 19 incident projections resolve to 5 LOCAL_PASS_NOT_CERTIFIED, 2 LOCAL_ADVISORY_REVIEW, 5 TARGET_PROBE_UNVERIFIED, 3 TARGET_PROBE_DESIGN_REQUIRED, 3 ORIGINAL_PROVIDER_RECEIPT_REQUIRED and 1 COMPANY_PRE_POST_DIGEST_REQUIRED. Zero local drift in that observed subset. A local PASS can never close an Original 313 gate.

Host-only protected probe and triage JSON are stored outside Git in the company-reconcile-depth-v3-20261111 report directory. They are only fresh within 1800 seconds of collection. Signed receipts and browser acceptance remain absent, so this is triage, not release certification.

## Implementation and tests

The Work Plan now includes the source dependency map, incident evidence lane coverage, risk register and a private operator UI with an expandable list of diagnostic waves. Source plan hashing now includes source dependencies and evidence lane definitions. No runtime target is probed when the admin screen loads; the deeper assessment runs only on request.

35/35 standalone PHP assertions passed, including policy cycles, wrong target UUID/environment, stale probes, forged signed authority, unsafe source policy autoapply and no mutations. The existing central WP-CLI regression remains 21/21. Central REST assessment returned admin HTTP 200 and anonymous HTTP 403 with private no-store. Admin JavaScript passed node --check. New company remains private-bootstrap-pending; Wayfatours remains existing-company-reconcile.

## Release governance and rollback

No company site, Woo settings, rate, order, payment gateway, user, owner or Full Clone was modified. Other companies were neither written nor enrolled. Original 318/313 stays HOLD. The company-manager main plugin file was not replaced due to concurrent source changes. Exact code/policy preimages are under the private report preimage folder; a separate backup retains the admin UI preimage. Restore the Work Plan before removing the new Depth Audit module. No Wayfatours rollback is needed.
