# Federated Control Protocol v1

These six JSON Schema files define proposed event contracts for company capabilities, task dispatch, local admission, progress, receipt and runtime snapshot.

All envelopes bind contract version, company ID, environment, site UUID, delivery ID and sequence. Task events also bind parent/child operation IDs, requested capability and idempotency key. Delivery IDs identify retry attempts; logical operation IDs identify effects.

The examples in `../fixtures` are synthetic and cannot be used as execution evidence. Schema validation only checks structure; transport authentication, local admission, authorization, replay protection and independent readback must be implemented and tested in the real Control Plane. Broker receipt 313 without matching readback must not be labelled Verified.
