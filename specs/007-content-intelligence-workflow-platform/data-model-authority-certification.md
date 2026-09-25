# Data Model Addendum — Authority and Dynamic Certification

## AuthorityDescriptor
- authority_id
- site_uuid
- authority_type
- issuer
- configured
- trusted
- advertised
- primary
- standby
- resource_policy_id
- subject_mapper_id
- runtime_verified
- last_live_verified_at
- last_live_verification_ref
- revision
- status_reason_codes

## AuthorityResourcePolicy
- policy_id
- authority_id
- environment
- protected_resources[]
- default_deny=true
- developer_enabled
- breakglass_enabled
- revision

## SubjectPrincipalBinding
- binding_id
- site_uuid
- authority_id
- issuer_fingerprint
- external_subject_fingerprint / normalized non-secret subject identity
- wp_user_id nullable
- nhi_public_id nullable
- enrollment_revision
- enabled
- revision
- created_at/updated_at

Unique active identity:
(site_uuid, authority_id, external_subject identity).

## OAuthKeyRecord
- key_id/kid
- authority_id
- role: current|next|previous
- public_jwk
- private_key_location reference only
- created_at
- activated_at
- retire_after
- retired_at
- state
- fingerprint

## ProviderArtifact
- artifact_id
- provider_id
- version metadata
- source
- archive_sha256
- tree_sha256
- critical_files_sha256
- schema/migration identity
- observed security surfaces
- created_at

## CapabilityFingerprint
- provider_artifact_id
- capability_id
- contract_version
- implementation_dependency_fingerprints[]
- schema_dependencies[]
- security_dependencies[]
- behavior_profile_version
- fingerprint_sha256

## CertificationEvidence
- evidence_id
- evidence_type
- provider_artifact_id
- capability_id
- environment_class
- site_uuid nullable
- probe_version
- result
- evidence_sha256
- depends_on[]
- reusable
- origin_evidence_id nullable
- lightweight_recheck_id nullable
- created_at
- invalidated_at/reason

## ArtifactDiffAssessment
- previous_artifact_id
- new_artifact_id
- classification
- changed_dependencies[]
- affected_capabilities[]
- confidence
- evidence refs
- assessment_sha256

## SiteRuntimeCompatibility
- site_uuid
- provider_artifact_id
- PHP/WP/DB identities
- extensions
- permissions
- cron/background
- object cache
- conflicts
- provider config fingerprint
- compatible
- blockers[]
- evidence_sha256

## ProviderResolutionDecision
- resolution_id
- required_capability_set_sha
- candidate evaluations[]
- selected_provider_id nullable
- policy_version
- reason codes
- evidence refs
- resolution_sha256
- non_authorizing=true

## ReleaseRingRecord
- provider_artifact_id
- capability_id
- current_ring
- previous_ring
- transition evidence
- auto_promoted
- policy_version
- transitioned_at

## MultiAuthorityLiveCertification
- certification_id
- site_uuid
- source/build/package identity
- authority_snapshot_sha
- probe_results[]
- verdict
- evidence_sha256
- created_at


## ApprovalTicket Candidate Binding

Approval authority is bound to an exact candidate and exact site/runtime identity. The
binding contract is `mad4b.approval-candidate-binding.v2`.

Required binding material includes:
- approval_ticket_id
- site_uuid
- site_profile_binding
- site_profile_revision
- site_profile_digest
- server/ability/provider identity
- target fingerprint
- candidate source/build/package identity where applicable
- expiry / one-time claim state
- binding_sha256

A clone, copy, restore, migration or environment duplication MUST NOT inherit an
approval merely because database rows or plugin files were copied. The cloned target
must reconcile its own site identity and obtain a fresh exact binding when the
effective target identity differs.

Site Profile drift after authorization invalidates the candidate binding. A change in
site UUID, Site Profile revision/digest, environment, authority mapping, target
fingerprint or exact candidate identity requires a new authorization decision rather
than silently reusing the old approval.

The current expected version is `10`; approval services fail closed when the required
physical schema or the expected binding fields are unavailable. Schema evolution may
advance this version only with an explicit migration and corresponding approval-model
contract update.
