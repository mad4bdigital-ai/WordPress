# Admin confirmation reload and persisted-state readback — 2026-10-17

## Observed source defect (Staging Write Owner Review)

The prior `MAD4B_SCP_Staging_Write_Owner_Intent::post()` successfully persisted and read back a signed, plan-bound intent, then redirected to `tools.php?page=mad4b-staging-write-owner-review&intent=recorded`. However, its GET page ignored the `intent` query AND rendered the exact same confirmation form unconditionally. It also evaluated a fresh convergence plan before displaying any valid old receipt. Thus successful confirmation could look indistinguishable from a static unchanged screen, or could be hidden by a new plan blocker.

The new GET presenter derives **signed post-action state** from the saved owner intent and exact current reviewed plan: `OWNER_INTENT_CURRENT`, `OWNER_INTENT_CLAIMED`, `OWNER_INTENT_EXPIRED`, `OWNER_INTENT_PLAN_CHANGED`, or `NO_VERIFIED_OWNER_INTENT`. It validates the site-secret HMAC, current enrolled owner, plan digest, issuance/expiry, and one-time claim status. Neither query parameters nor POST success alone certify the action. When active/claimed, the page shows a distinct result and does not repeat the input form. After an error, it shows the previously confirmed state as an advisory, not authority. The next-step link targets the exact **Operational Remediation** workspace instead of incorrectly returning to the generic Overview.

The PRG response uses **303**, not a plain 302, and performs a fresh signed readback before redirect. Site-local WordPress owner attestation remains **intent only**, not a write ticket, actual grant reconciliation, or a production approval.

## Cross-screen source audit

| Admin surface | Present mechanism | Remaining live check |
|---|---|---|
| Staging Write Owner Review | **Fixed**: signed current/claimed/expired/plan-changed view and no duplicate form | Confirm in authenticated ETG/ARE wp-admin with actual POST and fresh GET |
| Site Profile | Existing AJAX save checks durable profile digest/revision and replaces workspace with a no-store GET; native POST verifies readback | Confirm page uses AJAX on both hosts; inspect stale cached scripts or native-fallback behavior |
| Browser Acceptance Setup | Existing CAS revision and persisted option validation; 303 + actor-bound signed notice receipt | Verify new external browser setup state remains visibly distinct from provider registration |
| Approval Decisions | Existing ticket-bound nonce and signed notice with current candidate digest; separate Actionable/History pages | Verify a newly completed ticket moves to History; do not treat page notice as a mutation execution |
| Context Authority / Managed Skills | Their own readback flows apply; cannot infer an update from a confirmation checkbox alone | Live check the specific selected pages after deployment |

## Negative tests

- Correct signed active receipt: present persistent success state and no confirmation form.
- Correct claimed receipt: show consumed state, never repeat the form or silently grant permissions.
- Expired, tampered, wrong owner or wrong plan: no valid success state.
- Forged `?intent=recorded` query without a valid signed record: never displays success.
- One-time owner claim replay is refused and separately audited.
- No automatic grant, permission widening, installation, Developer or Breakglass paths.

These are PHP hermetic tests and static source audits, **not Staging browser acceptance**. Prior to merging/deploying, complete exact-head CI, verify available release identity, then obtain actual ETG and All Royal Egypt browser and post-action persistence readbacks. Production remains unchanged.
