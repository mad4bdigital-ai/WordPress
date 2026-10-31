# MAD4B Lifecycle Orchestrator — Central Staging

Version: 0.1.0-staging. This is an **additive, non-authorizing control-plane coordinator**, not a second provider executor.

## Roles

- **Trigger:** WordPress post create/update, taxonomy edit, image add, image ALT update, manual authenticated request, or trusted `mad4b_lifecycle_event` action.
- **Dynamic resolution:** Reads registered WordPress Abilities and canonical provider certification. Resolves a ContentJob's brand from the existing authoritative job when supplied. It does not invent owner or commercial authority.
- **Event record:** An immutable deterministic event ID built from site + goal + target + job + event source revision. Uses a durable unique WordPress option and replay returns the existing identity.
- **States:** `RECOVERING` during diagnostic retries, `BLOCKED_AUTHORITY` for missing grants/certification, `PARTIAL` for incomplete independent capability resolution, `READY` for resolved registered prerequisites. `VERIFIED` is **reserved for future native provider readback receipts**, never asserted by a capability discovery.
- **Reconcile:** WP Cron schedules read-only resolution. Bounded retry 3 with exponential delay, no automatic provider writes, no duplicate content mutation, no approval minting.
- **Handoff:** Existing Content Hub Dynamic Runtime Resolver, company operations, native WordPress/Rank Math/Media Ability. Their existing exact provider and object gates remain authoritative.

## API

Admin-only REST (WordPress cookie+nonce / authorized app credentials):
- `GET /wp-json/mad4b/lifecycle/v1/resolve?goal=seo&target_kind=post&target_id=123`
- `POST /wp-json/mad4b/lifecycle/v1/record`
- `GET /wp-json/mad4b/lifecycle/v1/status?event_id=<sha256>`
- `POST /wp-json/mad4b/lifecycle/v1/reconcile` (read-only check; persists diagnostic state).

Registered Abilities `mad4b/lifecycle-{resolve,record,status,reconcile}`.
Manual event input: `goal`, `target_kind`, `target_id`, `event_type`, `event_key`, optional `job_id`. A company/event without a numeric WordPress object needs a stable source event key or ContentJob. Do not include secrets or customer PII in keys.

## Explicit non-goals / TODO
- Does not patch Rank Math provider certification or Media Journal authority. Those require separate native provider provenance and identity repairs.
- Does not create a competing Company/Content Hub job queue.
- Does not claim numerical SEO score recalculation, Elementor full-render acceptance, publication, or final verification.
- The source is Staging-only. Do not activate on Production until separate verification and release decision.
- On WP Cron starvation, an operator can call `lifecycle-reconcile` with the exact event ID after checking the source state.
