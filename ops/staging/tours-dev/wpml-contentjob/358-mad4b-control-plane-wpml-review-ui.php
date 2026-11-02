<?php
/**
 * Plugin Name: MAD4B Control Plane — WPML Review Visibility
 * Description: Staging-only, read-only Control Plane admin view for pending WPML translation drafts and ContentJobs.
 * Version: 0.1.0-staging
 */
defined('ABSPATH') || exit;

final class MAD4B_Control_Plane_WPML_Review_UI_V1 {
    const PAGE = 'mad4b-wpml-review-dashboard';
    const PARENT = 'mad4b-control-plane';
    const READINESS_PAGE = 'mad4b-content-readiness-alerts';
    private static $readback = null;

    public static function ready() {
        return function_exists('wp_get_environment_type')
            && wp_get_environment_type() === 'staging'
            && rtrim(home_url(), '/') === 'https://tours.dev.mad4b.com'
            && class_exists('MAD4B_WPML_ContentJob_Adapter')
            && class_exists('MAD4B_SCP_Policy');
    }

    public static function permitted() {
        return self::ready()
            && current_user_can('manage_options')
            && MAD4B_SCP_Policy::can_read();
    }

    public static function data() {
        // Always check the current principal before returning a cached read:
        // WordPress permissions can change within a request (e.g. switch user).
        if (!self::permitted())
            return new WP_Error('mad4b_wpml_review_forbidden');
        if (self::$readback !== null) return self::$readback;
        return self::$readback = MAD4B_WPML_ContentJob_Adapter::review_dashboard(100);
    }

    public static function menu() {
        if (!self::ready()) return;
        if (class_exists('MAD4B_SCP_Admin_Route_Registry', false))
            MAD4B_SCP_Admin_Route_Registry::register(self::PAGE, 'manage_options', array(), array('locale', 'view'));
        add_submenu_page(self::PARENT, 'WPML Translation Reviews',
            'WPML Reviews', 'manage_options', self::PAGE,
            array(__CLASS__, 'render'));
    }

    private static function options($selected, $values) {
        $html = '';
        foreach ($values as $key => $label)
            $html .= '<option value="' . esc_attr($key) . '"' . selected($selected, $key, false) . '>'
                . esc_html($label) . '</option>';
        return $html;
    }

