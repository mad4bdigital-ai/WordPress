# Company + environment Portfolio readback (Staging)

This candidate adds a **non-authorizing**, central-Staging-only WordPress readback of the real signed V4 target and a separately namespaced environment-specific Portfolio assignment.

It deliberately does **not** copy a Staging Site Profile into the legacy Production Portfolio company registry, issue a 305 grant, register transport, or execute shell/WP-CLI. A verified Site Profile means **identity.read**, not **operations.write**.

## Source of truth

- Site and environment: `MAD4B_Company_Environment_Registry_V2::target(company, "staging")`
- Signed trust, independently verified: `MAD4B_Environment_Signed_Identity_V4::status(company, "staging")`
- Portfolio manager identity: `MAD4B_Company_Operation_Admission_V1::manager_id()`
- Separate, non-production assignment readback: `mad4b_company_environment_manager_assignments_v1`
- Binding digest: SHA256(company | staging | site UUID | canonical origin | profile digest)

An assignment becomes *visible as approved* only if it is bound to those exact values, in the `active` state, has `operations_manage`, and has matching exact proposal/approval digests. This is **read-only evidence**, not owner consent issuance or a substitute for the original 305 grant/transport contracts.

## Scope and next stage

The existing 305 original intake still requires independently enrolled owner, company, Site, Transport and original delegation. Do not replace it with this readback. The remote-hosting path for `staging.allroyalegypt.com` needs an original native transport provider; it is **not** a container on the central VPS.

Tests use synthetic fixtures; do not claim a real customer Native WP-CLI Job is certified.

Run:

```sh
php -l ops/mad4b-environment-portfolio-evidence-v1/316-mad4b-company-environment-portfolio-evidence.php
php ops/mad4b-environment-portfolio-evidence-v1/tests/portfolio_readback_hermetic.php
```

For any Staging deployment, preserve the current live MU plugin tree, separately plan and approve the release, keep exact SHA-256 backup/rollback, verify site identity, and do not change Production.
