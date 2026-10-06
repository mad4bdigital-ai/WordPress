#!/usr/bin/env python3
"""Prevent new admin pages or links from bypassing the shared route contract."""
from pathlib import Path
import re
root = Path(__file__).resolve().parents[1]
bootstrap = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
admin_experience = (root / 'includes' / 'class-mad4b-scp-admin-experience.php').read_text(encoding='utf-8')
early_return = bootstrap.index('unset( $mad4b_scp_early_zero_touch_reason );')
registry_load = bootstrap.index("require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-route-registry.php';")
registry_boot = bootstrap.index('MAD4B_SCP_Admin_Route_Registry::boot();')
first_page_load = bootstrap.index("require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-ui.php';")
if not early_return < registry_load < registry_boot < first_page_load:
    raise SystemExit('FAIL admin routes must boot on the normal path after the foreign-request return and before page declarations')

for marker in [
    'MAD4B_SCP_Admin_Experience::boot();',
]:
    if marker not in bootstrap:
        raise SystemExit('FAIL environment-context-shared-boot: ' + marker)
for marker in [
    "add_action( 'admin_notices', array( __CLASS__, 'environment_context_notice' ), 1 )",
    "'effective_environment'",
    "'raw_wordpress_environment'",
    "'authority_source' => 'mad4b_site_profile'",
    "wp_get_environment_type()",
    "Operational authority follows the MAD4B Site Profile",
]:
    if marker not in admin_experience:
        raise SystemExit('FAIL shared environment context missing: ' + marker)
checked = 0
for path in (root / 'includes').rglob('*.php'):
    source = path.read_text(encoding='utf-8')
    if re.search(r"admin_url\(\s*['\"]mad4b-[^'\"]+['\"]\s*\)", source):
        raise SystemExit(f'FAIL direct admin slug URL: {path.name}')
    if re.search(r"admin_url\(\s*self::PAGE(?:_SLUG)?\s*\)", source):
        raise SystemExit(f'FAIL direct constant admin URL: {path.name}')
    if not re.search(r'add_(?:sub)?menu_page\(', source):
        continue
    if 'add_submenu_page(' in source and (
        'MAD4B_SCP_Admin_Route_Registry::schedule_submenu(' not in source
        or re.search(r"add_action\(\s*['\"]admin_menu['\"]", source)
    ):
        raise SystemExit(f'FAIL submenu bypasses parent-order contract: {path.name}')
    checked += 1
    owner = re.search(r'final class (\w+)', source).group(1)
    slug = re.search(r"const (PAGE_SLUG|PAGE)\s*=\s*['\"](mad4b-[^'\"]+)['\"]", source)
    if not slug or not re.search(r'MAD4B_SCP_Admin_Route_Registry::register\(\s*' + re.escape(owner) + r'::' + slug.group(1) + r"\s*,\s*'manage_options'", source):
        raise SystemExit(f'FAIL missing route/permission declaration: {path.name}')
if checked < 14:
    raise SystemExit('FAIL incomplete admin page inventory')
print(f'Admin route link contract: PASS ({checked} page declarations)')
