# Feature 007 — Site-Neutral Market Intelligence & Business Activity Contracts

## Architectural decision (10 Oct 2026)
MAD4B remains **one reusable WordPress Control Plane**. DMC, driver, tour guide or any professional/business profile is NOT a PHP class, fixed post type, installed sub-plugin, or globally enabled module. It is a **site-owned optional `activity_contract` facet** of an existing `Content Experience Profile`.

The canonical source of configuration and versioned authority remains `mad4b_content_experience_profiles_v1`. No second tourism plugin, separate site-domain allowlist, parallel CPT registry, global role creation or hard-coded travel flows.

### General reusable layers
1. **Competitor research:** dynamically configured public source profiles; independently authored brand-aligned content and price benchmarking, without assuming a supplier contract. Research and media candidates are not customer booking inventory.
2. **Content Experience Profile:** site-selected registered CPT, taxonomy allowlist, meta allowlist, content and media mutation lanes, revision, exact plan hash, existing independent readback/rollback.
3. **Optional Business Activity facet:** `activity_contract.enabled=true` activates configurable User ↔ CPT relations, distinct protected keys on user/post, low-privilege role, attribute allowlist, allowed taxonomies, new user/post options. Defaults to DISABLED.
4. **Source sync targets:** each enabled profile can configure 0..12 targets with provider `wordpress` or `google_drive`, direction (`import`, `export`, `bidirectional`), field allowlist inherited from the Content Experience Profile, provider resource reference and conflict policy. `business-activity-sync-plan` is a read-only plan. It does not create external Drive files, pretend generic Context/Brand APIs update arbitrary Drive documents, or skip provider revisions/independent execution grants.
5. **Generic external image ingestion:** `media/import-plan` and `media/import-external` in the already existing Media Adapter, bounded HTTPS and safe HTTP validation, media type/size checks, stable idempotency key, source checksum, attachment/alt metadata and readback. The source import does not automatically publish or assign a featured image; media metadata may be updated separately using existing `media/update-metadata`.
6. **Persisted retry journal:** governed Brand Core write endpoints reserve server-side attempts and block blind repetition after a successful/uncertain write. An attempt reservation is not an independent authorization.
7. **Contract permissions:** `mad4b/business-activity-link-apply` is a separately approved administrator/content mutation. Users are created only with an explicitly configured registered nonprivileged role; new profiles remain Draft, with a stable operator key and mirrored relation readback.

### Runtime-discoverable abilities
- `mad4b/content-experience-profile-plan` → `mad4b/content-experience-profile-apply`: configure or extend the canonical profile (including the optional facet) with existing revision and exact plan hash.
- `mad4b/business-activity-status`: effective profile/role/field/sync mapping inspection.
- `mad4b/business-activity-link-plan`: normalize and validate user/profile/attribute/classification intent against registered CPT, attached taxonomies and the current effective profile.
- `mad4b/business-activity-link-apply`: exact approved write through the existing governed content lane, with unique operation key and bidirectional post/user meta readback. Partial failures retain a reconciliation lock rather than duplicating accounts.
- `mad4b/business-activity-sync-plan`: discover operations for each configured provider and require source/destination revision comparison, diff, conflict policy and independent provider write before update, enhancement, import or export.

### Site-scoped All Royal Egypt sample — NOT an active installation
The Staging runtime explicitly confirmed these registered CPTs:
- `dmcs`: taxonomy `location_jet`
- `drivers`: taxonomy `location_jet`
- `guides`: taxonomies `location_jet`, `guide-languages_jet`
- `tours-and-activities` is a separate content type for products, **not** the identity profile for professionals.

Example profile JSON is in `examples/all-royal-activity-contracts.json`. Its meta link keys are **proposed new keys**, not existing JetEngine field names. No user, post, taxonomy term, role, or profile configuration has been created or changed on the site. Before applying, reconcile live JetEngine definitions and actual meta mappings, choose correct role and relationship keys, and use the canonical Content Experience Profile review/approval.

### Required tests
- The engine has no `dmcs`, `drivers`, `guides`, All Royal host or travel-only conditions.
- No facet is active without an exact site-owned configured profile, registered CPT and attached allowlisted taxonomy.
- No privileged WordPress role can be assigned by profile configuration; unknown metadata, provider fields and source URL credentials fail closed.
- A user cannot silently be taken from an existing linked post, nor can a linked post be stolen from an existing user.
- Double submissions/pending external writes cannot silently create duplicate users or posts.
- New user and profile operations require exact authorized plan SHA; partial failures are reconciled independently.
- Google Drive plan is **not** proof that a scoped writable Drive provider/verified version/Drive revision exists.
- Media import requires a reviewed source plan, explicit permission, HTTPS safe retrieval, bounded files, type checks and exact readback.
- Native PHP/WordPress Staging runtime, multiple real role/CPT configurations, Staging acceptances and the external Drive write round trip remain independent release gates.

## Status
Implemented in source on Draft PR #366. **No new independent tourism plugin remains in the proposed branch**. This does not claim automatic Drive editing, activated site-specific profiles, executed media imports, final publication or native Staging acceptance.
