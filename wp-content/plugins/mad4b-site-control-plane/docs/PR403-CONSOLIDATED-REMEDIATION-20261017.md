# PR403 Consolidated Remediation â€” October 17, 2026

Scope: Egypt Tour Gates and All Royal Egypt Staging only. No Production mutation.

## Verified failures and dispositions

| Failure | Evidence | Batch action | Still needed |
|---|---|---|---|
| Current Staging candidate binding stale | Both site diagnostics: installed 653b39a, bound to 1edf2a13 | Do not bypass: supply fresh Write-only handoff via existing owner-authenticated handshake | Exact owner Step-up and site-specific governed apply after deployment |
| Owner review or governed-read execution intermittently throws internal connector error | Multiple live errors; direct Enrollment discovery and full handshake still respond | Expose independent Write-only plan and current owner receipt state on proven direct read route | Live transport verification after upload |
| Simple site-info fails internally | Both tools returned internal failures; PHP exception source not proven | Guard DB introspection, keep bounded fallback instead of leaking exception | Live check and connector logs |
| MCP client schema divergence | ARE rejects ETG-only diagnostic_scope field | Do not alter authorization to fit stale host schema | Tools/list refresh after deployment |
| Six Elementor and one JetSmartFilters writes shadow-gated per site | Provider closure matrices | Preserve independent behavioral and rollback verification | Signed canary/rollback evidence |
| Skills certification stale | Live diagnostics reference prior candidate | Do not authorize from historical certificate | Recertify exact deployed build |
| Adapter embedded/runtime versions differ | Bundle 0.6.1; both actual runtime 0.7.0 | Do not replace active adapter | Check post-install runtime remains 0.7.0 |
| Underlying tool host internal failure root uncertain | Host gives generic error, no WordPress exception details | Reuse safe existing direct transport and return bounded blocker codes | Host trace if errors recur |

## Security and test contract

The private write_only_handoff helper is called only by the authenticated read-only full authority handshake. It independently reads the Write-only handshake, compares runtime generations, validates exact plan, commit and owner review hashes, and checks signed same-owner unexpired intent status. Neither receipt inspection nor handshake authorizes Write or grants Developer/Breakglass. Every missing or stale plan fails closed. Existing apply and owner step-up enforcement remain unchanged.