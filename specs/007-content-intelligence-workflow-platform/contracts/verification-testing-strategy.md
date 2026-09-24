# Contract — Verification and Testing Strategy

Contract: mad4b.verification-strategy.v1

## Test layers

1. static/schema/lint;
2. unit;
3. property/invariant;
4. contract;
5. integration;
6. disposable behavioral;
7. live Staging acceptance;
8. canary/release-ring;
9. recovery/chaos where risk warrants.

Passing lower layers does not substitute for required higher layer.

## Property tests

Use property-based tests for:
- state-machine legal transitions;
- idempotency;
- canonical hashing;
- normalization;
- scope isolation;
- plan fingerprint stability;
- artifact graph acyclicity where required;
- retry classification.

## Fuzz/security tests

Fuzz bounded parsers and externally controlled envelopes:
- webhook/MCP payload;
- URLs;
- paths;
- archive entries;
- subject/issuer fields;
- artifact JSON;
- provider normalized outputs.

## Fault injection

Inject:
- timeout before/after external success;
- duplicate callback;
- reordered callback;
- DB failure between steps;
- process crash/lease expiry;
- rate limit;
- provider 5xx;
- JWKS outage/unknown kid;
- cache stale/miss;
- worker concurrency race.

## Compatibility matrix

Test declared support across relevant:
- PHP;
- WordPress;
- DB/MariaDB/MySQL class;
- MCP Adapter/protocol;
- provider artifact;
- object-cache/cron modes where material.

Unsupported combinations are explicit, not assumed.

## Reproducibility

Disposable fixtures are versioned and deterministic.
Tests do not depend on mutable Production state.

External services use controlled fixtures/mocks for deterministic contract tests, then separate live canaries.

## Denial-path coverage

Authorization and security tests prioritize negative paths:
wrong issuer/audience/resource/scope/site, stale plan, stale revision, denied capability, expired request, duplicate nonce, untrusted provider, Production isolation.

## Mutation testing

Security-critical policy/normalization code SHOULD use mutation testing or equivalent evidence that tests fail when essential deny conditions are removed.

## Flake policy

Flaky tests are quarantined only with owner/reason/expiry.
A quarantined security/release gate does not count as PASS.
