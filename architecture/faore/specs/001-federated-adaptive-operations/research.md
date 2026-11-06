# Source Inventory and Design Decisions

Read-only inspection on 2026-11-05 confirmed the WordPress Git master commit 5c72e489, with source paths:
- `wp-content/plugins/mad4b-site-control-plane`
- `ops/mad4b-portfolio-signed-binding-v1/mad4b-company-portfolio-manager.php`
- `ops/mad4b-same-host-broker-staging-v1/306-mad4b-central-vps-broker.php`
- `ops/mad4b-same-host-broker-staging-v1/312-mad4b-broker-exact-ssh-authority.php`
- `ops/company-readiness-workflow/remote-delegation-orchestrator/signed_broker.py`
- `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-ownership-reconciliation.php`

The active central staging WordPress PHP container mounts `/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy` as `/var/www/html`. It is not a Git repository. The host-local isolated source checkout was created beside that runtime, not inside its document root.

## Decisions
- Keep `architecture/faore` as an independent nested Spec Kit project: root `.specify` already governs ETG Dynamic Filter SEO Bridge.
- Preserve existing Broker and execution systems. Define FCP contracts first, then implement adapters.
- Do not assume `ops/` contains the complete current Company Manager plugin without reconciling with live deployed files.
- Do not use the illustrative Wayfatours contract as proof of a live authorized operation.

## Unresolved live inventory
Canonical Company Manager source owner, durable queue schema, actual local auth/admission API, Source Release Manager generation storage, Coolify binding and per-company capability manifests need verified inspection in task T001 before implementation.

## Original Broker and live-company inventory (read-only; 2026-11-05)
- 305: original WordPress intake request option keyed by original 32-hex request ID. A request is not an execution.
- 306: original Central VPS Broker JOB and CLAIM option records. A claimed job remains pending independent host authority.
- 312: original exact signed authority preflight. Same-Host path is restricted to enrolled Staging-company targets.
- 313: original root-owned native worker and independent readback. Its receipt must not be fabricated by a FAORE read-side projection.
- WordPress Remote Work Queue is already the durable semantic queue. FAORE reads it instead of creating another WordPress queue.
- Central Staging Site UUID: e5e64bca-ed62-48d2-b2b3-dff693cd702a (profile revision 11 at inspection).
- Wayfatours is enrolled in Central Company Registry as Production; UUID a3a7cc36-db7e-4a14-8456-b41857def05c; origin https://wayfatours.com.
- Original Native Target Binding references wayfa-rebuild-php but it is absent from the Docker host. Only a maintenance container was visible.
- Original 313 readiness snapshot says ORIGINAL_313_RECEIPT_REQUIRES_EXACT_JOB_READBACK.
- Central Remote Work Queue has 22 expired pending jobs: 10 performance sampling and 12 browser acceptance. No claims or retries were made.
- Broker journal signed preview succeeded; the recent live slice contains no Wayfatours-scoped event. Absence of an event is not an execution failure.
- New Broker / Queue FAORE GET projections are only source changes, not deployed routes.