    private static function filters() {
        $locale = isset($_GET['locale']) && is_string($_GET['locale'])
            ? sanitize_key(wp_unslash($_GET['locale'])) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view = isset($_GET['view']) && is_string($_GET['view'])
            ? sanitize_key(wp_unslash($_GET['view'])) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $languages = apply_filters('wpml_active_languages', null, 'skip_missing=0');
        $valid = array('all' => 'All languages');
        foreach (is_array($languages) ? $languages : array() as $lang => $details) {
            if (is_string($lang) && preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D', $lang))
                $valid[$lang] = is_array($details)
                    ? (string)($details['translated_name'] ?? $details['native_name'] ?? $lang)
                    : $lang;
        }
        if (!isset($valid[$locale])) $locale = 'all';
        $views = array('all' => 'All review items', 'draft' => 'Unpublished drafts',
            'published' => 'Published — awaiting approval');
        if (!isset($views[$view])) $view = 'all';
        return array($locale, $view, $valid, $views);
    }

    public static function render() {
        if (!self::permitted())
            wp_die(esc_html__('You do not have permission to view this review ledger.', 'mad4b-site-control-plane'), '', array('response' => 403));
        $data = self::data();
        echo '<div class="wrap mad4b-wpml-review-ledger">';
        echo '<h1>WPML Translation Reviews</h1>';
        echo '<p class="description">Read-only evidence from the current Staging site. Drafts are not published translations. No auto-approval or automatic completion.</p>';
        if (is_wp_error($data) || !is_array($data)) {
            echo '<div class="notice notice-error"><p>Review data is unavailable. The translation and ContentJob records have not been changed.</p></div></div>';
            return;
        }
        $totals = (array)($data['totals'] ?? array());
        $drafts = (int)($totals['draft_pending_review'] ?? 0);
        $published = (int)($totals['review_with_published_target'] ?? 0);
        $other = (int)($totals['needs_translation_or_other'] ?? 0);
        $unmapped = (int)($totals['draft_without_native_review'] ?? 0);
        $references_ok = (int)($totals['draft_references_verified'] ?? 0);
        $references_blocked = (int)($totals['draft_references_unresolved'] ?? 0);

        echo '<style>.mad4b-wpml-review-ledger{min-width:0;max-width:100%;box-sizing:border-box;overflow-x:clip}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-metrics{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-metric{background:#fff;border:1px solid #c3c4c7;border-left:3px solid #2271b1;min-width:160px;padding:12px 16px;box-sizing:border-box}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-metric strong{display:block;font-size:23px;line-height:1.5}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-filters{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:16px 0}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-filters label{display:block;font-weight:600;margin-bottom:4px}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-table-scroll{overflow-x:auto}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-table td{vertical-align:top}'
            . '.mad4b-wpml-review-ledger code{overflow-wrap:anywhere}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-state{display:inline-block;background:#f0f0f1;border-radius:3px;padding:2px 7px;font-weight:600}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-warning{border-left:4px solid #dba617;padding:10px 14px;background:#fff;margin:15px 0}'
            . '@media(max-width:782px){'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-metric{flex:1 1 120px;min-width:0}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-filters>*{min-width:150px}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-table-scroll{width:100%;max-width:100%;overflow-x:clip}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-table{display:block;min-width:0!important;width:100%!important;border:0;background:transparent}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-table thead{position:absolute!important;width:1px!important;height:1px!important;overflow:hidden!important;clip-path:inset(50%);white-space:nowrap}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-table tbody{display:block;min-width:0;width:100%}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-table tbody tr{display:block;max-width:100%;min-width:0;margin:0 0 12px;background:#fff;border:1px solid #c3c4c7;border-radius:5px;padding:5px 0}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-table tbody td{display:block!important;width:auto!important;max-width:100%;box-sizing:border-box;min-width:0;padding:7px 12px!important;border:0!important;word-break:break-word;overflow-wrap:anywhere}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-table tbody td::before{content:attr(data-label);display:block;font-size:11px;letter-spacing:.01em;color:#50575e;font-weight:700;margin:0 0 3px}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-table tbody td[colspan]::before{content:none}'
            . '.mad4b-wpml-review-ledger .mad4b-wpml-table tbody td .button{max-width:100%}'
            . '}</style>';
        echo '<div class="mad4b-wpml-metrics" role="group" aria-label="WPML review counts">';
        foreach (array(
            array($drafts, 'Unpublished drafts awaiting review'),
            array($published, 'Published targets awaiting approval'),
            array($unmapped, 'Drafts without native review'),
            array($references_ok, 'Drafts with verified technical references'),
            array($references_blocked, 'Drafts with unresolved references'),
            array($other, 'Other outstanding translation work'),
        ) as $metric) {
            echo '<div class="mad4b-wpml-metric"><strong>' . (int)$metric[0] . '</strong><span>'
                . esc_html($metric[1]) . '</span></div>';
        }
        echo '</div>';
        if ($drafts > 0)
            echo '<div class="mad4b-wpml-warning" role="status"><strong>' . $drafts
                . ' WPML draft translations require review.</strong> Verify Elementor / JetEngine references, language output and public rendering before publication. These ContentJobs remain visible in WAITING_REVIEW.</div>';
        if ($unmapped > 0)
            echo '<div class="notice notice-error"><p>Some drafts are missing a native review handoff. Investigate their ContentJob associations; do not automatically cancel them.</p></div>';
        if ($references_blocked > 0)
            echo '<div class="notice notice-warning"><p><strong>' . $references_blocked
                . ' WPML drafts have unresolved localized Elementor / JetEngine references.</strong> '
                . 'Their ContentJobs remain WAITING_REVIEW. Resolve the referenced provider or listing before publishing; '
                . 'do not create placeholder reviews or substitute an English listing without validation.</p></div>';

        list($locale, $view, $langs, $views) = self::filters();
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="mad4b-wpml-filters">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '">';
        echo '<div><label for="mad4b-wpml-locale">Language</label><select name="locale" id="mad4b-wpml-locale">'
            . self::options($locale, $langs) . '</select></div>';
        echo '<div><label for="mad4b-wpml-view">Review status</label><select name="view" id="mad4b-wpml-view">'
            . self::options($view, $views) . '</select></div>';
        echo '<div><button type="submit" class="button button-secondary">Filter</button></div></form>';

        $rows = array_values(array_filter((array)($data['rows'] ?? array()), static function ($row) use ($locale, $view) {
            if (!is_array($row)) return false;
            if ($locale !== 'all' && ($row['target_language'] ?? '') !== $locale) return false;
            if ($view === 'draft' && ($row['target_post_status'] ?? '') !== 'draft') return false;
            if ($view === 'published' && ($row['target_post_status'] ?? '') !== 'publish') return false;
            return true;
        }));
        echo '<p><strong>' . count($rows) . '</strong> review items shown. Data is refreshed from native WPML and ContentJobs on page load.</p>';
        echo '<div class="mad4b-wpml-table-scroll"><table class="widefat striped mad4b-wpml-table">';
        echo '<thead><tr><th scope="col">Source</th><th scope="col">Language</th><th scope="col">WPML target</th>'
            . '<th scope="col">Native ContentJob</th><th scope="col">Pending checks</th><th scope="col">Review</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="6">No matching review items. Other translation work remains tracked independently.</td></tr>';
        foreach ($rows as $row) {
            $id = (int)($row['target_post_id'] ?? 0);
            $url = $id ? get_edit_post_link($id, 'raw') : '';
            $source = (int)($row['source_post_id'] ?? 0);
            $source_url = get_edit_post_link($source, 'raw');
            $title = (string)($row['source_title'] ?? ('Post #' . $source));
            $target_state = (string)($row['target_post_status'] ?? 'missing');
            $job_state = (string)($row['native_state'] ?? 'unknown');
            $blockers = array_values(array_filter((array)($row['review_blockers'] ?? array()), 'is_string'));
            $outbox_states = array_values(array_filter((array)($row['outbox_statuses'] ?? array()), 'is_string'));
            echo '<tr>';
            echo '<td data-label="Source"><strong>' . ($source_url ? '<a href="' . esc_url($source_url) . '">' . esc_html($title) . '</a>' : esc_html($title))
                . '</strong><br><small>Source #' . $source . ' · ' . esc_html((string)($row['post_type'] ?? '')) . '</small></td>';
            echo '<td data-label="Language"><strong>' . esc_html((string)($row['target_language'] ?? '')) . '</strong></td>';
            echo '<td data-label="WPML target"><span class="mad4b-wpml-state">' . esc_html($target_state) . '</span><br>'
                . ($id ? '<small>WPML #' . $id . '</small>' : '<small>No target yet</small>') . '</td>';
            echo '<td data-label="Native ContentJob"><span class="mad4b-wpml-state">' . esc_html($job_state) . '</span><br>'
                . '<code>' . esc_html((string)($row['job_id'] ?? '')) . '</code><br><small>Outbox: '
                . esc_html(implode(', ', $outbox_states)) . '</small></td>';
            echo '<td data-label="Pending checks"><small>' . esc_html(implode(', ', $blockers)) . '</small></td>';
            echo '<td data-label="Review">' . ($url ? '<a class="button button-small" href="' . esc_url($url) . '" aria-label="Open WPML draft or target ' . $id . ' for review">Open in WordPress</a>'
                : '<span>Review record</span>') . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=' . self::READINESS_PAGE)) . '">Content Readiness &amp; Alerts</a>'
            . ' · <a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '">Refresh review evidence</a></p>';
        echo '<p class="description">Site-scoped, read-only display. Approval, publication and final translation Receipts must use their existing authorized processes.</p>';
        echo '</div>';
    }

    public static function readiness_notice() {
        if (!self::permitted()) return;
        $page = isset($_GET['page']) && is_string($_GET['page'])
            ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($page !== self::READINESS_PAGE) return;
        $data = self::data();
        if (is_wp_error($data) || !is_array($data)) return;
        $counts = (array)($data['totals'] ?? array());
        $n = (int)($counts['draft_pending_review'] ?? 0);
        if (!$n) return;
        echo '<div class="notice notice-warning"><p><strong>WPML review work: ' . $n
            . ' unpublished translation drafts</strong> remain tracked in native ContentJobs. '
            . '<a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '">Open the WPML review ledger</a>.</p></div>';
    }

    public static function boot() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 65);
        add_action('admin_notices', array(__CLASS__, 'readiness_notice'), 20);
    }
}
MAD4B_Control_Plane_WPML_Review_UI_V1::boot();
