# CSS Site-owned v17: company-only family, visual and audit jobs

This revision is a reviewed **candidate**. It does not change WordPress
settings, issue a Host execution grant, install new renderer files, or
enable jobs. It is stacked on the existing Multi-CSS v2 source branch
and must retain its normal owner/head, exact renderer and production
release admission.

## Verified root cause

At 20 Nov 2026, WordPress option `mad4b_css_wpcron_site_profile_v1`:
- Tours.dev Staging: `public`, `first_fold`, `families`, `visual`,
  `audit`, `retention`.
- Wayfatours Production: only `public`, `first_fold`.

Even if WordPress enables three additional jobs, the old Wayfatours
site-owned worker rejects those tickets because its private host profile
only allows two. In addition, legacy first-party family refresh, visual
monitor, and integrity audit default to **both** enrolled companies.
Duplicating them as-is would violate the site-owned processing model and
multiply Chromium/CPU work.

## Exact v17 design

1. The original WordPress 429 MU plugin is **unchanged**.
2. The first-party host worker pins exactly one company profile and
   appends its trusted `site_id` to the `families`, `visual`, and
   `audit` commands. No request/user-provided site selector.
3. The source-family and visual observers admit exactly one of
   `source` or `wayfa` and deny unknown or multi-site arguments.
   Legacy no-argument direct operator invocation still observes both,
   but is never used by the revised site-owned worker.
4. The integrity auditor already admits `source|wayfa|all`; v17
   writes `latest-source.json` or `latest-wayfa.json` for scoped
   calls, preserving the historical `latest.json` aggregate only
   for legacy explicit `all` calls. A scoped audit cannot overwrite
   the other company's report.
5. All three job ACK checks verify a **single matching site receipt**
   and reject success based on the other company's output. Error/
   deferred/partial outcomes remain queued for the bounded retry policy.
6. Shared report retention remains **source-owned only**. A Wayfatours
   profile with `retention` is rejected by the original worker.
7. Renderer v16 rebuilds to its *exact original 131-file SHA*
   `e238d0755bc2b3ac715f3f043353e6a5cba87bca19d5678bba19a658549e5aa4`.
   Renderer v17 is a *different pinned 131-file artifact*, changing
   only the three site-scoped scripts; no silent modification of old
   versioned images.
8. Chromium observer still requires its original resource lock and
   bounded Docker CPU/memory/pids; real performance certification and
   HTML readback remain distinct from successful task execution.

## Preflight checks before any live rollout

- Review the connected original Host execution authority and exact
  identity/profile/renderer SHA for EACH site. No arbitrary Shell
  dispatch replaces a first-party grant.
- Stage v17 on Tours.dev first; verify all existing cron work and
  legacy v16 rollback. Prove source `families`, `visual`, `audit`
  receipts contain the source site only.
- Publish a reviewed immutable v17 renderer with its own signing/
  receipt, then update Wayfatours host profile and verified original
  WordPress profile as one **rollback-capable** governed operation.
  Recommended bounded frequencies: `families` every 3600 s;
  `visual` and `audit` every 86400 s. Preserve existing
  `public` 1800 s and `first_fold` 3600 s.
- Prove one Wayfatours family report with no Tours.dev observation;
  no duplicated audit execution; verify resource budget and retries.
- Do not treat an empty source-family canary status or completed
  family-refresh task as a certified route. Activation still depends
  on Multi-CSS v2 exact source/market/viewport/HTML proof.

## Reproduction

Run the original tests plus v17 tests in the trusted source worktree:

```bash
cd ops/site-owned-css-cron-v1
python3 test_site_worker.py
python3 test_rebuild_renderer_v16.py
python3 test_site_task_scope_v17.py
```

No WordPress mutation occurs during these tests.
