# Tours.dev Skill Composition — original Staging registration

This bounded patch touches the native Skill Abilities class, read-only Skills
MCP adapter, and exact plugin entrypoint load order. Existing Skill Composition
and external-source service files already match Git source and are only loaded.

Only skill-match-task and skill-composition-plan were activated on Tours.dev
Staging. Original can_read permission callback, private MCP annotations, and
nonexecution contract remain. Live WordPress confirmed registration and an
advisory matching response; composition execution_ready remains false.

No new grants, App binding, WordPress role changes or Production deployment.
Preimage hashes, patches and rollback receipts identify the exact staging
source. The canonical Git source already contains both abilities.
