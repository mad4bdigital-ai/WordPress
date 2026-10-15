# Dynamic Staging Candidate Channel — Feature 007

**Scope:** Candidate testing from a selectable PR, branch, or immutable commit **without** merging into `master`. This channel is never a Production update feed. PR #258 is an example input, not a built-in requirement.

## Source selection contract

`candidate_source` is a three-field selector. All values are strings:

```json
{"repository":"mad4bdigital-ai/WordPress","type":"pull_request","reference":"258"}
```

Replace `reference` with any allowed PR number; for branches use `"type":"branch","reference":"feature/next"`; for a pinned commit use `"type":"commit","reference":"<40 lowercase hex>"`. Site Host owners may configure an additional repository allowlist using `MAD4B_SCP_STAGING_SOURCE_REPOSITORIES` (PHP array). Arbitrary URL input, redirects, fork-backed PRs and unapproved repositories are rejected.

Each planning call resolves the reference through the official GitHub API, records the **exact resolved commit**, and compares it to `source_commit_sha` in the locally built ZIP identity. The operation is never authorized by a PR number, branch label, or GitHub API response alone; the uploaded bytes are separately checked against `archive_sha256`, `build_fingerprint`, `package_manifest_digest`, and embedded provenance.

## Host prerequisites (fail closed)

The site must be Staging with:

- `WP_ENVIRONMENT_TYPE` explicitly set to `staging`;
- an enrolled exact Site Profile with governed write and `update_plugins` authorization;
- `MAD4B_SCP_DEPLOYMENT_BINDING` configured with a unique strong host-managed secret, confirmed by same-origin clone protection;
- `MAD4B_SCP_STAGING_CANDIDATE_UPDATES_ENABLED` explicitly `true` (host-controlled opt-in);
- the same governed mutation authorization and exact-plan approval used for regular uploads, **plus** an enrolled administrator's authenticated OAuth `mad4b:authority:step-up` scope for this Staging candidate lane;
- a backup and available rollback/readback runtime.

The existing fixed production release path, native pointer-based update, and normal `governed_file_upload` gate remain unchanged. This lane must **never** be used in Production, even if a Site Profile accidentally misclassifies the environment.

## Plan

Call `mad4b/control-plane-upload-plan` with the usual exact package identity plus:

```json
{
  "channel":"staging_candidate_upload",
  "candidate_source":{"repository":"mad4bdigital-ai/WordPress","type":"pull_request","reference":"258"},
  "version":"0.4.0-rc.96",
  "source_commit_sha":"<40 lowercase hex>",
  "archive_sha256":"<64 lowercase hex>",
  "build_fingerprint":"<64 lowercase hex>",
  "package_manifest_digest":"<64 lowercase hex>",
  "size_bytes":12345678,
  "reason":"Owner-reviewed Staging smoke acceptance of exact source candidate"
}
```

The plan is `eligible=false` until all Host prerequisites and exact identities pass. Display `blockers` and the frozen `candidate_source.resolved_sha` to the operator; never silently pick a new PR HEAD.

## Apply

Call the already governed `mad4b/control-plane-upload-apply` with **the same Plan input**, plus `expected_plan_sha256`, `package_base64` of the exact ZIP, and `candidate_confirmation="INSTALL EXACT STAGING CANDIDATE"`. The authority layer must independently authorize this action. During apply, the server:

1. Rebuilds the plan, rejecting changed PR/branch SHA or Site Profile.
2. Requires exact approved plan digest, Host opt-in, explicit confirmation, existing mutations policy, and correct actor.
3. Checks ZIP size/sha, embedded provenance and plugin identity.
4. Re-resolves the mutable source and rejects a changed HEAD immediately before the filesystem operation.
5. Reuses guarded maintenance, protected backup, activation, readback, post-update convergence, audit, and rollback.
6. Never modifies grants, release pointers, `master`, Production or Developer/Breakglass settings.

The commit is **immutable for a given plan**. If a PR moves, regenerate the ZIP and request a new plan. Do not turn the source resolver into a polling auto-deployment feature.

## Building a different PR

The legacy Hotfix7 Build Kit contains `refs/pull/258/head`, so unmodified V6 cannot select a different PR. The external `Build-MAD4B-F007-Selected-PR-Latest-v7.ps1` script adds `-PullRequest N`, binds the exact selected GitHub ref only in the extracted kit, checks GitHub PR origin/HEAD, and preserves the original independent PHP verifier and strict ZIP proof. The original kit remains unchanged. V7 is a migration bridge; the preferred long-term builder accepts `repository/type/reference` as canonical signed input without patching any tool.

## Non-goals and required evidence

A passing ZIP structure verifier is **not** a trustworthy publisher signature, CI certificate or live Staging acceptance. The operator must review the code/PR provenance, test the candidate, inspect release-set compatibility, and validate live REST/MCP, Site Profile, permissions and rollback after deployment. Publication to Production continues to require the root-trusted official release pipeline. Do not treat `staging_candidate_upload` as implicit promotion authorization.
