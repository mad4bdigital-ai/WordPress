# MAD4B Native Query Builder scoped invalidation — 2026-11-16

## Root cause

Existing JetEngine Query Builder uses a tenant/site, WPML locale and WCML
country/currency partitioned object cache. Previously a global revision bumped
on any published post mutation, invalidating ALL groups.

The 1.3.9 live governor was newer than selected Git source, so its full code
was forward-reconciled before patching to 1.4.1. All sensitive/private query
exclusions, WCML market checks, and legacy fragment invalidation are retained.

## Persistent source-derived dependency version

The flag mad4b_render_query_scoped_generation_v1 defaults OFF on unproven sites.
Tours.dev Staging enrolled ON after tests. For registered public posts queries
derive revisions from CPTs and taxonomies referenced in live JetEngine source.
For public terms queries derive revisions from taxonomies and their registered
WordPress source post types (required for hide_empty term count correctness).

save_post, post meta, term changes, term meta and set_object_terms update scoped
source revisions. Existing global generation still expires legacy HTML fragments.
Native query cache groups no longer invalidate on unrelated content edits.

Unknown queries fall back to global revision. Security, market, policy and
explicit external invalidations bump a separate global SAFETY version shared
by all scoped groups.

SSR/AJAX parity certificates use the same effective generation as the native
JetEngine Query Builder. The global legacy fragment revision alone does not
invalidate an otherwise valid component proof.

## Evidence and rollout

- Offline 16/16 dependency regression assertions PASS.
- Live source queries 193 and 291 resolve 2 and 16 dependencies respectively.
- Controlled version bumps on live Staging: unrelated services unchanged;
  tours source changed both dependent queries; destinations taxonomy changed
  terms query only. Global legacy epoch stayed unchanged in controlled test.
- Native SSR/AJAX parity regression 21/21 PASS.
- EN/ES HTTP 200 and Critical CSS ready remain intact.
- FastCGI HTML shared market cache remains protected/BYPASS.
- Wayfatours production is NOT changed.

Rollback: set scoped flag OFF before reverting governor and native lazy plugin.
Protected VPS rollback copies:
  /opt/mad4b-company-provisioning/ops/critical-css-cache-root-20261116.
