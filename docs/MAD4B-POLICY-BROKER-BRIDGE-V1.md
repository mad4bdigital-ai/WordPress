# MAD4B Company Policy Broker Bridge — planning contract v0.1.0

The bridge lives as a local MU component at wp-content/mu-plugins/mad4b-company-policy-broker-bridge.php.

It does **not** perform remote HTTP requests, issue Original 305 grants, claim Broker 306, or generate any fake 312/313 receipts. It converts a company policy assignment into a deterministic, idempotent, environment-bound delivery plan that the existing MAD4B Central Broker/Company Executor can consume only after its independent authorization succeeds.

## Routes

- GET /wp-json/mad4b-policy/v2/broker/targets: list company/environment targets discovered from the live MAD4B Company Environment Registry, their exact registered canonical_origin and site_uuid, and their central signed identity verification flag.
- POST /wp-json/mad4b-policy/v2/broker/plans: create or replay a local, idempotently stored policy distribution plan. Requires WordPress manage_options and central Company Strategy authority. Never initiates a delivery.

Example request (values are illustrative and must be read from GET /broker/targets, plus the target's authenticated GET /providers):

~~~json
{
  "company_code": "wayfatours",
  "environment": "staging",
  "site_uuid": "<registry-target-site-uuid>",
  "canonical_origin": "https://wayfatours.example",
  "expected_registry_revision": 0,
  "idempotency_key": "policy-intent-20261107-tour-x",
  "policy": {
    "provider_id": "native-rate-arbitration",
    "scope": "tour",
    "scope_id": 123,
    "state": "draft",
    "values": {"mode": "unambiguous_only"}
  }
}
~~~

Planning requires registered company + environment + verified central signed identity, exact site UUID and canonical origin, validated local provider fields, non-active initial rule, positive idempotency intent and integer target revision. If a repeated key changes its payload, return 409. The endpoint stores an intent for later handoff and returns state PLANNED_PENDING_ORIGINAL_BROKER.

The intended target instruction is:
POST <company canonical_origin>/wp-json/mad4b-policy/v2/policies
with the pinned payload (scope, provider, target_origin, target_site_uuid, expected_registry_revision). It must be executed by the **local, authenticated company executor**. Central registration does not grant that authorization.

### Before calling the existing Broker

The company must have a valid Original 305 Host/WordPress command grant, a registered verified target and an enrolled executor. The Original 306 claim, signed Native 312 apply and independent Original 313 receipts belong to their existing owners. The bridge does not change those components.

The plan explicitly records:
- requires_company_local_authenticated_executor=true
- requires_original_305_authority=true
- broker_306_claimed=false
- native_312_executed=false
- original_313_certified=false
- remote_write_performed=false

This is a planning integration, not finished cross-company distribution. Do not call it a successful rollout until the target's own REST mutation response and 313 proof can be independently read back.

## Verification

- 11 isolated regression assertions PASS: target discovery, verified identity, anonymous denial, create/readback, revision pin, no remote mutation, original Broker authority, idempotent replay, changed-payload rejection, cross-company UUID rejection and unauthorized Active policy blocking.
- Live Nginx/WordPress returned HTTP 401 for anonymous GET /broker/targets and POST /broker/plans.
- No central Broker job was created and no production company was modified.

Remaining execution: bind an existing Original 305/306 native job class to the stored intent and local authenticated policy REST request, then verify target-local 312 and Original 313. This must be done with an actually authorized owner/company executor session, not WP-CLI --user spoofing or an invented token.
