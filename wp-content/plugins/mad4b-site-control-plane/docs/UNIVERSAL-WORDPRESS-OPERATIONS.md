# Universal WordPress Operations Framework — rc.83

rc.83 consolidates existing governed WordPress capabilities behind a provider-neutral discovery and planning layer. It does **not** add a generic remote shell, arbitrary operation IDs, arbitrary package URLs, raw SQL, approval bypass, or Production mutation.

## Goals

1. A client asks for a semantic operation such as update, install, activate, deactivate, import, export, provider reconciliation, or Skills reconciliation.
2. `mad4b/wordpress-operation-discover` resolves universal WordPress semantics to the exact registered planner and executor. The pre-existing `mad4b/operation-discover` Remote Operation Parity surface remains unchanged.
3. Plugin operations may use `mad4b/plugin-transaction-plan` to normalize install/replace/update/activate/deactivate planning.
4. Dependency and certification impact is projected before mutation.
5. Existing exact planners/executors remain the authority boundary.
6. After a disconnected or uncertain write, `mad4b/operation-resume-status` reads durable idempotency state and requires reconciliation before retry when effect is uncertain.

## Contracts

- `mad4b.operation-registry.v1`
- `mad4b.operation-discovery.v1`
- `mad4b.plugin-transaction-plan.v1`
- `mad4b.dependency-impact-graph.v1`
- `mad4b.provider-transport-registry.v1`
- `mad4b.operation-resume-status.v1`

## Provider transport isolation

Provider MCP/REST transport descriptors move to `config/provider-transport-registry.json`. Unknown routes/callbacks are not auto-suppressed or allowlisted; they remain visible to fail-closed Peer Governance.

Internal retention is explicit per descriptor. A retained route must match its declared provider and HTTP method before internal dispatch.

## Multisite lifecycle

Plugin lifecycle plans now include `activation_scope: site|network`. `site_active` is derived from the site `active_plugins` option rather than WordPress's effective `is_plugin_active()` result, while `network_active` is read separately. Site-scoped lifecycle mutation fails closed while network activation controls the plugin. Network mutations require `manage_network_plugins`.

## Durable reconnect semantics

A disconnected request is never treated as evidence that a mutation failed. Resume status reports one of the safe client actions:

- completed → consume the recorded receipt;
- pending and not expired → reconnect without replay;
- pending and expired → reconcile provider/runtime state before retry;
- released after verified no-effect reconciliation → re-plan, then retry.

The resume endpoint never replays mutation and never exposes stored result payloads.

## Compatibility

Existing abilities remain in place. rc.83 adds orchestration on top of them rather than replacing their authorization, approval, plan-digest, package-integrity, rollback, readback, or certification rules.

## Declarative dynamic pipelines

The operation registry is also the source of truth for semantic aliases, ChatGPT read projection and pipeline profiles. A pipeline profile can add or reorder reviewed stages without changing the mutation executor, but the registry fails closed unless the profile preserves the mandatory order:

`planner → authorization → executor → verification`

Supported stage types are bounded to read checks, planning, authorization, execution, verification and reconciliation. The read-only `mad4b/operation-pipeline-compile` ability resolves conditions and exact stage bindings, emits a `pipeline_sha256`, and never runs a mutation stage. Arbitrary callbacks and arbitrary stage execution remain disabled.

## Dynamic provider trust ladder

Installed plugins are classified from runtime evidence into L0 inventory, L1 generic lifecycle planning, L2 governed read, L3 certified reversible write candidate and L4 functionally ready certified governed candidate. Unknown plugins cannot become write-capable merely because they are installed or active. Adapter availability, side-channel isolation, reversible contracts and provider certification are required evidence, and the matrix never creates authority or automatically enables mutation.

## Default provider autopilot

Dynamic provider autopilot is enabled by default.

For Staging, development and local environments the effective mode is `shadow_auto`. Discovery automatically generates an in-memory adapter candidate and a shadow provider identity certification proposal for uncovered plugins. These candidates are deterministic and fingerprinted but are not written to disk or registered as executable adapters.

For Production the effective mode is always `observe_propose_only`.

Default automatic behavior:
- generate adapter candidate: enabled
- shadow provider certification: enabled
- materialize generated PHP: disabled
- register generated adapter: disabled
- write certification: disabled
- create authority: disabled
- enable mutation: disabled

Automatic candidate generation and shadow identity certification stop at L1/lifecycle. L2/read requires a registered runtime adapter with at least one bounded read ability and clear side-channel governance. L3/L4 additionally require governed promotion with reversible contracts, certification evidence, functional acceptance and the normal authorization boundary.


## Content Experience bootstrap scenarios

Business content types remain configuration-driven. The fixed read-only ability
`mad4b/content-experience-bootstrap-plan` can inspect any registered post type and
produce a safe Content Experience profile proposal without creating content or
changing authority.

The bootstrap planner supports the generic scenarios:

