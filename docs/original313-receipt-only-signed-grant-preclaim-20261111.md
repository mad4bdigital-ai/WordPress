# First-party Original 306 receipt-only signed-grant admission

## Verified missing contract
The source-enrolled `company.code.release.receipt.reconcile` provider and
immutable historical recovery plan are present, but current Original 306
only admits `company.code.release` or `plugin.license.activate`. A catalog
row alone cannot produce a valid signed Original 305/306/312 Job. The old
applied Wayfatours Job `a37629fc009a97afe2fb66cef34a48ca` remains
reserved and is not replayable, and its Original 313 certificate does not
exist.

## New narrowly scoped preclaim
`signed_recovery_admission.py` is a pure verifier. It accepts a **new**
Broker-signed Ed25519 grant (using the *existing* first-party Broker public
key) with a new Job ID; operation exactly
`company.code.release.receipt.reconcile` and action
`receipt_only_readback`. It binds the new command SHA256, immutable recovery
plan SHA256, original Job and plan IDs, old signed Grant SHA256, old Host
receipt SHA256, company, environment, site UUID and canonical HTTPS origin.

A new grant is restricted to a 15-minute issuance window, may not grant
production mutation, DDL, Original 313 certification or renewal of old
authority, and does not accept arbitrary caller-supplied fields. The
root-private, non-secret grant file is looked up by the *new* Job ID, not the
old Job ID. Any missing grant, changed source, unknown signer, expired scope,
wrong company, replayed ID, or signature mismatch must fail closed.

The provider manifest pins the verifier's SHA256 dependency. The Original
306 candidate may use this preclaim ONLY after validating the exact pinned
provider and immutable registered plan. A positive preclaim is **not** a
finished Original 312 claim or Original 313 recovery certificate.

## Deployment status and required next integration

This change is a **source-only candidate**, not a live issuer or Worker
release. The live receipt-only Grant store does not exist, and no new grant
or Job was issued. The running Worker and active installed manifest are not
modified in this patch.

The remaining first-party steps are:
1. Enroll a new narrow Original 305 issuer that actually signs this exact
   recovery Grant using the existing Broker authority after verifying the
   owner/company operation entitlement. No operator-invented key or
   self-signed certificates.
2. Publish the new manifest and signed grant checker via First-party
   additive Registry release; pin the Worker source and its Source Guard to
   the new reviewed SHA. A current Original 306/312 authority must still
   be read from original WordPress records, not supplied by the caller.
3. Make the Host 306 adapter return a **receipt-only readback** result without
   any code re-apply, and install an independent Central 313 recovery
   Finalizer that records a distinct signed, idempotent historical recovery
   certificate. This certificate must never impersonate the original
   timely Original 313 Host Apply certificate.
4. Verify the new recovery Job and independent Central readback before
   reopening overlapping Wayfatours Tenant Sync Code Release.
5. Separately authorize Tenant Schema DDL with a fresh backup, isolated
   restore proof, exact 4-table scope and post-DDL independent receipt.

Test results are from isolated ephemeral test keys only; **no** generated
fixture is recognized as valid authority in Production.
