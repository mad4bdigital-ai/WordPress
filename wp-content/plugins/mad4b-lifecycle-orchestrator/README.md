# MAD4B Lifecycle Orchestrator — Central Staging

Version: 0.2.0-staging. This is an **additive, non-authorizing control-plane coordinator**, not a second provider executor.

## Roles

- **Trigger:** WordPress post create/update, taxonomy edit, image add, image ALT update, Rank Math SEO Meta change, manual authenticated request, or trusted `mad4b_lifecycle_event` action.
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

## Native producer integration (0.2.0-staging)

- Control Plane content-job-created and content-job-transitioned post-commit hooks: exact job/revision/state/stage readback; no business mutation.
- Content Hub bulk-task-finished hook: records task completion as a lifecycle event, not proof of publication.
- Company Portfolio Manager company-portfolio-transitioned hook: emitted after successful assignment write. Company/manager/site-binding and revision read back.
- VERIFIED is allowed only for a terminal ContentJob COMPLETED revision with trusted post-commit source hook and matching canonical readback. Its scope is content_job_terminal_transition_only. It does not certify article publication, SEO or media.
- BLOCKED_AUTHORITY runs no provider mutation. WP Cron rechecks certification twice, on bounded backoff; new readiness event or manual reconciliation can refresh later.

## Central Administrator acting on behalf (0.2.1-staging)

- Central Administrator can represent an enrolled company without inventing a Company Manager identity. Representative mode: `central_admin_proxy`.
- The authentic actor is the logged-in WordPress Administrator, with `manage_options` and `activate_plugins`; the represented party is resolved from the verified Company Registry binding.
- The request is a durable, idempotent scoped **operational intent**, not evidence of an owner-signed mandate, source owner approval, or successful provider mutation.
- The actor ID and company/binding are stored as separate principals and retained during background readiness reconciliation. An anonymous or non-admin event cannot claim central representation.
- Implementation lives in `mad4b-company-portfolio-manager/class-mad4b-central-admin-proxy.php`.
- WordPress admin screen: `/wp-admin/admin.php?page=mad4b-company-admin-proxy`.
- REST context: `GET /wp-json/mad4b/v1/central-admin/company-representation?company_code=wayfatours`.
- REST request: `POST /wp-json/mad4b/v1/central-admin/company-representation`, using a valid WordPress REST nonce and the exact current binding SHA. Input: `company_code`, `operation`, `reason`, `event_key`, `expected_binding_sha256`.
- The existing native provider's write and approval gates still apply. No arbitrary execution dispatch and no owner impersonation.
