<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Admin_Query_Performance_UI {
	const PAGE_SLUG = 'mad4b-control-plane-performance';
	public static function boot() {
		MAD4B_SCP_Admin_Route_Registry::schedule_submenu( array( __CLASS__, 'register_menu' ), 31 );
	}

	public static function register_menu() {
		add_submenu_page(
			'mad4b-control-plane',
			__( 'MAD4B Performance', 'mad4b-site-control-plane' ),
			__( 'Performance', 'mad4b-site-control-plane' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You do not have permission to manage MAD4B performance maintenance.', 'mad4b-site-control-plane' ) );
		$status = MAD4B_SCP_Admin_Query_Performance::status();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'MAD4B Performance Maintenance', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p>' . esc_html__( 'Performance indexes are never created during plugin upload, update, activation, or ordinary admin requests. Apply them only here during a maintenance window.', 'mad4b-site-control-plane' ) . '</p>';
		$job = isset( $status['maintenance_job'] ) && is_array( $status['maintenance_job'] ) ? $status['maintenance_job'] : array();
		$feedback = sanitize_key( MAD4B_SCP_Admin_Experience::query_string( 'mad4b_performance_apply', '', 128 ) );
		if ( $feedback && MAD4B_SCP_Admin_Experience::notice_verified( self::PAGE_SLUG, $feedback, hash( 'sha256', wp_json_encode( $job ) ) ) ) echo '<div class="notice notice-info"><p>' . esc_html( 'Maintenance request: ' . $feedback . '. Check the persisted worker and index status below.' ) . '</p></div>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		self::row( 'Environment', isset( $status['environment'] ) ? $status['environment'] : '' );
		self::row( 'Ready', ! empty( $status['ready'] ) ? 'yes' : 'no' );
		self::row( 'Automatic apply', ! empty( $status['automatic_apply'] ) ? 'yes' : 'no' );
		self::row( 'Index version', isset( $status['index_version'] ) ? $status['index_version'] : '' );
		self::row( 'Maintenance worker', isset( $job['status'] ) ? $job['status'] : 'Not requested' );
		self::row( 'Maintenance executor ready', ! empty( $status['maintenance_executor_ready'] ) ? 'yes' : 'no' );
		if ( isset( $job['result']['state'] ) ) self::row( 'Worker result', $job['result']['state'] );
		if ( isset( $job['result']['error_code'] ) ) self::row( 'Worker error', $job['result']['error_code'] );
		echo '</tbody></table>';
		if ( ! empty( $status['maintenance_job_health']['reconciliation_required'] ) ) echo '<div class="notice notice-warning"><p>' . esc_html__( 'The maintenance worker exceeded its lease. Reconciliation is required before another attempt; refreshing this page does not retry the operation.', 'mad4b-site-control-plane' ) . '</p></div>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Refresh persisted status', 'mad4b-site-control-plane' ) . '</a></p>';

		if ( class_exists( 'MAD4B_SCP_Core_Web_Vitals', false ) ) MAD4B_SCP_Core_Web_Vitals::render_admin_section();
		self::render_selective_cache_release();
		self::render_css_strategy_status();

		$indexes = isset( $status['indexes'] ) && is_array( $status['indexes'] ) ? $status['indexes'] : array();
		if ( $indexes ) {
			echo '<h2>' . esc_html__( 'Index status', 'mad4b-site-control-plane' ) . '</h2>';
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Index</th><th>Table</th><th>Present</th></tr></thead><tbody>';
			foreach ( $indexes as $key => $row ) {
				echo '<tr><td><code>' . esc_html( (string) $key ) . '</code></td><td><code>' . esc_html( isset( $row['table'] ) ? (string) $row['table'] : '' ) . '</code></td><td>' . esc_html( ! empty( $row['present'] ) ? 'yes' : 'no' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( empty( $status['ready'] ) && 'staging' === ( isset( $status['environment'] ) ? (string) $status['environment'] : '' ) ) {
			echo '<h2>' . esc_html__( 'Explicit maintenance action', 'mad4b-site-control-plane' ) . '</h2>';
			echo '<p><strong>' . esc_html__( 'This may run ALTER TABLE on WordPress meta tables. Run only during a maintenance window.', 'mad4b-site-control-plane' ) . '</strong></p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="mad4b_apply_admin_query_indexes">';
			wp_nonce_field( 'mad4b_apply_admin_query_indexes', 'mad4b_admin_query_performance_nonce' );
			submit_button( __( 'Apply performance indexes', 'mad4b-site-control-plane' ), 'primary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}

	/** Read-only accepted release evidence, independent from Google CrUX. */
	private static function render_selective_cache_release() {
		$receipt = get_option('mad4b_scp_render_cache_release_v1', array());
		$receipt = is_array($receipt) && ($receipt['contract'] ?? '') === 'mad4b.performance-release-verified.v1' ? $receipt : array();
		$policy = array();
		$file = WPMU_PLUGIN_DIR . '/mad4b-selective-render-policy.json';
		if (is_readable($file) && !is_link($file)) {
			$decoded = json_decode((string)file_get_contents($file), true);
			if (is_array($decoded) && ($decoded['contract'] ?? '') === 'mad4b.render-query.public-fragments.v1'
				&& ($decoded['site_host'] ?? '') === wp_parse_url(home_url('/'), PHP_URL_HOST)
				&& ($decoded['environment'] ?? '') === wp_get_environment_type()) $policy = $decoded;
		}
		echo '<h2>Selective Render Cache — source acceptance</h2>';
		echo '<p>Public JetEngine term fragments use the Query Governor and Redis. Visitor pricing, bookings and filtered results are excluded. Core Web Vitals uses independent, rolling 28-day Google field data.</p>';
		echo '<table class="widefat striped" style="max-width:960px"><tbody>';
		self::row('Enabled', (string)get_option('mad4b_render_public_fragments_enabled', '0') === '1' ? 'yes' : 'no');
		self::row('Policy', (string)($policy['status'] ?? 'not configured'));
		self::row('Eligible templates', (string)count((array)($policy['templates'] ?? array())));
		self::row('TTL seconds', (string)($policy['ttl_seconds'] ?? '—'));
		self::row('Last accepted release', (string)($receipt['verified_at_utc'] ?? 'not recorded'));
		self::row('WPML stale term links corrected', isset($receipt['wpml_links_repaired']) ? (int)$receipt['wpml_links_repaired'] : '—');
		self::row('HTML and interaction tests', (string)($receipt['parity_state'] ?? 'not recorded'));
		echo '</tbody></table>';
	}

	/** Read-only CSS delivery gates; this view never enables risky CSS modes. */
	private static function render_css_strategy_status() {
		$read = static function($key) { $v=get_option('litespeed.conf.' . $key, '0'); return $v === '1' || $v === 1 || $v === true ? 'on' : 'off'; };
		$ucss=$read('optm-ucss');
		$async=$read('optm-css_async');
		$plan=class_exists('MAD4B_Predictive_CSS_Plan_V2', false)
			? MAD4B_Predictive_CSS_Plan_V2::summary() : array();
		echo '<h2>CSS optimization — UCSS / CCSS delivery gates</h2>';
		echo '<p>These are current runtime states, not activation controls. UCSS reduces unused styles. Critical CSS provides above-the-fold styles when async loading is verified. Device-specific LCP and interaction evidence are required before rollout.</p>';
		echo '<table class="widefat striped" style="max-width:960px"><tbody>';
		self::row('LiteSpeed Generate UCSS', strtoupper($ucss));
		self::row('LiteSpeed UCSS Inline', strtoupper($read('optm-ucss_inline')) . ($ucss === 'off' ? ' (inactive until UCSS is enabled)' : ''));
		self::row('LiteSpeed Load CSS Async / CCSS', strtoupper($async));
		self::row('LiteSpeed Inline CSS Async Library', strtoupper($read('optm-css_async_inline')) . ($async === 'off' ? ' (inactive until Async is enabled)' : ''));
		self::row('MAD4B Verified Critical CSS', strtoupper((string)get_option('mad4b_perf_critical_css_v1','off')));
		self::row('MAD4B Predictive CSS plan', (string)($plan['release'] ?? 'none') . ' / ' . (string)($plan['runtime'] ?? 'off'));
        $ucss = $plan['ucss_mobile_coverage'] ?? array();
        if (is_array($ucss) && ($ucss['contract'] ?? '') === 'mad4b.mobile-css-qa.v1'
            && ($ucss['site_host'] ?? '') === wp_parse_url(home_url('/'), PHP_URL_HOST)) {
            self::row('Mobile CSS Async test', (string)($ucss['state'] ?? 'unknown'));
            self::row('Mobile UCSS candidate', (int)($ucss['full_bundle_gzip_bytes'] ?? 0) .
                ' → ' . (int)($ucss['candidate_ucss_gzip_bytes'] ?? 0) . ' gzip bytes (QA only)');
            self::row('Local CSS rule coverage', (int)($ucss['css_coverage_used_range_bytes'] ?? 0) .
                ' observed used-rule bytes; interaction review required');
            self::row('Mobile Async delivery', !empty($ucss['mobile_async_enabled_public'])
                ? 'enabled' : 'Canary enabled / public HOLD (LCP regression)');
        }

		echo '</tbody></table>';
		$routes=(array)($plan['routes'] ?? array());
		if ($routes) {
			echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>Route</th><th>Device</th><th>Gate</th><th>Baseline LCP</th><th>Async LCP</th><th>Critical gzip</th></tr></thead><tbody>';
			foreach ($routes as $entry) {
				if (!is_array($entry)) continue;
				$values=array($entry['path']??'', $entry['device']??'', $entry['performance_gate']??'unverified',
					isset($entry['baseline_lcp_ms']) ? (int)$entry['baseline_lcp_ms'] . ' ms' : '—',
					isset($entry['async_lcp_ms']) ? (int)$entry['async_lcp_ms'] . ' ms' : '—',
					isset($entry['critical_gzip_bytes']) ? (int)$entry['critical_gzip_bytes'] . ' B' : '—');
				echo '<tr>'; foreach ($values as $value) echo '<td>' . esc_html((string)$value) . '</td>'; echo '</tr>';
			}
			echo '</tbody></table>';
		}
        if (class_exists('MAD4B_Company_Performance_Intelligence',false)
            && method_exists('MAD4B_Company_Performance_Intelligence','predictive_css_decisions')) {
            $intelligence=MAD4B_Company_Performance_Intelligence::predictive_css_decisions();
            $decisions=(array)($intelligence['decisions']??array());
            if ($decisions) {
                echo '<h3>Performance Intelligence — preserve FCP, remediate LCP</h3>';
                echo '<p>A first-paint improvement can be adopted as a Staging optimization candidate without promoting a regressing LCP to live visitors. Each device has its own release gate and remediation steps.</p>';
                echo '<table class="widefat striped" style="max-width:1050px"><thead><tr><th>Device</th><th>Intelligence decision</th><th>Browser evidence</th><th>Live release</th></tr></thead><tbody>';
                foreach ($decisions as $decision) {
                    if (!is_array($decision)) continue;
                    $name=(string)($decision['device']??'unknown');
                    $samples=(int)($decision['evidence_samples']??0);
                    $source=$samples
                        ? 'FCP improved '.(int)($decision['fcp_improved_samples']??0).'/'.$samples
                            .'; LCP regressed '.(int)($decision['lcp_regressed_samples']??0).'/'.$samples
                        : 'Historical manifest only; new browser A/B required';
                    echo '<tr><td>'.esc_html(ucfirst($name)).'</td><td>'.
                        esc_html((string)($decision['recommendation']??'Review required')).'</td><td>'.
                        esc_html($source).'</td><td>'.
                        esc_html(!empty($decision['live_activation_eligible'])?'Eligible after acceptance':'Hold; native CSS preserved').
                        '</td></tr>';
                }
                echo '</tbody></table>';
                foreach ($decisions as $decision) {
                    if (!is_array($decision) || ($decision['device']??'')!=='mobile') continue;
                    echo '<h4>Mobile LCP recovery — ordered next steps</h4><ol style="max-width:1020px">';
                    foreach ((array)($decision['next_steps']??array()) as $step) {
                        if (!is_array($step)) continue;
                        echo '<li><strong>'.esc_html((string)($step['status']??'pending')).'</strong>: '.esc_html((string)($step['action']??'')).'</li>';
                    }
                    echo '</ol>';
                    if (!empty($decision['verified_lcp_image'])) echo '<p><strong>Observed LCP image:</strong> <code>'.
                        esc_html(wp_parse_url((string)$decision['verified_lcp_image'],PHP_URL_PATH)).
                        '</code></p>';
                    break;
                }
                echo '<p><a href="'.esc_url(admin_url('tools.php?page=mad4b-company-performance-scan')).'">Open full Company Performance Intelligence plan</a></p>';
            }
        }
        echo '<p>UCSS and CCSS must be verified for every public language and device, including interactive JetEngine, Swiper and JetSmartFilters states. Never infer a CrUX Passed verdict from these lab samples.</p>';
	}

	private static function row( $label, $value ) {
		echo '<tr><th style="width:260px">' . esc_html( $label ) . '</th><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
	}
}

MAD4B_SCP_Admin_Query_Performance_UI::boot();

// Routes are declared without booting menus or provider lifecycle on frontend requests.
if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry', false ) ) MAD4B_SCP_Admin_Route_Registry::register( MAD4B_SCP_Admin_Query_Performance_UI::PAGE_SLUG, 'manage_options' );
