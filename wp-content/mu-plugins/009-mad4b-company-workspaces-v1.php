<?php
/**
 * Plugin Name: MAD4B Company Workspaces
 * Description: Tenant-scoped Company/Content Jobs navigation for operators. No global WordPress administrator access.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Workspaces_V1 {
    private const OPERATIONS_CAP = 'mad4b_manage_company_operations';
    private const SLUG = 'mad4b-company-workspaces';
    private const JOBS_SLUG = 'mad4b-company-content-jobs';

    public static function boot(): void {
        // Add only already-defined company-scoped content/pricing capabilities
        // where the historical Master Admin role was not reconciled.
        add_action('init', [__CLASS__, 'reconcile_scoped_caps'], 110);
        add_action('admin_menu', [__CLASS__, 'menu'], 150);
    }

    public static function reconcile_scoped_caps(): void {
        $role = get_role('master_admin');
        if (!$role) return;
        foreach (['mad4b_manage_company_pricing', 'mad4b_propose_company_content'] as $cap) {
            if (!$role->has_cap($cap)) $role->add_cap($cap, true);
        }
        // Do not add manage_options, manage_woocommerce, user promotion,
        // plugin installation, Host authority or signed-release access.
    }

    private static function can_open(): bool {
        return current_user_can(self::OPERATIONS_CAP) && current_user_can('edit_others_posts');
    }

    public static function menu_parent(): string {
        global $menu;
        foreach ((array)$menu as $item) {
            if (($item[2] ?? '') === 'mad4b-company' &&
                current_user_can((string)($item[1] ?? ''))) return 'mad4b-company';
        }
        return self::SLUG;
    }

    public static function menu(): void {
        if (!self::can_open()) return;
        $parent=self::menu_parent();
        if ($parent === self::SLUG) {
            add_menu_page('MAD4B Workspaces','MAD4B',self::OPERATIONS_CAP,
                self::SLUG,[__CLASS__,'overview'],'dashicons-screenoptions',34);
        }
        add_submenu_page($parent, 'MAD4B Workspaces', 'Workspaces',
            self::OPERATIONS_CAP, self::SLUG, [__CLASS__, 'overview']);
        add_submenu_page($parent, 'Content Jobs', 'Content Jobs',
            self::OPERATIONS_CAP, self::JOBS_SLUG, [__CLASS__, 'jobs']);
    }

    private static function registered(string $slug): bool {
        global $menu, $submenu;
        foreach ((array)$menu as $item) {
            if (($item[2] ?? null) === $slug && current_user_can((string)($item[1] ?? ''))) return true;
        }
        foreach ((array)$submenu as $group) foreach ((array)$group as $item) {
            if (($item[2] ?? null) === $slug && current_user_can((string)($item[1] ?? ''))) return true;
        }
        return false;
    }

    private static function link(string $slug, string $label, string $hint): void {
        if (!self::registered($slug)) return;
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=' . $slug)) . '"><strong>' .
            esc_html($label) . '</strong></a> — ' . esc_html($hint) . '</li>';
    }

    public static function overview(): void {
        if (!self::can_open()) wp_die(esc_html__('Company operations access required.', 'mad4b'));
        echo '<div class="wrap"><h1>MAD4B Company Workspaces</h1>';
        echo '<p>Company-scoped operational tools. WordPress system settings, plugin installation, global WooCommerce settings and independent release signing remain separately authorized.</p>';
        echo '<h2>Content &amp; Jobs</h2><ul class="ul-disc">';
        self::link(self::JOBS_SLUG, 'Content Jobs', 'Read the existing site/brand/tenant-fenced jobs and their status');
        self::link('mad4b-company-staff-roles', 'Staff & Roles', 'Create and promote site-owned staff only within your capability ceiling');
        if (class_exists('MAD4B_SCP_Content_Readiness_Alerts'))
            self::link(MAD4B_SCP_Content_Readiness_Alerts::PAGE, 'Content Readiness', 'Readiness issues and follow-up');
        if (class_exists('MAD4B_SCP_Content_Completeness'))
            self::link(MAD4B_SCP_Content_Completeness::PAGE, 'Completeness & SEO', 'Content quality and SEO coverage');
        self::link('mad4b-company-configuration-jobs', 'Configuration Jobs', 'Plugin and provider operational alerts');
        echo '</ul><h2>Pricing</h2><ul class="ul-disc">';
        if (current_user_can('commerce_manage_pricing')) {
            self::link('mad4b-pricing-workspace', 'Pricing Workspace', 'Company pricing overview');
            self::link('mad4b-pricing-lab', 'Pricing Lab', 'Draft strategy and simulations');
            self::link('mad4b-policy-bindings', 'Policy Bindings', 'Inheritance references and previews');
            self::link('mad4b-pricing-currencies', 'Currencies & Markets', 'Company pricing draft configuration');
            self::link('etg-dynamic-pricing-controls', 'Dynamic Pricing Controls', 'Existing native JetEngine options');
        }
        echo '</ul><h2>Company operations</h2><ul class="ul-disc">';
        self::link('mad4b-company', 'Company Team', 'Tasks, staff and translation');
        echo '</ul></div>';
    }

    public static function jobs(): void {
        if (!self::can_open()) wp_die(esc_html__('Company content access required.', 'mad4b'));
        echo '<div class="wrap"><h1>MAD4B Content Jobs</h1>';
        echo '<p>Read-only site/brand/tenant-scoped view of existing native Content Jobs. Job creation, transitions, publication and owner approvals keep their original gates.</p>';
        if (!class_exists('MAD4B_SCP_Content_Jobs')) {
            echo '<div class="notice notice-warning"><p>Native Content Jobs module unavailable.</p></div></div>';
            return;
        }
        $states = MAD4B_SCP_Content_Jobs::states();
        $state = isset($_GET['job_state']) && is_string($_GET['job_state'])
            ? strtoupper(sanitize_key(wp_unslash($_GET['job_state']))) : '';
        if ($state !== '' && !in_array($state, $states, true)) {
            echo '<div class="notice notice-error"><p>Unsupported Content Job state.</p></div></div>';
            return;
        }
        $limit = 80;
        $result = MAD4B_SCP_Content_Jobs::list_jobs(['state' => $state, 'limit' => $limit]);
        if (is_wp_error($result)) {
            echo '<div class="notice notice-warning"><p>Native Content Jobs readback unavailable: <code>' .
                esc_html($result->get_error_code()) . '</code></p></div></div>';
            return;
        }
        if (!is_array($result) || ($result['read_scope'] ?? '') !== 'site_uuid_brand_id_tenant_id') {
            echo '<div class="notice notice-error"><p>Scope verification unavailable. No jobs displayed.</p></div></div>';
            return;
        }
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::JOBS_SLUG) . '">';
        echo '<label for="mad4b-job-state">State</label> <select id="mad4b-job-state" name="job_state">';
        echo '<option value="">All states</option>';
        foreach ($states as $option) {
            echo '<option value="' . esc_attr($option) . '"' . selected($state, $option, false) . '>' .
                esc_html($option) . '</option>';
        }
        echo '</select> <button class="button">Filter jobs</button></form>';
        $rows = (array)($result['items'] ?? []);
        echo '<p><strong>' . count($rows) . '</strong> most recent matching jobs (maximum ' . $limit . '). Results are restricted by the original tenant scope.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Subject</th><th>State</th><th>Stage</th><th>Language</th><th>Updated</th><th>Job ID</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $subject = wp_trim_words(wp_strip_all_tags((string)($row['subject'] ?? '')), 22, '…');
            echo '<tr><td>' . esc_html($subject) . '</td><td>' .
                esc_html((string)($row['state'] ?? '')) . '</td><td>' .
                esc_html((string)($row['stage'] ?? '')) . '</td><td>' .
                esc_html((string)($row['language'] ?? '')) . '</td><td>' .
                esc_html((string)($row['updated_at'] ?? '')) . '</td><td><code>' .
                esc_html((string)($row['job_id'] ?? '')) . '</code></td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="6">No matching jobs in this tenant scope.</td></tr>';
        echo '</tbody></table></div>';
    }
}

MAD4B_Company_Workspaces_V1::boot();
