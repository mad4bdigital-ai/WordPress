<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Stable IDs/order and generic primitives; provider names never select UI code. */
final class MAD4B_SCP_Search_Experience {
	const PAGE_SLUG = 'mad4b-search-intelligence';
	const MENU_PRIORITY = 20;
	public static function model( $profile, array $providers, array $jobs, array $views, $inventory_ready = true ) {
		$state = $profile ? ( ! empty( $profile['enabled'] ) ? 'READY' : 'PROFILE_DRAFTED' ) : 'UNCONFIGURED';
		$blockers = array(); $healthy = 0; $budgeted = 0; $fresh = 0;
		foreach ( $providers as $d ) {
			$allowed = ! isset( $d['profile_allowed'] ) || $d['profile_allowed'];
			if ( $allowed && ! empty( $d['certified'] ) && ! empty( $d['active'] ) && ( ! isset( $d['health']['breaker_state'] ) || 'closed' === $d['health']['breaker_state'] ) ) ++$healthy;
			if ( $allowed && ! empty( $d['usage']['remaining'] ) && isset( $d['usage']['reset_at'] ) && $d['usage']['reset_at'] > time() && ( ! isset( $d['budget_admissible'] ) || $d['budget_admissible'] ) ) ++$budgeted;
		}
		foreach ( $views as $view ) if ( ! empty( $view['captured_at'] ) && $view['captured_at'] > time() - ( $profile ? $profile['refresh_policy']['max_seconds'] : 86400 ) ) ++$fresh;
		if ( $profile && $profile['enabled'] ) {
			$state = $views ? ( $fresh ? 'ACTIVE' : 'EVIDENCE_STALE' ) : ( $jobs ? 'BASELINING' : 'READY' );
			if ( ! $inventory_ready && ! $jobs && ! $views ) $state = 'DISCOVERING';
			if ( ! $healthy ) { $state = 'DEGRADED_PROVIDER'; $blockers[] = 'No currently certified active provider; existing evidence remains available within its retention policy.'; }
			elseif ( ! $budgeted || ! empty( $profile['provider_policy']['freeze_spend'] ) ) { $state = 'DEGRADED_BUDGET'; $blockers[] = 'Provider allowance is exhausted, unknown or spend is frozen.'; }
			foreach ( $jobs as $job ) {
				if ( ! empty( $job['plan']['profile_id'] ) && $job['plan']['profile_id'] !== $profile['profile_id'] ) continue;
				if ( isset( $job['plan']['profile_revision'] ) && $job['plan']['profile_revision'] !== $profile['revision'] && 'COMPLETE' !== $job['state'] ) { $state = 'PROFILE_DRIFT'; $blockers[] = 'An outstanding job references a previous profile revision.'; }
				if ( in_array( $job['state'], array( 'RECONCILIATION_REQUIRED', 'PROVIDER_ENTERED' ), true ) ) { $state = 'RECONCILIATION_REQUIRED'; $blockers[] = 'An external effect needs reconciliation before another request.'; }
			}
		}
		$p = MAD4B_SCP_Search_Context::policy(); $sections = array();
		$available = array( 'overview' => true, 'diagnostics' => true, 'markets' => $profile && $profile['markets'], 'languages' => $profile && $profile['language_policy']['desired'], 'surfaces' => (bool) $profile, 'targets' => (bool) $profile, 'serp' => (bool) $views, 'competitors' => (bool) $views, 'archives' => $profile && ! empty( $profile['surface_policy'] ), 'experiments' => $profile && ! empty( $profile['experiment_policy'] ), 'budgets' => (bool) $profile, 'providers' => (bool) $providers );
		foreach ( $p['section_order'] as $order => $id ) if ( ! empty( $available[ $id ] ) ) $sections[] = array( 'id' => $id, 'order' => $order, 'label' => ucfirst( $id ), 'primitive' => in_array( $id, array( 'overview', 'diagnostics' ), true ) ? 'STATUS_CARD' : 'TABLE', 'url' => admin_url( 'admin.php?page=mad4b-search-intelligence&section=' . rawurlencode( $id ) ) );
		return array( 'contract' => 'mad4b.search-experience-model.v1', 'state' => $state, 'headline' => str_replace( '_', ' ', $state ), 'metrics' => array( array( 'id' => 'fresh_observations', 'value' => $fresh, 'scope' => 'bounded_current_page' ), array( 'id' => 'provider_count', 'value' => count( $providers ) ), array( 'id' => 'pending_jobs', 'value' => count( $jobs ), 'scope' => 'bounded_current_page' ) ), 'blockers' => array_values( array_unique( $blockers ) ), 'opportunities' => array(), 'recommended_actions' => array( $profile ? 'Review the observation cohort and its exact plan before capture.' : 'Create a search profile; markets and desired languages are independent of Site Profile.' ), 'sections' => $sections, 'reason_chain' => array( 'profile state', 'certified capabilities', 'known allowance', 'evidence freshness', 'outstanding job reconciliation' ), 'historical_intelligence_usable' => (bool) $views, 'authorizing' => false );
	}
	public static function boot() {
		MAD4B_SCP_Search_Profile_Admin::boot();
		// Register after the MAD4B parent menu (default priority 10). Registering
		// this submenu first can make WordPress derive a different plugin-page hook
		// before the parent exists, causing admin.php?page=... to fail with the
		// generic core "not allowed" screen even for an authorized administrator.
		MAD4B_SCP_Admin_Route_Registry::schedule_submenu( array( __CLASS__, 'menu' ), self::MENU_PRIORITY );
		add_action( 'admin_post_mad4b_search_control', array( __CLASS__, 'control_post' ) );
	}
	public static function menu() { add_submenu_page( 'mad4b-control-plane', 'Search Intelligence', 'Search Intelligence', 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render' ) ); }
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) || ! MAD4B_SCP_Policy::can_read() ) return;
		$id = MAD4B_SCP_Search_Profile_Admin::selected_id();
		$model = MAD4B_SCP_Search_Runtime::status( array( 'profile_id' => $id ) );
		echo '<div class="wrap"><h1>Search Intelligence</h1>';
		MAD4B_SCP_Search_Profile_Admin::render( $id );
		if ( is_wp_error( $model ) ) { echo '<p>' . esc_html( $model->get_error_message() ) . '</p></div>'; return; }
		echo '<h2>' . esc_html( $model['headline'] ) . '</h2><nav aria-label="Search Intelligence sections">';
		foreach ( $model['sections'] as $s ) echo '<a style="margin-right:16px" href="' . esc_url( $s['url'] . '&profile_id=' . rawurlencode( $id ) ) . '">' . esc_html( $s['label'] ) . '</a>';
		echo '</nav>';
		foreach ( $model['blockers'] as $blocker ) echo '<div class="notice notice-warning"><p>' . esc_html( $blocker ) . '</p></div>';
		echo '<table class="widefat"><caption>Current search state</caption><thead><tr><th scope="col">Metric</th><th scope="col">Value</th></tr></thead><tbody>';
		foreach ( $model['metrics'] as $m ) echo '<tr><th scope="row">' . esc_html( str_replace( '_', ' ', $m['id'] ) ) . '</th><td>' . esc_html( (string) $m['value'] ) . '</td></tr>';
		echo '</tbody></table>';
		foreach ( $model['recommended_actions'] as $a ) echo '<p>' . esc_html( $a ) . '</p>';
		$section = sanitize_key( MAD4B_SCP_Admin_Experience::query_string( 'section', 'overview', 96 ) );
		if ( ! in_array( $section, MAD4B_SCP_Search_Context::policy()['section_order'], true ) ) $section = 'overview';
		// Connection setup is useful before the first profile exists.
		if ( 'providers' === $section || ! $id ) MAD4B_SCP_Search_Provider_Connections::render( $id );
		if ( $id ) {
			$rows = self::section_rows( $id, $section );
			echo '<h2 id="search-' . esc_attr( $section ) . '">' . esc_html( ucfirst( $section ) ) . '</h2><table class="widefat striped"><thead><tr><th scope="col">Item</th><th scope="col">State or evidence</th></tr></thead><tbody>';
			foreach ( $rows as $row ) echo '<tr><th scope="row">' . esc_html( $row['label'] ) . '</th><td>' . esc_html( $row['value'] ) . '</td></tr>';
			echo '</tbody></table>';
		}
		if ( $id && MAD4B_SCP_Search_Runtime::can_configure() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mad4b_search_control"><input type="hidden" name="profile_id" value="' . esc_attr( $id ) . '">';
			wp_nonce_field( 'mad4b_search_control' );
			echo '<label for="mad4b-search-control">Operator control</label> <select id="mad4b-search-control" name="control">';
			foreach ( array( 'pause' => 'Pause observations', 'resume' => 'Resume observations', 'freeze_spend' => 'Freeze spend', 'unfreeze_spend' => 'Unfreeze spend', 'disable_provider' => 'Disable provider', 'enable_provider' => 'Enable provider', 'pin' => 'Pin target', 'unpin' => 'Unpin target', 'mute' => 'Mute target', 'unmute' => 'Unmute target', 'refresh' => 'Request refresh' ) as $value => $label ) echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
			echo '</select> <label>Provider ID <input name="provider_id" maxlength="96"></label> <label>Target ID <input name="target_id" maxlength="64"></label> ';
			submit_button( 'Apply control', 'secondary', 'submit', false ); echo '</form>';
		}
		echo '</div>';
	}
	public static function control( array $input ) {
		if ( ! MAD4B_SCP_Search_Runtime::can_configure() ) return MAD4B_SCP_Search_Contracts::error( 'configuration_unauthorized' );
		$id = isset( $input['profile_id'] ) ? $input['profile_id'] : ''; $control = isset( $input['control'] ) ? $input['control'] : '';
		$p = MAD4B_SCP_Search_Context::profile( $id ); if ( is_wp_error( $p ) ) return $p;
		if ( in_array( $control, array( 'pause', 'resume', 'freeze_spend', 'unfreeze_spend', 'disable_provider', 'enable_provider' ), true ) ) {
			$policy = MAD4B_SCP_Search_Context::policy(); $raw = array_intersect_key( $p, array_flip( $policy['profile_fields'] ) );
			if ( 'pause' === $control ) $raw['enabled'] = false;
			if ( 'resume' === $control ) $raw['enabled'] = true;
			if ( 'freeze_spend' === $control || 'unfreeze_spend' === $control ) $raw['provider_policy']['freeze_spend'] = 'freeze_spend' === $control;
			if ( 'disable_provider' === $control ) { if ( empty( $input['provider_id'] ) || ! MAD4B_SCP_Search_Contracts::id( $input['provider_id'] ) ) return MAD4B_SCP_Search_Contracts::error( 'provider_id_invalid' ); $raw['provider_policy']['disabled'][] = $input['provider_id']; }
			if ( 'enable_provider' === $control ) $raw['provider_policy']['disabled'] = array_values( array_diff( $raw['provider_policy']['disabled'], array( isset( $input['provider_id'] ) ? $input['provider_id'] : '' ) ) );
			$args = array( 'profile' => $raw, 'expected_revision' => $p['revision'] ); $plan = MAD4B_SCP_Search_Context::plan( $args );
			return is_wp_error( $plan ) ? $plan : MAD4B_SCP_Search_Context::apply( array_merge( $args, array( 'plan_sha256' => $plan['plan_sha256'] ) ) );
		}
		if ( in_array( $control, array( 'pin', 'unpin', 'mute', 'unmute', 'refresh' ), true ) && ! empty( $input['target_id'] ) ) {
			$row = MAD4B_SCP_Search_Store::read( 'target', $input['target_id'] );
			if ( ! is_array( $row ) || $row['target']['profile_id'] !== $id ) return MAD4B_SCP_Search_Contracts::error( 'target_profile_mismatch' );
			$next = $row; if ( 'pin' === $control || 'unpin' === $control ) $next['pinned'] = 'pin' === $control; if ( 'mute' === $control || 'unmute' === $control ) $next['muted'] = 'mute' === $control; if ( 'refresh' === $control ) $next['refresh_requested_at'] = time();
			return MAD4B_SCP_Search_Store::cas( 'target', $input['target_id'], $row, $next, 'OPERATOR_' . strtoupper( $control ) );
		}
		return MAD4B_SCP_Search_Contracts::error( 'control_invalid' );
	}
	public static function section_rows( $profile_id, $section ) {
		$p = MAD4B_SCP_Search_Context::profile( $profile_id ); if ( is_wp_error( $p ) ) return array();
		$out = array();
		if ( 'markets' === $section ) foreach ( $p['markets'] as $m ) $out[] = array( 'label' => $m['id'], 'value' => $m['country'] );
		elseif ( 'languages' === $section ) foreach ( MAD4B_SCP_Search_Surfaces::languages() as $id => $l ) $out[] = array( 'label' => $id, 'value' => $l['conflicting'] ? 'Requires reconciliation' : ( $l['active'] ? 'Live; owned surfaces: ' . $l['owned_count'] : 'Inactive' ) );
		elseif ( 'providers' === $section || 'budgets' === $section ) foreach ( MAD4B_SCP_Search_Providers::adapters() as $id => $a ) {
			$d = $a->descriptor(); $b = MAD4B_SCP_Search_Budgets::status( isset( $d['account_id'] ) ? $d['account_id'] : '' );
			$out[] = array( 'label' => $id, 'value' => 'budgets' === $section ? ( is_array( $b ) ? $b['authority_scope'] . '; remaining: ' . $b['remaining'] : 'Allowance requires observed usage evidence' ) : ( empty( $d['certified'] ) ? 'Certification required' : ( empty( $d['active'] ) ? 'Inactive' : 'Certified generation: ' . $d['certification_generation'] ) ) );
		} elseif ( 'targets' === $section ) {
			$cohort = MAD4B_SCP_Search_Runtime::cohort( array( 'profile_id' => $profile_id, 'limit' => 20 ) );
			if ( ! is_wp_error( $cohort ) ) { foreach ( $cohort['selected'] as $r ) $out[] = array( 'label' => $r['target']['normalized_query'], 'value' => 'Priority: ' . $r['decision']['effective_priority'] . '; refresh: ' . $r['decision']['refresh_seconds'] . 's; market: ' . $r['target']['market'] . '; language: ' . $r['target']['language'] ); foreach ( $cohort['excluded'] as $r ) $out[] = array( 'label' => $r['target_id'], 'value' => $r['reason'] ); }
		} else {
			$kinds = array( 'surfaces' => 'surface', 'archives' => 'surface', 'serp' => 'current', 'competitors' => 'signal', 'experiments' => 'experiment', 'diagnostics' => 'job', 'overview' => 'current' );
			$rows = MAD4B_SCP_Search_Store::list_rows( isset( $kinds[ $section ] ) ? $kinds[ $section ] : 'current', '', 50 );
			if ( is_array( $rows ) ) foreach ( $rows['items'] as $r ) {
				if ( isset( $r['surface'] ) ) { if ( 'archives' === $section && false === strpos( $r['surface']['surface_type'], 'ARCHIVE' ) ) continue; $out[] = array( 'label' => $r['surface']['public_url'], 'value' => $r['surface']['surface_type'] . '; ' . $r['surface']['language'] . '; ' . $r['surface']['eligibility']['effective'] ); }
				elseif ( isset( $r['state'], $r['plan']['profile_id'] ) && $r['plan']['profile_id'] === $profile_id ) $out[] = array( 'label' => $r['job_id'], 'value' => $r['state'] );
				elseif ( isset( $r['snapshot_id'], $r['profile_id'] ) && $r['profile_id'] === $profile_id && ! is_wp_error( MAD4B_SCP_Search_Store::evidence( 'snapshot', $r['snapshot_id'] ) ) ) $out[] = array( 'label' => $r['snapshot_id'], 'value' => gmdate( 'c', $r['captured_at'] ) );
				elseif ( isset( $r['payload'] ) && ( ! isset( $r['payload']['valid_until'] ) || $r['payload']['valid_until'] > time() ) ) { $v = $r['payload']; if ( 'signal' === ( isset( $kinds[ $section ] ) ? $kinds[ $section ] : '' ) && false === strpos( $v['family'], 'competitor' ) ) continue; $out[] = array( 'label' => isset( $v['family'] ) ? $v['family'] : $v['experiment_id'], 'value' => isset( $v['confidence'] ) ? 'Confidence: ' . $v['confidence'] : 'Observation windows bound to verified content/SEO change' ); }
			}
		}
		return $out ? $out : array( array( 'label' => 'Observation state', 'value' => 'No matching evidence on this bounded page.' ) );
	}
	public static function control_post() {
		check_admin_referer( 'mad4b_search_control' );
		$args = array();
		foreach ( array( 'profile_id', 'control', 'provider_id', 'target_id' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && ( ! is_string( $_POST[ $key ] ) || strlen( $_POST[ $key ] ) > 96 ) ) wp_die( 'Invalid control input.', '', array( 'response' => 422 ) );
			$args[ $key ] = MAD4B_SCP_Admin_Experience::request_string( $_POST, $key, '', 96 );
		}
		$result = self::control( $args );
		if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ) );
		wp_safe_redirect( admin_url( 'admin.php?page=mad4b-search-intelligence&profile_id=' . rawurlencode( $args['profile_id'] ) ) ); exit;
	}
}

// Routes are declared without booting menus or provider lifecycle on frontend requests.
if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry', false ) ) MAD4B_SCP_Admin_Route_Registry::register( MAD4B_SCP_Search_Experience::PAGE_SLUG, 'manage_options' );
