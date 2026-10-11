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
    "'authorizing' => false",
    "MAD4B_SCP_Site_Profile::current_environment()",
]:
    if marker not in admin_experience:
        raise SystemExit('FAIL shared environment context missing: ' + marker)

# Assert the actual environment authority and denial behavior, not retired
# human-facing notice wording. The profile remains authoritative only through
# its governed resolver; this admin notice grants no authority.
context = admin_experience.split('public static function environment_context() {', 1)[1].split(
    'public static function environment_context_notice() {', 1
)[0]
for marker in (
    "MAD4B_SCP_Site_Profile::current_environment()",
    "wp_get_environment_type()",
    "'authority_source' => 'mad4b_site_profile'",
    "'authorizing' => false",
):
    if marker not in context:
        raise SystemExit('FAIL environment truth/authority contract missing: ' + marker)

notice = admin_experience.split('public static function environment_context_notice() {', 1)[1].split(
    '/** Query values are strings', 1
)[0]
for marker in (
    "current_user_can( 'manage_options' )",
    "0 !== strpos( $page, 'mad4b-' )",
    "'implicit_nonproduction_override_confirmed'",
    "empty( $profile['wordpress_environment_explicit'] )",
    "'production' === $context['raw_wordpress_environment']",
    "'staging' === $context['effective_environment']",
    "'host_managed' === ( $profile['environment_sync_mode'] ?? '' )",
    "'host_aligned' !== ( $profile['environment_sync_state'] ?? '' )",
    "$warning = $host_sync_pending || ( ! $context['match'] && ! $confirmed_default );",
    "Confirmed staging profile; WordPress is using its implicit Production default. Host alignment is advisory, not a new grant.",
    "The environment differs: verify the exact host and Site Profile before accepting a release.",
):
    if marker not in notice:
        raise SystemExit('FAIL admin environment notice lost a safety guard: ' + marker)

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
    if slug:
        # Both explicit ClassName::PAGE and same-class self::PAGE are valid.
        # Require exact constant and manage_options: an unrelated route or
        # broad permission is not proof of registered admin-page authority.
        owner_ref = r'(?:' + re.escape(owner) + r'|self)::' + slug.group(1)
        if not re.search(r'MAD4B_SCP_Admin_Route_Registry::register\(\s*' + owner_ref + r"\s*,\s*'manage_options'", source):
            raise SystemExit(f'FAIL missing route/permission declaration: {path.name}')
    else:
        # Literal slug pages must register precisely the same guarded route.
        # A declaration alone, wildcard, or weaker capability never suffices.
        menu_blocks = re.findall(r'add_submenu_page\((.*?)\);', source, re.S)
        menu_slugs = []
        for block in menu_blocks:
            match = re.search(r"'manage_options'\s*,\s*'(mad4b-[a-z0-9-]+)'", block)
            if match:
                menu_slugs.append(match.group(1))
        routes = re.findall(
            r"MAD4B_SCP_Admin_Route_Registry::register\(\s*'(mad4b-[a-z0-9-]+)'\s*,\s*'manage_options'\s*\)",
            source,
        )
        if not menu_slugs or len(menu_slugs) != len(set(menu_slugs)) or set(menu_slugs) != set(routes):
            raise SystemExit(f'FAIL literal menu/route permission mismatch: {path.name}')
if checked < 14:
    raise SystemExit('FAIL incomplete admin page inventory')
print(f'Admin route link contract: PASS ({checked} page declarations)')
