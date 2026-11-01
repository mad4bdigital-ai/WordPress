# Local Git release preflight handoff — 2026-10-31

Target: tours.dev.mad4b.com; Staging only.

## New source workflow

- `source_drift_guard.py`: read-only exact installed source/manifest and reviewed change-set check.
- `control_plane_release_preflight.py`: independent source, grant, scope, executor and rollback evidence; **not** an operation authorization.
- `guarded_native_release.py`: one Staging Site UUID lock through source inspection, independently admitted original Native Operator single execution attempt, original readback and post-install exact manifest check.
- The lock is a cooperating release-executor contract, not a global WordPress filesystem lock. Concurrent direct edits by other code remain detectable drift; those writers must be enrolled into the same release pipeline to prevent races.
- Tests: 93/93 passed in local Git Worktree, including 11 drift tests, 18 native evidence tests, and 8 guarded-release tests. CI now runs them.

## Live observation: release source drift

Snapshot inspected from the enrolled Staging WordPress container returned:

```json
{"contract":"mad4b.release-source-drift-preflight.v1","state":"BLOCKED","reason":"RUNTIME_FILE_BYTES_DRIFT:includes/class-mad4b-scp-content-readiness-alerts.php","read_only":true,"production_mutation":false}
```

Installed manifest source: `202e62e9a480b5144af1c6ae1517f3d8df6a92fb`. The live Content Readiness Alerts code gained support for `loc_style_<language>` in its permitted localization alert-code list without a new canonical build/manifest. Preserve this live source as a Review candidate; never silently rewrite the pinned source hash, delete the change, or certify the installed package until a reviewed canonical rebuild and deployment with readback.

## Still open

- This source-only guarded wrapper is not yet attached to the active original Staging Native Release Executor. An actual authorized exact `control_plane.release.apply` needs its real signed operation grant, enrolled executor, rollback, and independent readback. Test doubles do **not** establish permission for deployment.
- The wider Full Authority `execute`, `verify`, and `resume` phases remain `not_executed` in the original lifecycle status until actual original receipts exist.
- Company Production rollout requires each company's own authority and compatibility/readback. No Production mutation was performed.
- GitHub Release Verdict currently requires an authorized owner attestation for the current exact PR head; do not self-attest or forge an approval.
