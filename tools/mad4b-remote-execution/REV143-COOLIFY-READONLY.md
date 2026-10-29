# REV143 — Coolify read-only Staging media inventory

This operator tool checks whether the existing MAD4B Commerce Staging and QA02
resources are **visible to the authorized Coolify context**. It does not install
Coolify, change any resource, read environment secrets, run Docker commands, or
enable Shared Media Hub. A successful result means INVENTORY_ONLY, never
ready to deploy.

## Verified Coolify CLI commands

Use the officially supported Coolify CLI with an already-authorized context:

~~~bash
coolify context verify
coolify resource list --format json
coolify app list --format json
coolify service list --format json
coolify server list --format json
~~~

Installed CLI help is authoritative:
~~~bash
coolify resource list --help
coolify app list --help
~~~

The preflight runs only these five commands with argument arrays; commands such
as deployment, environment-variable listing, restart, key retrieval and config
mutation are rejected. Errors are reported without copying CLI stdout/stderr.

~~~bash
python3 tools/mad4b-remote-execution/coolify_readonly_inventory.py \
  --host tours.dev.mad4b.com \
  --host qa02.example.com \
  --output rev143-coolify-inventory.json
~~~

Replace the illustrative QA02 hostname **only after** an actual public HTTPS
hostname has been registered and verified. No local/IP hostname qualifies.

## Test

~~~bash
python3 -m unittest discover \
  -s tools/mad4b-remote-execution \
  -p 'test_coolify_readonly_inventory.py' -v
~~~

## What the result proves

INVENTORY_ONLY: the supplied hostnames appear exactly in one or more
Coolify resources, using the active context's read access.

BLOCKED: CLI missing, context unverified, required inventory failed, or a
specified hostname not found. Being absent from Coolify does **not** prove
the Docker workload is absent: existing MAD4B Staging containers may have been
installed outside Coolify. Do not recreate/replace them on that basis.

## Separate activation gates

Coolify inventories cannot certify the canonical publisher key or issuer
enrollment, immutable content-addressed object storage, exact tenant-only
read-only catalog mounts, signer rights, browser-owner review, CDN revocation,
or real two-domain image delivery. Keep the shared-media flag disabled until
those gates are independently certified against the actual tenant containers.

The source candidate is REV142, and remains offline-only pending an approved
Staging deployment. Production and All Royal Egypt are out of scope.

Official CLI documentation: https://coolify.io/docs/cli/what-is-the-coolify-cli
