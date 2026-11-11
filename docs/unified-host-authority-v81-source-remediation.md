# MAD4B Unified Host Authority v81 — source remediation checkpoint

Date: 2026-11-11 Africa/Cairo
Branch: feat/original-remote-hostinger-20261109

## Source-only improvements

- deploy/host-authority/v81/bootstrap_signed_job.py: purpose-separated, Broker Ed25519 signature bound to first Anchor, exact one-time enrollment, scoped original claim; no previous 305/328 Grant. Does not issue Anchor or authorize execution.
- deploy/host-authority/v81/original306_admission_router.py: v1/v2 operation-specific typed Job classifier, preserving legacy v1; rejects v2 fallback, absent registry operation and drift. Does not change installed Worker.
- deploy/host-authority/v81/current_revision_gate.py: checks signed root revision ledger event, separately signed high-water checkpoint, fresh Original318 observation and post-reservation state, plus Revoke invalidation. Requires a DIFFERENT Ed25519 key for the separately signed checkpoint, and rejects reusing the ledger signing key as a supposed independent witness. Does not create/fetch trusted live stores or enforce their persistence.
- deploy/host-authority/v81/operation313_evidence.py: requires separately signed Original313 independent proof for Issue/Revoke with exact Job, 312, command, plan, before/after monotonic revision, and prior Grants ineligible after revoke. Does not sign a Central313 receipt.
- scripts/guard_original_318_release.py: non-publishing read-only pre/post-image source observer. Installed Original318 SHA-256: ecfefbee1a60f52431c4688f7b5858ea1df3633812483c9794f36515c418af55; v68 candidate SHA-256: 17895c0c66381b2acaab1ef022d89a88eb79199847ebb022921da1296671ed7d. Not deployed. Host has no PHP CLI, so the local observer PHP-lint gate currently reports BLOCKED_PHP_LINT_RUNTIME_UNAVAILABLE; both candidate and installed baseline separately passed php -l inside an existing Staging PHP container. Hash-only source observation reports BLOCKED_AUTHORIZED_ORIGINAL318_PUBLISHER_REQUIRED. External container lint and cryptographic fixture success do not authorize publishing.

## Tests and live baseline

16 focused Python test suites: 180/180 PASS, including original v68/v74/v75/v76/v77/v79/v80 plus 5 new v81 suites. Isolated PHP v78 signed-anchor runtime fixture (source v68 injected on stdin into an existing Staging PHP container): 16/16 PASS, no site files copied or changed. Ephemeral test Ed25519 private keys only; no Broker or Original313 signing keys accessed.

8/8 original release guards PASS_ALREADY_CURRENT. Worker Preclaim SHA c98aa36ee312dc96589474540216cefee6831b0df4c2acd522be6083579811b8 remains verified; do not change it. Those eight guards do not publish Original318. Live registry contains only 3 existing providers.

## Open live integration gates

1. Original318 v68 needs the separately approved first-party protected release publisher, exact vetted preimage, backup/rollback, PHP runtime lint and independent readback. Earlier channel denial must NOT be bypassed by root-shell copy.
2. First-party, independently approved Enrollment Authority issuer must read actual company and remote host identity, sign purpose-specific first Anchor with existing Broker mechanism, atomically consume nonce. Source-only signature verification does not issue authority.
3. Install durable append-only root revision event ledger and separately persisted central monotonic high-water checkpoint, prove no rollback, and bind all Original318/305/328/312/313 consumers. Synthetic dictionaries in isolated tests are never trusted live authority.
4. Install true signed scoped v2 305/328/312 Grants and separately signed first-anchor Bootstrap; do not require a user OAuth or WordPress actor for host v2 but do require first-party scope approval, authentic identity, exact one-time nonce and authority.
5. Connect Source v81 classifier to installed Original306 Worker; after one-time reservation and immediately before actual mutation recheck revocation and exact revision. Build real Issue/Revoke handlers and independent postcondition readback with distinct Original313 signing key, while preserving v1 and Code Release.
6. Only enroll real, executable first-party providers after integrated live canary and genuine 313 receipts. ARE Production write policy remains denied and must be changed only by separate scoped approved company policy.

## Explicit non-claims

No live Original318 deployment, no newly enrolled Issue/Revoke Provider, no real issued/revoked Anchor, no new Grants, no runtime v2 execution, no Original313 signed receipt, no Production write/content mutation, no waiver of rejected release channel. Source commit is not a Production authorization.

Preexisting untracked deploy/host-authority/v63/ is untouched.

