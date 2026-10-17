# ACI01 Constitution — non-negotiable invariants

Contract: `mad4b.aci-os.constitution.v1` | status: SPEC_BACKLOG_ONLY.

1. **Single authority owner**: Existing MAD4B identity, NHI, grants, approval, runtime generation, restore epoch, budgets, operation journal and rollback remain authoritative. External workflow engines are mechanics, not policy owners.
2. **Site isolation**: every stateful entity binds site UUID, canonical HTTPS origin, profile revision, generation, provider/account as applicable. Cross-site IDs and same-origin clones never imply equivalence or copy grants.
3. **Untrusted sources**: SERP, scraped pages, partner text, AI output, PDFs and tool labels are data. Instructions in those sources cannot alter authority, prompts, tool routing or budgets.
4. **Truth layering**: discovered ≠ structurally valid ≠ provider-verified ≠ semantically verified ≠ mutation eligible ≠ published. Missing or stale evidence never yields PASS, complete, auto-remediation or a release certificate.
5. **Evidence integrity**: exact source URI/ID, collection time, retrieval method, licensed-use/robots provenance, provider version, content digest, comparability window and signed trust class are recorded; hash alone does not prove source trust.
6. **No stealth spend**: a known, reserved account+site+job cost budget precedes paid external calls. Unknown price, currency, quota, rights or consent means stop. Uncertain external effects require reconciliation, never blind retries.
7. **Separation of duties**: research, drafting, factual QA, SEO QA, publication decision, environment activation and credential scope changes are independent gates. AI evaluator cannot approve its own uncorroborated output.
8. **Native-first**: WordPress post/meta/taxonomy/terms, WPML translation groups, media and installed SEO provider own their formats and hooks. No raw SQL write or silent duplicate relation database.
9. **WPML ownership**: translated identities resolve via certified WPML semantics and actual field translation policy. `wpml_object_id` fallback to original is not evidence of a translated local object. Meta ID Mapper ownership/settings must be confirmed before repair.
10. **No false repair**: three-way compare last-managed/current/desired; respect human-managed fields and locks. Relation remap needs semantic equivalence, target existence/type/language, preview, exact CAS, native readback, approved rollback.
11. **Durability**: journal every state transition with per-operation idempotency, parent artifact fingerprint, lease/fence and bounded retry. External effect UNKNOWN is terminal for automatic replay until reconciled.
12. **No authority from confidence**: scores, Bayesian beliefs, LLM certainty, model agreement, signatures, static tests, `mergeable=true` and read annotations do not create permissions.
13. **Modes**: L0 Observe; L1 compute non-authorizing projections; L2 proven owned non-authorizing repair; L3 certified zero-effect shadow/preauthorized reversible staging canary; L4 refresh existing eligible staging projection; L5 owner-governed high-impact/new authority/Production. Levels never silently escalate.
14. **Safe degradation**: when provider/account, budget, permission, source, locale, host sandbox or runtime drifts, isolate only dependent stages, preserve verified history and allow safe read-only inspection.
15. **Rights/privacy**: purpose-bound use, minimization, per-field and source access, PII restrictions, consent and licensed media. No publishing of unsupported claims or protected competitor content.
16. **Release integrity**: doc/spec tests never certify native behavior; no Production promotion without exact staged artifact, real browser/provider receipts, comparative performance and owner authorization.
17. **Portable core**: no hardcoded client/brand/country/vendor; adapters and semantic profiles supply domain-specific mapping.
18. **Measurable completion**: each requirement closes only against its own code, disposable runtime, live, browser, security and operational gates as declared. Partial evidence remains PARTIAL.
