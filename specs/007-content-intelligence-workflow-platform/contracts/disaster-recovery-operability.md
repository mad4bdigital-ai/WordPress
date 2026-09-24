# Contract — Disaster Recovery and Operability

Contract: mad4b.disaster-recovery-operability.v1

## Recovery profiles

Define configurable:
- RPO;
- RTO;
- backup frequency;
- restore procedure;
- evidence retention;
- failover/fallback constraints.

Do not claim an RPO/RTO that has not been rehearsed.

## Recovery scenarios

Rehearse at least:
- database restore;
- plugin/control-plane rollback or forward-fix;
- corrupted artifact/evidence index;
- stuck job/lease recovery;
- provider outage;
- provider artifact quarantine;
- OAuth key compromise/rotation;
- lost credential;
- workflow duplicate/late callback;
- failed publishing mutation;
- Host Connector unavailability.

## Backups

Backup status is read back.
Restore test validates data plus application invariants, not only archive existence.

## Kill switches

Independent kill switches exist for high-risk planes:
- public publishing;
- WorkflowProvider writes;
- provider/capability artifact;
- Host Connector writes;
- Developer;
- Breakglass;
- optional Local/External authority advertisement.

Kill switches default to narrow behavior and are audited.

## Degraded mode

Degraded mode is explicit:
- what reads remain;
- what writes are blocked;
- whether queued jobs pause;
- whether fallback provider is permitted.

No silent security downgrade.

## Incident evidence

Incident records include:
- time window;
- affected identities/capabilities/sites;
- candidate/artifact identities;
- containment actions;
- recovery;
- post-incident permanent regression tests.

## Restore gate

Production-eligible release families that introduce new durable state or irreversible writes require a restore/rollback rehearsal appropriate to the risk.

## Host execution and terminal independence

Normal operation SHOULD NOT require a human to open a hosting-provider terminal.

Recovery design includes:
- canonical `wp mad4b` diagnostics when WordPress core can bootstrap;
- Host Runner for non-HTTP execution;
- provider API/CLI adapters where certified;
- minimal out-of-band Recovery Runner/transport when WordPress/plugin boot is unavailable.

Required recovery rehearsals:
- WordPress plugin boot failure while package/runtime health remains inspectable;
- restore of an attested known-good package without normal content-write authority;
- Host Runner queue/lease corruption;
- provider API outage with explicit non-authorizing fallback;
- loss of WP-CLI with alternate certified read path;
- runner credential rotation/revocation;
- stale or replayed recovery job rejection.

Interactive hosting shell remains a last-resort operator path, not the normal automated recovery contract.
