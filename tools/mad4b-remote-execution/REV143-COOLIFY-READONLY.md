# REV143 — Coolify read-only Staging media inventory

This operator tool checks whether the existing MAD4B Commerce Staging and QA02
resources are **visible to the authorized Coolify context**. It does not install
Coolify, change any resource, read environment secrets, run Docker commands, or
enable Shared Media Hub. A successful result means INVENTORY_ONLY, never
ready to deploy.

## Verified Coolify CLI commands

Use the officially supported Coolify CLI with an already-authorized context:

~~~bash
coolify --context mad4b-production context verify
coolify --context mad4b-production resource list --format json
coolify --context mad4b-production app list --format json
coolify --context mad4b-production service list --format json
coolify --context mad4b-production server list --format json
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
  --context mad4b-production \
  --host tours.dev.mad4b.com \
  --output rev143-coolify-inventory.json
~~~

Add `--host <real-public-qa02-hostname>` only after a real public QA02
DNS name is registered and verified. The QA02 workload is currently
an on-host Docker deployment, not proof of a public Coolify registration.
No loopback/IP hostname qualifies.

**Observed VPS read-only evidence (2026-10-29):** the default Coolify
CLI context was `cloud` and returned HTTP 401; the explicitly named
`mad4b-production` context verified successfully. CLI v1.10.0
`resource list`, `app list`, and `service list` returned no
`fqdn`/`domain`/`domains` metadata in any row. When hostname
matching cannot be performed, the tool now reports
`COOLIFY_LIST_API_OMITS_DOMAINS`, **not** an absent tenant or a valid
activation certificate. Separately, the VPS had running
`mad4b-tenant-qa02-php` and `etg-commerce-staging-php` containers.
No Shared Media issuer/mount evidence was established by this CLI test.

## Test

~~~bash
python3 -m unittest discover \
  -s tools/mad4b-remote-execution \
  -p 'test_coolify_readonly_inventory.py' -v
~~~

## What the result proves

INVENTORY_ONLY: the supplied hostnames appear exactly in one or more
Coolify resources, using the active context's read access.

BLOCKED: CLI missing, context unverified, required inventory failed,
list APIs omit hostname metadata, or a specified hostname cannot be found
when such metadata is available. Being absent from Coolify does **not** prove
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
