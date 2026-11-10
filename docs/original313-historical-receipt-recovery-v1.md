# Original 313 historical receipt recovery — fail-closed contract

## Observed incident

Original 306 applied a company-code-release to Wayfatours Production and
validated the two exact postimage hashes during the original signed window.
Original 313 evidence was durably ingested in Central WordPress, but the
signed proof expired while Central Finalization was blocked. The immutable
Original 306 reservation prevents replay and the original signed Broker
Job/312 eligibility expires. Neither an old pending proof nor a signed
finish *attempt* constitutes an Original 313 execution certificate.

## Current safe status

`HISTORICAL_SIGNED_APPLY_POSTIMAGE_VERIFIED__313_UNCERTIFIED` means only:
- Same immutable plan and original Git source verified against current files.
- Historical signed Broker apply grant verified cryptographically.
- Original Host apply receipt happened within that grant's original window.
- A signed Original 306 reservation prevents executing the Job again.
- Signed Original native-finish attempt is tied to the same postimage receipt.

It does **not** mean Original 313 certified or that current authorization
remains eligible. Historical verification cannot extend the grant.

## Required future First-Party receipt-only recovery provider

1. Add a distinct operation `company.code.release.receipt.reconcile` to the
   dynamic enrolled provider registry. It must have its own narrower command
   and signed Original 305/306/312 admission; no `apply`, no code or DDL writes.
2. Pin `company`, `environment`, `site_uuid`, original `job_id`, `plan_sha256`,
   original grant SHA, original Host receipt SHA, signed Original 306 reservation,
   and fresh independent live postimage readback.
3. Verify historical signatures **at their actual signed event times** and
   current recovery authority **at the time of reconciliation**. Never extend
   an old job, edit nonce/expiration, or replace the original Central proof.
4. Write an append-only versioned recovery audit record. Original 313 must
   independently verify it against Original 305/306/312 current recovery
   authorization, with a separate receipt-only status; only Central may issue
   a new explicit recovery certificate.
5. Test: expired original Job, changed postimage, forged grant, wrong tenant,
   wrong plan, changed receipt, absent reservation, duplicated recovery,
   revoked recovery scope, and transient WordPress read failures.
6. Leave the four Tenant schema tables to their own schema-migration signed
   provider and fresh isolated-restorable database backup. This evidence is
   never schema-migration authorization.

The current auditor is **read-only** for Production and may write only its
private local audit report. It does not install this future provider.
