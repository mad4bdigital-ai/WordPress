# MAD4B ContentJob typed relationship writeback

Staging verified, site/company independent source. This extends the original
ContentJob and Translation Bridge; it does not replace the registered owners.

Native abilities:
- mad4b/content-job-typed-relation-write-plan (read)
- mad4b/content-job-typed-relation-write-apply (write, original WP permission)

Source owners:
- featured_image: media/set-featured, exact expected_thumbnail_id=0,
  same-site source/target WPML family, local attachment translation,
  independently certified Media rights/alt/asset evidence from a registered
  first-party media evidence provider.
- elementor_template_refs: MAD4B_WPML_Design_Reconciliation_V1 preview
  and bounded sync; allows only one exact localized target diff, zero
  unresolved references, no cross-locale side effects, no authored text overwrite.
- typed_meta: registered exact field contract, original JetEngine
  update-post-meta ability, source-localized reference identity, native
  protected-field/field-create policy; never generic postmeta update.

Every write requires a live bound ContentJob artifact, exact original WPML
family, current job revision, source fingerprint, original owner permission,
current first-party QA/rights, Central Write Acceptance atomic attempt,
native independent readback and stable receipt. The synchronous original
mad4b_scp_content_job_transitioned action probes eligible media/Elementor
owners and runs only READY ones. Typed meta requires the declared field key,
so is available through the explicit governed ContentJob apply ability but
is not guessed on lifecycle events. There is no background queue.

Native ContentJob transition now supports the **same-state same-stage**
operation to bind an already existing verified WPML target artifact via its
existing revision-CAS/audit/event transaction. Every translation job artifact
change, even when accompanied by a state transition, is revalidated against
the exact source relationship graph. Unrelated or repeated IDs are denied.

Staging acceptance (2026-11-02):
- Original Graph 14/14 PASS after true Kom Ombo artifact binding
- Original typed providers 12/12 PASS
- New writeback acceptance 12/12 PASS
- Central CWA native/safe-fixture 11/11 PASS
- Actual Kom Ombo French term #2951 bound to existing ContentJob
  fe3d871e-ee50-435c-93fb-63bcdb1bf3b7, revision 4 -> 5, state
  WAITING_REVIEW/EDITORIAL_QA unchanged, is_public=0 unchanged.
- Foreign artifact #57563 rejected from Elementor job #2158e5c3...
  including when combined with otherwise valid state transition; original
  ContentJob revision and state remained unchanged.
- WordPress CLI anonymous attempt for mutation returned
  ability_invalid_permissions.
- Current translation jobs have no complete eligible media/template/typed-meta
  provider proof. All such writes HOLD, none claimed. No real user image,
  Elementor document, or metadata was modified by this increment.
- Production untouched; publication and managed route settings OFF.

An actual source/target provider-positive relationship write must be confirmed
with its original Media/Elementor/JetEngine permission, current independent QA,
and exact durable post-write readback. Absence of evidence never authorizes it.
