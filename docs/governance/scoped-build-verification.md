# Scoped Build Verification (SBV) v1 — live-first, two-site implementation

## Contract and security boundary

For **ordinary new drafts and edits to existing drafts** only, the manifest's all-files check is replaced with a site-local, *code-owned* list of material PHP dependencies. This is a **selective file integrity check**, not a signed source release certificate. The exact manifest identity and its complete declared-entry digest must be coherent; every required file must match its recorded bytes and SHA-256. An unrelated image, stylesheet, admin script, or template difference does not disqualify an otherwise verified draft.

`MAD4B_SCP_Scoped_Build_Verification::governs_content_write()` selects only the two native content Abilities and create/update/publish routes belonging to enabled Content Experience profiles. `verify()` returns a `scoped` verdict only for draft/pending creates and changes to drafts/pending posts and only when the original impact classifier does not require a ticket. Absent post IDs, unknown targets, published edits, publish actions and any commercial key names (including nested metadata) require the **full** manifest check. Package installation, source code updates, permissions and other sensitive operations always remain on their existing strict path.

The first-party governed `MAD4B_SCP_Authorization::authorize_mutation_impl()` runs this verifier **after** registered identity, exact grant, and current candidate-bound write authority have passed. Therefore this only replaces the broad file comparison for the selected content route; it never bypasses actor, site, grant, candidate, approval, resource, audit, rollback, or production gates. If a material file has changed, authorization fails closed. The existing native WordPress-user delegation path remains separately protected by its original rules and is not redefined by SBV.

The read-only `mad4b/scoped-build-verification` Ability and `wp mad4b build scoped-plan` command expose the same decision; the existing Unified Execution Plan now reports `build_verification_scope` and exact material mismatches. Site IDs, URLs and secret keys are absent from this logic. A missing optional verifier restores the original behavior, never an automatic grant.

## Live verification (19 Nov 2026)

- Applied to Tours.dev Staging and Wayfatours Production. The same module and Control Plane changes are installed on both sites; registered the read Ability on `mad4b-read`.
- The standalone hermetic test proves that an unrelated CSS file can drift while `scoped` stays ready; changed authorization code, tampered manifest digest and published-content edits fail closed; plugin packages remain full scope.
- Both live sites currently match **23 of 30** material files. Seven files are different from their current package manifest: authorization, agent grant lifecycle, impact policy, context preflight, Site Profile, Content Experience runtime and Content Experience media rights. The exact drift must be reviewed and packaged; never rewrite their manifest entries in place.
- No new Grants, no Candidate Binding reconciliation and no Production gate change. Both sites still report effective write authority as false.
- Current live backups: `/opt/mad4b-backups/scoped-build-verification-20261119/`, with SHA-256 verification. Original plugin files were retained and must be restored without overwriting unrelated site files if rollback is needed.

## Release acceptance

After reconciling these six live code files into a separately approved package and certifying that package, test one exact draft create, read it back, and undo it; then verify production's approved exact write grants and controlled mutation gate. Do not mark execution ready merely because a scoped verdict passes. High-risk operations continue to require strict full-package verification.
