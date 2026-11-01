# QA31 Staging static UI source preservation — 2026-11-01

Status: **SOURCE-PRESERVATION-ONLY / NON-AUTHORIZING**.

Source: `tours.dev.mad4b.com` Staging Control Plane `0.4.0-rc.118`, independently retained in the pre-merge snapshot. Exact files were checked against `qa31-live-drift-report.json` source digests and byte counts. This is **not** a release package provenance replacement.

| Imported path (relative to plugin root) | SHA-256 | Bytes |
| --- | --- | ---: |
| `assets/operational-remediation-ui.css` | `d797b081f4963ab93753bcf4d1c0bc58225ff00aa823c9844a4ab01c749f70de` | 7,322 |
| `assets/operational-remediation-ui.js` | `6313d3e66b2b58790fd4b8cc7590db62021b4915222e92ee9eeef7954ad9cc0b` | 4,483 |

Scope and restrictions:
- Preserve two **Staging-only** CSS/JS diagnostic assets in Git as dormant source; do not change the PHP bootstrap, enqueue hooks, operation registry, runtime permissions, or site configuration.
- JS provides navigation of local admin tabs, search/filter of existing table rows, and history fragment updates. It contains no network API, form submission, credential access, or WordPress mutation code.
- CSS selectors are restricted to `#mad4b-remediation`, including media queries.
- Source hashes are verified by a read-only manifest comparison. `node --check` passed for the JS.
- These files are not considered runtime-wired or staging-certified by this PR. A future guarded integration must verify the PHP admin markup and opt-in asset enqueue path.
- The global QA31 release gate remains `BLOCKED_RUNTIME_MANIFEST_DRIFT`, including six provenance mismatches and unresolved active-source divergence.

**Explicitly excluded**: authorization/delegation, Company Local WP-CLI, adaptive asset runtime, Search Provider Autoprovision (potential spend activation), private keys or credentials, live configuration, database changes, Staging installation, Production, and `master`.

Follow-up: review the remaining runtime differences by exact SHA and 3-way lineage, with WordPress principal/nonce, site/company scope, executable allowlisting, rollback evidence, and spending/budget safety before any operational import.
