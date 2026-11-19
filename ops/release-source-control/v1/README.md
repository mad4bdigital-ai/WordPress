# MAD4B Release Source Control — mandatory reconciliation preflight contract

**Purpose:** Let the operator select the release source independently of the target site and the deployment mechanism, and ensure the selected source is *qualified through read-only reconciliation* before any release approval. This preflight is a host-side source qualification step, **not** code promotion, package signing, grant reconciliation, or effective write authority. It deliberately does not mark any live site as deployment-ready.

## Four interchangeable source modes

| Source choice | Invocation | Immutable basis |
|---|---|---|
| Local integration or local `master` | `--source local_integration --ref <local-branch>` | Exact commit from the local branch, pinned with re-read |
| Tours.dev running code | `--source live_toursdev` | SHA-256 over current live files; must still be captured into reviewed Git source |
| GitHub `master` | `--source github_master` | Verified exact GitHub branch HEAD SHA |
| Any GitHub candidate branch | `--source github_candidate --ref <branch>` | Verified exact GitHub branch HEAD SHA |

Repository is fixed to `mad4bdigital-ai/WordPress`. All lookups are parameterized subprocess calls; no caller-supplied URL, arbitrary repository, shell interpolation, or executable source is accepted. Remote branches are fetched into a temporary bare checkout with shared local objects; the integration worktree is never reset or checked out. The tool only reads the live WordPress tree.

## Mandatory workflow

1. **Select source type and reference.** No default branch has implicit authority. The local `master` and GitHub `master` are distinct options; a local `integration/*` or `review/*` branch can be selected explicitly.
2. **Resolve exact identity.** The branch HEAD is pinned and re-read; live code is hashed without assuming that its old build manifest is current. Validate the source package manifest and report missing or stale declared files. No manifest identity is fabricated.
3. **Reconcile against the requested target sites.** Compare installable Control Plane components and MAD4B MU extensions against Tours.dev and/or Wayfatours. Exclude repository-only `tests/`, `docs/`, and tools from runtime drift. Preserve all existing live-only files. Highlight security/authorization and site-specific identity/media contracts. Every difference produces exact selected and live SHA-256 values, plus a deterministic reconciliation-plan SHA-256.
4. **Qualify for reconciliation, not deployment.** `NEEDS_RECONCILIATION` means an exact source identity was established but differences require review. `NEEDS_PACKAGE_PROVENANCE` means files match, but the candidate still lacks coherent current release evidence. `READY_FOR_CERTIFICATION_REVIEW` is possible only when the compared runtime code, source commit and manifest identity agree. None of these states is a deployment approval.
5. **Explicit candidate selection** (`--select`) stores only a root-owned, SHA-256-bound pointer to the preflight receipt in `release-source-selected.json`. It never updates WordPress, Production, Git refs, release artifacts, credentials or grants. No candidate is selected by default.
6. **Separate release certification and approval.** After reviewing and reconciling code, create an immutable source commit and an independently certified package, then acquire the existing one-time owner approval. Immediately before deployment, recheck selected source SHA, live target inventory, current permissions, signed package, and rollback point. Use the existing original release executor; this tool never installs anything. **The old selected-head installer is not automatically rewired by this host-only preflight in v1**; integrate its admission gate using independently attested host evidence before claiming a mandatory platform-wide policy.

## Operator commands

Host config is root-owned `/opt/mad4b-company-provisioning/config/release-source-host-config.json`, deployed from `host-config.example.json` and kept **outside Git**. Run as an authorized host operator:

```bash
python3 ops/release-source-control/v1/source_preflight.py --source local_integration --ref master --target both
python3 ops/release-source-control/v1/source_preflight.py --source local_integration --ref integration/pr814-821-source-converged-20261115 --target both
python3 ops/release-source-control/v1/source_preflight.py --source live_toursdev --target both
python3 ops/release-source-control/v1/source_preflight.py --source github_master --target both
python3 ops/release-source-control/v1/source_preflight.py --source github_candidate --ref feat/scoped-build-verification-20261119 --target both
```

Append `--select` only when you deliberately want to set a *nonauthorizing* candidate source pointer after its preflight. Inspect it with `--show-selection`. Selecting a source does **not** mark reconciliation complete or create a release approval. The resulting JSON receipt is mode `0600` in the host-managed reports directory, with a full file-level diff/reconciliation plan. No WP database or plugin release state is changed.

## Current evidence (19 November 2026)

All four source modes resolved successfully, including the separate local master/integration branch and GitHub master/candidate checks. The live Tours.dev source has many live-only MU extensions compared with Wayfatours. GitHub and local Git are not equal at `master`. In all observed comparisons reconciliation is still required, and the declared package manifest does not yet prove the selected build to be an approved release. No source was selected for deployment, no site writes were attempted, and effective authorization remains blocked by separate grants/candidate/production gates.

## Security and limitations

The script is a host-runner **preflight adapter**, not an installed WordPress admin screen or a replacement for Release Verdict. Its source identity and reconciliation receipts must be independently checked at admission time; report JSON is root-owned evidence, not a cryptographically signed Original312/313 release receipt. This deliberate division avoids weakening the existing signed execution lanes. GitHub API integration, Control Plane menu controls and the signed host-to-WordPress approval boundary can be added later without changing the four-source selector contract. Never use `source_identity_qualified_for_reconciliation` as evidence of permission to deploy.
