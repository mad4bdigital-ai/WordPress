# Content Hub execution: ChatGPT Client default; Background AI opt-in

Scope: `tours.dev.mad4b.com` Staging, 2026-10-30. No Production deployment or actual content publication.

## Decided behavior

- **Execution/orchestration mode** defaults to `chatgpt_client`. The authenticated ChatGPT client receives a bounded handoff (exact ContentJob, stage, revision, recommended Skill and next MCP Ability), examines source Artifacts, and supplies evidence-backed parameters in subsequent tool calls. **The WordPress server never pretends the chat client performed an AI inference.** There is no separate model API/key requirement for client-driven execution.
- `background_ai` is an *optional execution mode*, disabled by default. A WordPress operator must explicitly enable it and select it for a specific active, site/brand-matched ContentJob. Selection requires a real installed background-runner callback. Dispatch uses the registered `mad4b_hub_background_ai_dispatch` filter and only accepts a correlated queued/running receipt; it never invents a model response.
- The **review/quality mode** is separate: `autonomous` continues to be the default; `assisted` and `strict` remain opt-in. Choosing an executor never bypasses Fact/Editorial/SEO QA, company policies, publication authority, tenant isolation or revision checks.
- The **principal** remains the authenticated, verified WordPress user under `delegated_user` by default. Independent NHI agents are optional; this feature does not change OAuth/Agent Registry or raw shell/SQL/Production grants.

## Registered Central Hub MCP Abilities

| Ability | Purpose | Mutates? |
|---|---|---|
| `mad4b/hub-executor-status` | Read global default, job-specific selection and runner readiness | No |
| `mad4b/hub-executor-handoff` | Return next ChatGPT-client MCP tool and real context required | No |
| `mad4b/hub-executor-configure` | Enable/disable background availability (default false) | Configuration only |
| `mad4b/hub-executor-select` | Set executor for an exact ContentJob | Configuration only |
| `mad4b/hub-executor-dispatch` | Ask an explicitly selected installed background worker to queue execution | May enqueue with real receipt only |

Endpoints: `/wp-json/mad4b-hub/v1/execution/{status,configure,select,handoff,dispatch}`. Read and write have separate WordPress permission checks; no anonymous access.

## Example handoff from actual Staging job

`14d65a58-73f2-4f1c-8512-c9fb652317a0`:
- stage `INFORMATION_GAIN`, review `autonomous`
- executor `chatgpt_client`
- Skill candidate `research-provider-routing`
- next Ability `mad4b/research-keyword-record`
- `backend_model_called=false`. The client must inspect the exact evidence and provide the required keywords/input; output is a plan, not execution.

## Verified on Staging

- All five WordPress Abilities and REST routes registered.
- Default status `chatgpt_client`, `background_ai_enabled=false`, content review `autonomous`.
- Client handoff returned exact existing job, stage, next Ability and Skill name.
- Attempting to select background while disabled: denied. Enabled for test, tried selecting with no registered worker: denied. Optional toggle was then reset to false. Dispatch without selecting: denied.
- Unauthenticated REST read: HTTP 401.
- Standalone runtime contract `CHATGPT_CLIENT_EXECUTOR_DEFAULT: PASS`. PHP lint passed.
- Site HTTP 200. No new Hub ContentJob, model-generated text, published post or remote company data.

## Pending

- **Real ChatGPT client MCP round trip** from the connected session (external client invocation and actual Artifact append), to confirm projection and completion, not just WordPress registration. A WP-CLI probe is not an authenticated ChatGPT OAuth client session.
- Background runner discovery/integration is absent on the verified host. Optional mode has a provider extension contract but cannot execute until a compatible worker is installed and registered.
- Continue iterative research, actual writer/critic/QA operations, canonical release, remote consumer readiness and feedback loops as separate work.

These are operational modes; the server cannot cause ChatGPT to continue in the background without a live client turn, and must not claim that it can.
