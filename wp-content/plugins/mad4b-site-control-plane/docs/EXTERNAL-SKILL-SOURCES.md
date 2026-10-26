# External Agent Skills — source-backed explorer

The Control Plane has an existing managed Skill Registry. External discovery is a separate **read-only, untrusted inventory**, not another skill installer, runner or grant plane.

## Configure

Open **Control Plane → Skills → External Skill sources** as a WordPress administrator. Add a public GitHub `owner/repository` and an exact branch/tag/ref. Sources are site-local options; no repository or specific vendor is preconfigured. Removing a source updates only this allowlist, not previously authored local Skills.

## MCP read flow

1. `mad4b/skill-sources-list` — list configured sources and stable source IDs; no network.
2. `mad4b/skill-source-skills` with `source_id` — fetch a bounded GitHub recursive tree and enumerate `*/SKILL.md` paths.
3. `mad4b/skill-source-get` with `source_id`, `path`, and discovered `blob_sha` — read a specific Git blob by content-addressed SHA; confirm the file still belongs to the selected source tree.
4. `mad4b/skill-source-resource` additionally accepts `skill_path` and supporting `path` in that skill's references/, assets/, or scripts/ folder. This **only reads UTF-8 text**; scripts are never run.

Returned provenance includes the repository, Git ref, tree SHA, blob SHA, and SHA-256 of exact bytes. If a branch changes, rediscover; old file SHA input must never silently select new instructions.

## Governance and limits

- Public GitHub-only fetch through the existing egress policy and reviewed provider-origin configuration; HTTPS, TLS verification, no redirects, bounded response and timeout.
- Sources must be explicitly registered by a WordPress administrator; no arbitrary user-supplied URLs to the MCP tools.
- At most 40 configured sources, 500 Skills per source, 4 MiB of tree JSON and 256 KiB of UTF-8 per file. Incomplete Git trees are rejected, not presented as complete.
- MCP operations use the existing governed read capability; no MCP mutation endpoint.
- No import into managed Skill Registry; no code execution, auto-install, deployment, or change to owner approvals.
- No vendor-specific repository names in the runtime. References and samples are passed as configuration rather than code assumptions.
- Cache is an optional 5-minute object-cache hint; it is not authoritative and never certifies the source.
- No third-party files or instructions are redistributed in this repository.

## Verification

Run `php -l wp-content/plugins/mad4b-site-control-plane/includes/class-external-skill-sources.php` and `php wp-content/plugins/mad4b-site-control-plane/tests/external-skill-sources-runtime.php`. The Dynamic Skills workflow invokes both on each relevant PR. Live public GitHub connectivity and MCP client tool discovery require environment-specific acceptance; fixture tests do not prove live egress.
