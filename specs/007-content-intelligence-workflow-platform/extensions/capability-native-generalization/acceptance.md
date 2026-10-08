# Adversarial design objections & acceptance matrix
| Objection | Required outcome |
|---|---|
| Another WordPress site does not have ETG plugin | Atlas still lists known plugin-family capabilities; no ETG driver chosen |
| Multiple unrelated plugins claim `browser.dom_result_count` | Both candidates retained; no implicit provider preference |
| Unknown plugin with no family mapping | Mark incomplete/unmapped; no fake capability |
| Plugin family has no semantic oracle | Declared/inventory only; never PASS |
| Site sends arbitrary CSS, JavaScript or a click/POST action | Declarative Driver rejects extra case fields and unsupported probe type |
| Site tries `//evil.example`, `../`, encoded traversal, REST, wp-admin, login, query arguments | Plan rejected pre-browser |
| Page redirects from expected path | Fail closed |
| Page title or canonical differs from independent native oracle | `matches_expected=false`; cannot issue certificate |
| Good observation but no reducer or invalid signature | No certificate |
| Changed build identity/revision or challenge nonce | Evidence assembler/reducer rejects |
| Browser driver registry contains generic Driver but no WordPress Provider | No selection/plan; remain blocked |
| Plugin asks for write, install, checkout or production operations | Separate approved effectful driver and transactional authority required |
| Native PHP/Node test absent or CI queued | No runtime acceptance claim |

## Site-neutral solution federation acceptance — source slice

- Exact-site admission across unrelated CMSs: scoped opaque ID (including UUIDs starting with digits), environment, origin digest, runtime generation; reject cross-site or stale scope.
- Catalog introspection is dynamic through host-provided authorized read callbacks, never through fixed provider slugs. A bare list cannot prove coverage complete; a typed site-bound catalog is required.
- Plugin inventory supports ordinary plugins, MU plugins, drop-ins and REST-visible Abilities; the existing Capability Atlas remains the owner of declared capability claims; G3 remains the owner of effect certification.
- High/exceptional risk and mutating-effect claims remain review-only and cannot be automatically routed to an unrestricted file writer. Existing Plugin Discovery risk evidence is joined by exact plugin file and version.
- A planner's mutation proposal requires independent backup, rollback, consent, site scope, execution/health readback and separate Production approval. The proposal cannot execute or authorize a tool.
- External catalog paging is deterministic and refuses stale snapshots; source observation expiry is checked when bounded timestamps are available.
- Source proof: run `node tools/solution-discovery/test-federation.mjs`, the PHP 7.4/8.3 Solution Discovery and Assistant Router fixtures, and the independent Feature 007 preflight. Missing runtimes are BLOCKED, never PASS.
- Operational proof: actually connected catalog/host tools for an enrolled site, independent Staging runtime, approved executor and real postcondition. The portable module alone is **not** a live connector or host mutation.
