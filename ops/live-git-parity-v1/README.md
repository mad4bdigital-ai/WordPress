# MAD4B Live-Aware Git Parity (root-side, read-only)

This observer is **not a release authority**, a replacement for the signed Original312/313 chain, a permission grant, a Git synchronization process, or a WordPress plugin.

## Source policy

- **Live WordPress bytes** are the mandatory factual baseline for reconciliation. Root-only SHA-addressed snapshots retain the actual bytes observed from the site.
- **The reviewed exact Git commit** remains the desired source for authoring/publishing *after* reconciliation and an independently approved signed release.
- The comparison SHA in \`/etc/mad4b/live-git-parity.v1.json\` is **diagnostic only**. It is not an owner attestation or approved release.
- An existing \`company.code.release\` plan remains protected by its exact expected live preimage (CAS) and just-in-time recheck. Diverged files must not be silently overwritten or automatically promoted to Git.
- **Do not change** \`/etc/mad4b/company-code-release-policy.json\` \`source_git_root\` merely to unify directories: existing signed and pending plans bind that old path. Retire/reconcile old plans first, then migrate this executor's source root under an explicit signed release.
- Never automatically copy a live file over a newer reviewed Git file. Reconcile with a three-way comparison and preserve both variants until reviewed.

## Runtime

Installed observer: \`/opt/mad4b-company-transport/live-git-parity-v1/check.py\`

Config: \`/etc/mad4b/live-git-parity.v1.json\` (0600, root-only)

Report: \`/opt/mad4b-company-provisioning/reports/live-git-parity-v1/latest.json\` (0600, root-only)

Snapshots: \`.../live-git-parity-v1/snapshots/<scope>/<SHA256>.php-or-asset\`

Timer: \`mad4b-live-git-parity.timer\`, every ~15 minutes. This is a **Host filesystem observer**; do not run it as WP-Cron because WordPress must not gain root Host filesystem authority.

\`MATCH\` means same bytes, \`DIVERGED_HOLD\` means review required, \`SOURCE_MISSING\` means a live file is not at the comparison Git commit, and \`LIVE_MISSING\` means a source file is missing on the site.

The observer captures and reports facts. It **does not block a separately authorized native release process by itself**. The native release engine enforces CAS; an explicit preflight policy is still necessary to mandate three-way conflict review before authorizing a new plan.

## Tests and rollback

Run: \`python3 -m unittest ops/live-git-parity-v1/test_check.py\`

Service check: \`systemctl status mad4b-live-git-parity.service mad4b-live-git-parity.timer\`

Rollback only the observer: \`systemctl disable --now mad4b-live-git-parity.timer\`. Preserve snapshot and report evidence. No WordPress file or database restoration is needed because the observer never mutates them.
