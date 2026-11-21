# MAD4B Workspace Lifecycle — safe server Git worktree maintenance

Purpose: prevent repeated gigabyte-scale checkout accumulation without losing
unmerged company source work. Infrastructure tool only, no WordPress or company
database modifications, Host execution permissions or GitHub merge changes.

The VPS tool runs under:
  /opt/mad4b-company-provisioning/ops/workspace-lifecycle/
This source copy is bit-for-bit identical and reviewed through a Draft PR.
The deployed policy.json is derived from policy.example.json.

Cron: /etc/cron.d/mad4b-workspace-lifecycle daily 02:23 UTC with flock,
nice, ionice and eight weeks of log rotation.

Four explicitly enrolled local Git repositories are inventoried. Main Git
roots and the central reviewed Source worktree are permanently protected.
Any checkout outside the approved filesystem prefixes is held.

Before parking, the manager verifies: HEAD unchanged, no Git lock, >=14 days
checkout inactivity, no referring process, no staged/unstaged/untracked files,
no ignored files, immutable refs/parked/lifecycle/YYYYMMDD recovery ref.
It uses git worktree remove WITHOUT force, at most 4 checkouts per run, and
skips execution under 1-minute load above 8.

The manager never auto-stashes dirty code, deletes Git objects, runs
git clean, purges Docker, touches live WordPress or bypasses access controls.
Dirty checkouts are reported and held. Older modified worktrees in the 2026-11-21
manual cleanup have independent archive and stash receipts.

Read-only:
  python3 workspace_lifecycle.py --audit

Executable controlled cycle:
  flock -n /var/lock/mad4b-workspace-lifecycle.lock python3 workspace_lifecycle.py --execute

Hermetic test:
  PYTHONDONTWRITEBYTECODE=1 python3 test_workspace_lifecycle.py

The test makes an isolated temporary Git repo, proves dirty-source denial,
parks only a clean checkout and recreates it from the recovery ref.

To disable future automated cleanup, disable the single cron entry while
retaining all refs/parked/* and recovery archives. Restoring a suspended
checkout uses git worktree add --detach NEW_EMPTY_DIR RECOVERY_REF.
The logs latest-audit.json and events.jsonl are restricted on the host.
This Draft review is NOT a WordPress production release or Host authority.

Disk capacity is also reported on every audit cycle. The installed policy
uses 75% / 85% warning/critical usage thresholds; only when the threshold
is crossed during an executable run does the manager write a durable local
alert receipt and send a daemon.warning event to the host system journal.
No outbound mail or webhook and no destructive cache or volume purge is
triggered by these alerts.
