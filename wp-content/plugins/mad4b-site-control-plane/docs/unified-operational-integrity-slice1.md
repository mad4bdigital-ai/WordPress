# MAD4B Unified Operational Integrity — WP Dedicated Slice 1

This code slice integrates existing Site Profile, Context Authority and Content Jobs. It **does not** establish a new grant or automatically publish content.

## Enforced contracts

- Derive scope from `MAD4B_SCP_Deployment_Mode_Resolver`, rejecting missing enrollment, origin/deployment drift and untrusted client-supplied tenant/brand.
- Preserve existing `brand_id` during display-name edits; brand transfer and multi-brand activation require an explicit governed migration, not a rename.
- Quarantine sources without a matching site and brand and refuse source ID overwrites if an existing source is owned by another/unverified brand. Prevent cross-source reading through the existing provider gateway that consumes Context Authority.
- List and load Content Jobs by both site and brand. Reject creation when caller-supplied brand differs from verified Brand Profile. Keep DB schema and existing job identifiers unchanged.
- A read-only Dedicated status call never initiates legacy Site Profile bootstrap migration when a stored v2 option is absent. Enrollment/migration is a separate operator action.

## Intentional fail-closed and migration impacts

- Existing Content Jobs or Context Sources with no valid Brand Context or no enrolled Deployment Binding are hidden/blocked rather than silently assigned to an arbitrary brand.
- Legacy/source records missing `brand_id` remain physically untouched but are inaccessible until an explicit migration with ownership evidence. The older source hash is preserved and collisions reject instead of assigning ownership.
- This slice does not make the entire plugin's write surface pass through the new guard: next mandatory integrations include source/asset mutations, other content operations, workflow executors, asynchronous claims, and the MCP custom-server tool inventory.
- This slice does not certify production readiness, source rights, external signatures, or WordPress Multisite switching under real traffic.

## Native acceptance

`php -l` on all changed PHP files; `php tests/deployment-mode-resolver-runtime.php` and `php tests/unified-operational-scope-runtime.php` on an exact checkout; the cross-repository compatibility checker; then a non-destructive Staging canary with one enrolled site and two synthetic Brands. Existing operator workflows require migration before sources with unknown ownership are reused.
