# Native Relation and WPML Semantics Contract
Contract: `mad4b.aci-os.native-relation-wpml.v1`. Provider-native Post Meta and taxonomies are authoritative; no SQL rewrite through this kit.

## Field registry

One versioned SemanticFieldDefinition per site, source post type, field key and owner provider:
- `field_kind`: post_reference | term_reference | value | provider_special;
- `target_kind`: post | term; `target_post_type` or `target_taxonomy`; `id_scheme`: post_id | term_id | term_taxonomy_id;
- `cardinality`: one | many; `serialization`: scalar | array | serialized_php | JSON | provider_owned;
- `wpml_field_mode`: copy | copy_once | translate | ignore | unknown;
- `locale_policy`: same_locale | shared | inherit_review | manual;
- `owner_provider`, `ownership_mode`, `value_cas_supported`, `rollback_contract`, `version`, `approved_by`.
Unknown field policy ⇒ inspect-only, never repair.

## Read-only mapper discovery

1. Query the installed provider inventory and actual version/package signature, then WPML status.
2. Obtain field registration owner, exact per-field WPML preference, and source/target locale groups.
3. Inspect WPML Meta ID Mapper UI source/hook/configuration if permitted; classify implementation as hook-driven, manual, dual or unknown; never infer from an installed plugin label.
4. Read `related_tour_id`, `related_package_term_id`, `related_properties_id` and any registry-declared fields on a bounded translated family.
5. For each target ID, resolve `site_uuid+entity_kind+type/taxonomy+id_scheme+local_id`; query existence, type, status, locale and WPML element_type/trid. Term IDs must not mix with term-taxonomy IDs.
6. Compare translated semantic identity explicitly; matching language/type alone proves only structure.
7. Distinguish deliberate shared references, copied source IDs, untranslated fallback, missing target translation, remap candidate and human-owned edit.
8. Record strict unknowns as `UNRESOLVED` with next permitted read/action.

## Critical denial rules

- `wpml_object_id(return_original_if_missing=true)` does not establish that a local translation exists.
- WPML group equality is necessary for translation-membership claims but not sufficient for commercial entity equality.
- Empty record list, incomplete pagination, no expected coverage set, unverified source record or stale provider proof cannot produce `complete=true`.
- Source/target under different sites, wrong namespace, unknown taxonomy, wrong element_type, duplicate locale in one group or provider reconfiguration forces conflict/review.
- A relation scan has two independent verdicts: structural findings and `semantic_verification_complete`; it cannot emit `eligible_for_mutation=true`.

## Mutating relation plan (future, separately governed)

Planner may prepare `RelationChangeProposal`: before value digest, target semantic key, locale policy proof, ownership evidence, expected PostMeta CAS, native hook strategy, render impact, affected languages/URLs, reversible or external-effect classification. Existing MAD4B Content Operations or provider-owned adapter performs only approved exact plan. An uncertain original provider hook ⇒ do not execute or re-run blindly. Verify field, target, translation, taxonomies, WPML meta and rendered page after applying; compensate only if certified rollback can restore exact semantics.

## Site-specific historical example (read-only only)

On 2026-10-08 All Royal Egypt Staging, tour rate 45005 EN and 45007 FR were reported in trid 638158. Their tour references differ, package-term references differ, shared property list matches. These observed facts do **not** prove a defect or certify Meta ID Mapper. Production, other locales, WPML field options and postpublication behavior unverified.

## Conformance matrix

Source post missing, deleted target, wrong post_type, wrong taxonomy/id_scheme, absent translation, original-ID fallback, duplicated target, shared deliberate relation, human override, conflicting mapper configuration, serialized array, partial language family, pagination race, restore epoch drift, provider disabled and rollback failure. Positive acceptance must include real provider version and exact native+rendered readback on disposable and Staging assets.
