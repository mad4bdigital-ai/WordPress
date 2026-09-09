---
name: wordpress-site-diagnostics
description: Diagnose a MAD4B-connected WordPress site's runtime, plugins, providers, and configuration when the user asks to troubleshoot, inspect, assess health, or identify a root cause.
---

Use this skill for WordPress diagnosis through the MAD4B read gateway.

1. Start with `mad4b/site-info`, `mad4b/diagnostics-health`, and `mad4b/runtime-authority-status`.
2. Read `mad4b/connection-status` only when transport, OAuth, MCP, or external-client state could be relevant.
3. Use `mad4b/list-plugins` and the most specific provider or adapter status tools needed for the affected subsystem.
4. Separate confirmed failures from warnings, version drift, missing capabilities, and hypotheses.
5. Prefer provider-owned read tools over generic inference. Do not infer filesystem or database state that the ChatGPT gateway does not expose.
6. Do not request or perform mutation unless the user explicitly asks for a change and a separately governed write authority is available.
7. Return: current state, root cause, supporting evidence, remediation, remaining uncertainty, and risk level.

When runtime health is degraded but connection certification is healthy, keep those layers separate rather than reopening OAuth or MCP transport without evidence.