## Supplemental v81 first-Anchor plan contract correction

The earlier v79 operation validator rejected grant_revision=0 and anchor_revision=0 for an initial Anchor. Source v81 now has a separate immutable operation plan validator at deploy/host-authority/v81/operation_plan_contract.py. It retains the same plan_contract string required by the v79 Registry, accepts zero revisions ONLY for an explicit Issue Bootstrap containing enrollment ID, one-time enrollment nonce and requested next revision=1; it continues to require positive revisions for ordinary Issue/Rotation and Revoke. The v81 Original306 typed router now calls this verifier for Host v2 jobs, rejecting unsigned plan drift before routing. Legacy Code Release v1 remains separate and unaffected.

The protected request to test storage of this new first-anchor plan in the actual original Registry was blocked by execution safety checks; therefore live Registry admission is NOT certified by these source tests. No provider enrollment, signed first Anchor or Production mutation followed.

## 2026-11-11 — v82 original signed revocation observation

New signed non-authorizing Original318 source candidate:
deploy/host-authority/v82/318-mad4b-native-host-exact-grant.php
SHA-256 56c78c0036c1e690069a2abbbbe4e84f8745bf30fec4dcc9c875835e52e442be.
Does not replace deployed Original318 (still SHA ecfefbee1a60f52431c4688f7b5858ea1df3633812483c9794f36515c418af55).

The existing v68 dynamic_anchor() intentionally reads only current ACTIVE anchors and returns null for revoked anchors. A real independent 313 Revoke postcondition must also see the independently signed revoked-record shape, not a Handler success. v82 adds independent_revoked_anchor_observation_v2(), which verifies the old Broker public signature, registered company identity, scope, host and policy digest shape for a signed REVOKED record, and returns no eligible privilege or final independent certification. Its independent_highwater_verified is always FALSE by design until the separate current-revision ledger/reader is installed.

Validated with in-memory WordPress/PHP fixtures using ephemeral Ed25519 keys against existing Staging PHP container; no actual WP option or production state was written. Previous v78 active anchor regression runs unchanged in the same fixture.

Bounded authoritative release proposal: deploy/host-authority/v82/release-proposal.NOT-AUTHORIZATION.json. This is NOT an executable or signer enrollment. The original protected release service still contains only eight other modules, no 318 publisher. The direct governed WordPress catalog currently exposes no dedicated original318 release or host Anchor issue/revoke operation. No alternate transport was used to bypass the earlier release refusal.

Other source-only acceptance improvements:
- tests/test_operation_plan_bootstrap_v81.py validates zero prior revisions only for an explicitly signed first Issue plan, preserving operational and Revoke v79 shape.
- scripts/host_v2_live_release_readiness.py is strictly read-only and enumerates exact installed source hash, registry manifest integrity, absent host Providers, missing v2 worker admission and 313 evidence.
- tests/test_host_v2_live_release_readiness.py proves fail-closed detection and no mutation.

Remaining production prerequisites: reviewed protected Original318 release, real first-party independent enrollment issuer, durable monotonic root ledger and independent high-water store, 305/328/312 v2 signed operational grants, current-state post-reservation recheck, registered real Issue/Revoke handlers, operation-specific signed 313 receipt after independent readback. No external OAuth or WP actor is introduced as a universal requirement.

## Exact 11 November 2026 source acceptance

Nineteen focused Python suites, 206/206 tests PASS, including one isolated real PHP v82 runtime test executed against the existing PHP 8.5 Staging container. The v82 test also runs the earlier v78 active Anchor fixture; it uses an ephemeral Ed25519 key and in-memory test_options only. The candidate passes php -l.

A corrected historical-revision rule retains verification of a cryptographically signed old root event after its original expiration, but rejects any expired/missing *current* independent high-water checkpoint. The old root signature is not a substitute for a fresh independent witness. A live append-only ledger and its separate high-water writer/reader have not been implemented or enrolled.

Live diagnostic checks: original318 candidate exact PASS; source revocation observer present PASS; installed index manifest digests PASS; live v82 Original318 NOT installed; host Issue/Revoke Providers NOT registered; live worker v2 admission NOT installed; host v2 Original313 independent evidence NOT installed; independent signed Original313 receipt NOT present. Therefore the correct release decision is BLOCKED.

The previously rejected source-publisher authorization is unchanged. Never copy candidate directly into the active WordPress module using SSH/root as a release substitute. The source bundle is a proposed artifact, not an authorized grant.
