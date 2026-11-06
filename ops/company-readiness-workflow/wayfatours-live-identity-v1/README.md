# Wayfatours live company identity — source reconciliation

This is a source-only, reviewed patch set for the VPS-run MAD4B Company Provisioning and Broker modules. It records the active identity correction without importing the original VPS directory into the WordPress repository, without copying secrets, and without touching historical receipts.

## Current canonical identity
- Company: Wayfatours, Production
- Origin: `https://wayfatours.com`
- WordPress Site UUID: `a3a7cc36-db7e-4a14-8456-b41857def05c`
- Container: `php-guxhp9y6vgh7oouuw8plxrlr`
- Database: `wayfa_rebuild_wp`

## Patch sources

- `provisioning.patch` targets the existing VPS root `/opt/mad4b-company-provisioning` (21 original runtime/config/test files).
- `transport.patch` targets the existing VPS root `/opt/mad4b-company-transport` (2 original Broker configuration/diagnostic files).
- `source-manifest.json` records **before and after SHA-256 hashes** for each file. A release tool must reject an unexpected previous hash and verify the after-hash; do not apply this patch by filename-only matching.

Both patch groups were applied to **isolated temporary copies of the exact previous files** with `patch --batch --fuzz=0 -p1` and all 23 resulting SHA-256 hashes matched the live target copies. No WordPress Production data was changed by generating the Git artifacts.

### Safety and release

- Not an authorization, owner grant, 305/312/313 receipt, deploy instruction, or historical database rewrite.
- The source commit documents **already applied host changes**; a future deployment must reconcile against the live site identity, not re-run migration blindly.
- Legacy site UUIDs in genuine source-to-target migration evidence, rollback snapshots and historical receipt records intentionally remain unchanged.
- Admin emails, secret values, credentials, private key material and production databases are not included.
- No automated merge or deployment from this source-only PR.

### Live verification

6/6 independent active policy-binding readbacks passed; 5/5 runtime regression checks passed (Schema Compatibility, Publication Plan, Governed Runtime, Plugin Dependencies, Broker Signature).
