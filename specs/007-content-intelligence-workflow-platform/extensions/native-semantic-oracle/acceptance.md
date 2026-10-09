# Adversarial native Browser Acceptance checklist
| Case | Expected outcome |
|---|---|
| Unrecognized provider / brand changes | No site-name branching; native provider stays capability-specific |
| Public published WordPress page, same-origin canonical | Server signs plan; genuine signed matching browser evidence may PASS |
| Page password protected or draft | Never put in native plan |
| Native build manifest mismatch/stale | Block plan before browser |
| Browser worker has no private RSA signer | Fail before remote sessions |
| WordPress has no pinned public key | Block plan |
| Wrong/weak RSA key, tampered evidence or malformed signature | BLOCKED |
| Validly signed evidence but observed canonical differs | FAIL |
| Client forges matches_expected without browser signature | BLOCKED |
| WordPress source fingerprint, plugin inventory, theme or admin revision changes mid-execution | BLOCKED |
| Expired issued_at / challenge invalid | BLOCKED |
| Non-HTTPS/off-root/subdirectory site in unsupported scope | BLOCKED without over-generalizing |
| Generic provider and specialist provider both recognized | Explicit profile or other governed intent required; no default by hostname |
| High-risk booking/payment mutation or arbitrary selector/JS | Unsupported; never given browser authorization |
| CI queued or local source simulation only | No release PASS |

| Valid-looking PEM but weak / invalid RSA key | NOT READY / BLOCKED |
| Browser signs with a different valid RSA key | Abort before MCP plan and remote session using SPKI fingerprint mismatch |
| Public RSA key exists but no trusted browser run | Public-key validity may be true; browser_attestation_ready and release_ready remain false |
| Two native plans issued in the same second | Distinct nonce and plan_digest |
| Replayed signed evidence inside active window | Do not claim single-use protection; independent consumption ledger required before release |
| Signed plan snapshot derived from empty or stale provider registry | Reject, require actual registry-bound fingerprint and operator revision |
| Public GET probe attempts POST/REST/admin/blob script | Blocked by passive-only network mode, without changing ETG specialist policy |
| Capability Atlas inventory_complete=true | All external/semantic/replay/release evidence gates remain NOT_PROVEN |
| Generic passive browser attempts WebSocket | Independent WebSocket route closes it before connecting to server |
| External browser adapter lacks WebSocket route support | Block the generic browser run; never downgrade to HTTP-only proof |
| WebRTC or DNS/IP egress not audited | Release gate remains blocked regardless of passive-only source checks |

| Specialist + generic browser provider both recognized, no profile | Choose single primary specialist, not supplemental generic; still do not reuse specialist for unsupported site |
| Multiple primary functional providers recognized, no operator selection | Ambiguity BLOCKED |
| Saved operator profile selects supplemental generic | Honor declared operator profile without hostname rules |
| Arbitrary invalid provider selection role | Reject inside WordPress Provider Registry |
| Generic page executes JavaScript / emits dynamic XHR/fetch | Block script and active request transport; JS disabled |
| Generic navigation redirects to a second WordPress page | Intercept and reject any document path not in signed plan |
| Distinct cases share the same signed public URL | De-duplicate navigation allowlist, preserve separate semantic cases |
| Signed-but-ill-typed HTTP status, extra release_ready key, unexpected observer claims | Treat as infrastructure BLOCKED, never spurious product FAIL or release PASS |
| First public page entries password protected, duplicate ID/path | Scan bounded additional published pages and select unique public candidates |
| Static marker count with site JavaScript disabled | Use fixed validated attribute locator and count(); never run evaluateAll or site JavaScript |
| WordPress site has pages but no installed plugin mapped to the generic capability | Recognize by `source_post_types: ['page']`, no ETG/brand fallback |
