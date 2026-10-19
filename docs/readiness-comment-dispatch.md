# Readiness comment dispatch — independent trusted receiver

This bootstrap is independent of the Production Readiness control baseline in
PR #429 and dynamic registry in PR #426. The receiver must be merged to
`master` before GitHub will run `issue_comment` events.
A code review of this PR is not a live end-to-end test.

## ChatGPT-operable PR command

On a currently open, same-repository pull request targeting `master`,
post a **new top-level conversation comment**, as the enrolled repository
owner, containing exactly:

```text
/run-production-readiness <current 40-character PR-head SHA>
```

This is an ordinary PR issue comment, **not an edit of the PR description**.
Use PR conversation comments for the deployed workflow. GitHub rejected the
`commit_comment` event declaration during workflow validation, before any job
started; it is therefore not exposed by this receiver. The Python helper's
commit-association logic remains tested, but does not establish a live trigger.
The connected ChatGPT GitHub app can post PR conversation comments.

The trusted default-branch job validates the owner and sender identities
using the base-owned governance policy. It reads live PR identity, source
repository, target branch and exact head, then finds a PR-sourced
`mad4b-production-readiness-matrix.yml` run matching PR number, branch,
head **and original base commit SHA**. Mismatched/stale runs are rejected,
not reported as current evidence. No untrusted PR code is executed by this
receiver. Only a known run with failed/cancelled/timed-out conclusion can
be retried; in-progress and successful runs are acknowledged without retry.

The receiver has read-only contents/PR/issues and bounded
`actions:write` solely for rerunning a previously registered PR Actions
run. It cannot create or overwrite source, merge, deploy, grant WordPress
permissions, sign live receipts or authorize Production.

## Actual activation sequence

1. Merge this bootstrap PR after owner attestation, required checks and
   review. This adds the trusted receiver to the default branch.
2. Merge parent readiness PR #429 under its own owner governance and
   ruleset constraints so new PRs produce the matrix check.
3. Generate an exact-head PR matrix run on the current base. If master
   advances, rerun of an old base's event is forbidden; update/reconcile
   the PR branch through the normal reviewed source process.
4. Post the command with the current head (ChatGPT can do this using its
   connected GitHub account). Check the comment event Actions job and the
   matching matrix run/attempt by API, then report actual result.
5. If the matrix fails on code, modify/review the source through normal CI
   rather than rerunning endlessly. An updated head requires new approval.

This is a rerun mechanism, not a way to dispatch arbitrary workflows. It
does not automatically turn `Production NO-GO` into `GO`. The ruleset
required-check addition remains separately tracked in Issue #430.
