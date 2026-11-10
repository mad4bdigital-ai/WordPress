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
# Foreign REST short-circuit must not skip the private type/JetEngine fence.
assert entry.index(boot) < entry.index("if ( ! $mad4b_scp_early_owned )"), "Private boundary must precede foreign REST early return"
assert entry.index(boot) < entry.index("register_deactivation_hook("), "Private boundary must be registered in the earliest plugin kernel"
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
# Source-scope wiring review for the replacement Bootstrap Git blob.
# These checks do not certify a live site, a release, or write authority.
import re
version_header = re.search(r"^ \\* Version: ([^\\s]+)", entry, re.MULTILINE)
version_constant = re.search(r"define\\( 'MAD4B_SCP_VERSION', '([^']+)' \\);", entry)
assert version_header and version_constant
assert version_header.group(1) == version_constant.group(1), "Bootstrap version drift"
required_modules = re.findall(
    r"require_once MAD4B_SCP_DIR \\. '([^']+\\.php)'\\s*;", entry,
)
assert len(required_modules) >= 30, "Bootstrap module inventory unexpectedly missing"
for relative in required_modules:
    module = (PLUGIN / relative).resolve()
    assert module.is_relative_to(PLUGIN.resolve()) and module.is_file(), (
        "Missing or unsafe bootstrap dependency: " + relative
    )
# Keep all new optional boot calls after their defining module is loaded.
for class_name, path in (
    ("MAD4B_SCP_Core_AI_Governance", "includes/class-mad4b-scp-core-ai-governance.php"),
    ("MAD4B_SCP_Core_Connector_Vault", "includes/class-mad4b-scp-core-connector-vault.php"),
    ("MAD4B_SCP_Company_Code_Reconciliation", "includes/class-mad4b-scp-company-code-reconciliation.php"),
    ("MAD4B_SCP_FCP_Local_Observability", "includes/class-mad4b-scp-fcp-local-observability.php"),
):
    include = "require_once MAD4B_SCP_DIR . '" + path + "';"
    boot_call = class_name + "::boot();"
    assert entry.count(include) == 1 and entry.count(boot_call) == 1, class_name
    assert entry.index(include) < entry.index(boot_call), class_name + " boot precedes require"
assert "MAD4B_SCP_Private_Operations_Boundary::boot();" not in entry, (
    "Private boundary already registers its hooks on guarded include"
)
print("BOOTSTRAP_SOURCE_REGISTRATION=PASS")
print("PASS: early boot, private CPTs, dynamic site-local relation, authorized staff roles")
