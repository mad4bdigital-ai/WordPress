# Open PR consolidation: isolated conflict-resolution evidence

Snapshot date: 2026-10-31. Repository: mad4bdigital-ai/WordPress.

The source of truth for the unified branch remains GitHub CI. The following integrations were performed in an isolated VPS worktree and **are not yet published as integrated source**.

| PR | Local integration | Decision | Checks |
|---|---|---|---|
| #653 | merged | Preserve workflow-scoped concurrency; add `persist-credentials: false` | `git diff --check`, ancestor check |
| #650 | merged | Add dynamic JetEngine consumer runtime while retaining the legacy Nile-planning guard `legacy_nile_consumer_mode=planning_only` | PHP lint 3 files; `git diff --check`; ancestor check |

Local integration HEAD after both merges: `24aff1af0ba`.

These changes require a GitHub source reimplementation/review and complete integration CI. No production deployment, no master write, and no force push occurred. GitHub Actions publishing workflow changes requires a properly authorized identity; do not bypass that permission.

The open PR count was 63 at this snapshot. Additional conflicting PRs remain, including #658/#671 and an unrelated-history #655. Do not mark this integration merge-ready before all current PR heads and checks pass.
