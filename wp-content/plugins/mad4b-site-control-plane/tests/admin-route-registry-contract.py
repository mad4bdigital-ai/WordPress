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
    # This is the original Company Workspace *parent*, not an early child.
    # Its own submenu has exactly the same delegated company capability.
    # Keep the native parent order at priority 12; all other children use
    # the registry's deferred registration. Do not grant WP administrator.
    if path.name == 'class-mad4b-scp-company-master-admin.php':
        required = (
            "const CAP='mad4b_manage_company_operations';",
            "add_menu_page('Company Operations','Company',self::CAP,'mad4b-company',",
            "add_submenu_page('mad4b-company','Team & Translation','Team & Translation',self::CAP,'mad4b-company',",
            "add_action('admin_menu',array(__CLASS__,'menus'),12);",
            "public static function can():bool {return current_user_can(self::CAP);}",
            "'permission_callback'=>array(__CLASS__,'can')",
            "'manage_options','activate_plugins','install_plugins','update_plugins'",
            "if(!in_array(wp_get_environment_type(),['local','staging'],true))",
            "MAD4B_SCP_Company_Owner_Activation::browserOwnerAuthorized()",
        )
        for marker in required:
            if marker not in source:
                raise SystemExit('FAIL company parent menu/role boundary: ' + marker)
        if (source.count("add_menu_page(") != 1 or source.count("add_submenu_page(") != 1
                or source.count("add_action('admin_menu'") != 1):
            raise SystemExit('FAIL company parent menu scope expanded')
        checked += 1
        continue
    # The delegated Company Task Handoffs screen is a separate top-level WP
    # capability surface, not an administrator-only MAD4B submenu. Check its
    # exact WP capability AND its independent per-user access and nonce gates,
    # rather than demanding manage_options (which would break staff access).
    if path.name == 'class-mad4b-scp-company-task-handoff.php':
        required = (
            "add_menu_page('My Task Handoffs','Task Handoffs','mad4b_work_company_tasks',",
            "'mad4b-task-handoffs',[__CLASS__,'screen'",
            "return current_user_can('mad4b_work_company_tasks') &&",
            "(self::master() || self::allowed(get_current_user_id()))",
            "if(!self::accessible())wp_die('Not an approved internal team member.');",
            "if(!self::accessible())wp_die('Company staff access required.',403);",
            "check_admin_referer('mad4b_company_task_handoff');",
            "add_action('admin_menu',[__CLASS__,'adminMenu'],12);",
            "add_action('admin_post_mad4b_company_task_handoff',[__CLASS__,'adminPost']);",
        )
        for marker in required:
            if marker not in source:
                raise SystemExit(f'FAIL delegated company task screen access/nonce guard: {marker}')
        if source.count("add_menu_page(") != 1 or "add_submenu_page(" in source:
            raise SystemExit('FAIL delegated company task menu scope expanded')
        checked += 1
        continue
    # Migration Decisions has two intentionally different WordPress menus:
    # admin-only Tools plus a delegated Company Workspace staff submenu.
    # Check each exact capability, parent-order scheduling, route declaration,
    # and the page/REST permission instead of weakening generic menu policy.
    if path.name == 'class-mad4b-scp-company-migration-decisions.php':
        required = (
            "MAD4B_SCP_Admin_Route_Registry::register(self::MENU,'mad4b_manage_company_operations')",
            "MAD4B_SCP_Admin_Route_Registry::schedule_submenu([__CLASS__,'admin_menu'])",
            "add_management_page('Company Migration Decisions','Company Migration Decisions',",
            "'manage_options',self::MENU,[__CLASS__,'page']",
            "add_submenu_page('mad4b-company-workspace','Migration Decisions','Migration Decisions',",
            "'mad4b_manage_company_operations',self::MENU,[__CLASS__,'page']",
            "if(!self::can_propose())wp_die('Company migration planning access denied',403);",
            "return (current_user_can('mad4b_manage_company_operations') ||",
            "(current_user_can('manage_options') && current_user_can('activate_plugins'))",
            "'permission_callback'=>[__CLASS__,'permission']",
        )
        for marker in required:
            if marker not in source:
                raise SystemExit(f'FAIL migration decision staff/admin permission or parent scheduling: {marker}')
        if source.count("add_submenu_page(") != 1 or source.count("add_management_page(") != 1:
            raise SystemExit('FAIL migration decision extra menu scope')
        if re.search(r"add_action\(\s*['\"]admin_menu['\"]", source):
            raise SystemExit('FAIL migration decision submenu bypasses parent scheduling')
        checked += 1
        continue
    # Readiness Scopes uses the existing Company Operations staff capability,
    # not manage_options. Both WordPress menu and registry must agree exactly.
    if path.name == 'class-mad4b-scp-readiness-scopes.php':
        integration = (root / 'includes/class-mad4b-scp-readiness-integrations.php').read_text(encoding='utf-8')
        required = (
            "const PAGE = 'mad4b-readiness-scopes';",
            "MAD4B_SCP_Admin_Route_Registry::register(self::PAGE,MAD4B_SCP_Readiness_Integrations::CAP)",
            "MAD4B_SCP_Admin_Route_Registry::schedule_submenu(array(__CLASS__,'menu'),35)",
            "add_submenu_page('mad4b-company','Content Work Scopes','Content Work Scopes',",
            "MAD4B_SCP_Readiness_Integrations::CAP,self::PAGE,array(__CLASS__,'screen')",
            "if(!self::can_manage())wp_die('Forbidden',403);",
            "return MAD4B_SCP_Readiness_Integrations::can_manage();",
        )
        for marker in required:
            if marker not in source:
                raise SystemExit('FAIL readiness scopes capability/parent route mismatch: ' + marker)
        for marker in (
            "const CAP='mad4b_manage_company_operations';",
            "return current_user_can('manage_options') || current_user_can(self::CAP);",
        ):
            if marker not in integration:
                raise SystemExit('FAIL readiness scopes delegated permission drift: ' + marker)
        if source.count("add_submenu_page(") != 1 or re.search(r"add_action\(\s*['\"]admin_menu['\"]", source):
            raise SystemExit('FAIL readiness scopes expanded or bypassed original menu authority')
        checked += 1
        continue
    # The Staging Brand Trial registers a self::MENU slug rather than a
    # literal slug. Its original REST and page authority must remain strictly
    # staging-only and administrator-only, in addition to ordered menus.
    if path.name == 'class-mad4b-scp-staging-brand-trial.php':
        required = (
            "const MENU='mad4b-staging-brand-trial';",
            "if(wp_get_environment_type()!=='staging')return;",
            "MAD4B_SCP_Admin_Route_Registry::register(self::MENU,'manage_options')",
            "MAD4B_SCP_Admin_Route_Registry::schedule_submenu(static function()",
            "add_submenu_page('mad4b-control-plane','Staging Test Brand','Staging Test Brand',",
            "'manage_options',self::MENU,[__CLASS__,'page']",
            "return wp_get_environment_type()==='staging'",
            "current_user_can('manage_options') && current_user_can('activate_plugins')",
            "if(!self::canReview())wp_die('WordPress Administrator on Staging required.');",
            "'permission_callback'=>[__CLASS__,'canReview']",
        )
        for marker in required:
            if marker not in source:
                raise SystemExit('FAIL staging brand trial parent/permission/REST guard: ' + marker)
        if (source.count("add_submenu_page(") != 1
                or source.count("MAD4B_SCP_Admin_Route_Registry::register(") != 1
                or re.search(r"add_action\(\s*['\"]admin_menu['\"]", source)):
            raise SystemExit('FAIL staging brand trial menu scope expanded')
        checked += 1
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

if checked < 14:
    raise SystemExit('FAIL incomplete admin page inventory')
print(f'Admin route link contract: PASS ({checked} page declarations)')
