# MAD4B whole-plugin operator experience — source and live-discovery audit
Date: 2026-10-11. Repository: mad4bdigital-ai/WordPress, PR #258.
Scope: source implementation and live **read-only MCP** catalog/diagnostics on All Royal Egypt Staging, not a logged-in visual wp-admin/browser or real new-build E2E session.

## Evidence boundary
- GitHub source branch was inspected, and accepted code changes added to PR #258.
- The live Staging runtime was reported HEALTHY by session-safe diagnostics; ready were write authority, current grant snapshot, candidate binding, DB topology, read-your-writes, protocol profile and managed skills. HEALTHY does not certify any specific individual write.
- **Installed plugin:** rc.96, source SHA `0b52e7995f1b317b8947f27083f1c0ab4ce58959`. The new PR functionality is not yet on the installed plugin, and thus no real user could access its new abilities there yet.
- The live `mad4b/operation-discover` index returned only 15 operations for empty search. Query `plugin` and `recovery` returned no matching operations, though the different `mad4b/tool-discover` and `mad4b/write-discover` catalogs explicitly list relevant plugin and recovery Abilities. These are **different catalogs** and this inconsistency is a UX/discoverability concern; it is not proof that the individual abilities cannot work.
- No logged-in WordPress browser, assistive technology, role impersonation, form submission or real MariaDB crash tests occurred. CI on this PR has historically queued; check latest exact HEAD before claiming native PHP pass or acceptance.

## End-to-end operator journey matrix

| Goal | Discover | Plan/prepare | Apply lane | Independent result | Risk / UX interpretation |
| --- | --- | --- | --- | --- | --- |
| Site enrollment and permissions | site-profile-status | write-authority-status, grant reconciliation plan | separately governed enrollment | write-authority-status | Current global permissions are not a per-operation write grant |
| Find remote actions | operation-discover | progressive-requirements-plan, operational-remediation-prepare | exact owning Ability, never generic exec | parity-status or native readback | An operation index is NOT an inventory of every WordPress Ability |
| Forms and plugin integrations | cso/form-catalog, integration-search | form-schema, form-validate | read-only in this CSO01 lane | validation result only | No arbitrary secrets/settings/form submission is implied |
| Brand Core | brand-core-coverage | brand-core-convergence-plan, original provider proof | exact consent + brand-profile-save (or distinct legacy owner transfer) | fresh context/brand-core-coverage and legacy readback | Provider membership, content review and rights ownership differ |
| Content drafts | content-job-list | draft-plan | content-apply-bundle with approval | draft-verify and post readback | Draft persistence != publication permission |
| Workflow automation | workflow-provider-status | workflow-plan, workflow-compile | provider-specific governed executor | signed or stored job result | Compile != executed and no generic auto-write |
| Plugin update | control-plane-update-status | selected-head-plan | selected-head-apply if exact certified ZIP exists | installed source/manifest and rollback readback | Queued CI, unsigned ZIP or stale version never imply readiness |
| Host-free native rehearsal | native-recovery-status | native-recovery-plan | start then cleanup, each separately approved | independent-request readback | Scope is a scratch option, not Host/MariaDB crash certification |
| Diagnose and recover | session-safe-diagnostics | operational-remediation-status, recovery-alternatives | separate executor chosen by exact authority | acceptance-result | HEALTHY != full end-to-end acceptance |

## Confirmed code-level usability issues and remediation on same PR

