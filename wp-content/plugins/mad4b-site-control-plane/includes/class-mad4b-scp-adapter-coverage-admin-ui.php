<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only product surface for automatic plugin/adapter coverage discovery. */
final class MAD4B_SCP_Adapter_Coverage_Admin_UI {
	const PAGE_SLUG = 'mad4b-adapter-coverage';
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 30 );
	}

	public static function register_menu() {
		add_submenu_page(
			MAD4B_SCP_Admin_UI::PAGE_SLUG,
			__( 'Adapter Coverage', 'mad4b-site-control-plane' ),
			__( 'Adapter Coverage', 'mad4b-site-control-plane' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function snapshot() {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_adapter_coverage_capability_denied', 'Administrator capability is required to inspect adapter coverage.' );
		if ( ! class_exists( 'MAD4B_SCP_Plugin_Discovery' ) ) return new WP_Error( 'mad4b_plugin_discovery_unavailable', 'Plugin adapter discovery is unavailable.' );
		return MAD4B_SCP_Plugin_Discovery::coverage();
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You do not have permission to inspect adapter coverage.', 'mad4b-site-control-plane' ) );
		$snapshot = self::snapshot();
		$tabs = array(
			'overview' => __( 'Overview', 'mad4b-site-control-plane' ),
			'installed' => __( 'Installed Plugins', 'mad4b-site-control-plane' ),
			'priority' => __( 'Priority Coverage', 'mad4b-site-control-plane' ),
			'functional' => __( 'Functional Gaps', 'mad4b-site-control-plane' ),
			'requests' => __( 'Support Requests', 'mad4b-site-control-plane' ),
		);
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		if ( ! isset( $tabs[ $tab ] ) ) $tab = 'overview';

		MAD4B_SCP_Admin_Experience::styles();
		echo '<div class="wrap mad4b-scp-admin-page"><h1>' . esc_html__( 'MAD4B Adapter Coverage', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Read-only adapter readiness workspace. It separates discovery, support coverage, provider certification and runtime blockers. Unknown plugins are never auto-installed, auto-enabled, auto-generated or granted write authority.', 'mad4b-site-control-plane' ) . '</p>';
		if ( is_wp_error( $snapshot ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $snapshot->get_error_message() ) . '</p></div></div>'; return; }

		$counts = isset( $snapshot['counts'] ) && is_array( $snapshot['counts'] ) ? $snapshot['counts'] : array();
		$functional_counts = isset( $snapshot['functional_family_counts'] ) && is_array( $snapshot['functional_family_counts'] ) ? $snapshot['functional_family_counts'] : ( isset( $snapshot['functional_counts'] ) && is_array( $snapshot['functional_counts'] ) ? $snapshot['functional_counts'] : array() );
		MAD4B_SCP_Admin_Experience::stages( self::coverage_stages( $counts, $functional_counts ) );
		MAD4B_SCP_Admin_Experience::tabs( self::PAGE_SLUG, $tabs, $tab );

		if ( 'overview' === $tab ) self::render_overview( $snapshot, $counts, $functional_counts );
		if ( 'installed' === $tab ) self::render_plugins( isset( $snapshot['plugins'] ) && is_array( $snapshot['plugins'] ) ? $snapshot['plugins'] : array() );
		if ( 'priority' === $tab ) self::render_priority( isset( $snapshot['priority_external'] ) && is_array( $snapshot['priority_external'] ) ? $snapshot['priority_external'] : array() );
		if ( 'functional' === $tab ) self::render_functional( isset( $snapshot['plugins'] ) && is_array( $snapshot['plugins'] ) ? $snapshot['plugins'] : array(), $functional_counts );
		if ( 'requests' === $tab ) self::render_requests( isset( $snapshot['support_requests'] ) && is_array( $snapshot['support_requests'] ) ? $snapshot['support_requests'] : array() );
		echo '</div>';
	}

	private static function coverage_stages( array $counts, array $functional_counts = array() ) {
		$installed = isset( $counts['installed'] ) ? (int) $counts['installed'] : 0;
		$needs_adapter = isset( $counts['adapter_required'] ) ? (int) $counts['adapter_required'] : 0;
		$needs_cert = isset( $counts['adapter_present_certification_required'] ) ? (int) $counts['adapter_present_certification_required'] : 0;
		$side_channel = isset( $counts['adapter_present_side_channel_blocked'] ) ? (int) $counts['adapter_present_side_channel_blocked'] : 0;
		$safety_blocked = isset( $functional_counts['safety_blocked'] ) ? (int) $functional_counts['safety_blocked'] : 0;
		$review_candidates = ( isset( $functional_counts['contract_discovery_required'] ) ? (int) $functional_counts['contract_discovery_required'] : 0 ) + ( isset( $functional_counts['status_only_candidate'] ) ? (int) $functional_counts['status_only_candidate'] : 0 ) + ( isset( $functional_counts['adapter_missing'] ) ? (int) $functional_counts['adapter_missing'] : 0 );
		return array(
			array( 'label' => __( 'Discover', 'mad4b-site-control-plane' ), 'state' => $installed > 0 ? 'complete' : 'pending', 'detail' => __( 'Inventory installed and active plugins.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'installed' ) ),
			array( 'label' => __( 'Match adapter', 'mad4b-site-control-plane' ), 'state' => 0 === $needs_adapter ? 'complete' : 'attention', 'detail' => __( 'Map each plugin to a governed support strategy.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'requests' ) ),
			array( 'label' => __( 'Certify provider', 'mad4b-site-control-plane' ), 'state' => 0 === $needs_cert ? 'complete' : 'attention', 'detail' => __( 'Verify exact provider version and contract before mutation.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'installed' ) ),
			array( 'label' => __( 'Clear runtime blockers', 'mad4b-site-control-plane' ), 'state' => 0 === $side_channel ? 'complete' : 'blocked', 'detail' => __( 'Resolve parallel MCP/write-plane isolation blockers.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'installed' ) ),
			array( 'label' => __( 'Functional readiness', 'mad4b-site-control-plane' ), 'state' => $safety_blocked > 0 ? 'blocked' : ( $review_candidates > 0 ? 'attention' : 'complete' ), 'detail' => $safety_blocked > 0 ? __( 'Execution exists in the product model but remains fail-closed until safety contracts are certified.', 'mad4b-site-control-plane' ) : __( 'Review remaining status-only or missing functional coverage.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'functional' ) ),
		);
	}

	private static function render_overview( array $snapshot, array $counts, array $functional_counts = array() ) {
		$supported = (int) ( isset( $counts['supported_reversible'] ) ? $counts['supported_reversible'] : 0 )
			+ (int) ( isset( $counts['supported_governed'] ) ? $counts['supported_governed'] : 0 )
			+ (int) ( isset( $counts['read_only_supported'] ) ? $counts['read_only_supported'] : 0 );
		$needs_adapter = isset( $counts['adapter_required'] ) ? (int) $counts['adapter_required'] : 0;
		$needs_cert = isset( $counts['adapter_present_certification_required'] ) ? (int) $counts['adapter_present_certification_required'] : 0;
		$side_channel = isset( $counts['adapter_present_side_channel_blocked'] ) ? (int) $counts['adapter_present_side_channel_blocked'] : 0;
		$priority_missing = isset( $counts['priority_external_missing'] ) ? (int) $counts['priority_external_missing'] : 0;
		$functional_blocked = isset( $functional_counts['safety_blocked'] ) ? (int) $functional_counts['safety_blocked'] : 0;
		$contract_discovery = isset( $functional_counts['contract_discovery_required'] ) ? (int) $functional_counts['contract_discovery_required'] : 0;
		$functional_review = $contract_discovery + ( isset( $functional_counts['status_only_candidate'] ) ? (int) $functional_counts['status_only_candidate'] : 0 ) + ( isset( $functional_counts['adapter_missing'] ) ? (int) $functional_counts['adapter_missing'] : 0 );

		MAD4B_SCP_Admin_Experience::cards( array(
			array( 'label' => 'Installed', 'value' => isset( $counts['installed'] ) ? (string) $counts['installed'] : '0', 'state' => ! empty( $counts['installed'] ) ? 'complete' : 'pending', 'help' => 'Plugins included in runtime discovery.' ),
			array( 'label' => 'Supported', 'value' => (string) $supported, 'state' => 'complete', 'help' => 'Reversible, governed or read-only coverage.' ),
			array( 'label' => 'Needs adapter', 'value' => (string) $needs_adapter, 'state' => 0 === $needs_adapter ? 'complete' : 'attention', 'help' => 'No silent fallback to write authority.' ),
			array( 'label' => 'Needs certification', 'value' => (string) $needs_cert, 'state' => 0 === $needs_cert ? 'complete' : 'attention', 'help' => 'Adapter exists but exact provider proof is missing.' ),
			array( 'label' => 'Runtime blocked', 'value' => (string) $side_channel, 'state' => 0 === $side_channel ? 'complete' : 'blocked', 'help' => 'Parallel MCP/write-plane risk remains.' ),
			array( 'label' => 'Functional blocked', 'value' => (string) $functional_blocked, 'state' => 0 === $functional_blocked ? 'complete' : 'blocked', 'help' => 'Desired provider execution remains intentionally unmounted until safety contracts close.' ),
			array( 'label' => 'Contract discovery', 'value' => (string) $contract_discovery, 'state' => 0 === $contract_discovery ? 'complete' : 'attention', 'help' => 'Providers whose exact read/write/credential/side-effect contract must be captured before scope expansion.' ),
			array( 'label' => 'Functional review', 'value' => (string) $functional_review, 'state' => 0 === $functional_review ? 'complete' : 'attention', 'help' => 'Contract discovery, status-only or missing provider functions that still need an explicit scope decision.' ),
			array( 'label' => 'Priority missing', 'value' => (string) $priority_missing, 'state' => 0 === $priority_missing ? 'complete' : 'pending', 'help' => 'Priority external plugins not installed on this site.' ),
		) );

		if ( $side_channel > 0 ) {
			MAD4B_SCP_Admin_Experience::next_step( 'Highest-priority attention', 'One or more adapters are blocked by a parallel MCP/write surface. Keep normal mutation denied until the provider-native plane is isolated and certified.', 'blocked', MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'installed' ), 'Inspect runtime blockers' );
		} elseif ( $functional_blocked > 0 ) {
			MAD4B_SCP_Admin_Experience::next_step( 'Functional execution blocked', 'One or more provider execution paths are intentionally unmounted until their safety contracts close.', 'blocked', MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'functional' ), 'Inspect functional blockers' );
		} elseif ( $needs_cert > 0 ) {
			MAD4B_SCP_Admin_Experience::next_step( 'Next step', 'Complete exact provider certification for adapters that are present but not yet mutation-ready.', 'attention', MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'installed' ), 'Inspect provider certification' );
		} elseif ( $needs_adapter > 0 ) {
			MAD4B_SCP_Admin_Experience::next_step( 'Next step', 'Review deterministic adapter support requests for plugins that do not yet have a governed support contract.', 'attention', MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'requests' ), 'Review support requests' );
		} elseif ( $functional_review > 0 ) {
			MAD4B_SCP_Admin_Experience::next_step( 'Contract discovery required', 'Provider inventory is known, but one or more functional contracts still need runtime/vendor evidence before scope can safely expand.', 'attention', MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'functional' ), 'Review contract discovery' );
		} else {
			MAD4B_SCP_Admin_Experience::next_step( 'Coverage ready', 'No unresolved adapter, certification, runtime or functional-contract blockers are reported by the current discovery snapshot.', 'complete' );
		}

		echo '<h2>' . esc_html__( 'Coverage counts', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped" style="max-width:1000px"><tbody>';
		foreach ( array( 'installed', 'active', 'supported_reversible', 'supported_governed', 'read_only_supported', 'adapter_registered_inactive', 'adapter_present_certification_required', 'adapter_present_side_channel_blocked', 'adapter_required', 'excluded_high_risk', 'priority_external_missing' ) as $key ) {
			echo '<tr><th>' . esc_html( str_replace( '_', ' ', $key ) ) . '</th><td>' . esc_html( isset( $counts[ $key ] ) ? (string) $counts[ $key ] : '0' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

		echo '<div class="mad4b-scp-panel"><h2>' . esc_html__( 'How to read coverage', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p><strong>supported_reversible</strong> — ' . esc_html__( 'bounded mutation plus certified restore/undo contract.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<p><strong>supported_governed</strong> — ' . esc_html__( 'governed support exists but reversibility may be intentionally narrower.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<p><strong>adapter_present_certification_required</strong> — ' . esc_html__( 'implementation exists, but the exact provider runtime is not yet trusted for mutation.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<p><strong>adapter_present_side_channel_blocked</strong> — ' . esc_html__( 'a parallel write/MCP plane prevents normal writer authority until isolation is certified.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<p><strong>adapter_required</strong> — ' . esc_html__( 'no supported adapter contract exists; write remains denied by default.', 'mad4b-site-control-plane' ) . '</p></div>';
	}

	private static function render_plugins( array $items ) {
		echo '<h2>' . esc_html__( 'Installed plugins', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="mad4b-scp-section-lead">' . esc_html__( 'Use this table to distinguish installation state from governance readiness. An active plugin is not automatically a mutation-ready provider.', 'mad4b-site-control-plane' ) . '</p>';
		if ( ! $items ) { echo '<p>' . esc_html__( 'No plugins discovered.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th>Plugin</th><th>Version</th><th>Active</th><th>Family</th><th>Adapter</th><th>Coverage</th><th>Provider cert</th><th>Runtime blocker</th><th>Risk</th><th>Support request</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$request = isset( $item['support_request']['support_request_id'] ) ? (string) $item['support_request']['support_request_id'] : '';
			$provider_cert = empty( $item['provider_certification_required'] ) ? 'not-required' : ( ! empty( $item['provider_certification_ok'] ) ? 'certified' : 'required' );
			$runtime_blocker = isset( $item['side_channel_blocker'] ) ? (string) $item['side_channel_blocker'] : '';
			echo '<tr><td><strong>' . esc_html( isset( $item['name'] ) ? $item['name'] : '' ) . '</strong><br><code>' . esc_html( isset( $item['plugin_file'] ) ? $item['plugin_file'] : '' ) . '</code></td>';
			echo '<td>' . esc_html( isset( $item['version'] ) ? $item['version'] : '' ) . '</td><td>' . esc_html( ! empty( $item['active'] ) ? 'yes' : 'no' ) . '</td><td>' . esc_html( isset( $item['family'] ) ? $item['family'] : '' ) . '</td><td><code>' . esc_html( isset( $item['adapter_id'] ) ? $item['adapter_id'] : '' ) . '</code></td><td><strong>' . esc_html( isset( $item['coverage_state'] ) ? $item['coverage_state'] : '' ) . '</strong></td><td>' . esc_html( $provider_cert ) . '</td><td><code>' . esc_html( $runtime_blocker ) . '</code></td><td>' . esc_html( isset( $item['risk'] ) ? $item['risk'] : '' ) . '</td><td><code>' . esc_html( $request ) . '</code></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_priority( array $items ) {
		echo '<h2>' . esc_html__( 'Priority external coverage', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="mad4b-scp-section-lead">' . esc_html__( 'Priority external families remain first-class coverage targets even when their archives are not stored in this repository.', 'mad4b-site-control-plane' ) . '</p>';
		if ( ! $items ) { echo '<p>' . esc_html__( 'All configured priority external plugins are installed.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th>Plugin</th><th>Adapter</th><th>Coverage</th><th>Risk</th><th>Support request</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$request = isset( $item['support_request']['support_request_id'] ) ? (string) $item['support_request']['support_request_id'] : '';
			echo '<tr><td>' . esc_html( isset( $item['name'] ) ? $item['name'] : '' ) . '</td><td><code>' . esc_html( isset( $item['adapter_id'] ) ? $item['adapter_id'] : '' ) . '</code></td><td>' . esc_html( isset( $item['coverage_state'] ) ? $item['coverage_state'] : '' ) . '</td><td>' . esc_html( isset( $item['risk'] ) ? $item['risk'] : '' ) . '</td><td><code>' . esc_html( $request ) . '</code></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_functional( array $items, array $counts ) {
		echo '<h2>' . esc_html__( 'Functional coverage gaps', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="mad4b-scp-section-lead">' . esc_html__( 'Provider-family view of active business-function gaps, ordered by blocking state and provider risk. Counts are deduplicated by family so add-ons do not inflate readiness. This view is read-only and never creates adapters, grants, approvals or mutation authority.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<div class="mad4b-scp-panel"><strong>' . esc_html__( 'Provider-family summary:', 'mad4b-site-control-plane' ) . '</strong> ';
		foreach ( array( 'functional_ready','status_only_candidate','contract_discovery_required','safety_blocked','adapter_missing','intentionally_excluded' ) as $key ) {
			echo '<span style="margin-right:14px"><code>' . esc_html( $key ) . '</code> ' . esc_html( isset( $counts[ $key ] ) ? (string) $counts[ $key ] : '0' ) . '</span>';
		}
		echo '</div>';

		$ranks = array( 'functional_ready'=>1, 'intentionally_excluded'=>2, 'status_only_candidate'=>3, 'contract_discovery_required'=>4, 'adapter_missing'=>5, 'safety_blocked'=>6 );
		$groups = array();
		foreach ( $items as $item ) {
			if ( empty( $item['active'] ) || empty( $item['functional_coverage'] ) || ! is_array( $item['functional_coverage'] ) ) continue;
			$f = $item['functional_coverage'];
			$state = isset( $f['state'] ) ? sanitize_key( (string) $f['state'] ) : '';
			if ( in_array( $state, array( 'functional_ready', 'inactive' ), true ) ) continue;
			$key = ! empty( $item['family'] ) ? sanitize_key( (string) $item['family'] ) : sanitize_key( (string) ( isset( $item['plugin_file'] ) ? $item['plugin_file'] : '' ) );
			if ( '' === $key ) $key = 'unknown-provider';
			$member = trim( (string) ( isset( $item['name'] ) ? $item['name'] : '' ) );
			if ( '' === $member ) $member = isset( $item['plugin_file'] ) ? (string) $item['plugin_file'] : $key;
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'item'=>$item, 'functional'=>$f, 'state'=>$state, 'members'=>array( $member ) );
				continue;
			}
			$groups[ $key ]['members'][] = $member;
			$current_rank = isset( $ranks[ $groups[ $key ]['state'] ] ) ? (int) $ranks[ $groups[ $key ]['state'] ] : 0;
			$new_rank = isset( $ranks[ $state ] ) ? (int) $ranks[ $state ] : 0;
			if ( $new_rank > $current_rank ) {
				$groups[ $key ]['item'] = $item;
				$groups[ $key ]['functional'] = $f;
				$groups[ $key ]['state'] = $state;
			}
		}
		$risk_ranks = array( 'critical'=>5, 'high'=>4, 'medium'=>3, 'low'=>2, 'none'=>1, ''=>0 );
		uasort( $groups, static function( $left, $right ) use ( $ranks, $risk_ranks ) {
			$left_state = isset( $left['state'] ) ? (string) $left['state'] : '';
			$right_state = isset( $right['state'] ) ? (string) $right['state'] : '';
			$left_state_rank = isset( $ranks[ $left_state ] ) ? (int) $ranks[ $left_state ] : 0;
			$right_state_rank = isset( $ranks[ $right_state ] ) ? (int) $ranks[ $right_state ] : 0;
			if ( $left_state_rank !== $right_state_rank ) return $right_state_rank <=> $left_state_rank;
			$left_risk = isset( $left['item']['risk'] ) ? sanitize_key( (string) $left['item']['risk'] ) : '';
			$right_risk = isset( $right['item']['risk'] ) ? sanitize_key( (string) $right['item']['risk'] ) : '';
			$left_risk_rank = isset( $risk_ranks[ $left_risk ] ) ? (int) $risk_ranks[ $left_risk ] : 0;
			$right_risk_rank = isset( $risk_ranks[ $right_risk ] ) ? (int) $risk_ranks[ $right_risk ] : 0;
			if ( $left_risk_rank !== $right_risk_rank ) return $right_risk_rank <=> $left_risk_rank;
			$left_family = isset( $left['item']['family'] ) ? (string) $left['item']['family'] : '';
			$right_family = isset( $right['item']['family'] ) ? (string) $right['item']['family'] : '';
			return strcmp( $left_family, $right_family );
		} );

		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th>Family</th><th>Active plugins</th><th>State</th><th>Read</th><th>Write</th><th>Risk</th><th>Reason</th><th>Evidence needed</th><th>Safe now</th><th>Blocked scope</th><th>Blockers</th><th>Next safe action</th></tr></thead><tbody>';
		foreach ( $groups as $family => $group ) {
			$item = $group['item']; $f = $group['functional']; $members = array_values( array_unique( $group['members'] ) );
			$visible = array_slice( $members, 0, 4 ); $member_text = implode( ' · ', $visible );
			if ( count( $members ) > count( $visible ) ) $member_text .= ' · +' . ( count( $members ) - count( $visible ) ) . ' more';
			echo '<tr><td><strong>' . esc_html( $family ) . '</strong></td><td>' . esc_html( $member_text ) . '</td><td><strong>' . esc_html( $group['state'] ) . '</strong></td>';
			echo '<td>' . esc_html( isset( $f['read_ability_count'] ) ? (string) $f['read_ability_count'] : '0' ) . '</td><td>' . esc_html( isset( $f['write_ability_count'] ) ? (string) $f['write_ability_count'] : '0' ) . '</td>';
			echo '<td>' . esc_html( isset( $item['risk'] ) ? $item['risk'] : '' ) . '</td><td><code>' . esc_html( isset( $f['reason'] ) ? $f['reason'] : '' ) . '</code></td>';
			echo '<td>' . esc_html( ! empty( $f['evidence_requirements'] ) && is_array( $f['evidence_requirements'] ) ? implode( ', ', $f['evidence_requirements'] ) : '' ) . '</td>';
			echo '<td><code>' . esc_html( ! empty( $f['safe_now'] ) && is_array( $f['safe_now'] ) ? implode( ', ', $f['safe_now'] ) : '' ) . '</code></td>';
			echo '<td><code>' . esc_html( ! empty( $f['prohibited_until_certified'] ) && is_array( $f['prohibited_until_certified'] ) ? implode( ', ', $f['prohibited_until_certified'] ) : '' ) . '</code></td>';
			echo '<td><code>' . esc_html( ! empty( $f['blockers'] ) && is_array( $f['blockers'] ) ? implode( ', ', $f['blockers'] ) : '' ) . '</code></td><td>' . esc_html( isset( $f['next_action'] ) ? $f['next_action'] : '' ) . '</td></tr>';
		}
		if ( empty( $groups ) ) echo '<tr><td colspan="12">' . esc_html__( 'No active functional coverage gaps are currently detected.', 'mad4b-site-control-plane' ) . '</td></tr>';
		echo '</tbody></table></div>';
	}

	private static function render_requests( array $items ) {
		echo '<h2>' . esc_html__( 'Adapter support requests', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="mad4b-scp-section-lead">' . esc_html__( 'These are deterministic evidence records only. They do not install plugins, generate PHP, create NHI/grants, approve tickets or enable mutation.', 'mad4b-site-control-plane' ) . '</p>';
		if ( ! $items ) { echo '<p>' . esc_html__( 'No adapter support requests are currently required.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th>Request</th><th>Plugin</th><th>Reason</th><th>Risk</th><th>Requested contracts</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$contracts = isset( $item['requested_contracts'] ) && is_array( $item['requested_contracts'] ) ? implode( ', ', $item['requested_contracts'] ) : '';
			echo '<tr><td><code>' . esc_html( isset( $item['support_request_id'] ) ? $item['support_request_id'] : '' ) . '</code></td><td>' . esc_html( isset( $item['plugin_name'] ) ? $item['plugin_name'] : '' ) . '</td><td>' . esc_html( isset( $item['reason_code'] ) ? $item['reason_code'] : '' ) . '</td><td>' . esc_html( isset( $item['risk'] ) ? $item['risk'] : '' ) . '</td><td>' . esc_html( $contracts ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
