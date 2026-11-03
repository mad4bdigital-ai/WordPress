# Issue #724 — signed remote 305 request adapter (Staging candidate)

Status: **source candidate, not connected to the live Broker poller and not activated as a service**.

- `remote_authority.py` consumes a bounded JSON message via standard input, signed
  with the previously enrolled Broker host Ed25519 key. The public key is loaded
  from a root-only pinned local file; callers cannot supply their own key.
- Signed transport identity **does not replace the company manager/owner's
  delegation**. The adapter requires exact Original Core site UUID, canonical
  origin, staging environment and registered host scope. It delegates the
  manager admissibility check and any `305` enrollment to the **original
  WordPress native issuer**, and verifies resulting status through a new live
  Original Core readback. It never impersonates a WordPress user.
- Operations: `preflight` (no writes) and `enroll305` (staging only; uses
  the original signed issuer). Requests expire within 60 seconds. Enrollment
  requests are single-use with a protected nonce ledger; ambiguous outcomes
  are not retried. No general shell, SQL, production mutation, 312 or 313
  operation is performed here.
- Scope limitations: This adapter is **local central VPS source**, not a
  network API. The Broker must explicitly invoke the fixed handler using an
  enrolled signer, execute the exact signed request, and independently verify
  the response before remote execution is certified. No new broad access
  results from installing source alone.

## Test commands

```sh
python3 -m unittest discover -s . -p 'test_*.py' -v
python3 -m py_compile remote_authority.py test_remote_authority.py
```

## Live Staging readback (2026-11-01)

- `tours-central:staging`: `ENROLLED_WORDPRESS_COMPANY_MANAGER_NOT_VERIFIED`.
- `all-royal-egypt:staging`: `FULL_SHELL_SQL_AND_HOST_SCOPE_PENDING`.
- `egypt-tour-gates:staging`: `EXACT_HOST_SCOPE_AND_BINDING_PENDING`.
- No grants issued or Production changes. Tested using an **ephemeral test
  signature** against live Original Core projection; this does **not** certify
  the actual pinned Broker transport channel.

## Integration/activation blockers

1. Enroll company-manager assignment/membership through the original
   company-authorized route, or implement a reviewed independent signed
   administrator issuer. Do not fabricate the WordPress admin/owner.
2. Provision exact trusted staging Host Scopes for ARE and ETG via original
   host-policy issuer. Do not hand-edit host policy.
3. Wire central Broker fixed dispatch to this handler without exposing
   arbitrary shell input and certify the pinned signature in a live call.
4. Certify `305/306/312/313/314`, independent receipts and rollback.
5. Keep Production mutation separately approved.

## Issuance objective

The preferred remote action is `reconcile305` (`enroll305` remains an alias).
It automatically requests a real site-specific persistent Grant and Transport
from the original WordPress issuer when the exact host and manager authorization
are already valid. A `preflight` request remains non-mutating.

Original WordPress `mad4b company-native-wpcli status` confirms the persisted
pair and live company delegation even if native executor activation is pending.
The 305 projection's `original_305_active_pair_observed` is not proof that
the grant was newly issued, because newly issued pairs start pending.

Outcomes: `GRANT_ISSUED_CONFIRMED` means a new grant with independent readback;
`GRANT_EXISTING_CONFIRMED` means no duplicate was created; `grant_registered`
means the persistent record exists, while `grant_active` is only true when
Original WordPress verifies both host authority and full WP-CLI activation.
Individual command execution still needs independent `312/313` verification.

Original Company Portfolio Manager enrollment and signed host scope remain
mandatory. Broker signature by itself never confers a company delegation.
Production grants cannot be issued through this Staging-only adapter.

31 hermetic tests PASS locally after distinguishing issued and active grants.
