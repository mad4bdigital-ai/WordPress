# P0 Integration Remediation — ACI01, exact-source audit

**Contract scope:** first three read-only abilities, not authorization, publication or Production release.
**Code state:** committed on child branch; exact-head PHP execution and Staging native acceptance are **pending**, not silently waived because CI is broken.

## Verified source responsibilities

1. `MAD4B_SCP_ACI01_Runtime_Binding::current()` delegates authority to existing Site Profile and Adaptive Operations Context; denies configured-but-quarantined, origin/environment/deployment drift, missing current site URL enrollment, invalid generation, stale package manifest or absent restore epoch. Returns an exact typed read receipt with origin SHA, profile digest, runtime generation, artifact digest, external record digest, restore epoch.
2. Intake and evidence capture a read binding before their native providers, verify the same binding after the read, and propagate the binding through their preview scopes. The opportunity composer re-checks the *current* binding after both previews and rejects any cross-epoch/cross-generation/cross-site/cross-URL/brand/locale/market mismatch.
3. `MAD4B_SCP_ACI01_Semantic_Recipe::resolve()` requires ContentJob `content_type` to map to the *exact* enabled Content Experience Profile `slug` and WordPress `post_type` (current authority SHA, catalog match and revision). No guessing from `native:<post_type>` or a matching display name. Missing/ambiguous/stale/disabled mapping is `DENIED`.
4. Semantic obligations now require approved domain recipe, brand context, rights, factual/editorial/SEO QA and native language identity. Native post/term relation proof is additive when the live native profile has taxonomies or WPML is detected; presence/absence of taxonomies is **not** a certificate of translation equivalence. No content or term IDs are inferred, no SEO checks are faked.
5. Local WordPress environment is recognized; native inventory bounds align to 96 to avoid 80-vs-96 detector inconsistency. All outcomes remain `NEEDS_EVIDENCE`, `NEEDS_REVIEW` or `DENIED` as appropriate; none grants a write.

## Security checks for the PHP acceptance matrix

- Valid local profile with matching origin, revision, package, runtime and epoch may produce a **read-only** binding.
- Configured profile with `authority_ready=false`, cloned origin, invalid/changed host URLs, missing generation reader, stale package proof, unexpected restore epoch, changed profile/manifest digests must deny or invalidate candidate.
- Configured `tour-guide` ContentJob mapped to `excursion` via reviewed enabled profile may produce a candidate; `post`, unconfigured, disabled, stale or ambiguous profiles must deny.
- WPML with zero taxonomies must still demand post-language identity proof; taxonomies without WPML do not constitute translated term identity.
- Stale sources, uncertain attribution, unsupported commercial facts and unapproved editor state must never promote from `NEEDS_EVIDENCE` to ready.

**Non-evidence:** Source-level checks, workflow YAML or the presence of a PHP fixture do not count as executed PHP tests. Genuine P0 closure further needs local exact-blob PHP 7.4/8.3 tests, current Staging Ability readback, a controlled WPML/non-WPML fixture, read budget measurements and owner confirmation of ContentExperience profiles.

## P1 follow-on and native guardrails

Real Content Recipe field/commerce requirements, multilingual ID parity and schema authority must be certified through existing governed provider adapters before applying native writes. Do not auto-install plugins, launch paid providers, insert artifacts, perform host mutation, turn candidate previews into grants or merge #258 into master from this delivery.
