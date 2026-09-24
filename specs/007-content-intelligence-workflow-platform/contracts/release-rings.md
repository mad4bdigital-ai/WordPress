# Contract — Provider Release Rings and Autopromotion

Contract: mad4b.provider-release-rings.v1

## Rings
R0_DISPOSABLE:
isolated certification environment.

R1_CANARY_STAGING:
ETG Staging or designated first canary.

R2_SELECTED_STAGING:
small explicitly selected staging sites.

R3_GENERAL_STAGING:
artifact/capabilities generally eligible on compatible staging sites.

R4_PRODUCTION_ELIGIBLE:
certification allows Production consideration.

Production eligible != Production authorized.

## Promotion requirements
Each ring has exact requirements for:
- artifact identity;
- capability set;
- structural probes;
- behavioral probes;
- security profile;
- recovery/rollback;
- site compatibility;
- error budget / incident status;
- observation duration where required.

## Autopromotion
Optional policy may automatically promote certification between rings when:
- all requirements pass;
- no high-risk unresolved change;
- evidence dependency graph remains valid;
- target ring policy permits auto;
- no incident/quarantine;
- human approval is not explicitly required.

## Demotion/quarantine
A regression, security event, incompatible site probe or evidence invalidation may:
- hold promotion;
- demote affected capability/artifact;
- quarantine capability/artifact;
- leave unaffected capabilities eligible when dependency graph proves isolation.

## Evidence
Every transition records:
- artifact SHA
- capability IDs/fingerprints
- from/to ring
- evidence bundle
- policy
- actor/automation
- time
- reason
