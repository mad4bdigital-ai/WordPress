# CSO01 — Execution and recovery protocol

Proposed capability family is listed in abilities.json and is NOT registered live. For an actual WordPress Ability, require namespace, registered category, input/output schemas compatible with the supported WordPress subset, execute callback, per-input permission callback, and a separate exposure policy. The planner and UI must not accept a generic execute-any route.

Discover → Schema → Validate → Draft → Plan → Approve → Commit → Verify → Receipt. At commit re-check issuer/subject/resource, current site/origin/env/source, vendor adapter, target revision, approval/step-up and idempotency key. For each step save non-secret payload fingerprint, locks, stage and verifier. An unconfirmed prior attempt is UNCERTAIN and must be reconciled by independent target readback before any retry.

Cross-plugin or external workflows are sagas. Compensation is a distinct governed action with a new revision, cannot unsend external emails, reverse all webhooks or guarantee order side effects. Mark irreversibility and readback. Bulk canary a subset first; per-item receipts are not merged into false global success.

Monitoring conditions and signed event/webhook sources trigger only pre-approved scopes, not new authority. Release promotion is only a proposed signed diff until separate Production governance approves it.

Provider adaptation must invoke original native save hooks (Settings API/registered meta/vendor API), maintain cache/SEO/translation/relations and never arbitrarily mutate private custom tables. Errors are sanitized; secret/PII value never returned in evidence.
