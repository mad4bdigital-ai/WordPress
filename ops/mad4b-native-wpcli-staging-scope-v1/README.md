# Native WP-CLI enrolment: exact Staging Company Manager scope

The original root-owned operator source `/opt/mad4b-company-transport/broker-v1/native-company-wpcli-enroll.py` is the **only** scoped enrolment client. It creates original 305 transport/grant records only after exact original owner, signed principal, pinned signer, host identity and verified WordPress delegation. The script's `plan` action is read-only.

## Root cause

Its embedded WordPress actor-readback previously read
`MAD4B_Company_Portfolio_Manager_V1::ASSIGNMENTS[manager|company]` and company-wide `has_delegation`, both Production-scoped. This incorrectly rejected signed `all-royal-egypt:staging` even after the separate Staging Manager lifecycle was introduced.

## Correction

For requested `environment=staging`:
- Read `mad4b_company_environment_manager_assignments_v1[manager|company|staging]`, from existing `316/317`.
- Verify original active enrolled WordPress actor through `318::has_delegation` using the exact signed Staging site UUID, canonical origin and original owner proposal/approval SHA.
- Require the exact active assignment, current matching proposal/approval digest, one eligible actor, original principal ledger, native Ed25519 key anchored in WordPress, pinned Hostinger host key and Host Policy.
- Preserve the original company-only assignment checks **only** for Production. Never repurpose Production registry identity for Staging.
- Keep `plan` non-mutating; `enroll` remains a separately authorized 305 original transport/grant operation. It still does not authorize actual remote host execution without independent 312/313 certification.

## Independent readback on central Staging

The **candidate embedded scope PHP** was executed read-only against central Staging on the VPS. Its result for All Royal Egypt:
- correct company `all-royal-egypt`, environment `staging`, signed Site UUID `49c562d1-8f2f-456f-b454-26816c6ba4cb`
- `assignment_active=false`, `eligible_actor_count=0`, `assignment_revision=null`
- `current_original_central_principal_verified=true`

This is a correct denial pending **original owner** Staging proposal/approval/activation. No grants or host commands were created.

## Deployment

The source folder is **not** an executable enrollment. An authorized release must verify the exact root-owned original script SHA256 `f83664d4c60b03acc3da0453c9276ad969edcc280803bc1f8dac2d1ddd8cf1d5`, candidate SHA256 `08b5f7a6e70d35257fbde980287561391f69de52dfd269bcce28f4719641b085`, exact staging-only host authority, root-only backup, native operator plan/apply/readback and rollback. Do not modify Production.

Run regressions:

```sh
python3 -m unittest discover -s ops/mad4b-native-wpcli-staging-scope-v1/tests -v
```
