# MAD4B — Original Executor-time CAS Provider V1

Date: 2026-11-12.
Scope: Central tours.dev.mad4b.com and Wayfatours Production only.

## What is implemented and deployed

Two source-owned files are live on both sites: mad4b-company-executor-time-cas-v1.php (WordPress MU plugin) and mad4b-company-executor-time-cas-policy-v1.json (root-owned provider scope catalog).

- The local read-only CAS inspect method validates a real resource before/manifest/company-preservation digest. It cannot sign Original 313 or execute a write.
- The signed CAS method checks existing Original 305 broker Ed25519 public-key signature, one exact original Job, company, site UUID, canonical origin, environment, plan digest, resource type/ID, post type, meta key and row ID, expected before/after SHA256, short-lived signature and nonce.
- An independent root-owned local scope catalog allows initially only provider company.resource.cas.apply, WordPress post type tour-rates and meta key rate_title. Fields including single_price and double_price, other commercial pricing dimensions and unregistered operations are denied on the target itself, even when a test signature otherwise matches.
- The mutation method takes post, postmeta, term-relationship and relevant company option row locks in one SQL transaction, rereads the exact source-defined WordPress snapshot, checks current before/manifest/preservation SHA256, validates exact row meta ID and raw value digest, inserts a unique once-per-Original-Job replay fence, executes one conditional UPDATE of the allowed Meta row, and validates the expected postimage/preservation digest before COMMIT. Any failure triggers ROLLBACK. A successful local transaction is pending Original 313, not signed/certified.
- No public REST Apply or general WP-CLI Apply exists. The private Broker key was not copied, inspected or changed.

## Original dynamic provider: source candidate, NOT production enrolled

Source code contains a separately content-pinned dynamic first-party provider named company.resource.cas.apply, with Host Handler, independent target readback adapter, manifest and evidence schema.

The Host Handler requires the existing Original 305/306/312 signed claimed Job with an exact command SHA, an immutable source plan, the original root-only Worker replay reservation, an additional operation-specific Broker-signed Release grant bound to the same job/plan/company/site/meta field, and the pinned Original host target/actor. It signs a short-lived derivative witness with the existing Broker signer after all these checks. It cannot mint an Original Job or Grant, and an applied host receipt is explicitly pending independent Original 313.

The independent readback adapter verifies a root-only host receipt, the freshly observed target postimage and the exact durable replay-fence fingerprint. It reports unsigned evidence and cannot issue a signed Original 313.

The new provider is NOT present in the currently enrolled Original dynamic Registry. Its Original 306 preclaim admission is still blocked by the running first-party Worker, whose approved grant cases do not include this provider. The current Original 313 finalizer is specific to the code-release provider and does NOT sign CAS receipts. The corresponding owner-issued operation Release grant has NOT been created. DO NOT enroll this mutating provider until operation-specific 306 grant admission and signed independent Original 313 finalization are both implemented and verified.

This is source-backed CAS code and a live target-side read-only verification tool, not a certified production write operation.

## Tests and real validation

- 320 existing Company Reconcile and target Evidence PHP tests passed unchanged.
- 22 new atomic-CAS transaction and signed-witness tests passed, using ephemeral fixture keys, mock SQL transactions, mismatches, postimage drift, rollback and replay rejection. The real Original Broker private key was not used.
- 36 new Original dynamic provider/source-plan/grant/host-readback tests passed using test signatures, including denied price fields, wrong company, UUID, plan, authorization, expiration, tampered manifest and missing reservation.
- Wayfatours published Tour Rate 61152 (39 metadata keys) was read via authorized WordPress CLI. Matching source digest returned PRECONDITION_MATCH_READ_ONLY_UNSIGNED; intentionally wrong digest returned PRECONDITION_DRIFT_READ_ONLY_HOLD.
- Live target source-owned catalog allowed rate_title, denied single_price and denied unknown operations.
- Both target home/schema smoke checks passed. Guest CAS Apply REST returned HTTP 404. No production write, Original 305 submission, Original 313 certificate or Full Clone occurred.

## Remaining acceptance gates

1. Implement and enroll operation-specific Original 306 signed grant preclaim for CAS, with immutable host source and verified current Broker authority.
2. Extend and deploy the independent Original 313 finalizer to this dynamic provider, checking the signed job, host receipt, fresh exact target postimage and replay fence before signing.
3. Central Company Repair Jobs must bind a governed expected after-image, a genuine restorable rollback artifact, and an authorized child resource journal and preimage before any owner-approved job issuance.
4. Business postconditions for rates, seasonal pricing, occupancy/children, WPML and commerce workflows are separate. No numeric price mutation is currently allowed.
5. Original 318/313 signed release remains HOLD. The 64-children-per-incident resource index is unchanged.

## Rollback

This iteration introduced the two source-owned target files to Central and Wayfatours, no tenant data changes. To roll back, remove those two files through the approved source release mechanism. Prior CAS module preimages before adding the independent scope catalog are archived under /opt/mad4b-company-provisioning/reports/company-executor-time-cas-v1-20261112/preimage-before-source-scope/. Do not erase central repair journals or original host evidence. The installed Original registry and worker were never changed.
