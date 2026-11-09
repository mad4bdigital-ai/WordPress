# rc.121 Live-source reconciliation

Derived from the tested rc.120 merge and newer tours.dev.mad4b.com
Control Plane source changes observed on November 6, 2026.
Includes the new Core Web Vitals admin/REST/cron field-data observer,
its schema assessment, REST lifecycle and asynchronous worker tests.

Preserves rc.120 MCP/Skills fixes, rc.119 runtime-cache/native MCP changes,
the live-only admin Performance UI integration, and canonical managed Skill
reference contracts (without generated external discovery caches).

All site-specific options, Secrets, tenant runtime config and Production
content remain outside the package. This is a Staging release candidate
until exact source/package identity and post-install checks pass.
