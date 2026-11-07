# MAD4B Company Policy REST API v2.3

Deployed first on Tours.dev Staging as wp-content/mu-plugins/mad4b-company-policy-rest-v2.php.
Base URL: https://tours.dev.mad4b.com/wp-json/mad4b-policy/v2

## Contract and authorization

All new routes require an authenticated WordPress principal with the manage_options capability. Browser requests use WordPress REST nonces; supported external workflows may use WordPress Application Passwords or an authorized company-native identity. A central company registry entry does NOT grant write access.

All rule mutations require an integer expected_registry_revision. Updates and rollback additionally require integer expected_rule_revision. Requests with stale revisions receive HTTP 409 and are never retried automatically. Missing preconditions return 428. No Idempotency-Key contract is advertised.

Include target_origin and target_site_uuid for centrally dispatched writes. They must match the destination company's verified local origin and profile. The UUID can be read via the authenticated GET /providers route. The API is local to each WordPress site; no remote writes or automatic rollout are implied.

## Endpoints

| Method | Path | Action |
|---|---|---|
| GET | /providers | Provider schemas, local site UUID and registry revision |
| GET | /company-defaults | Company loading and pricing defaults with current SHA256 |
| PATCH | /company-defaults | Validate and update company defaults atomically |
| GET | /policies | Filtered/paginated list of versioned rules |
| POST | /policies | Create a local policy rule |
| GET | /policies/{id} | Single rule and bounded history |
| PATCH | /policies/{id} | Change rule fields, scope, strategy or state |
| POST | /policies/{id}/rollback | Restore a previous rule snapshot as a new revision |
| POST | /assignments | Create a local scoped assignment using the policies contract |
| POST | /resolve | Read-only policy simulation using real Registry resolution |
| GET | /decisions | Last 40 explicit API simulation receipts (not all runtime decisions) |

Pre-existing routes: GET /summary, GET /intelligence, GET /companies and POST /company-receipt. The latter is an authenticated, registry-matched report ingestion endpoint, NOT a policy assignment or original 313 certification.

GET /policies supports page, per_page (1–100), state, scope, provider_id, policy_id. Returned items are sorted by rule ID. GET /policies/{id} lists a bounded history (up to 60 total registry change events).

## Example: Tour Rate arbitration policy

POST /policies

~~~json
{
  "provider_id": "native-rate-arbitration",
  "scope": "tour",
  "scope_id": 64947,
  "state": "draft",
  "conditions": {"component": "base"},
  "values": {"mode": "lowest_verified"},
  "expected_registry_revision": 0,
  "target_origin": "https://tours.dev.mad4b.com/",
  "target_site_uuid": "<UUID returned by GET /providers>"
}
~~~

The registry revision 0 is an observed Staging baseline, NOT a permanent setting. Fetch the current value first. This request is illustrative and was NOT submitted against the live site. Draft and Canary policies are inert. Only Active policies affect runtime decisions.

PATCH /policies/{id}

~~~json
{
  "state": "active",
  "expected_registry_revision": 1,
  "expected_rule_revision": 1
}
~~~

The revisions here are examples and must be replaced with current values. The pricing provider only chooses among native-validated comparable quotes in a single currency. No published Rates, inventory, booking or Checkout authority change.

## Example: update company defaults

Read GET /company-defaults, then supply its SHA256 in PATCH /company-defaults:

~~~json
{
  "policy": {
    "pricing_default": "hybrid",
    "mobile_rows": 2,
    "desktop_rows": 3
  },
  "expected_defaults_sha256": "<SHA256 from previous GET>",
  "target_origin": "https://tours.dev.mad4b.com/"
}
~~~

Supported fields: listing_mode, pricing_default, mobile_prefetch_px, desktop_prefetch_px, mobile_rows, tablet_rows, desktop_rows. Values are strictly validated before an exact DB compare-and-swap. No silent clamping is performed by the REST layer.

## Example: simulation, history and rollback

POST /resolve

~~~json
{
  "policy_id": "pricing.rate.choice",
  "context": {
    "tour_id": 64947,
    "component": "base",
    "effective_date": "2026-11-07"
  }
}
~~~

A simulation returns values and a decision receipt including rule_id, decision_id and registry_revision. It does not perform an actual Booking price or availability calculation. GET /decisions returns a bounded 24-hour diagnostic of explicit API simulations only.

To rollback: GET /policies/{id}, select a history.change_id marked restorable=true, then send:

POST /policies/{id}/rollback

~~~json
{
  "change_id": 2,
  "expected_registry_revision": 3,
  "expected_rule_revision": 3
}
~~~

This restores a historical before snapshot by creating a new revision and invalidation event. Initial creations have no earlier snapshot; switch their state to paused instead of deleting audit history.

## Status codes and safety

- 401 unauthenticated; 403 insufficient capability.
- 404 absent rule or prior snapshot.
- 409 wrong target, duplicate ID, stale revision, conflicting DB update.
- 413 request JSON over 16 KiB.
- 422 invalid provider, field, context or value.
- 428 missing revision precondition.
- 503 store corruption or write unavailability.

The REST bridge uses exact option_value compare-and-swap and clears WordPress option caches. The existing Admin POST forms remain backward compatible, but they are not changed by this REST module; concurrent REST and legacy Admin POST writes should be reviewed because the old admin path does not itself require an expected revision.

## Testing and deployment limitations

The standalone fixture suite uses an in-memory fake WordPress database. It tests route registration, authorization callbacks, creation, stale revision rejection, duplicates, wrong-site rejection, PATCH, rollback, company defaults CAS, simulation and diagnostic receipts.

Run the fixture by setting MAD4B_POLICY_REGISTRY_FILE, MAD4B_POLICY_ADAPTIVE_FILE, and MAD4B_POLICY_REST_FILE to the corresponding PHP source files and executing php tests/test_mad4b_company_policy_rest_v2.php.

On Tours.dev, route existence and anonymous HTTP 401 were independently checked. The site has not been given new active policies by these tests. A real authenticated write using a logged-in administrator session or an authorized WordPress principal remains an integration acceptance step. Browser parity, LCP, CLS, pricing quote parity and rollout on other companies are separate acceptance tasks.
