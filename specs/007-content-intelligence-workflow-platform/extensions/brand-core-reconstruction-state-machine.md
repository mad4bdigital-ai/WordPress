# BR01 — Governed Brand Context Reconstruction State Machine

## Goal and contract

A missing Brand Core asset must never strand content authoring, but it must not become an implied approval. The new context/brand-reconstruction-plan is read-only and site-bound; scenario simulations are never authorities. Every write uses the existing operation-specific authorization, fresh binding and independent readback.

## Dependency DAG

- brand_strategy: owner-authored/owner-confirmed authoritative document only. Never generate approved strategy from competitor websites or WordPress operations notes.
- tone_of_voice: an unapproved draft may be generated only after owner-approved brand_strategy, independent evidence and writer review.
- editorial_guidelines: an unapproved draft may be generated only after approved brand_strategy and tone_of_voice plus editorial and SEO evidence.
- Third-party image licenses, resale/distribution, live prices and availability require separate evidence and legal/commercial authorization.

## Scenario matrix

| Scenario | State | Recovery or escalation |
|---|---|---|
| Approved exact asset | READY | Verify exact reviewed content and coverage |
| Strategy missing or file removed | OWNER_AUTHORITY_REQUIRED | Restore/supply authoritative owner asset; review exact evidence |
| Upstream required context absent | WAIT_DEPENDENCY | Reconstruct prerequisite in DAG order |
| Provider unavailable / timeout | SOURCE_RECOVERY | Reconnect and re-scan before recreate |
| Version stale / context receipt drift | RESCAN_REQUIRED | Re-scan, reconcile fingerprints and obtain fresh approval |
| Garbled or partial file | NORMALIZATION_REQUIRED | Repair parsing, re-scan complete source |
| Conflicting strategies | HUMAN_ARBITRATION | Independent human policy resolution |
| Weak evidence | EVIDENCE_COLLECTION | Gather first-party evidence and quality samples |
| Provider write unavailable | DESTINATION_RECOVERY | Restore governed managed destination |
| Assistant unverified | HUMAN_DRAFT_PREPARATION | Human writer prepares unapproved draft |
| Certified assistant and quality pass | DRAFT_PREPARATION | Writer prepares bounded unapproved draft |
| Existing unverified materialization | MATERIALIZATION_RECONCILE | Compare current provider state and prior idempotency before retry |
| Reviewer rejected | HUMAN_REVIEW | Resolve comments and re-plan |
| Supplier/media rights unknown | RIGHTS_REVIEW | Independently document rights, channels, expiry and prices |
| Repeated failure (3 attempts) | CIRCUIT_OPEN | Human reset with new evidence; stop recursive automation |

## Assistant orchestration

Researcher discovers primary, first-party, approved sources and dissent; Writer drafts governed unapproved Tone of Voice / Editorial Guidelines; independent Critic verifies content quality, provenance, originality, factual claims and SEO evidence; Reviewer uses an independently granted human or delegated AI review ability. Writer/researcher never self-approve. An assistant is usable only after live Managed Skills Runtime Certification; a caller-provided availability flag never elevates an uncertified assistant. Missing assistant falls back to human draft and review.

## Transition protocol

1. Bind source and category to site UUID, registry revision, authority fingerprint, evidence digest, operation ID and retry stage; plans are not approval tickets.
2. Prefer an existing approved document, followed by provider restore or rescan; only then produce an unapproved new draft. A browser page or public supplier catalog never creates commercial rights.
3. Reconcile duplicate/in-flight drafts by idempotency key. For an authorized new draft, separately approve context/brand-draft-create, then approve context/materialize-brand-draft.
4. On uncertain provider write, execute governed context/reconcile-brand-materialization first; do not blindly create again.
5. Evaluate independent quality, human/authorized AI review, source-scan readback and context/brand-core-coverage. Fresh reviewed content must match exact state.
6. Repeat the Context Receipt + exact mutation ticket acquisition before the publication process. Source/authority drift invalidates previous plans.
7. Hard retry ceiling of 3 per stage, fail closed and require human recovery on further failure.

## Evidence and security

No auto-approval of Brand Strategy, content authenticity, supplier contracts or third-party media. No Production, Breakglass, grant expansion or direct publication. Public URLs do not imply license or authorisation. All scenario-mode outputs are hypothetical and read-only.

All Royal Egypt Staging evidence (2026-10-09): a governed managed Drive destination and a human_and_ai review lane are present; two unreviewed WordPress operational documents were heuristically treated as Brand Strategy, not legitimately owner-approved strategy. The Memphis Tours listing is a reference, not an authorized retail offer. The machine preserves these distinctions.