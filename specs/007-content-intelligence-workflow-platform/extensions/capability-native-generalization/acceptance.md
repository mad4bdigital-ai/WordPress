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
