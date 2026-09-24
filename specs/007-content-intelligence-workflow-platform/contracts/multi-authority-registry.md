# Contract — Multi-Authority Registry

Contract: mad4b.multi-authority-registry.v1

## Problem
A single mode value such as local/external/hybrid conflates independent policy dimensions: trust, advertisement, resource access, subject mapping and live readiness.

## AuthorityDescriptor
Required fields:
- authority_id
- authority_type: local | external | managed | custom
- issuer
- configured
- trusted
- advertised
- primary
- standby
- resource_policy_id
- subject_mapper_id
- runtime_verified
- last_live_verified_at
- last_live_verification_ref
- metadata_source
- jwks_source
- environment_scope
- status_reason_codes[]

## Policy dimensions

### Trust policy
Which issuers/tokens may be accepted after cryptographic/policy checks.

### Advertisement policy
Which authorization servers are returned in protected-resource metadata.

trusted_authorities and advertised_authorities are independent sets.

### Resource policy
Exact protected resources each authority may access:
- mad4b-chatgpt
- mad4b-enrollment
- mad4b-developer
- mad4b-developer-breakglass
- future resources

Trusting an authority does not grant all resources.

### Subject mapper
Exact mapper used after token validation to resolve issuer-bound subject to a site principal/NHI.

### Live evidence
configured/trusted does not mean runtime_verified.

External live readiness is established by explicit certification probes, not hidden network calls inside ordinary status.

## Suggested policies

Staging:
- dual-capable allowed;
- dual-advertised only after both subject mapping and live canary pass.

Production target:
- trusted: external + local where desired;
- advertised: external primary;
- local: standby/recovery;
- Developer/Breakglass resources not automatically available to Local authority.

## Fail-closed rules
- unknown issuer: deny before discovery;
- trusted=false: deny;
- resource not allowed by authority_resource_policy: deny;
- subject mapper missing/ambiguous: deny;
- runtime_verified=false does not necessarily prevent offline token validation, but MUST be truthfully surfaced and may block configured policies that require live verification;
- authority A subject or JWK cannot satisfy authority B policy.
