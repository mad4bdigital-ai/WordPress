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

## Three-lane frontend drift admission
A source template or plugin repair is not complete until code/data root-cause proof, active Skill/provider coverage, and deduplicated Content Readiness/browser evidence each pass independently. Record issue identity as observed frontend surface → page type/context → locale → defect family. Detect raw WPML term-family duplicates, nested Elementor orphan grids, dynamic field cards with empty rendered output, broken mobile booking/sidebar position, missing JS assets, display conditions and translated menus; escalate visitor-blocking booking/rate controls to critical, structural drift to high. Never infer Fluent Boards assignment from settings alone: require verified task ID or report blocked governance.

## Cross-plugin company settings lifecycle diagnosis

For JetEngine/WooCommerce/MAD4B option disagreements, use the `wordpress-settings-lifecycle` workflow. Dynamically enumerate registered JetEngine Options Pages and their storage modes; distinguish a missing value, an inherited default, explicit override, invalid value and a provider whose module is not loaded. Inspect both raw stored values and each consumer's resolved value. Compare live Staging and Production on the same semantic field, not merely on matching option names. Verify Company Communication sender filters, Woo store currency and tax setup, Tour Mode display inheritance, and WPML locale query readiness as separate checks. A Settings Alignment screen is a navigation and discovery surface, not a write-through guarantee. Return an evidence matrix and exact root cause before recommending migration or copying fields.
