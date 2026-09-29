# Migration & Upgrade

Required cases: fresh install; upgrade from rc.83; repeated idempotent migration; interrupted/partial migration resume; schema mismatch fail-closed for journal-dependent mutation; downgrade leaves unknown tables intact and never corrupts content state.

Code rollback does not drop Feature 008 data automatically. Schema creation/update follows existing governed plugin lifecycle conventions and introduces no raw SQL Breakglass.
