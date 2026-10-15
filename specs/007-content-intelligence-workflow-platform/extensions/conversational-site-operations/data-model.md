# CSO01 — Domain entities and exact identity

| Entity | Identity and essential data | Owner |
|---|---|---|
| SiteScope | site UUID, origin, environment, deploy binding, package/source SHA, locale | existing Site Profile |
| ActorScope | issuer, subject, OAuth client, resource audience, grants, expiry | existing MAD4B OAuth/NHI |
| AdapterDescriptor | provider/plugin/version, storage kind, validators, hooks, capability, fingerprint, rollback/readback traits | certified registry |
| StorageSelector | site, CPT/post/term/user/option/vendor object, locale, path, expected revision; cannot be arbitrary SQL | certified adapter |
| FormDescriptor | form id, exact site/actor/source/adapter/schema hashes, fields, dependencies, sensitivity, expiry | schema compiler |
| FormSession | scoped draft, allowed field IDs, safe values only, TTL, explicit user confirmation, resume cursor | redacted draft store |
| SecretHandoff | opaque nonce, first-party origin, provider/field, one-time-use expiry, status-only receipt | secret ingress |
| MutationPlan | signed/hashed exact target, revision, before/after *redacted* diff, side effects, risk, budget, rollback feasibility | plan service |
| ApprovalTicket | exact mutation hash + authorizer + TTL + role separation | existing authority |
| OperationJournal | operation/step/generation/idempotency, state, locks and verified side effects | Mutation Manager |
| VerificationReceipt | operation, independent source, exact postcondition revision, result and signed evidence ref | verification plane |
| CompensationPlan | supported reverse action, required owner, new revision, irreversible effects | governed workflow |
| WorkflowDefinition | versioned DAG, certified abilities, triggers, waits, conditional dependencies and budgets | inert until authorized |
| MultiSiteBatch | parent proposal plus independent child site plans and per-site receipts | coordinator without shared grants |
| TemplateVersion | signed reusable form/workflow recipe and compatibility migrations; NO secrets/grants | template registry |
| MonitoringSubscription | condition, cadence, tenant/site scope, dedup key, expiration and privacy retention | bounded monitoring |
| PromotionCandidate | exact signed artifact and independent Staging tests; separate Production approval | release plane |
| AuditEvent | event/actor/operation refs, redacted transitions and retention policy | privacy-aware append-only log |

## Non-negotiable rules

An entity ID or post ID alone is never globally unique; bind site UUID+type+locale+revision. Hidden/private fields cannot be listed or hinted to a caller without read permission. Empty/read-only descriptors do not imply execution. A key's configured boolean is permitted; its plaintext or low-entropy digest is not. Approval expires separately from a draft. The term VERIFIED means independently observed exact postcondition, not just an HTTP response. Unknown provider state halts write admission. A compensated workflow has independently re-verified compensating postconditions.
