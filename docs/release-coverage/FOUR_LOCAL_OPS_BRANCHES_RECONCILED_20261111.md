# MAD4B — Four additional local Ops branch sources (2026-11-11)

**State: EXACT_GIT_SOURCE_IMPORTED / NOT_REGISTERED_OR_INSTALLED.**

The local full WordPress composite source was missing four independent first-party operational development families. They were selected from the existing VPS Git object store, without fetching or requiring GitHub.

| Branch / responsibility | Source objects referenced |
| --- | ---: |
| Pricing policy and cache inheritance | 24 |
| Runtime policy, route registry and cache coherence | 23 |
| Hostinger CDN + SSH company operations | 19 |
| Fleet Adaptive Intake and optional authorization | 56 |
| **References** | **122** |
| **Unique source paths** | **103** |

Nineteen source paths are shared between the Hostinger CDN/SSH and Fleet Adaptive Intake branches. Their source Git object identifiers match byte-for-byte. They are stored once, with both branch names in the per-file manifest.

Every imported file is recorded with its exact original source branch, full Git Commit SHA, blob SHA-1, source SHA-256 and byte count in four-local-branch-source-overlay-20261111.json. tools/verify_four_local_branch_source_overlay.py independently checks byte fidelity and commits, rejects changed source, changed branch tips or missing files. An initial integrity test found that a text-reading operation had trimmed file endings; that error was corrected **before** any Commit by restoring the original raw Git blob bytes. The final verifier passes all 103 paths, covering all 122 branch references.

Static acceptance: 28 PHP files passed PHP 8.5 syntax lint, 24 Python files passed compilation, eight JSON documents parsed, and five provenance-verifier tests passed. Tests did not execute any deployment script, service, timer, Nginx rule, WP-CLI command, WordPress mutation or remote company operation.

These are legacy or candidate operational sources for analysis and selective future reconciliation. In particular, source presence does **not** enroll a Company, change normal site OAuth requirements, approve a signed Original 305 job, issue a Host Anchor, install a systemd unit or authorize Code Release.

**Global platform release remains HOLD**, because other local development heads still contain distinct source paths and incompatible modifications, the candidate differs from 60 deployed Control Plane/Portfolio source files, and live Original 318 v82 / 313 signed readback are not complete.
