# Contract — Host Connector

Contract: mad4b.host-connector.v1

## Purpose
Expose host-level capabilities without widening WordPress filesystem or Developer authority.

## Separate identity
Host Connector requires its own:
- transport subject binding;
- NHI/agent grant coordinates;
- target/environment scope;
- approval/budget policy;
- audit/evidence.

WordPress administrator status alone is insufficient.

## HostTarget
- host_target_id
- provider_id
- environment
- account/site/domain scope
- allowed resources
- status

## Capability families
Read first:
- host.files.list
- host.files.read
- host.logs.list
- host.logs.read
- host.php.status
- host.cron.list
- host.cron.health
- host.process.status
- host.backup.list
- host.backup.status
- host.database.list
- host.database.status
- host.domain.list
- host.domain.status

Future write candidates:
- host.files.patch
- host.php.update-policy
- host.cron.schedule
- host.cron.unschedule
- host.backup.create
- host.backup.restore
- bounded host.database.*
- bounded host.domain.*

## Write requirements
Every write:
- exact target;
- expected current state;
- bounded desired change;
- blast radius;
- reversibility/recovery;
- approval;
- readback;
- evidence.

Arbitrary Production shell is not an ordinary capability.

## Provider adapters
Hostinger is one possible adapter. Generic host contracts cannot depend on Hostinger-specific schemas.
