# Cross-Plugin Deployment — Admin / Remote MCP

Status: source implementation on an unmerged PR; **not installed or deployment-certified**.

The same source ledger `config/cross-plugin-sources.json` lists a plugin's
repository, canonical source directory, WordPress plugin main file, and its
certification identity. It supports plugins in subdirectories of the main MAD4B
repository and separately reviewed external repositories, including plugins that
have never been installed on the target site. A source entry is not a deployable
artifact: ETG Dynamic Filter SEO Bridge starts as
`source_reviewed_artifact_pending` with no SHA/ZIP certification.

## Operator procedure

1. Select the site (Staging or Production) and inspect
   `mad4b/plugin-deployment-sources` on `mad4b-read`, or the **Plugin Deployment**
   page in wp-admin. It shows installed state, source repository/subdirectory,
   and package-certification state. Opening the page performs no GitHub requests.
2. Explicitly plan with `mad4b/plugin-deployment-plan`: source ID, ref type
   (`commit`, `branch`, `pull_request`, `branch_commit`), reference and reason.
   A bounded GitHub API request resolves the source to an exact 40-hex SHA.
   Fork PR heads and unrelated branch commits are denied.
3. The source/pinned SHA and plugin root must match an approved immutable
   package certificate in the source registry. A missing certified ZIP is
   **NEEDS_REVIEW**, not an invitation for WordPress to build/run arbitrary code.
   Private repositories need a separately enrolled artifact provider: site HTTP
   deliberately does not store or request GitHub Personal Access Tokens.
4. A certified **Staging** plan delegates to the existing
   `mad4b/plugin-package-plan` and exposes the corresponding existing
   `mad4b/plugin-package-apply` name and exact plan binding for separately
   authorized remote execution. The parent executable retains its own
   grants, owner identity, maintenance lease, backup, activation-state
   restoration, archive readback, Audit and rollback. This planner does
   **not** mint approvals, install ZIPs or create a new high-impact executor.
5. **Production** uses the same discovery and source resolution but requires a
   *separate Production promotion attestation and reviewed Production-specific
   installer*. Until implemented/certified, `production_package_executor_not_yet_certified`
   is an explicit hard blocker. No Staging grant is reused.
6. `WP-Cron` must not install arbitrary GitHub commits. An exact artifact
   job delegation, expiry, owner approval and post-activation readback would
   need their own workflow certification. No unattended mutation is scheduled.

## New source onboarding
Add a reviewed entry to the registry with a constrained `owner/repo`, plugin
subdirectory, canonical plugin main file, and provider ID. Build a deterministic
ZIP from exactly that subdirectory using trusted CI, hash/certify it, publish the
exact source and package manifest, and add the corresponding reviewed provider
contract. No WordPress plugin can be installed merely because a repository URL
was typed or because a branch was found.

## Acceptance
- PHP 7.4, 8.3, and the deployed PHP 8.5 runtime must lint and run hermetic
  source/role tests; WordPress and MCP tests must confirm ability registration
  and permissions under actual operator sessions.
- Regressions: foreign repository/PR, ref/path traversal, incorrect branch
  ancestor, source/digest mismatch, protected plugin, absent plugin install,
  active plugin replace, dependency break, missing archive, GitHub outage,
  no owner grant, Staging/Production confusion and failed readback/rollback.
- Only a separately approved ETG/ARE Staging canary and Production
  promotion acceptance can certify each installation path. Source checks alone
  are not sufficient.

## Trusted artifact candidate builder

The optional GitHub workflow `.github/workflows/mad4b-cross-plugin-candidate.yml`
runs only from the trusted default master branch via explicit workflow_dispatch.
Inputs are a reviewed source ID and an exact commit SHA; the target repository
is resolved from the reviewed local registry, not a caller URL. The build
checks out source with `persist-credentials: false`, `submodules: false` and
does **not** execute any plugin code or its repository CI workflows.

The deterministic ZIP builder checks the checkout SHA, plugin root/main header,
symlinks and archive budgets, and creates reproducible ZIP bytes plus a
SHA-256/file-manifest provenance receipt. The uploaded GitHub Actions artifact
is **BUILT_PENDING_INDEPENDENT_REVIEW** only. An independent build review,
certification profile, exact SHA/ZIP registration, Staging behavior test and
separate Production promotion are still required to make it deployable.
Do not equate a successful ZIP build with a deployment authorization.
