# Host v144: VPS-to-enrolled-host SSH-only Remote Repair transport

## Accepted operator requirement

The user wants hosting connection and remote repair machine credentials to
exist only on MAD4B Central VPS and linked hosting accounts, over SSH.
They do not want to connect their personal Desktop Commander or register
an Owner device key merely to repair or operate company hosting.

This changes connection and remote repair transport. It does NOT convert
an SSH session into a human Owner signature, Original305/312 approval,
or independent Original313 cryptographic revision witness. Original318 v3
remains MISSING_OR_INVALID until valid first-party signed issuance.
A new SSH-only approval model requires its own versioned signed authority
migration and first-party admission. Simply setting write_authorized
to true would forge trust and is not implemented.

## Reuse existing Root credentials

The existing first-party broker-v1/registry_ssh_adapter.py is source-pinned
and recognizes hosting-1 and hosting-3 with individually pinned host keys.
The existing hosting-2 Root-only credential and
external-site-ssh-inventory.v1.json are pinned in the new helper; hosting-2
is not silently added to Original312's Broker grant allowlist.

Actual fixed SSH diagnostic results at creation:
- hosting-1: authenticated; company mapping not established in this
  inventory, so no company write grant is inferred
- hosting-2: All Royal Egypt Staging; pinned SSH authenticated
- hosting-3: Egypt Tour Gates Staging; pinned SSH authenticated

No new private keys are generated. SSH always uses BatchMode, IdentitiesOnly,
StrictHostKeyChecking=yes, fixed known_hosts, and no proxy/forwarding.
Changing a pinned host key causes a hard trust failure, not TOFU.

## Remote repair classification

A new non-mutating Root helper:
ops/host-authority-v144/vps_host_ssh_repair.py

Modes: local, probe, publish, plan --target ... --problem ...
All modes use only a fixed, read-only remote diagnostic. Plan mode routes
SSH identity errors, WP runtime/source-release faults and missing Original305,
Original312, Original313 and Original318 trust certificates to the matching
first-party subsystem.

Any write must still be authorized by a genuine Original312 exact broker
grant with a registered company/site, backup, rollback, live readback and
the Original313 predecessor required by v3. SSH does not grant Write
automatically and this helper has no arbitrary shell execution endpoint.

Publishing writes one Root-private latest.json file (0600 inside 0700).
A sandboxed systemd oneshot/timer checks transport every 30 minutes,
offset from the existing Host v142 readiness timer to reduce contention.

## Owner experience

No Desktop Commander or Owner workstation is required for VPS-to-hosting
SSH connection or remote repair under already authorized grants.
Earlier v143 device signer remains source-only and is NOT invoked,
required or installed by this SSH repair path.

The current Original305 v3 exact Owner signature remains a distinct trust
requirement until a legitimate first-party versioned protocol migration.
The VPS SSH transport identity cannot impersonate the human Owner.

## Rollout and rollback

Deploy v144 helper and its two new Root systemd units with exact Git pin.
No WordPress, Production, Original305 HOLD, Original313 archives or signing
credentials change. Rollback disables the v144 timer and removes its
exact prefixed files only.
