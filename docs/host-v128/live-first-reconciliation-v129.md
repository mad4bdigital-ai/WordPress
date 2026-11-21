# Host v129 — Live-First, signed Desired, selective reconciliation

**Authority split:** `current` answers what is actually installed. `desired`
answers what was independently approved for this exact site. `candidate`
is an uninstalled Git/code proposal. `previous` is a sealed, signed Host
Runner rollback backup, not just a filename, manifest or historical digest.

These concepts deliberately **do not collapse into one another**. In
particular, a live hotfix is not automatically trusted as global source; and a
Git branch is not by itself an approved release or site write permission.

## Data and provenance

| Role | Required authentic source | Can perform writes? |
| --- | --- | --- |
| `current` | Fresh signed exact-site Host Runner observation with file hashes and nonce | No |
| `desired` | Owner/approved-source Ed25519 signature plus fresh, **independently signed** desired-registry high-water | No |
| `candidate` | Exact Git source head plus file hashes equal to the signed Desired record; bytes must still be verified by executor | No |
| `previous` | Real Host Runner private preimage snapshot with signed seal, hashes, and exact group/site/current binding | No |
| executor | Separate site-specific authorized operation and final commit-boundary CAS + rollback | Only under its existing approvals |

The independent desired-registry writer must enforce monotonic revisions and
record revocation. The read-only `verify_desired` accepts the latest
independently signed checkpoint only. Its public keys must come from the
first-party trusted fleet registry, **never client-supplied pubkeys**. Missing
or stale high-water fails closed, even if an older desired signature verifies.

The `desired` record signs: company/environment/site/host, Host key and
policy hashes, revision and source commit, approved base file hashes, desired
file hashes, and named atomic deployment groups. Every file is in exactly one
group. Source accepts only MAD4B code files, not wp-config, site credentials,
uploads, third-party plugins, private keys or arbitrary host paths.

## State machine, not a binary Git-vs-live comparison

Each independently approved atomic group is classified:

- `IN_SYNC`: installed live hashes already equal Desired.
- `EXACT_GROUP_CHANGE_PENDING_AUTHORIZATION`: live hashes still equal the
  previously approved baseline, and exact Candidate equals Desired; an
  authorized executor may later consider the group.
- `PRESERVE_LIVE_DRIFT_REVIEW`: one or more live files diverged from both
  the approved baseline and Desired. Preserve all files in this group.
  Generate a review proposal so the live hotfix can be assessed, tested,
  and integrated into Git/Desired via a new separate approval.
- `CANDIDATE_NOT_APPROVED`: Candidate head or content does not match
  the independently signed Desired. Do not schedule any install.

A conflict in one group does **not** erase independent non-conflicting
groups. However each group is atomic: for example, the two Original318 v3
PHP files must not be partially installed independently.

`all_desired_realized` is a comparison of live Current with signed Desired;
it does not imply a successful or authorized installation, and does not
automatically promote Desired or Git.

## Controlled write preparation

Source function `prepare_atomic_group_operation` requires:

1. Signed Current and independent signed Desired/high-water.
2. Candidate equal to Desired head and file manifest.
3. A sealed, verified, independently signed Host Runner private backup of
   the **exact Current preimage** for the selected atomic group.
4. A **new** signed Current site readback, with a different nonce,
   equal file hashes, and a valid site identity.
5. The same atomic group still eligible after fresh reconciliation.

It returns a **non-authorizing proposal**, including exact digests, group,
backup receipt and `executor_must_recheck_live_preimage_at_commit=true`.
The Root/Host executor must still verify its separate owner/operation grant,
compare preimages **at the actual mutation boundary**, apply atomically,
issue completion receipt, and re-read the target after deployment. If any
condition fails, the executor must preserve the installed source and
restore or hold its previous state; no blind retries.

The operation ID `mad4b.host-v129.atomic-group-install` is a **required
future executor contract**, not a registered or live enabled ability. This
PR adds *no command to execute that operation* and does not activate Host
Write, WordPress or Production. The installed Host Runner currently has
older, separately governed `wordpress_plugin_deploy` paths; they are not
silently reused for an unapproved MU-plugin update.

## Deployment topology for the MAD4B fleet

**Central Tours.dev:** read live Current from Tours.dev, review against its
approved Desired, promote reviewed fixes to a new signed Desired source via
the independent approval route. A change on Tours.dev can be proposed for
other companies, but not copied with its site UUID, host key or options.

**Company target:** independently read target Current, bind target-specific
Desired and backup, and reconcile; block conflicting groups without
clobbering company-specific changes. Updates to the global template do not
authorize changes to per-company configuration or data.

**Site profile enforcement:** Source may be live-first, but the actual
authority to publish still comes from the enrolled exact-site Host Runner,
owner/operator grants, and the installed WordPress readback.

## Status and acceptance boundary

- Implemented: source-only pure verification and reconciliation contracts;
  repeatable signed Ed25519 fixtures, conflicts and replay-denial tests.
- Not yet implemented: independent desired-registry writer/migration, new
  atomic MU-group executor, authenticated multi-file Current and backup
  receipts on every target, or live Staging installation.
- No assertion that PR merge == WordPress activation or that unsigned
  Tours.dev file observations authorize remote publication.
- Existing manual-Staging artifact qualification `optional` does not
  change Host security, signed approvals, CAS/rollback or Production policy.
