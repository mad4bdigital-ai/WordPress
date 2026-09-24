# Contract — Dynamic Artifact/Capability/Behavioral Certification

Contract: mad4b.dynamic-provider-certification.v1

## Principle
Version is metadata and a risk/compatibility hint.
Evidence is certification authority.

Eligibility:
exact artifact
+ capability contract
+ structural compatibility
+ behavioral evidence
+ security invariants
+ rollback/recovery evidence where required
+ environment compatibility
+ canary/release-ring evidence
= capability eligibility.

## Capability lifecycle
- UNKNOWN
- DISCOVERED
- STRUCTURALLY_COMPATIBLE
- READ_CERTIFIED
- SHADOW
- CANARY_VERIFIED
- REVERSIBILITY_VERIFIED
- WRITE_CERTIFIED
- ACTIVE
- QUARANTINED

Transitions are evidence-backed. QUARANTINED can be entered from any trusted state on critical drift/failure.

## Capability fingerprint
Each capability has a fingerprint derived from:
- semantic capability contract version;
- relevant implementation subtree/file hashes;
- required schema/migration identity;
- input/output normalization contract;
- error semantics;
- security profile dependencies;
- rollback/recovery implementation dependencies;
- required runtime/provider invariants.

Provider package fingerprint and capability fingerprint are separate.

## Artifact Diff Classifier
Classify exact artifact change:
- NO_RUNTIME_CHANGE
- ADDITIVE
- BEHAVIORAL_CHANGE
- SECURITY_RELEVANT_CHANGE
- SCHEMA_CHANGE
- EXECUTION_ENGINE_CHANGE
- UNKNOWN_CRITICAL_CHANGE

Classifier output includes affected capability IDs and confidence/evidence.

UNKNOWN_CRITICAL_CHANGE invalidates affected writes conservatively.

## Evidence dependency graph
CertificationEvidence declares depends_on fingerprints such as:
- implementation tree
- repository/schema
- queue engine
- contract version
- auth/security surface
- provider dependency versions

On new artifact:
1. compute diff;
2. find changed dependencies;
3. invalidate only affected evidence/capabilities;
4. reuse unaffected evidence only when dependency proof is exact;
5. run lightweight smoke for reused evidence;
6. record original evidence + reuse decision.

## Global registry + site compatibility
A centrally certified exact artifact MAY reuse certification across sites when artifact SHA is identical.

Site eligibility additionally requires SiteRuntimeCompatibility:
- PHP
- WordPress
- DB/server
- required extensions
- permissions
- cron/background execution
- object cache
- known conflicts
- provider runtime state
- site-specific configuration constraints.

Global certification does not auto-grant a site.

## Autopromotion
May be permitted when policy allows and ALL are true:
- trusted source;
- exact artifact acquired/scanned;
- no unresolved high-risk change;
- affected structural probes pass;
- affected behavioral probes pass;
- rollback/recovery requirement passes;
- current release-ring canary passes;
- no hard governance blocker.

SemVer alone can never autopromote.

## Historical learning
Every provider bug or regression once confirmed SHOULD become a permanent certification probe and dependency rule.
