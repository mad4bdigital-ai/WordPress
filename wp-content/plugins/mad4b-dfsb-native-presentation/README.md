# MAD4B DFSB Native Presentation

A normal WordPress plugin supplying **read-only** public Elementor Dynamic Tags and `dfsb_context_*` shortcodes from the already-published WordPress query, without loading Dynamic Filter SEO Bridge's SEO Full Boot or using release certificates.

## Scope
- Keeps existing dynamic tag names `dfsb-context-value` and `dfsb-context-image`.
- Keeps `[dfsb_context_value field="title"]`, `[dfsb_context_image]`, `[dfsb_context_heading]` and `[dfsb_context_editorial]`.
- Reads through the current installed DFSB `PageContextResolver` and `ContextValueResolver`, which enforce public post type, published post, recognized taxonomy, and trusted local attachment checks.
- Supports Elementor existing editor preview settings under `manage_options`. Public requests cannot select arbitrary post IDs.
- Does not intercept existing native JetEngine / JetSmartFilters widgets and queries. Elementor widgets using the original DFSB context tag names can render even when SEO Full Boot is held.
- Does not publish SEO, change Rank Math, mutate posts, or set the BootGuard option. No new REST/AJAX write route.
- Requires the original DFSB plugin for its existing safe context resolvers. Without it, values fail closed to empty output.

**This is a targeted native presentation compatibility layer, not a claim that every DFSB advanced dynamic tag, SEO publishing route, or AJAX filter integration has been enabled.** Those remain governed by the original plugin.

## Smoke test
Use `wp eval-file wp-content/plugins/mad4b-dfsb-native-presentation/tests/wp-cli-smoke.php` on a Staging WordPress process. Check published post and unresolvable post, shortcode registration, the two Elementor tags and unchanged BootGuard state.

## Installation
Install as a *separate normal WordPress plugin* using WordPress's ordinary plugin installer after source review. Do not overwrite DFSB or reuse the old DFSB signed ZIP identity. Stage-only rollout and rollback: deactivate and remove **MAD4B DFSB Native Presentation** to restore the original behavior; no database migrations or persisted settings are introduced.
