# MAD4B Control Plane source convergence — 2026-11-19

**Status:** non-authorizing source preflight, source-only. Not a deployment, release certificate, owner attestation, source-import decision, or approval to merge existing Draft PRs.

## Candidate inputs

| Input | Exact commit / identity | Role |
| --- | --- | --- |
| GitHub master | `07bf7b4796758167cf8dfd77114e58d4b2b3aac4` (rc.104 plugin header) | Previously approved Git baseline; preserves #914 mobile source |
| PR #882 | `65bb2791f7b46c5887f3e814feb8b48604a3e0ae` | Control Plane source slices; 60/61 workflows green, owner Release Verdict failed |
| PR #920 (includes stacked #915/#912/#910/#909 and #925) | `e1b8c8aa83fd72bea87cfe6511f8200b12` | Destination Term Meta brand alerts, preflight, origin audit |
| Local rc.129 review | `45e4cbdc46145092e9b9484ecb6cb47bf7ef8ee7` | Closest byte-level candidate to frozen Tours.dev |
| Local rc.129 integration | `749c706186f4eec165754e9e5e67cef616da0567` | Other implementation candidates, not the authoritative version solely by version number |
| Frozen Tours.dev rc.128 | Archive captured 2026-11-18 12:30 UTC, SHA-256 `9379c9f16e8e5b352170589b1f430245ab6c162114a4ca771797b27018ed789e` | Verified 995-file preservation snapshot; not proof of current live state |

## Independent preflight results

Comparing the frozen 995-file snapshot by exact Git blob bytes: PR #882 has **706 identical**, **180 divergent**, and **109 missing**. Of the 289 unmatched, **212** have an exact byte-identical candidate in at least one of the other four Git refs, **77** do not. This proves retrieval candidates exist, **not** that overwriting newer code is correct. Local rc.129 review matches 876/995; local rc.129 integration 800/995; #920 head 789/995.

A separate *on-disk, non-authoritative* readback from the local Tours.dev site-copy directory found **991/995** frozen paths still present, **14** changed since the frozen archive, **4** absent. The three newly encountered extra paths are dated pre-change backup copies, not new deployable code. For the 991 currently present paths PR #882 matches 701; of the 290 not identical, 206 have exact candidates in other refs, while 84 have none. **Do not certify current production runtime from this readback alone.**

## Merge preview (no branch or WordPress mutations)

A source-only Git `merge-tree --write-tree` preview for PR #882 + PR #920 reports **57 conflicting paths**: **46 Control Plane code/tests**, **11 specs/governance/workflows**. Treat merge of these two tips as blocked pending a human-reviewed conflict resolution, exact baseline/source ancestry, and fresh CI.

## Work order

1. Preserve current GitHub master and all stacked reviewed branches unchanged; no mass copy of rc.128 over rc.129 or vice versa.
2. Re-capture current live Tours.dev and Wayfatours *per site*, record exact file hash, runtime activation/dependency references and source provenance. Distinguish backup/local-only from executable source.
3. Review highest-risk live differences first: Company Master Admin, Company Communication, Content Readiness Alerts, Host Agent Bridge, bootstrap/authority, and host media components. For each path record exact base/candidate/resulting SHA, keep-versus-import decision, dependency/bindings, tests, rollback, reviewer.
4. Resolve the 57 merge conflicts semantically; preserve immutable historical governance records and independent source-review evidence. Build a separate unified candidate from the latest master without mutating it.
5. Run source continuity preflight for the **resulting** tree, then full CI including Release Verdict. Owner must authorize the *final exact head*, not an old SHA.
6. Promote to Staging with rollback evidence, verify runtime and company-specific overrides, and only then consider Production or other companies.

The detailed forensic matrices are retained outside the public Git repository at `/opt/mad4b-company-provisioning/reports/source-unification-20261119/`. This document intentionally excludes local file contents, credentials, sensitive paths, and auto-generated approvals.
