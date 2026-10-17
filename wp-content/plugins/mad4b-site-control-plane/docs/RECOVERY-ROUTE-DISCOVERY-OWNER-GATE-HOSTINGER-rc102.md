# rc.102 — Recovery discovery, manual vs MCP coverage, owner gate and Hostinger

## Operational relationship, not cryptographic trust
The user confirms Staging and Production are in ONE Hostinger hosting account. This is shared operations/billing control, not proof they have the same WordPress root, Unix user, DB, secret, or release authority. Verify distinct Site UUID, canonical URL, environment, private host key and Site Profile for each. Never share or copy the Staging Host key into Production.

## Live dynamic discovery -> classification -> sorted coverage
The read-only ability mad4b/recovery-route-matrix dynamically checks each recovery-stage Ability against wp_has_ability and the actual canonical MCP mounts in MAD4B_SCP_Servers. The current WordPress user must be able to manage_options to read the matrix; registration and mounting are not write authorization. A bounded, ranked recovery taxonomy is used to avoid presenting other vendors' arbitrary operations as executable. Each record includes WordPress Admin navigation, MCP Ability, lane, the owner/provider gate and the next verification step. Unregistered/unmounted abilities are visibly blocked. This catalog is bounded to recovery; it is NOT a claim of coverage of every vendor plugin.

| Stage | WordPress manual | OAuth/MCP remote | Approval / after |
|---|---|---|---|
| Discovery | Operational Remediation | mad4b/recovery-route-matrix; mad4b/chatgpt-tool-projection-discover | Per-subject read; check live registry and mounts |
| Update | WordPress Plugins | mad4b/control-plane-native-plan | Certified exact release; independent restart readback |
| Host binding | Operational Remediation | mad4b/deployment-binding-native-status/plan/apply/readback | Admin POST + nonce OR exact owner ticket; verify new-request host key |
| Owner Write decision | Tools > MAD4B Write Review | mad4b/staging-write-owner-review/attest | Exact enrolled owner, HMAC intent, no grants |
| Governed Write | Owner review guidance | mad4b/staging-write-authority-convergence-handshake/apply | OAuth Step-Up, exact package/site/plan/owner intent; new-request zero-drift grant readback |
| Safe runtime | Operator Control Center signed Automation switch | mad4b/runtime-convergence-plan | Explicit owner opt-in then independent G8 SLO Cron ticket; no Write or Production authorization |
| Skills/Context | Managed Skills; Context Authority | mad4b/skills-runtime-certification; mad4b/context-source-onboarding-preflight | Owner folder selection and independent provider refresh |
| Production host | Production Operational Remediation | mad4b/deployment-binding-production-status | Read-only local key verification, never mint or share |
| Production restore and promotion | Not yet one end-to-end certified executor | No executable registered | Requires independent Production owner release, signed shared deployment-family relationship, backup/restore canary and rollback |

## Exact owner gate instructions
Manual: open tools.php?page=mad4b-staging-write-owner-review as the **same explicitly enrolled Staging WordPress owner** and review Site UUID, profile revision, current source and plan SHA. Type exactly AUTHORIZE EXACT STAGING WRITE CONVERGENCE. The POST is nonce-protected and persists a 600-second signed owner-intent receipt, not any mutation ticket.

Remote: discover/prepare the read-only mad4b/staging-write-authority-convergence-handshake. Its exact_apply includes expected_plan_sha256, expected_source_commit_sha, expected_build_fingerprint, expected_package_manifest_digest, expected_artifact_identity, expected_site_uuid, expected_profile_revision, expected_profile_digest, and confirmation ENABLE GOVERNED STAGING WRITE AUTHORITY. The delegated OAuth identity must be the SAME owner, with step-up scope mad4b:authority:step-up. Supply exactly ONE proof: owner_review_digest (remote one-call owner intent) or owner_intent_sha256 (from manual WordPress review). Call the existing governed mad4b/staging-write-authority-convergence-apply through its authorized transport only. Never bypass one-time operation ticket policies.

After: in a separate request, check current Write authority and exact candidate; missing/stale/wildcard/broad grants must be zero. Re-read runtime-convergence-status and context-source-onboarding-preflight and collect Context provider evidence independently. A same-request apply result is not an independent release certificate.

Automation: if the owner previously paused safe phases by Automation Kill Switch, only the local signed switch change can opt back in. The opt-in code may requeue only a matching checkpoint paused for the exact kill switch cause. A CAS lock, exact current runtime identity, clear relevant scopes and independent Cron SLO ticket remain mandatory. Unknown external outcomes, other blockers, mismatched builds, failed CAS, unavailable Cron are NOT automatically retried. No Production and no Developer/Breakglass Write scope.

## Shared Hostinger account vs Production promotion
The present Staging promotion proof is MAC-bound to its native Host key. The current Production verifier compares the Staging binding digest with the Production-local binding digest. Independently generated per-site secrets will not match even under the same Hostinger account. This is a **Production acceptance blocker**. Design a separate externally verifiable, signed deployment-family identity linking both Site UUIDs, origins and the exact reviewed artifact, along with separate independent proof of each host key. Do not weaken the existing MAC comparison or copy secrets before a trustworthy cross-host issuer/verifier exists.

Production mutation/rollback/backup restoration is **not implemented by this patch**. The matrix reports missing governed Production executors clearly rather than claiming completion.

## Acceptance and negative tests
Use exact-head CI, PHP7.4/8.3 fixtures, dynamic Ability/MCP mount discovery and wrong-environment cases; validate WordPress admin non-OAuth review vs remote bearer Step-Up, expired/replayed receipt, Hostinger sibling sites with distinct secrets, kill switch pause/release and Cron/CAS failure, WP updates, real Skills checkpoint reconciliation, Google provider/source approvals and independent Write readbacks. Do not deploy to Production or declare complete disaster recovery without a real backup/restore rehearsal and a signed cross-host deployment-family proof.
