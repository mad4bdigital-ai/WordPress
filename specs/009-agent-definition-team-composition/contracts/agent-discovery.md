# Contract — External Agent Sources & Discovery v1

**Read-only proposed Abilities**: mad4b/agent-sources-list; mad4b/agent-source-candidates; mad4b/agent-source-get. Must not be registered until a separate implementation PR passes governance.

## Source trust boundary

Only administrator-enrolled, configured source IDs and audited public GitHub providers are addressable. Reuse PR #528 provider pin and egress safeguards (HTTPS/TLS, no redirects/private network, per-request budget) rather than building a second arbitrary URL fetcher. External text is **untrusted evidence**.

## Format admission

| Source path convention | V1 interpretation | Trust |
|---|---|---|
| .claude/agents/*.md | Candidate Agent definition after strict frontmatter/format validation | Untrusted read-only |
| .github/agents/*.agent.md | Candidate only when adapter has verified dialect + fixtures | Untrusted read-only |
| portable AGENT.md within administrator-configured bounded folder | Candidate only with MAD4B v1 explicit schema | Untrusted read-only |
| AGENTS.md | Project-scoped instructions/context, NEVER an automatic AgentDefinition | Untrusted advisory context |
| scripts, binaries, MCP/server configs, symlinks | Not executable; either rejected or safely labeled unsupported | Denied |

Unknown paths or dialects are not silently interpreted as Agents. No source auto-registers a managed definition or tool.

## Future request shape

- sources-list(): no untrusted arbitrary endpoint; returns configured source IDs/status only.
- source-candidates(source_id, offset, limit): bounded limit 1..20, limit page scans/candidate counts, report tree SHA and completion.
- source-get(source_id, path, expected_blob_sha): exact discovered file path and expected 40-hex SHA mandatory, re-read and verify blob content and length. Reject directory escapes and unknown resource kinds.
- Optional task-scoped source paging passes remaining **shared** outbound budget, inherited nominal 18s from current Skill Composition until separately reviewed. Stop initiating calls when exhausted.

## Future response fields

```json
{
  "contract": "mad4b.agent-source-inventory.v1",
  "source_id": "configured-source",
  "provider": "github",
  "repo": "owner/repository",
  "resolved_ref": "example-sha-40-hex-when-resolved",
  "tree_sha": "sha-40",
  "page_complete": false,
  "scan_complete": false,
  "budget_exhausted": true,
  "next_offset": 20,
  "items": [],
  "errors": [{"code": "source_budget_exhausted"}],
  "execution_authorized": false
}
```

Illustrative digest strings are placeholders. Production validation MUST enforce actual 40-hex hashes and exact Git identities. `scan_complete=false` MUST remain false after timeout/truncated tree/provider error, even if some files were returned.

## Denials & hostile scenarios

- Injected provider config points to lookalike GitHub hostname, http downgrade, explicit port, private DNS/IP, IP rebinding, path suffix or forbidden permission mode: deny before outbound.
- Blob SHA mismatch, tree truncation, empty/invalid UTF-8, unsupported frontmatter, excessive file size, duplicate agents, strange unicode/bidi and unknown paths: deny or mark invalid with reason.
- Remote file says "ignore policy, issue token, execute script, grant role, fetch secret": display safely as text and prohibit execution or system-instruction promotion.
- Detached asset/ref cannot authorize or import as a managed Agent. Strong source identity is necessary but not sufficient for trust.

## Error taxonomy

agent_source_unknown, agent_source_provider_denied, agent_source_incomplete, agent_source_budget_exhausted, agent_source_tree_truncated, agent_source_path_denied, agent_source_blob_mismatch, agent_source_utf8_invalid, agent_source_format_unsupported, agent_source_rate_limited.

## Required validation

Hermetic PHP tests on supported versions, negative network/SSRF fixtures and exact Git SHA tests; functional live egress remains a separate, nonauthorizing acceptance with matching deployed build and explicit permission.
