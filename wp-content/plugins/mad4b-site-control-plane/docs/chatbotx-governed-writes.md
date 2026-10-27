# ChatbotX governed writes — ETG WooCommerce Lab

Three SCP Write Abilities are registered for ChatbotX:
- mad4b/chatbotx-support-create-ticket: native Fluent Support TicketService, existing customer, agent and mailbox only; suppresses email notifications for the call
- mad4b/chatbotx-support-add-note: native ResponseService agent-only internal note, silence hooks
- mad4b/chatbotx-cpt-create-draft: creates unpublished travel CPT draft only, no price/rate/order/booking

Every action requires idempotency_key, SCP centralized authorization,
provider certification, global mutation gate, authenticated administrator,
and native provider permissions. Successful actions preserve only the
object ID in their idempotency entry and minimal SCP audit metadata.

ETG Lab on 2026-10-27: 3 write/5 read declared. Site Profile write_enabled
is false; ChatbotX provider is uncertified; Fluent Support has no mailbox,
customer, ticket or agent fixtures. Attempted write Abilities failed with
ability_invalid_permissions; direct helper calls failed with
cmb_mutation_gate_closed. No live business writes were performed.

Do not enable global Site Profile write_enabled to activate only this
provider, because it could unlock other SCP adapter mutations. First
certify the provider and approve the specific ability, agent and NHI
through established SCP policy. Do not bypass those gates.

Fluent Forms submissions must use its native validated request workflow,
not database inserts. WooCommerce cart/order, paid checkout, inventory
and quote/booking operations need their own approved domain contracts.

Run isolated contract:
php wp-content/plugins/mad4b-site-control-plane/tests/chatbotx-governed-writes.php

## Write preflight for ETG Lab

The additional read-only Ability `mad4b/chatbotx-write-preflight`
accepts an exact registered write Ability name plus optional non-secret
customer/mailbox/ticket IDs or supported CPT post type. It evaluates the
Site Profile, effective mutation policy, native provider availability and
provider certification, without creating any external records, credentials,
grants, approvals or fixtures. An eligible preflight is never execution
authorization: the caller must still pass SCP's exact Agent/NHI grant,
risk-specific approval, provider certification and audit gate at execution.

ETG Lab's enrolled environment is **local**. The canonical
`mad4b/site-profile-write-enable` contract only enables Staging/Production
and requires a verified OAuth step-up plus a matching source SHA and
build fingerprint. Never relabel the current Local Site Profile, mutate
WordPress options directly, or insert a bypass filter to activate this
ChatbotX adapter. A separate real, exactly enrolled Staging origin is
required for the governed authority progression.

Isolated verification:
`php wp-content/plugins/mad4b-site-control-plane/tests/chatbotx-write-preflight.php`.
