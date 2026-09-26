# Reference — Current Platform Baseline at Feature 007 Inception

Checked: 2026-09-24

Repository:
mad4bdigital-ai/WordPress

Current rc.59 integration line:
- branch: integration/control-plane-rc59-20260924
- PR: #47
- exact head at Spec Kit creation: ab179816c03acb45751c1707eddee50d19178298
- PR state: Open / Draft
- mergeable at check: true
- GitHub Actions observed on the head: completed successfully for the active rc.59 suite

Closed convergence line requiring reconciliation:
- PR #45
- head: b5d697f05a939d87ca0b2ede1a08eb9e15f97312
- merge base with current #47 line: f43ec3bead478749ead2c3939c8d362922f4b80a
- relation observed: #47 ahead by 469 commits and behind by 10 commits

Existing platform primitives confirmed in repository:
- Official MCP Adapter integration
- multiple isolated MCP surfaces
- OAuth/resource bridge
- NHI/grants
- one-time approvals
- budgets
- audit/rollback
- plugin discovery/lifecycle/package governance
- WordPress-scoped filesystem read/write/patch
- structured database read/update
- raw SQL Breakglass
- Developer filesystem/WP-CLI/execution
- Developer Breakglass
- Dynamic Skills
- Context Authority/Google Drive ingestion
- provider certification/capability contracts
- Workflow Provider facade
- Bit Flows adapter
- Media/SEO/site provider adapters

Feature 007 must reuse these primitives unless a documented incompatibility is found.


## Baseline synchronization update — 2026-09-24

During hostile architecture review, PR #47 had advanced to:
`1ca70c0f85f85dcd08529ed60c6b518a6c64c4fe`

Feature 007 was 27 commits behind its target integration line. The new baseline changes affected OAuth lifecycle, Full Staging Authority, Local OAuth, OAuth Resource Bridge and related runtime/contract tests, so the drift was architecture-sensitive.

Feature 007 synchronized by a real merge-parent commit:
`64b7e0115493d553b0953c7b877f8c419f5676a7`

The creation baseline remains historical provenance:
`ab179816c03acb45751c1707eddee50d19178298`

Future PR-base movement is a hard CI condition; green Feature 007 validation on a stale base is not considered current.

## Canonical master synchronization — 2026-09-25

Feature 007 is now reviewed against canonical `master`, not the historical rc.59 integration branch.

Current reviewed baseline:
- branch: `master`
- exact SHA: `45b44d5885c24976df8df9cc47a51fbf6cd0a3bb`
- commit: `Merge PR #62: ci(root-trust): verify trusted master attestation end-to-end`
- Feature 007 governed-tooling branch is created with this SHA as its direct parent.

The historical creation baseline and earlier integration-line synchronization remain provenance only. They no longer represent the current PR base.

Any later movement of `master` reopens:
- ancestry verification;
- architecture-sensitive semantic impact review;
- `current_baseline_head` update;
- affected references/contracts if semantics changed.

This synchronization does not imply live Staging or Production readiness; those remain separately evidenced.


## Post-governance/schema synchronization — 2026-09-25

Feature 007 was synchronized again after the governed merge sequence:

- PR #62 — trusted master release-root verification implementation;
- PR #61 — Schema v9 live migration diagnostics and real MariaDB v6→v9 certification;
- PR #60 — release evidence identity and repository-governance bootstrap/policy.

Current exact baseline:
- branch: `master`
- SHA: `ae40fa8821934318bec7c386d7acdf77653b8a87`
- Feature 007 PR base: exact same SHA
- behind: 0 at synchronization

The repository-governance policy is now committed, but external ruleset enforcement remains a distinct evidence gate until independently read back as active. Committed policy MUST NOT be interpreted as enforced policy.

The previous `45b44d5885c24976df8df9cc47a51fbf6cd0a3bb` baseline remains historical trusted-root evidence. Because `master` advanced after it, the current descendant requires its own release/root evidence before live deployment claims can be made.

This exact baseline is also the anchor for `implementation-closure.json` and Phase 36.


## Enforced repository-governance synchronization — 2026-09-25

Feature 007 was synchronized again after PR #64 completed the repository-governance bootstrap tooling and permanently retired the temporary bootstrap exception.

Current exact baseline:
- branch: `master`
- SHA: `540d5db4be521297de673c8a4d14974c23b67a6a`
- merge: `Merge PR #64: fix repository governance bootstrap tooling`
- external ruleset id: `23968498`
- enforcement: active
- target: `refs/heads/master` only
- bypass actors: none
- required check: `Repository release verdict`
- required-check integration id: `15368`
- allowed merge method: `merge`
- review-thread resolution: required
- strict required-status policy: enabled

Post-merge master governance workflow `36072550595` passed.

The one-time bootstrap exception is no longer part of Repository Governance or Release Verdict. Ordinary master-bound changes therefore fail closed if active governance cannot be independently verified.

Latest trusted-master package evidence for this baseline:
- package run: `36072550999`
- General Distribution artifact: `10838403565`
- artifact digest: `sha256:23887c052950d62f42d68ddba378460ec80a68f9b14365bc3d91220be451b63e`
- receipt artifact: `10838323685`
- receipt digest: `sha256:baa92a2dc3e5ebf92b80d172983be8585486aed1065951b8ddb452d0bfb33cfc`
- build fingerprint: `f16cb7ecccff30bd1d54aa3088de404f5315823222f4e26b73fb340b4195a8c6`
- package manifest digest: `3d2870fd75ad5b6822a76fa00e1b7b90489ad6b121d6d0dd8e31ea6f07541950`
- Control Plane archive SHA-256: `c8885bdfc42e6aa1a6ce44896f6a9b6b54742a1d7d56269605402ffe11c33b06`
- trusted signer ref: `refs/heads/master`
- attestation verification: PASS

ETG Staging remains a separate live gate and is not claimed to be on this baseline yet.


## Reviewed-parent / runtime-release split — 2026-09-25

After PR #63 merged, repository `master` advanced to:

- repository HEAD: `b1f7e837efc69aa220385d84761e46f64c2442b1`
- change class: Feature 007 specification/contract merge

The latest externally trusted runtime package at that point remained:

- runtime release source: `540d5db4be521297de673c8a4d14974c23b67a6a`
- package run: `36072550999`
- General Distribution artifact: `10838403565`
- build fingerprint: `f16cb7ecccff30bd1d54aa3088de404f5315823222f4e26b73fb340b4195a8c6`
- package manifest digest: `3d2870fd75ad5b6822a76fa00e1b7b90489ad6b121d6d0dd8e31ea6f07541950`
- Control Plane archive SHA-256: `c8885bdfc42e6aa1a6ce44896f6a9b6b54742a1d7d56269605402ffe11c33b06`

This distinction is now normative. Repository HEAD and deployable runtime-release SHA are different identities.

The bulk closure branch is reviewed against parent `b1f7e837efc69aa220385d84761e46f64c2442b1` and contains runtime-affecting changes (ContentJob runtime plus Recovery Plane hardening). Therefore the older runtime release is retained only as historical/known-good evidence and its state for future deployment is `RECAPTURE_REQUIRED`.

A new trusted runtime-release identity may be selected only after these runtime changes merge and the protected master package/root-trust workflow emits exact post-merge evidence. No old artifact may be relabeled to the new repository commit.
