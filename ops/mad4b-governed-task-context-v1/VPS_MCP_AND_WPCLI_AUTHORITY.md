# MAD4B native VPS MCP + WordPress MCP authentication

## Two authentication paths, one per-operation policy

- **WordPress MCP:** Users connect to their own authorized WordPress site/resource and authenticate via OAuth. Each user's original mapped identity, permissions and MCP grants remain in force; "usable by any user" does not mean anonymous site access.
- **VPS MCP:** The infrastructure tool uses its existing managed SSH/Native Operator authenticated principal and scoped target (e.g. tours-central:staging). On the enrolled WordPress instance, a **separately verified explicit WP-CLI --user** is the WordPress application actor. This is not an OAuth session and never makes that actor the ChatGPT client.
- Neither channel confers authorization merely by existing. MAD4B's native WordPress policy, scoped Abilities, and native provider receipts govern the **operation**, independently of transport. Do not create or self-sign identity evidence in PHP.

## Live findings / regression correction (2026-11-01)

1. The correct CLI entry point is `wp --path=/var/www/html --user=<authorized WordPress user> mad4b hub fallback <operation>` with one JSON object via standard input. Earlier assistant attempts passed arbitrary PHP through `wp eval`; that is not an end-to-end test of the registered fallback command. The host tool also returned a platform safety refusal before execution, which **does not establish** a WordPress authorization or PHP failure.
2. The fallback previously imposed an **extra blanket Production write denial**, even if MAD4B's native mutation policy and the exact Ability permitted an operation. That duplicate gate is removed. Original Production governance and the destination's operation policy remain fully enforced; this project work still targets **Staging only**.
3. The fallback now exposes an original **read-only bulk-queue list** Ability. It does not treat that read as a claim, a model execution, or a write.
4. The audit records requested + result response with request/result digests and verified WP-CLI actor provenance. An Ability returning without error is **not** proof that a Queue task completed or that independent readback occurred. The response explicitly reports `independent_readback_verified_by_transport=false` until the original operation provides its separate evidence.
5. The native operator's `wordpress_queue_execution_allowed=false` field is a static diagnostic value in its current status implementation. It must not be misreported as an observed authentication denial. Actual Queue grants and eligibility must be checked through the Queue's original policy and issuer, including the original assigned executor and its lease/receipt.

## Safe operating examples

Read current executor status using the verified original WP-CLI application actor:

```bash
docker exec -u www-data etg-commerce-staging-php wp --path=/var/www/html --user=1 mad4b hub fallback status
```

Read Bulk Queue through the exact original registered Ability after installation of this version:

```bash
docker exec -u www-data etg-commerce-staging-php wp --path=/var/www/html --user=1 mad4b hub fallback queue-list
```

Submit bounded evidence **only** when its actual original source, Content Job revision, actor and ability permission are verified; use the original `submit-keywords` command with a JSON object on STDIN and re-read the source artifact/Queue afterward. Never claim ChatGPT OAuth from WP-CLI. Do not copy a task assigned to `mcp_client` into a fictitious `vps_mcp` or `mcp_client` lease. Creating an independent VPS Queue executor requires an explicit native executor binding and matching Queue authorization and guards, with end-to-end claim/finish/rollback tests; it is **not enabled** by this fallback fix.

## Verification criteria

- Existing WordPress MCP/OAuth user connections remain unchanged.
- Verified WP-CLI user can read status/queue even without OAuth.
- Original WordPress policy controls write authority on Staging or Production, not the fallback's own environment ban.
- Wrong CLI user or unknown operation is still rejected; writes are audited and do not assert independent receipt verification.
- No fake client identity, queue claim, publication or Production mutation.
