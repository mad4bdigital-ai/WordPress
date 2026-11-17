# PR #814 — Purpose-specific operations, CI regressions and Production routing

Reviewed November 17, 2026 against the cumulative PR #814 source. SOURCE ONLY, never deployment authority.

## 16 Staging-purpose executors

All sixteen exceptional abilities are captured individually in governance/production-readiness/purpose-specific-environment-operations.json. The registry records the original first-party owner source and provider, requires complete enumeration, rejects uncontrolled Production expansion, and does not issue Grants. The source matrix is checked by tests/test_purpose_specific_environment_operations.py and by the hermetic PHP provider parity fixture.

Four entries have RELATED independently governed Production-context routes. All have route_equivalence_proven=false. Production deployment, recovery, provider credential refresh, native build, offline CI attestation and scratch recovery must not be promoted by rewriting their original Staging-only guards.

## Source-backed fixes for head f5bc4821

1. Attachment Transfer: The original static staging_only check did not match the new per-Site-Profile code. Updated static and executable tests verify explicit Production confirmation, exact company transfer isolation, WordPress upload/transfer privileges and kill-switch denial.
2. Owner Preview: The historical test expected the optional-governance provider to be hidden on Production. Its source provider now appears in both environments, but local Owner Preview still rejects Production and issues no authority. The test enforces both properties.
3. Shared Product Core: The manifest now lists 137 files instead of 135. Source-only CI validates actual file count and SHA manifest, uses its current immutable version when naming the artifact, and still forbids automatic deployment.
4. Dynamic Skills: A synthetic ICL_SITEPRESS_VERSION was introduced for a fake WPML REST provider and carried into write grant reconciliation in one PHP request. The next independent request did not have that fake provider and reported two stale translation grants. The synthetic read-only probe is separated into its own WP-CLI request before the actual authority test.
5. Historical Commerce ancestry: Preserve the original 23-node graph and 0.1.2 version in an immutable Git ancestor. Confirm the current 27-node graph consists of those exact 23 plus four named Presentation-only additions, with current version 0.1.3.
6. Dynamic Readiness: Its 10 exact source review gaps are mapped to three extended existing Ability reviews, four CI-only capability reviews, and three original-first-party exact Git blob behavior reviews. Controls and Release Owner authority are unchanged.

## Independent final gates remain intact

- Company/Agent Site Profile, explicit Production write confirmation and exact active provider.
- Current Owner decision, one-time grant, OAuth authorization, audit and rollback.
- Native Host/Original execution, receipt and independent deployment certification.
- No synthetic WPML, self-certified Staging install or CI-outage attestor may be substituted for Production release proof.
- Feature 007 root branch gate and exact-head PR Owner Attestation must not be disabled or faked.

Source QA is not final CI approval. Execute workflows against the exact published GitHub SHA before declaring a release or touching live sites.
