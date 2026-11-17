# MAD4B Standing Code Release Delegation — v1

**Default: OFF. Source implementation is not Host enrollment.** The existing manually approved Original313 lane remains valid and unchanged for every operation outside this small, independently signed scope.

## Why

Don't require the Master Admin to click a new approval ticket for each **routine** update to previously registered front-end assets. One time-bounded, deliberately narrow Master consent is enough. Technical signatures are generated/verified by first-party issuers, not typed by the administrator. Every actual release is still an immutable, independently signed, one-use job with rollback/readback.

## Two authority documents, no new issuer key

1. A **Master-signed standing certificate**: exact company/environment, Site UUID, origin, Original313 registered operation/provider/Ability and manifest SHA, exact individually named existing front-end files, maximum 10 changed files and maximum 1 MiB source bytes per release, maximum 30 days validity. Source contract `mad4b.native-source-standing-delegation.v1`. Install only through authorized first-party enrollment in root-private `/etc/mad4b/native-source-standing-delegation.v1.json`, never by writing Git or WordPress options. Removing it revokes future readbacks. A forged or expired certificate fails.
2. A **fresh Master-issuer-signed decision for each immutable CAS plan**, at most 15 minutes, bound to that certificate SHA, plan SHA, Git full commit, manifest/binding SHA, same origin/Site UUID and unique nonce. Contract `mad4b.native-source-delegated-plan-decision.v1`. It can be emitted automatically by the **existing trusted issuer** only after it verifies the current standing certificate and plan. There is deliberately **no signing utility or private key in this source repository**.

The executor and Original306/312/313 paths verify both signatures, live root-private certificate readback, SHA-pinned source dependency, existing file preimages, Broker-signed exact grant and Original312 job before any write. One-use reservation prevents reusing the same signed decision. After deployment, the existing Original313 independently signed readback remains mandatory.

## Strict default boundaries

- Only existing `.css` or `.js` files under an explicit `wp-content/plugins/<plugin>/assets/...` allowlist. No pattern/wildcard grants; no new files, no no-op changes, no symlink/traversal paths, no edits to security/Control Plane/platform/commerce/WooCommerce/MU/Host code, no PHP, no payment/pricing/credential/issuer policies.
- Every file must have its exact current preimage and source SHA in the plan. The plan must match its original Site Profile, immutable signed operation, and enrolled manifest.
- The feature flag in the root-private Native Source Publisher policy is `standing_delegation_enabled: true`, **only after separate first-party signed enrollment** and SHA-pinning `standing_release_delegation.py` in the Original313 manifest dependency closure. `false`/absent keeps the old human-approved lane. A flag alone grants nothing.
- Initial rollout remains **disabled** for existing Wayfatours Production and Tours.dev; no signed standing certificate, delegated issuer decision, installed pinned dependency or Original313 receipt is claimed in this Git change.
- Any protected or nonmatching update follows the existing explicit human approval path. Master can revoke the standing certificate at any time; the next preclaim/finalizer rejects it.

## User-facing explanation

The WordPress Approval Decisions page explains standing permissions, human decisions, Broker technical grants and Original313 receipts without pretending to know root-private issuer state. A normal business administrator does not manage keys, private Host files or Git hashes to execute a routine update.

## Verification

`python3 -m unittest discover -s tests -p 'test_native_source_standing_delegation.py' -v`

`python3 ops/native-source-publisher-v1/ci_portable_native_tests.py`

Both are source/offline verification and **never** authorize a production mutation or prove the existing Host has enrolled this lane. Host enrollment, independent issuer configuration and actual signed Original313 tests must be completed by the authorized first-party registry flow; do not override an Original305/312 HOLD by root SSH, direct WP-CLI or self-signed fixtures.
