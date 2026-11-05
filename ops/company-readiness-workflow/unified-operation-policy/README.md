# MAD4B Unified Operation Policy — source review candidate

**Scope:** source-only portable snapshot of the VPS Staging implementation as of 2026-11-01. This PR is NOT a production deployment, native executor certification, or authorization of a company operation.

## Problem and fix

The older Universal Resolver stores `operations` for specific SSH / release-observer transport capabilities. ETG Staging's read-only observer has `operations: []`; ARE Staging has legacy scoped SSH capabilities. Neither value is an Original Core authorization decision for `plugin.install` or `content.write`. Treating them as one policy leads to false denials or false grant claims.

`company-unified-operation-policy.py` projects company+environment+site identity, per-operation decisions from the live original WordPress Core, original 305 transport/grant readback, and the independent legacy transport observations. It explicitly cannot create grants, execute jobs, or certify an original 313 receipt.

The mirrored `universal-runtime/resolver.py` changes the transport field semantics, supports generic exact-site registered read-only observers, and exposes the core/transport separation in the read-only status UI. The former ETG-specific observer dispatch is no longer required.

`test_resolver.py` corrects a pre-existing test counting every open review: the identity-resolution assertion now queries exactly the identity review, independently of the site-binding review. The prior broad test failed even against pre-change resolver source.

## VPS acceptance evidence

- Connected VPS source: 49/49 tests PASS for `test_resolver.py`, `test_multi_transport_authority.py`, `test_etg_central_integration.py`, and `test_etg_staging_release_observer.py`.
- Unified operation-policy isolated selftest: 10/10 PASS.
- 5 companies and 7 targets in live read-only snapshot; original 305 active transport/grant pair is not inferred from observer status.
- systemd unit `mad4b-company-unified-operation-policy.service` first invocation PASS; timer enabled and active.
- No production writes, no ETG Signed V4 install, and no owner grant/312/313 certification.

## Source layout and limitations

Files under `universal-runtime` are the **actual VPS Staging source snapshot** (including imported supporting modules and regression tests), **not** the canonical packaged WordPress plugin. They depend on site-local paths, original Core, registered SSH and external runtime data. The separate GitHub Actions workflow is deliberately hermetic: compilation and source-contract tests only. The full 49-test live acceptance is NOT claimed as a hosted CI result.

Reviewers must reconcile these source files with the release-owned executable architecture before merging or shipping a package. Keep existing WordPress plugin source and all Production environments unchanged until separate build, governed apply, and independent readback gates pass.

## Authority boundaries

1. Host/SSH reachability is transport authentication, never a WordPress actor or grant.
2. Original Core decides `company + environment + site UUID + operation`.
3. Any native shell application requires an independently authorized executor and original grants/receipts (305 → 306/312 → 313).
4. Empty read-only observer `operations` does not make an entire company globally read-only, and nonempty legacy `operations` does not authorize company writes.

## Full Host Authority — general capability template (increment 1)

The new `full-host-capability-catalog.json` declares the default **discoverable**
host, WordPress, SQL, native-executor and Broker/MCP/SSH-fallback capability
families for **every** company site, both Staging and Production. No ETG,
ARE, central-origin or company-specific host scope appears in this template.

The original read-only `company-unified-operation-policy.py` report now emits
a `full_host_authority` preview for **each original Core enrolled target**,
with exact company, site UUID, canonical origin and environment, plus a common
13-family capability menu. A new site becomes eligible for this same template
without a bespoke per-company source edit.

**Important trust separation:** Default capability discovery is not credential
distribution, grant issuance or proof of a full-host executor. The template
requires an enrolled exact host and company delegation; original Core / 305
must perform authoritative host binding, grant reconciliation and revocation
checks. Original 306/312 must validate the exact job, and 313 must independently
verify execution and readback. The connected native dispatcher remains the
only execution authority; failed or incomplete evidence is a pending dependency,
not an assumed allow. Production uses the same capability menu with its own
verified runtime risk gate. No one company can derive a grant for another
company from this catalog.

This increment is **catalog + non-authorizing per-target projection + tests**.
It neither creates credentials nor installs SSH keys, changes a database,
issues grants, executes shell/WP-CLI/SQL, configures a new host, changes
systemd, deploys a native executor, or mutates Staging/Production.

### Remaining runtime integration (not claimed)

- Wire Company Provisioning enrollment events to the original 305 host proof
  and grant-delta planner for the exact site/host/agent/operation, with audit,
  revalidation, conflict handling and rollback. Zero-touch where original
  source authorization and evidence already exist.
- Publish tested trusted adapters for Shell, SSH, WP-CLI, SQL and Native
  Executor, using existing separate privileged boundaries and avoiding a
  universal raw-command permission.
- Unify Broker/MCP/SSH fallback dispatch and exact 312/313 readback, including
  retry, idempotency, host drift and failure rollback, before any live apply.
- Reconcile every registered company/environment using signed original-Core
  inventory; a previous blocked enumeration is **not** evidence of bulk grants.

The source selftest retains its prior 10/10 cases. The source-contract test
adds targeted catalog, Staging/Production parity, site isolation and denied
self-authorization cases. Both must pass in PR CI before integration.

### Enrollment-event fast path (increment 2)

The same original Core snapshot can be re-evaluated on an enrollment/binding
change by invoking the **read-only** `event` action on a registered central
operator worker with an exact `--company`, `--environment`,
`--site-uuid`, and `--canonical-origin`. The event values are only lookup
hints; the script independently re-fetches the original Core company registry,
operation plans and original 305 channel readback and rejects a mismatched
company, environment, site UUID or origin. This is not a caller-asserted grant.

Example (illustrative placeholders, not a privileged operator action):

```text
company-unified-operation-policy.py event --company=company-code --environment=staging --site-uuid=exact-registered-uuid --canonical-origin=https://enrolled.example.test
```

For each target, the report now identifies the original-Core next transition:
site enrollment, owner-verified original 305 standing grant, exact host proof,
paired transport, or independent 312/313 native execution readback. It reports
**no** host proof verification or execution permission of its own, even when
original 305 reports a persistent grant and active transport pair.

**Deployment/wiring status:** this implements the source event command and
hermetic negative tests; the live Company Provisioning event dispatcher has
not yet been wired to invoke it. Existing periodic `once` readback remains
in place. Do not interpret an enrollment event as authority to provision SSH
keys, mint standing grants or execute a Production command.
