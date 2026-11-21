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

# Reviewed native WordPress Admin pages outside the central MAD4B route
# redirect registry. Their menu capabilities are enforced by WordPress itself.
# A distinct positive contract is required for each role-/environment-scoped
# module; this list cannot be populated from the files under test.
native_menu_guards = {
    'class-mad4b-scp-company-task-handoff.php': (
        "add_action('admin_menu',[__CLASS__,'adminMenu'],12);",
        "add_menu_page('My Task Handoffs','Task Handoffs','mad4b_work_company_tasks',",
        "return current_user_can('mad4b_work_company_tasks') &&",
        "self::allowed(get_current_user_id())",
        "!self::authorized($task)",
    ),
    'class-mad4b-scp-company-master-admin.php': (
        "add_action('admin_menu',array(__CLASS__,'menus'),12);",
        "add_menu_page('Company Operations','Company',self::CAP,'mad4b-company'",
        "add_submenu_page('mad4b-company','Team & Translation','Team & Translation',self::CAP",
        "const CAP='mad4b_manage_company_operations';",
        "if(!self::can())wp_die('Not authorized');",
    ),
    'class-mad4b-scp-readiness-scopes.php': (
        "add_action('admin_menu',array(__CLASS__,'menu'),35);",
        "add_submenu_page('mad4b-company','Content Work Scopes','Content Work Scopes',",
        "MAD4B_SCP_Readiness_Integrations::CAP,self::PAGE",
        "if(!self::can_manage())wp_die('Forbidden',403);",
        "'permission_callback'=>array(__CLASS__,'can_manage')",
    ),
    'class-mad4b-scp-readiness-integrations.php': (
        "add_action('admin_menu',array(__CLASS__,'company_menu'),34);",
        "add_submenu_page('mad4b-company','Content Readiness','Content Readiness',self::CAP",
        "add_submenu_page('mad4b-company','Content Follow-up','Content Follow-up',self::CAP",
        "add_submenu_page('mad4b-company','Completeness & SEO','Completeness & SEO',self::CAP",
        "const CAP='mad4b_manage_company_operations';",
        "return current_user_can('manage_options') || current_user_can(self::CAP);",
    ),
    'class-mad4b-scp-readiness-preactivation.php': (
        "add_action('admin_menu',array(__CLASS__,'menu'),36);",
        "add_submenu_page('mad4b-company','Pre-Activation Console','Pre-Activation',",
        "self::CAP,self::PAGE",
        "const CAP='mad4b_manage_company_operations';",
        "if(!self::can_manage())wp_die('Forbidden',403);",
    ),
    'class-mad4b-scp-company-migration-decisions.php': (
        "add_action('admin_menu',static function(){",
        "},20);",
        "add_management_page('Company Migration Decisions','Company Migration Decisions',",
        "add_submenu_page('mad4b-company-workspace','Migration Decisions','Migration Decisions',",
        "'mad4b_manage_company_operations',self::MENU",
        "if(!self::can_propose())wp_die('Company migration planning access denied',403);",
    ),
    'class-mad4b-scp-dfsb-environment-policy.php': (
        "add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );",
        "add_submenu_page( 'mad4b-control-plane', 'Dynamic SEO Environments',",
        "'Dynamic SEO Environments', 'manage_options'",
        "if ( ! current_user_can( 'manage_options' ) ) return;",
    ),
    'class-mad4b-scp-firecrawl-crawl-integration-v1.php': (
        "add_action('admin_menu',[__CLASS__,'menu'],99);",
        "add_submenu_page('mad4b-control-plane','Crawl Pages','Crawl Pages',",
        "'manage_options',self::MENU",
        "if(!current_user_can('manage_options'))wp_die('Administrator required.');",
    ),
    'class-mad4b-scp-staging-brand-trial.php': (
        "if(wp_get_environment_type()!=='staging')return;",
        "add_action('admin_menu',static function(){",
        "},70);",
        "add_submenu_page('mad4b-control-plane','Staging Test Brand','Staging Test Brand',",
        "'manage_options',self::MENU",
        "current_user_can('manage_options') && current_user_can('activate_plugins')",
    ),
    'class-mad4b-scp-staging-context-fixtures.php': (
        "if(wp_get_environment_type()!=='staging')return;",
        "add_action('admin_menu',static function(){",
        "},73);",
        "add_submenu_page('mad4b-control-plane','Staging Context Fixtures',",
        "'mad4b_manage_company_operations',self::MENU",
        "current_user_can('manage_options') || current_user_can('mad4b_manage_company_operations')",
    ),
}
checked_native = set()
checked = 0
for path in (root / 'includes').rglob('*.php'):
    source = path.read_text(encoding='utf-8')
    if re.search(r"admin_url\(\s*['\"]mad4b-[^'\"]+['\"]\s*\)", source):
        raise SystemExit(f'FAIL direct admin slug URL: {path.name}')
    if re.search(r"admin_url\(\s*self::PAGE(?:_SLUG)?\s*\)", source):
        raise SystemExit(f'FAIL direct constant admin URL: {path.name}')
    if not re.search(r'add_(?:sub)?menu_page\(', source):
        continue
    # The native plugins.php GET shell is intentionally earlier than the normal
    # route registry. Validate its tiny manifest/capabilities separately; it
    # never executes an admin page callback or creates a dynamic WP route.
    if path.name == 'class-mad4b-scp-native-plugin-admin-menu.php':
        manifest = (root / 'includes/class-mad4b-scp-admin-navigation-manifest.php').read_text(encoding='utf-8')
        for marker in ("MAD4B_SCP_Admin_Navigation_Manifest::menu_rows()", "self::PARENT", "'manage_options'", "render_only_after_normal_boot"):
            if marker not in source:
                raise SystemExit('FAIL native menu guard missing: ' + marker)
        for marker in ('public static function menu_rows()', "'capability' => 'manage_options'", "public static function normalize_submenus()"):
            if marker not in manifest:
                raise SystemExit('FAIL native manifest guard missing: ' + marker)
        continue
    if path.name in native_menu_guards:
        for marker in native_menu_guards[path.name]:
            if marker not in source:
                raise SystemExit(f'FAIL native role/menu/parent-order guard: {path.name}: {marker}')
        checked_native.add(path.name)
        checked += 1
        continue
    # PHP Traits may own a submenu callback while the original owning class
    # registers that callback with the centralized parent-order scheduler.
    # Resolve both exact require_once and use binding; never accept an orphan
    # Trait, a loosely named class, or a direct admin_menu hook.
    trait_decl = re.search(r'\btrait\s+(MAD4B_SCP_[A-Za-z0-9_]+)\s*\{', source)
    if trait_decl:
        trait_name = trait_decl.group(1)
        users = []
        for owner_path in (root / 'includes').rglob('*.php'):
            if owner_path == path:
                continue
            owner_source = owner_path.read_text(encoding='utf-8')
            required = (
                "require_once __DIR__.'/" + path.name + "';",
                "require_once __DIR__ . '/" + path.name + "';",
            )
            if (re.search(r'\buse\s+' + re.escape(trait_name) + r'\s*;', owner_source)
                    and any(marker in owner_source for marker in required)):
                users.append((owner_path, owner_source))
        if len(users) != 1 or not re.search(r'final class (MAD4B_SCP_\w+)', users[0][1]):
            raise SystemExit(f'FAIL admin submenu Trait owner binding: {path.name}')
        source = users[0][1] + '\n' + source
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
# Regression: Market Profiles, Country Seasons, and Source Hub must share
# one parent-order and capability registry rather than installing their own
# admin_menu handler before the parent is registered.
for filename, slug in (
    ('class-mad4b-scp-market-profiles.php', 'mad4b-market-profiles'),
    ('class-mad4b-scp-season-calendar.php', 'mad4b-season-calendar'),
    ('class-mad4b-scp-source-hub.php', 'mad4b-source-hub'),
):
    src = (root / 'includes' / filename).read_text(encoding='utf-8')
    if ("MAD4B_SCP_Admin_Route_Registry::register('" + slug + "','manage_options')") not in src:
        raise SystemExit('FAIL missing original parent menu route authority: ' + slug)
    if 'MAD4B_SCP_Admin_Route_Registry::schedule_submenu(' not in src:
        raise SystemExit('FAIL direct admin submenu bypass: ' + slug)

if checked_native != set(native_menu_guards):
    raise SystemExit('FAIL missing reviewed native Admin page coverage: '
                     + repr(sorted(set(native_menu_guards) - checked_native)))
if checked < 14:
    raise SystemExit('FAIL incomplete admin page inventory')
print(f'Admin route link contract: PASS ({checked} page declarations)')
