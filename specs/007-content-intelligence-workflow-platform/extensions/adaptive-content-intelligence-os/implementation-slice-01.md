# ACI01 Implementation Slice 01 — WordPress-native read-only intake

**Status:** SOURCE_IMPLEMENTED · DISPOSABLE_TESTED · STAGING_NOT_CERTIFIED
**Contract:** `mad4b.aci01.intake-preview.v1`
**Entry point:** `mad4b/aci01-intake-preview` (WordPress Ability, governed read surface)
**Authority:** existing `MAD4B_SCP_Policy::can_read` only. Never elevates grants, performs writes or claims verified Content/Brand/Provider receipts.

## Reuse instead of a parallel engine

- Site UUID, canonical origin and environment from the existing Site Profile provider.
- Enumerated post types, current user's content capability and related native taxonomies from `MAD4B_SCP_Content_Experience_Profiles::discover`.
- WordPress Abilities registry and existing MAD4B read permission; no REST endpoint, option table, schema, journal, token or AI-provider callback is added.
- Later implementation will use existing `MAD4B_SCP_Content_Jobs`, `MAD4B_SCP_Artifacts`, `MAD4B_SCP_Content_Intelligence_Pipeline`, `MAD4B_SCP_Context_Preflight` for actual bounded jobs and artifact lineage. This slice deliberately does **not** create or mutate artifacts.

## Input and observed output

Read-only input: optional `post_type`, `brand_id`, `locale`, `market`. If the target post type is not given, the response lists only inventoried types the active user may create/publish and returns `NEEDS_REVIEW`. Selecting one returns a candidate `native:<post_type>` and a deterministic fingerprint of the scoped preview.

The response includes `site_discovery`, `evidence_pack`, `blueprint`, `draft_qa` and `operator_review` stage markers. The model is conservative: metadata alone cannot verify brand context, research provenance, WPML or site write permissions. A selected target therefore remains `NEEDS_EVIDENCE` until the existing governed evidence flows are wired into a later slice.

Native taxonomy presence triggers `native_relation_policy_unverified` and a review requirement, not an inferred WPML translation. The preview never auto-selects tour content, invents post IDs or calls external providers.

## Blocking and negative conditions

Permission revoked, unenrolled site, malformed HTTPS origin/UUID/environment, post type outside the actor's visible editable inventory, unknown schema key or nonstring argument, duplicate/malformed types, oversized post-type/taxonomy registry all fail closed with `DENIED`.

Outputs always include `authorizing=false`, `eligible_for_mutation=false`, `mutation_performed=false`, `external_provider_called=false`, `paid_calls=0` and no credentials.

## Exact disposable checks

```bash
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-aci01-intake-preview.php
php wp-content/plugins/mad4b-site-control-plane/tests/aci01-intake-preview-contract.php
```

These checks were executed on PHP 8.4 in a standalone fixture; they do not prove live WordPress boot or WPML parity. Every `ACI-T...` spec task stays OPEN; runtime certification and schema/view approvals are independently pending.

## Next slice

Resolve native content type → registered content recipe, current site identity/provider binding → governed context receipt, no-charge EvidencePack → OpportunityHypothesis and Blueprint → existing Content Intelligence Pipeline, with independent review ticket. No actual publication, paid search or Production promotion is included in this first slice.
