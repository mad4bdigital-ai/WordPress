# WordPress-user default principal — route coverage (Staging 2026-10-30)

## Decision

`delegated_user` is the default. WordPress's actual authenticated user and
capabilities are the authority for native actions. NHI is separately selectable
for independent agents, not a prerequisite for every WordPress task. The default
does not create fake Agent IDs, grant wildcard rights, publish content by default,
or waive tenant/site isolation.

## Verified implementation

| Path | Observed state | Evidence |
| --- | --- | --- |
| WordPress User proof | Implemented for explicit WP-CLI --user, validated login cookies (REST requires nonce), and existing verified mapped OAuth / Application Password contexts | Local positive/negative tests; live browser bearer not tested |
| Shared site write predicate | WordPress administrator can pass the site write gate without NHI; arbitrary wp_set_current_user is insufficient | Staging passed |
| ContentJob create/read/cancel | Native registered WordPress Abilities, with no NHI; transactional audit and site/brand/tenant readback retained | Staging job created and cancelled, revision 2 |
| ContentJob transition | Reviewed native Ability path | No separate transition execution test |
| Local draft apply-bundle | Exact WP-CLI user with registered post type, draft/pending status and post caps; no automatic NHI enrollment | Positive gate + forged identity negative; no full bundle run |
| Explicit independent NHI | Previous independent mode preserved | Negative mode-switch test |
| Reversible adapter mutations | NOT migrated; journal requires actual agent_id | Code read: Reversible_Adapter_Mutations::execute |
| Generic remote write dispatch | NOT migrated; Agent grants and budget/claim paths remain | Code read: Authorization::claim_mutation |
| Host, SQL, filesystem, secrets, deployment, breakglass | NOT implicitly enabled by WordPress default | Separate reviewed capability paths required |

## Route inventory

At review time, the Staging plugin had 53 source files referencing
`can_mutate()`, 26 referencing `resolve_agent()`, and the active WordPress
runtime registered 224 write-annotated Abilities across native and MAD4B
providers. This is a coverage inventory, **not** evidence that all write
Abilities now support native WordPress execution.

The common principal proof is `MAD4B_SCP_Delegation_Mode::verified_wordpress_user()`.
The native execution whitelist currently includes only ContentJob create,
transition and cancel. Other operations retain their existing execution
authorization until their full persistence and recovery contracts support a
WordPress user actor.

## Why the remaining adapters are not switched by a boolean

The reversible mutation journal writes an `agent_id` into each mutation
record, uses the Agent in hooks and rollback, and re-resolves Agent identity on
undo. A broad bypass of `resolve_agent` would break durable actor attribution
and risk allowing operations that cannot be rolled back.

## Required remaining integration

1. Define a principal union (`wordpress_user` or `independent_nhi`) in
   authorization and mutation evidence; migrate schema additively without
   rewriting historical Agent records.
2. Apply verified WordPress caps at the actual Ability and transport boundary,
   not only at `Policy::can_mutate()`. OAuth requires existing verified mapped
   user and write scope; login-cookie REST requires a valid nonce.
3. Upgrade Reversible_Adapter_Mutations, undo, snapshots, budget attribution,
   and execution claims to accept a real `wp_user_id` and no fake Agent.
4. Audit provider-specific WordPress capabilities: content, media, plugins,
   WooCommerce, forms, settings. Keep host/SQL/developer and Production
   operations explicitly permission-scoped, not wildcard admin-by-default.
5. Test real WordPress cookie, Application Password and OAuth dispatch plus
   negative anonymous, revoked, unrelated tenant and cross-site cases.
6. Verify same principal across Plan → Execute → Audit → Readback → Undo.
   Only after acceptance apply the pattern to other MAD4B plugins.

No extra owner signature, release certificate or independent Writer approval
is introduced as a default prerequisite.

## Automated regression

```bash
php wp-content/plugins/mad4b-site-control-plane/tests/wordpress-principal-default-runtime.php
```

Staging only: the real `wp --user=2` ContentJob create/cancel smoke passed.
No Production changes or cross-site user registration occurred.