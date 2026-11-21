# PR #745 — Live WordPress source reconciliation hold

**Site:** `https://tours.dev.mad4b.com` (Staging, not Production)  
**Audit date:** 2026-11-03 UTC  
**Audited Git source:** `16dd69de0dc3ff85f4f1a0d2168dbc60af92bf86`  
**Machine:** `srv2030908`, Docker container `etg-commerce-staging-php`

The authoritative hashes and paths are preserved in [the structured live-source parity evidence](evidence/live-source-parity-20261103.json). Its Git source SHA predates the documentation-only commit that adds the evidence; re-run the verification before any promotion. **No live PHP files were replaced during the audit.**

## Findings

| Inventory | Count |
| --- | ---: |
| Candidate changed PHP runtime files | 10 |
| Exact deployed matches | 4 |
| Live source drift | 3 |
| Candidate modules not installed on this site | 3 |
| Live bootstrap additional `require_once` dependencies vs candidate | 47 |
| Additional live dependencies absent from candidate Git tree | 45 |

Exact drift includes:
- `includes/class-mad4b-scp-content-readiness-alerts.php`: additional live **pricing-workbench**, **locale experience**, **Geo/transport** alerts and native ContentJob integration.
- `includes/class-mad4b-scp-frontend-impact-priority.php`: live tour-specific discovery vs candidate generic public post-type discovery.
- `mad4b-site-control-plane.php`: many later live company/authority/provider imports and bootstrap controls not represented on PR #745.

Uninstalled modules: `mad4b-frontend-impact-loader.php`, `mad4b-readiness-tour-origin-evidence.php`, `class-mad4b-scp-content-readiness-admin-view.php`. Their absence reflects a different active runtime composition: the live main plugin directly loads/boots Frontend Impact and currently includes Readiness presentation/source evidence inline. **Do not blindly copy only these files** without a coordinated cutover.

The separate local checkout `/opt/staging/mad4b-wordpress-consolidation-20261030` is on a different Git history/HEAD and does **not** establish either GitHub PR or deployed code parity. The isolated PR checkout `/opt/staging/mad4b-pr745-ci-source-20261103` is the exact GitHub PR source after fetch.

## Work completed

- Preserved the live WordPress files and saved read-only copies of the three changed classes and bootstrap in `/opt/staging/mad4b-pr745-live-reconcile-20261103/live/`.
- Reconciled `Readiness_Workflow_Options` pricing/Geo transport code-family classification from live into GitHub, which now exactly matches the deployed file.
- Added `tools/verify_live_wordpress_source_parity.py` to compare exact Git candidate vs mounted container hashes, plus actual bootstrap dependency inclusion. It reports `HOLD_SOURCE_RECONCILIATION` and returns **exit 2** with `--enforce`.
- PHP lint PASS on the four live files; public EN/ES pages return 200/TLS verified; unauthenticated Frontend Impact REST correctly returns 401.
- No Production, pricing, reservations, Content Jobs, or live plugin assets modified.

## Source convergence prerequisites

1. Identify which upstream branches/PRs own the 45 live-only bootstrap dependencies. **Reconcile their actual source and module dependencies first**, rather than copying arbitrary files or dropping hooks.
2. Extract the newly active Readiness Geo/locale/pricing providers into source-owned, optional adapters to preserve both the live features and the site-neutral 900-line service boundary. Add their existing live acceptance cases.
3. Review and reconcile the live Frontend Impact discovery strategy with the generic Git candidate. Verify six locales × home/archive/tour route coverage and meaningful fallback before changing the runtime.
4. Reconcile the main plugin bootstrap using a **base-aware three-way merge**, with all live-only imports accounted for. Avoid replacing the entire live bootstrap with the shorter PR version.
5. In an isolated candidate Staging environment, validate PHP lint, route permissions, source fingerprints, actual interactive JSF filters and booking widgets, product/MU presentation authority, ContentJob state immutability and rollback. Only then rerun exact-source parity and complete normal CI/owner review for the new HEAD.

## Repeatable read-only release preflight

```bash
python3 tools/verify_live_wordpress_source_parity.py \
  --repo /opt/staging/mad4b-pr745-ci-source-20261103 \
  --base 5c72e4893e36e992f056c90cfc75908b3a8f7785 \
  --container etg-commerce-staging-php \
  --docroot /var/www/html \
  --out /opt/staging/mad4b-pr745-live-reconcile-20261103/evidence/live-parity.json \
  --enforce
```

**Exit 2 = HOLD** and must prevent replacing the installed source; exit 3 = audit unavailable/error, also HOLD. A green GitHub CI workflow alone cannot override this gate. This preflight currently exists as an executable tool and recorded operator decision; deployment-pipeline integration is separately required and is **not** claimed complete.

**Release disposition:** Keep PR #745 in Draft; no merge or deployment until missing dependencies and changed live source are reconciled. The prior owner attestation was for a different HEAD and is not reusable.
