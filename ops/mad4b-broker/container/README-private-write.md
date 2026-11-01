# Permanent Staging container write lane — bounded review records

## Purpose

This lane is the **default for nonprivileged, review-only source-parity
metadata writes** on `tours.dev.mad4b.com`. The durable output is a private,
non-web-served directory on the VPS. Its contents are review proposals, **not**
GitHub commits, signed grants, runnable Shell, WordPress posts, or deployable
plugins.

This is **not** an alternative channel for a source-code edit that the GitHub
integration rejected. In particular, no process in this lane may install a
MU plugin, change `wp-config.php`, edit the privileged Native Operator,
modify a systemd unit, upload protected code or push to GitHub.

## Runtime

The authorized operator can use the reviewed, installed wrapper:

`/opt/staging/mad4b-container-parity-20261101/run-private-parity-review.sh`

It produces the five-item source-parity manifest through fixed-path read-only
checks. The actual output write happens **inside an isolated container**,
as UID/GID `33:33`, to the only writable bind mount:

`/opt/staging/mad4b-container-parity-20261101/proposals`

Container controls: `--read-only`, `--network none`, `--cap-drop ALL`,
`--security-opt no-new-privileges`, 64 PIDs, 128 MiB memory, and no Docker
socket, private key, WordPress, host root filesystem or Git checkout mounts.

An operator starts the container using the host's authorized Docker control;
**that host permission is not passed into the process**. The only writer
inside the container is the `www-data` non-root UID 33. Root is used only to
perform fixed-path digest reads and launch the sandbox; it is never the
writer of the proposal record.

The wrapper verifies its installed PHP code against the matching GitHub
remote-tracking source before accepting an input. Proposals are created
exclusively at `0600`, never overwritten, and contain no secrets or source
bytes. Duplicate, production, unauthorized path, extra-field and command
requests are denied.

## Tested Staging acceptance — 2026-11-01

- Original source head at first positive write:
  `e783639ce5b8de28da4911c7199614ee9269a377`
- Private first proposal digest:
  `60732ded929b8a494dd8018589bbcfc3c2762b5f1920494413f64e243b4c4c05`
- Installed reusable wrapper positive result at head
  `2236c069f9dea1960e1fc221bb2fe4a05e60b126`:
  `PRIVATE_REVIEW_PROPOSAL_SAVED`
- Negative tests: `production`, `extra-operation`,
  `unlisted-path`, `duplicate` all **DENY**.
- No Production mutation, no WordPress mutation, no privileged source write,
  and no Shell execution.

## Release and review requirements

PR #687 remains Draft until an authorized repository maintainer reviews and
accepts the **five privileged-source files**. The source-parity CI check
must remain a blocking check until those reviewed files exist in GitHub with
the expected, independently checked SHA256 digests. Container review
proposals do not substitute for that approval or turn a declined GitHub
write into an allowed one.

A separate authorized action would be required to extend this lane from
review metadata to an actual application write operation. Such an action
must bind exact capabilities, principal identity, scope, and rollback
and must be reviewed **without** using a denied write as a fallback.