1. **No simple starting point for tasks.** Added `MAD4B_SCP_Operator_Journeys` read-only discovery with nine fixed task paths, labels, status and next steps. Added `mad4b/operator-journeys` to canonical read catalog; added a `Tasks & Workflows` tab to main MAD4B wp-admin dashboard with expandable task sequences. A missing Ability is explicitly labeled. Even every Ability mounted returns `REGISTERED_NOT_OPERATIONALLY_VERIFIED`, never PASSED.
2. **Governance schema outage could hide the new help tab.** Main wp-admin snapshot now returns a minimal read-only Tasks view without requiring governance schema readiness. This is safe because the task list does not execute writes or read secret data.
3. **Remediation UI was raw Gate/Owner/Executor strings only.** Added concise operator summary, a four-step safe workflow and a Host-free native recovery section with next tool and resumable job ID. Details are progressively disclosed rather than showing nine long task tables by default.
4. **Errors in the remediation diagnostic could cause misleading PHP array access.** Added an explicit `WP_Error`/invalid-response check and safe administrator notice.
5. **Interrupted chat loses the native scratch `job_id`.** Added `mad4b/native-recovery-status` as a read-only resumable session entry, with READY/ACTIVE/EXPIRED/INTEGRITY-BLOCKED states and exact next Ability. The user need not remember IDs.
6. **A second scratch attempts to consume an approval while an earlier one is occupied.** Added an option-presence preflight before `claim_mutation`; denial directs to recovery status and cleanup. It does not unlock or automatically overwrite the scratch.
7. **Cleanup failure was misleading when audit failed after deletion.** Now records a non-mutating cleanup intent *before* deletion and records final success after. If final audit fails the response still reports `scratch_removed=true` with `CLEANED_AUDIT_REPAIR_REQUIRED` and explicit reconciliation. Audit failure before deletion blocks the mutation.
8. **WordPress-native recovery semantics remain strictly limited.** Functional scratch and metadata Shadow are Host-free; no real original Context owner transfer, arbitrary plugin secret editing, MariaDB crash certificate, CI signature or Production write.
9. **Arabic/RTL:** existing CSO search supports Arabic text folding and returns direction metadata; new task labels are WordPress-translatable in wp-admin but no actual visual Arabic/RTL or keyboard/screenreader test was executed. Non-translated machine codes stay LTR.

## Priority blockers or remaining changes

**P0 acceptance:** Get an exact-HEAD certified source package, admit actual native PHP 7.4/8.3 tests, install on Staging and verify every new Ability/route; do not merge based on source existence.

**P1 capability indexing:** Reconcile *operation-discover* and *tool-discover* / *write-discover* so queries like plugin/recovery/forms return explainable complete fallback navigation. The new task index mitigates this but does not fix the underlying catalog synchronization.

**P1 source privilege onboarding:** Test owner OAuth step-up, grant issuance and revoke, approval claim failure, provider reconnect and a second user without admin rights. No privilege widening is authorized as part of this UX patch.

**P1 native recovery browser/session E2E:** Plan probe, approve, start, independently readback in another request, recover after losing the job ID, intentionally expire a scratch with WP-Cron disabled, clean up with separate approval, and compare original Context options. Shadow must prove provider inventory remains unchanged and stale source proof is rejected.

**P1 write forms:** CSO01 read-only schema/validation does not yet cover universal write forms, secret management or content save end-to-end. Provide typed separately governed write adapters, not a generic DB or shell form.

**P1 plugin lifecycle:** Verify update/rollback using a truly signed immutable package, and actual installed source SHA against the selected plan. No CI/rollback receipt was fabricated.

**P2 accessibility and localization:** Real wp-admin mobile/desktop visual checks, keyboard tab order and focus, contrast, notices, Arabic locale/RTL, screen reader, pagination, and responsive tables. Source-level escaping and HTML details are necessary but not sufficient.

## Acceptance checklist

- [ ] Task index visible on main WordPress Control Plane screen and via `mad4b/operator-journeys` in ChatGPT
- [ ] Main Tasks tab remains readable with intentionally failed governance schema (isolated lab only)
- [ ] Nine task journeys display registered/missing vs approved/executed truthfully
- [ ] Unsupported task doesn't create action or approval token
- [ ] Read-only user cannot perform owner-approved writes
- [ ] Native scratch status is discoverable before and after execution and after session interruption
- [ ] Separate MCP request is necessary for native readback PASS
- [ ] Occupied scratch doesn't claim a second approval
- [ ] Expired job can be cleaned up without relying on WP-Cron
- [ ] Audit failure before cleanup prevents removal; failure after removal reports truthful removed state
- [ ] Shadow doesn't mutate original 12 file records and rejects provider or registry drift
- [ ] Exact source package/rollback tested and installed readback matches plan
- [ ] Arabic/RTL, screenreader, keyboard and responsive admin tests pass
- [ ] Content, provider, form and workflow tasks are tested in their respective real adapters

## Test ownership
- `tests/operator-journeys-runtime.php`: pure scenario matrix for nine tasks.
- `tests/native-recovery-runtime.php`: WordPress Options API fixture and interrupted job/approval/refusal conditions.
- `tests/brand-core-remote-recovery-runtime.php`: existing CI-linked PHP fixture embeds task index assertions.
- `tests/brand-core-remote-recovery-contract.py`: existing CI-linked static acceptance contract extends to admin/task and resume UX.
- The large Feature 007 CI workflow is a separate authorization surface; do not claim the standalone fixtures are actually run unless the job records show execution.

No change in this source review is a signed Host receipt, CI PASS, WordPress release certificate, business/IP ownership approval, or full live UX acceptance.
