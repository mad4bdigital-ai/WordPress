# MAD4B — Additional live WordPress module source recovery (11 Nov 2026)

## Local Git source snapshot only

Four components were absent in the nominated full WordPress Git branch. Their executable PHP, JavaScript and CSS files have now been copied, without replacing preexisting source, from the current Tours.dev Staging filesystem into an isolated local worktree:

| Source component | Captured code files |
| --- | ---: |
| MAD4B Lifecycle Orchestrator | 12 |
| MAD4B Content Hub | 61 |
| MAD4B Platform (including embedded historical legacy code) | 451 |
| MAD4B Render Query Governor MU | 1 |
| **Total** | **525** |

Each source file's relative location, exact byte count, SHA-256 and Git blob digest are preserved in missing-live-code-source-snapshot-20261111.json. The source verifier rejects unknown filenames outside these four groups, path traversal, altered code, missing files, or a falsely authorized release. It was checked against both the local snapshot and the live source. A bounded scan for common literal credential formats found no hits, but that is **not** a security audit or release approval.

All 430 PHP files of these four recovered source scopes passed syntax lint using an isolated PHP 8.5 container, with network disabled and a read-only filesystem. Source tests also reject changed hashes or false release claims.

**Important:** These are source snapshots, not runtime release files. Historical WordPress/MU files under mad4b-platform/legacy-mu-tree are archived by their existing live paths, not activated by this Git integration. JSON configuration, plugin artwork, release manifests, WordPress database options and site-specific company values are outside the PHP/JS/CSS snapshot contract and must not be inferred as included.

Original 318 in this snapshot is the **already deployed older version**. It is not signed Original 318 v82, does not issue or revoke Host Authority anchors, and does not resolve independent Original 313 lifecycle receipts.

## Global coverage evidence

The source-only Git coverage auditor at tools/audit_git_live_code_coverage.py compares PHP/JS/CSS files by relative location and exact SHA. It reports no missing live code paths across the seven reviewed components, but source differences remain: 46 Control Plane files and 14 Portfolio Manager files. The reported state is CODE_PATHS_COMPLETE_WITH_DIVERGENCE__HOLD. Even future exact parity does not mean remote GitHub feature coverage, WordPress runtime compatibility, grant authorization or release acceptance.

The source snapshot also contains legacy/staging-specific gates and hostnames as installed; those conditions require semantic review before any environment-neutral production release. Do not delete host identity checks or signed operation boundaries en masse.

## Release decision

**HOLD.** Only the local Git worktree was modified. No company production or staging deployment, WordPress DB change, PHP plugin activation, signed Job or Grant was performed.

### Original byte-preserving lint caveat

Several historical files use CRLF and intentional whitespace suffixes. The ordinary Git whitespace checker detects these preexisting source bytes. Altering them would break the exact live SHA-256 proof. For this snapshot only, ordinary tracked tool/manifest/README files were checked under the normal whitespace policy, while the complete snapshot was checked once with temporary command-local Git settings disabling end-of-line whitespace warnings. No global Git configuration or live source file was changed. The source verifier is the authoritative exact-content acceptance of the snapshot.
