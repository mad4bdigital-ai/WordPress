# Contract — Generic Plugin/Provider Onboarding

Contract: mad4b.provider-onboarding.v1

## Goal
Allow MAD4B to become broadly capable without turning "unknown plugin" into arbitrary PHP/database mutation.

## State machine
DISCOVERED
→ CONTRACT_INSPECTED
→ SAFE_READ
→ SHADOW_WRITE
→ CANARY_VERIFIED
→ REVERSIBLE_VERIFIED
→ CERTIFIED_WRITE

BLOCKED may be entered from any state.

## Discovery
Collect only non-secret facts:
- plugin/provider identity
- version
- active state
- package identity
- public APIs/hooks/Abilities/REST/MCP surfaces
- capability/security checks
- data stores
- background execution
- native side channels

Discovery creates no write authority.

## Contract inspection
Prefer, in order:
1. official WordPress Abilities API;
2. official provider APIs/services;
3. stable public PHP APIs/hooks;
4. provider REST endpoints with exact auth/capability;
5. narrowly certified storage contract only when no supported public API exists.

Arbitrary eval and raw SQL are not ordinary adapters.

## Safe read
Read adapters:
- minimize returned secrets/PII;
- expose stable normalized schemas;
- prove provider/version assumptions;
- fail closed on incompatible runtime.

## Mutation certification
A write needs:
- semantic capability ID;
- exact provider package certification;
- permission model;
- expected state;
- bounded mutation;
- readback;
- reversibility/recovery;
- audit;
- risk/approval policy;
- target-site canary.

## Unknown-provider result
If inspection cannot establish safe mutation semantics, provider stays read-only/inventory-only. This is a valid final state, not a failure to be bypassed.
