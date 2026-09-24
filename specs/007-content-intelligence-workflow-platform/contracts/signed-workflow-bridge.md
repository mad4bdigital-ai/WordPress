# Contract — Signed Workflow Bridge

Contract: mad4b.workflow-execution-request.v1

## Purpose
Allow MAD4B ↔ WorkflowProvider webhook/custom-app integration without generic execute-any requests.

## Request envelope
- contract
- request_id/jti
- provider_id
- workflow_ref
- capability_id
- plan_sha256
- workflow_sha256
- site_uuid
- environment
- candidate/source/build identity when operation is release-bound
- payload/input_hash
- issued_at
- expires_at
- nonce
- callback contract/ref optional

## Authentication/binding
Use an authenticated channel and/or signature/MAC appropriate to provider integration.

The receiver verifies:
- trusted sender identity
- expiration
- nonce/jti replay state
- exact site/environment
- plan SHA
- workflow SHA
- allowed capability/workflow
- provider certification state

## Response
Bound to request_id and includes:
- provider execution ref
- status
- output/result hash
- timestamps
- evidence ref
- failure reason code

## Rules
- no generic ability name supplied by untrusted caller;
- no arbitrary URL/PHP/SQL field;
- one request cannot be replayed after acceptance;
- changed plan/workflow invalidates request;
- provider callback never directly widens MAD4B authority.
