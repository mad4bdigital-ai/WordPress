---
name: staging-control
description: Verify and use the MAD4B WordPress Staging MCP safely, starting read-only and refusing Production mutation.
---

Use only the registered MAD4B Staging MCP connection for this skill.

Before any tool call that could depend on target identity, verify the target reports both:

- site URL `https://staging.egypttourgates.com`
- environment `staging`

If either check fails, stop and do not use write, admin, plugin lifecycle, filesystem mutation, database mutation, approval, NHI mutation, or breakglass tools.

The initial plugin connection is read-only and must map only to `mad4b-read`. Treat `mad4b-write`, `mad4b-admin`, `mad4b-content`, and `mad4b-breakglass` as unavailable until a later T103 certification step explicitly adds a separate exact connection/grant.

Do not weaken MCP peer isolation, provider certification, exact-grant checks, transactional budgets, approval requirements, or audit integrity checks in order to make a tool execute.

Never copy credentials, bearer tokens, OAuth authorization codes, refresh tokens, WordPress application passwords, secrets, or raw authorization headers into chat output, logs, skills, repository files, or MAD4B identity context.

For the first authenticated session, perform read-only evidence in this order:

1. establish MCP session;
2. list available tools;
3. call the MAD4B site-info/readiness surface;
4. verify the authenticated subject bridge is present;
5. verify mutation and breakglass remain disabled;
6. report the exact blockers remaining before any NHI or mutation work.
