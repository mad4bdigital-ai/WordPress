# MAD4B VPS Broker — local dispatch / claim bridge

This is **one integration** into the existing `mad4b-dispatch-v1.service` and timer, not a second standalone dispatcher.

The Central Staging WordPress MU plugins are:
- `304-mad4b-central-dual-lane-registry.php` — status of both native execution lanes
- `305-mad4b-central-ssh-command-intake.php` — revocable one-time and persistent company operation grants and command-hash intake
- `306-mad4b-central-vps-broker.php` — authenticated browser dispatch and Ed25519-signed VPS claim

The host-owned broker poller is `vps-broker-poller.py`; its root-owned directory is
`/opt/mad4b-company-transport/broker-v1`. The Ed25519 private key **never**
moves into WordPress. Only its base64 public key, exact host identity and signed
heartbeat are registered centrally. The host configuration lists eligible company
identities; default deny for any company not explicitly enrolled.

## States

`ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY` →
`QUEUED_FOR_VPS_BROKER_CLAIM` →
`BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY`.

The poller verifies the company UUID/environment/host, signs claims and journals
the result. It **does not execute shell commands** from the WordPress queue.
It does not create SSH credentials, issue OS-level privileges, overwrite native
WordPress grants or elevate security-denied semantic jobs.

Host-executed work requires a **separate** existing SSH/host identity, exact
operator command bytes matching the recorded SHA-256, an operation-scoped
authority and original-executor receipt, plus independent readback and rollback
for writes. The prior `central_governed_dispatch.mjs` implements this policy
with an injected host transport; its tests use mocks and are not a claim of
live SSH execution.

A persistent grant can be reused across distinct correlation IDs until revoked.
Every command still requires a fresh host security check, and grant revocation
is allowed even if the company manager delegation has already been suspended.

## Verification

```bash
python3 /opt/mad4b-company-transport/broker-v1/vps-broker-poller.py selftest
systemctl is-active mad4b-dispatch-v1.timer
systemctl show mad4b-dispatch-v1.service -p Result
docker exec -u www-data etg-commerce-staging-php wp --path=/var/www/html --skip-themes --user=1 mad4b broker pending --limit=10
```

An empty claim queue means **no live Claim execution was proven**. A verified signed
heartbeat proves the broker-to-WordPress cryptographic control channel only.

## Rollback

Remove `/etc/systemd/system/mad4b-dispatch-v1.service.d/50-vps-broker-claim.conf`
and reload systemd; this leaves the original Dispatch V1 service and timer intact.
Restore versioned Staging MU backups and independently rotate the broker signing
key if necessary. Never move the root-owned private key into WordPress or GitHub.