`create_nonpublic → create_structured → update_existing → publish_or_private → verify → rollback`

Safe defaults are deliberate: new content is non-public, metadata starts with an
empty allowlist, external helpers are not auto-enabled, and taxonomy access is an
explicit allowlist. The planner can include assignable public/operator-visible
taxonomies and infer featured-media support from the live post type. It also
returns external helper candidates as evidence only; enabling SEO, translation,
builder or other provider helpers remains an explicit reviewed profile change.

The returned `profile_plan` is applied only through the existing governed
`mad4b/content-experience-profile-apply` mutation. Generated create/update/publish
routes become active on the next request, so this adds an ergonomic bootstrap
layer without creating a generic write bypass or hardcoding business types such
as tours, products, properties or jobs.


### Provider-compatible post media storage

The Content Experience layer keeps **attachment identity** canonical even when a
provider field stores a different database representation. A profile can now
project verified Media Library attachments into bounded storage shapes:

- single: `id`, `url`, `id_url`, `json_id_url`;
- gallery: `ids`, `csv_ids`, `urls`, `csv_urls`,
  `id_url_items`, `json_id_url_items`.

The adaptive bootstrap can infer these shapes from existing content without
returning the underlying values. This is useful for field systems that support
Media ID, Media URL, or combined ID+URL formats while preserving the rule that
remote acquisition creates a WordPress attachment first.

`mad4b/content-experience-media-binding-plan` is the read-only bridge from
verified attachment IDs to one exact Content Experience profile. It resolves a
single featured image and ordered single/gallery targets, validates contextual
usage fields, previews the exact provider storage projection, and returns the
logical `featured_media_id` / `meta` fragment consumed by the normal
create/update planner. Ambiguous field targets remain fail-closed.


### JetEngine field-definition discovery

When JetEngine is active, the JetEngine adapter now contributes media-field
candidates from its live post-type field context instead of relying only on
meta-key names or sampled posts. Media/Gallery fields with a declared
`value_format` of `id`, `url`, or `both` are translated into the
canonical Content Experience storage projections. Unknown provider formats are
reported but not mapped.

Provider declarations, registered-meta heuristics, and sampled live values are
kept as separate evidence sources. If they disagree, bootstrap marks
`spec_conflict=true`, keeps the alternative specs, and requires review rather
than silently widening or guessing the post-meta storage contract.


### Manifest lifecycle and recoverable imported media

Multi-image acquisition now has an explicit lifecycle instead of treating each
successful sideload as an isolated write:

```
remote manifest plan
→ per-item import plan
→ per-item verified Media Library import/reuse
→ durable recovery stage
→ exact manifest receipt
→ post-media binding plan
→ content plan
→ content apply
→ manifest bound to post
```

The manifest SHA and item index are execution-correlation evidence; they do not
change the reviewed per-image import plan identity. Every successful manifest
item receives durable stage evidence on the attachment. The stage records
whether the asset was **created for this manifest** or was a **pre-existing
reused attachment**.

If a later post plan or post apply fails, the imported Media Library asset is
not deleted. Its state remains `staged_unbound` and
`media/remote-recovery-status` can surface it for deterministic retry.
Calling the same Ability without a manifest returns a bounded overview of
unbound manifests and created-but-unbound attachment counts.

There is deliberately no automatic orphan deletion. Recovery reports
`auto_delete=false` and `cleanup_policy=manual_only_after_reference_review`.
This prevents data loss while also preventing abandoned imports from becoming
invisible operational debt.

Before content creation, `mad4b/content-experience-media-binding-plan` verifies
that every manifest index completed exactly once, that each staged attachment
matches the reviewed import plan, and that every staged item is consumed by the
post binding. It emits four exact hand-off identities:

- `expected_media_manifest_sha256`
- `expected_media_manifest_item_count`
- `expected_media_recovery_receipt_sha256`
- `expected_media_binding_state_sha256`

The generic content planner independently recomputes the recovery receipt,
attachment set, provider-normalized media mapping, and remote provenance state.
Any TOCTOU drift fails closed before mutation. After a successful content
mutation, the manifest is bound to the post and verified during readback;
rollback restores/removes this binding with the post state.


Manifest correlation now also carries a per-item SHA and the reviewed binding
role. The import stage persists both. The post-binding planner rejects role
drift (for example, a reviewed gallery item silently becoming featured).
A manifest item may declare `shared` when the same imported asset is
intentionally reused across multiple final post roles; otherwise the reviewed
role remains exact.


Manifest execution correlation is now fail-closed even when an Ability is
invoked directly rather than through schema validation. A manifest-correlated
item must carry the exact manifest SHA, bounded index, per-item SHA, reviewed
import-plan SHA, and a supported binding role. The per-item SHA is recomputed
from `index + binding_role + plan_sha256` before any media mutation. Unknown
roles are rejected instead of being silently coerced to gallery. The same
correlation survives source reuse, pre-download content reuse, and post-download
dedupe reuse paths.
