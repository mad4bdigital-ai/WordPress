# PR #687: privileged runtime source-parity review

**Status:** Staging acceptance PASS, canonical Git source-parity **BLOCKED**, PR must remain Draft.
**Environment:** `https://tours.dev.mad4b.com` / local `srv2030908`. **No Production mutation.**

## Verified original central receipts

| Staging canary | WordPress Job | Result |
| --- | --- | --- |
| Direct root SSH orchestration | `6ea95165c9890df12cf04d709d911cf1` | `GENERAL_SHELL_EXECUTION_READBACK_VERIFIED` |
| Headless `systemd` unit | `aee4cc47ef81495fb3733118a2cf7ffb` | `GENERAL_SHELL_EXECUTION_READBACK_VERIFIED` |

Both jobs have `executed=true`, `independent_readback_verified=true`, and an original signed `314` receipt. Reusing the direct canary's correlation was denied. The Staging `mad4b-native-central-dispatch.timer` is enabled/active, and the original direct SSH fallback passed its original status check.

## Source parity gate: FAIL

Latest source commit when audited: `eaee0141a6807b3f8bb43b7dfc5fb7c76e22564d` (10/10 GitHub checks PASS, but **they do not cover the unuploaded live code**).

| Git path | Live SHA256 | Gap |
| --- | --- | --- |
| `ops/company-readiness-workflow/executor/vps-native-operator.py` | `1bd0f161708a5400e504f7532662b85084158385ffabc40369f2e0c7b98dd299` | Git version still only accepts independent root SSH; tested Staging also accepts an exact, root-owned `systemd` service identity |
| `ops/mad4b-broker/vps-native-central-auto-dispatch.py` | `34466f2c253ddbc421010394a39c37a08efbb9bbcb4d50ef972c0151d5609baa` | Matching Staging `systemd` principal support not yet in Git |
| `ops/mad4b-broker/vps-native-central-dispatch-worker.py` | `57b51219ee384b756744794e000cbd696e400d46870f4f02cdd4ac2d3df8f1e4` | New root-only bounded inbox worker absent from Git |
| `ops/mad4b-broker/systemd/mad4b-native-central-dispatch.service` | `228070be9d345c51e29804b34e96968e72383adb0d15fac31ac63c686756912f` | New service unit absent from Git |
| `ops/mad4b-broker/systemd/mad4b-native-central-dispatch.timer` | `617c31282afe4f33c406330de436fe99e185a3da5f54415edeec46e481fcba46` | New timer unit absent from Git |

**Do not merge, advertise release parity, or deploy to Production based on CI alone.**

## Required authorized code-review sequence

1. Review the exact Staging files listed above through the approved repository-maintainer workflow. Preserve the original independent root-SSH path; never impersonate a WordPress user.
2. Apply only the reviewed exact-service-identity changes and root-owned bounded worker/service/timer to the PR branch using GitHub's ordinary repository governance, with no force push.
3. Verify tests for: exact Staging origin, original `305 → 306 → 312 → Native Operator → 314` chain, per-command digest, root ownership, replay denial, service identity validation, parking uncertain outcomes, and independent root-SSH fallback.
4. Confirm all five Git SHA256 values match the tested Staging files; run full CI on the **new** head; then review and merge via branch protection.
5. Do not use a separate shell transport to work around a rejected GitHub source-write operation.

## Why this handoff is open

The connected GitHub tool rejected updates to the privileged root-service authorization logic and separately rejected creation of the privileged inbox worker. No alternative Git upload route was used. This is a **source review/authorization** blocker, not a failure of the already-observed live Staging canaries. This report is documentation-only and grants no new authority.

Operator acceptance: `/opt/staging/mad4b-native-full-dispatch-20261101/ACCEPTANCE.json`.
