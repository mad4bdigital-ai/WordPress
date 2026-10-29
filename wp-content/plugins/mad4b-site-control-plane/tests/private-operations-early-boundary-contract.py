#!/usr/bin/env python3
"""Source contract for the early private Tour Operations confidentiality fence."""
from pathlib import Path

PLUGIN = Path(__file__).resolve().parents[1]
entry = (PLUGIN / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
guard = (PLUGIN / "includes/class-mad4b-scp-private-operations-boundary.php").read_text(encoding="utf-8")

boot = "require_once __DIR__ . '/includes/class-mad4b-scp-private-operations-boundary.php';"
early_auth = "require_once __DIR__ . '/includes/class-mad4b-scp-ai-only-early-auth-guard.php';"
assert entry.count(boot) == 1, "Guard must be included exactly once"
assert entry.index(early_auth) < entry.index(boot)
assert entry.index(boot) < entry.index("MAD4B_SCP_WPCLI_Passive_Gate::allowed_request()")
for kind in ("tour_orders", "tour_bookings"):
    assert kind in guard, f"Private CPT missing: {kind}"
for requirement in (
    "register_post_type_args",
    "rest_pre_dispatch",
    "pre_get_posts",
    "template_redirect",
    "'public'] = false",
    "'publicly_queryable'] = false",
    "'show_in_nav_menus'] = false",
    "private_relation(",
    "'parent_object'",
    "'child_object'",
    "'posts::'",
    "'master_admin'",
    "'operator'",
    "no-store",
):
    assert requirement in guard, f"Confidentiality contract missing: {requirement}"
assert "jet-rel/126" not in guard, "Relation IDs are site-local, must not be hardcoded"
assert "67531" not in guard, "QA fixture IDs must not enter the shared module"
print("PASS: early boot, private CPTs, dynamic site-local relation, authorized staff roles")
