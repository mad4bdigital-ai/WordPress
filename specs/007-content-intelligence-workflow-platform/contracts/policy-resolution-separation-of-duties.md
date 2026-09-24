# Contract — Policy Resolution and Separation of Duties

Contract: mad4b.policy-resolution-sod.v1

## Purpose

Define one deterministic way to combine global, tenant, site, environment, authority, provider, capability, release, quality, kill-switch and approval policies.

## Policy inputs

A decision MAY include:
- global platform policy;
- tenant/business policy;
- site profile;
- environment policy;
- authority resource policy;
- provider/capability certification;
- exact grants;
- feature flags;
- kill switches;
- quality gates;
- release-ring eligibility;
- approval policy;
- risk/blast-radius budget;
- legal/data-processing policy.

## Precedence

Default ordering for widening decisions:
1. hard safety/security/legal deny;
2. kill switch / quarantine;
3. environment prohibition;
4. exact authority-resource prohibition;
5. certification/compatibility blocker;
6. quality/release blocker;
7. approval requirement;
8. grant/allow;
9. feature enablement.

An allow cannot override a higher-precedence hard deny.

## Effective decision

The engine emits:
- decision: ALLOW | DENY | REQUIRE_APPROVAL | BLOCKED | DEFER;
- stable reason codes;
- policy versions;
- matched rules;
- precedence chain;
- required approvals if any;
- effective capability/environment/target;
- decision_sha256.

The decision is non-secret and auditable.

## Separation of duties

ApprovalPolicy declares:
- operation/risk class;
- whether requester may approve;
- required approver roles;
- quorum;
- distinct-principal requirement;
- delegation rules;
- expiry;
- revocation;
- emergency override policy;
- Production restrictions.

### Default high-risk rules

Production publish, Developer, Breakglass, Host write, restore, destructive migration and broad provider activation SHOULD require a principal distinct from the requester unless an explicit emergency policy authorizes otherwise.

Self-approval for Breakglass is denied by default.

## Delegation

Delegation:
- is explicit and time-bounded;
- cannot exceed delegator authority;
- is scoped to capabilities/sites/environments;
- is revocable;
- is audited.

## Emergency approval

Emergency policy:
- never bypasses immutable hard security boundaries;
- has a short TTL;
- requires reason/incident reference;
- creates post-use review obligation;
- is separately reported.

## Conflict examples

Feature enabled + capability denied => DENY.
Capability certified + site policy Production-blocked => DENY.
Grant exists + approval required and absent => REQUIRE_APPROVAL.
Approval exists + stale target fingerprint => BLOCKED.
Kill switch active + any allow => DENY.

## Tests

- conflicting site/global policies;
- allow cannot override hard deny;
- self-approval denial;
- quorum enforcement;
- delegation expiry;
- emergency approval expiry;
- stale policy version invalidates cached decision;
- explainability contains the complete precedence chain.
