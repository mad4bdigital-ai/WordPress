<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Guarded, read-only projection of reproducible Competitive Experience evidence.
 *
 * The generated resource is executable PHP with an ABSPATH guard so direct web
 * requests do not disclose competitor intelligence from the plugin webroot.
 */
final class MAD4B_SCP_Competitive_Evidence {
	const CONTRACT = 'mad4b.competitive-evidence-summary.v2';
	const FILE = 'config/competitive-evidence-summary.php';
	const HISTORY_FILE = 'config/competitive-evidence-history.php';
	const HISTORY_CONTRACT = 'mad4b.competitive-evidence-history.v2';
	const MAX_BYTES = 2097152;
	const MAX_HISTORY_ENTRIES = 64;
	const MAX_PACKAGES = 64;
	const MAX_CAPABILITIES = 1024;
	const MAX_SOURCES_PER_CAPABILITY = 64;

	public static function boot() {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 41 );
		}
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/competitive-evidence-status' ) ) return;

		wp_register_ability(
			'mad4b/competitive-evidence-status',
			array(
				'label' => 'Competitive Evidence Status',
				'description' => 'Read-only packaged comparison evidence with capability-local task, source, foundation and acceptance links.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'summary' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			)
		);
	}

	public static function summary( $input = array() ) {
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . self::FILE : '';
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			return new WP_Error( 'mad4b_competitive_evidence_missing', 'Competitive evidence summary is unavailable.' );
		}

		$size = filesize( $path );
		if ( false === $size || $size < 2 || $size > self::MAX_BYTES ) {
			return new WP_Error( 'mad4b_competitive_evidence_size_invalid', 'Competitive evidence summary exceeds the bounded package limit.' );
		}

		$data = include $path;
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'mad4b_competitive_evidence_invalid', 'Competitive evidence summary resource is invalid.' );
		}

		if ( self::CONTRACT !== ( isset( $data['contract'] ) ? (string) $data['contract'] : '' )
			|| ! array_key_exists( 'authorizing', $data )
			|| false !== $data['authorizing'] ) {
			return new WP_Error( 'mad4b_competitive_evidence_invalid', 'Competitive evidence summary contract is invalid.' );
		}

		$claimed = isset( $data['summary_sha256'] ) ? (string) $data['summary_sha256'] : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $claimed ) ) {
			return new WP_Error( 'mad4b_competitive_evidence_digest_missing', 'Competitive evidence summary digest is missing.' );
		}

		$digest_basis = $data;
		unset( $digest_basis['summary_sha256'] );
		$encoded = wp_json_encode( self::sort_value( $digest_basis ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$observed = is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
		if ( '' === $observed || ! hash_equals( $claimed, $observed ) ) {
			return new WP_Error( 'mad4b_competitive_evidence_digest_mismatch', 'Competitive evidence summary digest does not match its packaged contents.' );
		}

		$packages = isset( $data['packages'] ) && is_array( $data['packages'] ) ? $data['packages'] : array();
		$capabilities = isset( $data['capabilities'] ) && is_array( $data['capabilities'] ) ? $data['capabilities'] : array();
		if ( count( $packages ) > self::MAX_PACKAGES || count( $capabilities ) > self::MAX_CAPABILITIES ) {
			return new WP_Error( 'mad4b_competitive_evidence_inventory_unbounded', 'Competitive evidence inventory exceeds its bounded runtime limits.' );
		}
		if ( count( $packages ) !== ( isset( $data['package_count'] ) ? (int) $data['package_count'] : -1 )
			|| count( $capabilities ) !== ( isset( $data['capability_count'] ) ? (int) $data['capability_count'] : -1 ) ) {
			return new WP_Error( 'mad4b_competitive_evidence_inventory_mismatch', 'Competitive evidence package or capability counts do not match the packaged inventory.' );
		}

		foreach ( $packages as $package ) {
			if ( ! is_array( $package )
				|| empty( $package['id'] )
				|| empty( $package['version'] )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', isset( $package['sha256'] ) ? (string) $package['sha256'] : '' ) ) {
				return new WP_Error( 'mad4b_competitive_evidence_package_invalid', 'Competitive evidence package identity is invalid.' );
			}
		}

		foreach ( $capabilities as $row ) {
			if ( ! is_array( $row )
				|| empty( $row['id'] )
				|| empty( $row['task_ids'] )
				|| empty( $row['evidence_ids'] )
				|| empty( $row['evidence_sources'] ) ) {
				return new WP_Error( 'mad4b_competitive_evidence_capability_invalid', 'Competitive evidence capability row is incomplete.' );
			}
			$sources = is_array( $row['evidence_sources'] ) ? $row['evidence_sources'] : array();
			if ( count( $sources ) > self::MAX_SOURCES_PER_CAPABILITY ) {
				return new WP_Error( 'mad4b_competitive_evidence_sources_unbounded', 'Competitive evidence capability source list exceeds its bounded limit.' );
			}
			foreach ( $sources as $source ) {
				if ( ! is_array( $source ) || empty( $source['id'] ) || empty( $source['kind'] ) || empty( $source['path'] ) ) {
					return new WP_Error( 'mad4b_competitive_evidence_source_invalid', 'Competitive evidence source identity is invalid.' );
				}
				if ( ! array_key_exists( 'runtime_verified', $source ) || false !== $source['runtime_verified'] ) {
					return new WP_Error( 'mad4b_competitive_evidence_static_runtime_claim', 'Static competitive evidence cannot claim runtime verification.' );
				}
			}
		}

		$history = self::history_status();
		if ( is_wp_error( $history ) ) return $history;
		$data['history'] = $history;
		$data['integrity_verified'] = true;
		$data['direct_web_resource'] = false;
		$data['static_evidence_creates_runtime_certification'] = false;
		$data['authority_created'] = false;
		return $data;
	}

	public static function history_status() {
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . self::HISTORY_FILE : '';
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) return new WP_Error( 'mad4b_competitive_history_missing', 'Competitive evidence history is unavailable.' );
		$size = filesize( $path );
		if ( false === $size || $size < 2 || $size > self::MAX_BYTES ) return new WP_Error( 'mad4b_competitive_history_size_invalid', 'Competitive evidence history exceeds the bounded package limit.' );
		$data = include $path;
		if ( ! is_array( $data ) || self::HISTORY_CONTRACT !== ( isset( $data['contract'] ) ? (string) $data['contract'] : '' ) || false !== ( isset( $data['authorizing'] ) ? $data['authorizing'] : null ) ) return new WP_Error( 'mad4b_competitive_history_invalid', 'Competitive evidence history contract is invalid.' );
		$retention = isset( $data['retention'] ) && is_array( $data['retention'] ) ? $data['retention'] : array();
		if ( self::MAX_HISTORY_ENTRIES !== (int) ( isset( $retention['max_entries'] ) ? $retention['max_entries'] : 0 ) || 'append_only_fail_closed' !== ( isset( $retention['policy'] ) ? (string) $retention['policy'] : '' ) || ! array_key_exists( 'silent_pruning', $retention ) || false !== $retention['silent_pruning'] ) return new WP_Error( 'mad4b_competitive_history_retention_invalid', 'Competitive evidence history retention contract is invalid.' );
		$entries = isset( $data['entries'] ) && is_array( $data['entries'] ) ? $data['entries'] : array();
		$alerts = isset( $data['alerts'] ) && is_array( $data['alerts'] ) ? $data['alerts'] : array();
		$acks = isset( $data['acknowledgements'] ) && is_array( $data['acknowledgements'] ) ? $data['acknowledgements'] : array();
		if ( empty( $entries ) || count( $entries ) > self::MAX_HISTORY_ENTRIES || count( $alerts ) > self::MAX_HISTORY_ENTRIES || count( $acks ) > self::MAX_HISTORY_ENTRIES ) return new WP_Error( 'mad4b_competitive_history_unbounded', 'Competitive evidence history or journal exceeds its bounded limit.' );
		$previous_generation = ''; $previous_entry = '';
		foreach ( $entries as $index => $row ) {
			if ( ! is_array( $row ) || (int) ( isset( $row['revision'] ) ? $row['revision'] : 0 ) !== $index + 1 ) return new WP_Error( 'mad4b_competitive_history_revision_invalid', 'Competitive evidence history revision is invalid.' );
			$generation = isset( $row['generation_sha256'] ) ? (string) $row['generation_sha256'] : '';
			$claimed = isset( $row['entry_sha256'] ) ? (string) $row['entry_sha256'] : '';
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $generation ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $claimed ) ) return new WP_Error( 'mad4b_competitive_history_digest_invalid', 'Competitive evidence history digest is invalid.' );
			if ( ! hash_equals( $previous_generation, (string) ( isset( $row['previous_generation_sha256'] ) ? $row['previous_generation_sha256'] : '' ) ) || ! hash_equals( $previous_entry, (string) ( isset( $row['previous_entry_sha256'] ) ? $row['previous_entry_sha256'] : '' ) ) ) return new WP_Error( 'mad4b_competitive_history_chain_invalid', 'Competitive evidence history chain is invalid.' );
			$basis = $row; unset( $basis['entry_sha256'] );
			$encoded = wp_json_encode( self::sort_value( $basis ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$observed = is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
			if ( '' === $observed || ! hash_equals( $claimed, $observed ) ) return new WP_Error( 'mad4b_competitive_history_entry_tampered', 'Competitive evidence history entry integrity failed.' );
			$previous_generation = $generation; $previous_entry = $claimed;
		}
		if ( ! hash_equals( $previous_generation, (string) ( isset( $data['current_generation_sha256'] ) ? $data['current_generation_sha256'] : '' ) ) || ! hash_equals( $previous_entry, (string) ( isset( $data['current_entry_sha256'] ) ? $data['current_entry_sha256'] : '' ) ) ) return new WP_Error( 'mad4b_competitive_history_current_invalid', 'Competitive evidence current history pointer is invalid.' );
		$open = 0; foreach ( $alerts as $alert ) if ( is_array( $alert ) && 'open' === ( isset( $alert['state'] ) ? (string) $alert['state'] : '' ) ) $open++;
		return array(
			'contract'=>self::HISTORY_CONTRACT,
			'revision'=>count( $entries ),
			'current_generation_sha256'=>$previous_generation,
			'previous_known_good_generation_sha256'=>isset( $data['previous_known_good_generation_sha256'] ) ? (string) $data['previous_known_good_generation_sha256'] : '',
			'open_alert_count'=>$open,
			'acknowledgement_count'=>count( $acks ),
			'retention_max_entries'=>self::MAX_HISTORY_ENTRIES,
			'integrity_verified'=>true,
			'direct_web_resource'=>false,
			'authorizing'=>false,
		);
	}

	public static function render_operator_view() {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) return;

		$data = self::summary();
		if ( is_wp_error( $data ) ) {
			echo '<h2>' . esc_html__( 'Competitive evidence', 'mad4b-site-control-plane' ) . '</h2>';
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Competitive evidence is unavailable because its packaged integrity could not be verified.', 'mad4b-site-control-plane' ) . '</p></div>';
			return;
		}

		$status_filter = isset( $_GET['mad4b_ce_status'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_ce_status'] ) ) : '';
		$class_filter = isset( $_GET['mad4b_ce_class'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_ce_class'] ) ) : '';
		$workstream_filter = isset( $_GET['mad4b_ce_workstream'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_ce_workstream'] ) ) : '';
		$parity_filter = isset( $_GET['mad4b_ce_parity'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_ce_parity'] ) ) : '';
		$query = isset( $_GET['mad4b_ce_q'] ) ? sanitize_text_field( wp_unslash( $_GET['mad4b_ce_q'] ) ) : '';
		$query_lc = strtolower( $query );

		echo '<h2>' . esc_html__( 'Competitive evidence', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Reproducible archive and capability evidence. Static or marketed evidence never proves runtime parity or creates access.', 'mad4b-site-control-plane' ) . '</p>';

		$cards = array(
			array(
				'label' => __( 'Reference packages', 'mad4b-site-control-plane' ),
				'value' => (string) ( isset( $data['package_count'] ) ? (int) $data['package_count'] : 0 ),
				'help' => __( 'Immutable uploaded archive identities.', 'mad4b-site-control-plane' ),
				'state' => 'complete',
			),
			array(
				'label' => __( 'Mapped capabilities', 'mad4b-site-control-plane' ),
				'value' => (string) ( isset( $data['capability_count'] ) ? (int) $data['capability_count'] : 0 ),
				'help' => __( 'Each row links evidence, MAD4B foundation, task ownership and acceptance.', 'mad4b-site-control-plane' ),
				'state' => 'complete',
			),
			array(
				'label' => __( 'Evidence generation', 'mad4b-site-control-plane' ),
				'value' => substr( (string) ( isset( $data['snapshot_generation_sha256'] ) ? $data['snapshot_generation_sha256'] : '' ), 0, 12 ),
				'help' => __( 'Verified packaged snapshot fingerprint; not runtime certification.', 'mad4b-site-control-plane' ),
				'state' => 'pending',
			),
		);
		$history = isset( $data['history'] ) && is_array( $data['history'] ) ? $data['history'] : array();
		$cards[] = array(
			'label' => __( 'Evidence history', 'mad4b-site-control-plane' ),
			'value' => (string) ( isset( $history['revision'] ) ? (int) $history['revision'] : 0 ),
			'help' => __( 'Append-only repository evidence generations with fail-closed retention.', 'mad4b-site-control-plane' ),
			'state' => ! empty( $history['open_alert_count'] ) ? 'warning' : 'complete',
		);
		if ( class_exists( 'MAD4B_SCP_Admin_Experience' ) ) MAD4B_SCP_Admin_Experience::cards( $cards );
		echo '<details class="mad4b-evidence-details"><summary>' . esc_html__( 'Evidence history context', 'mad4b-site-control-plane' ) . '</summary><p><strong>' . esc_html__( 'Current generation:', 'mad4b-site-control-plane' ) . '</strong> <code><bdi>' . esc_html( (string) ( isset( $history['current_generation_sha256'] ) ? $history['current_generation_sha256'] : '' ) ) . '</bdi></code></p><p><strong>' . esc_html__( 'Previous known good:', 'mad4b-site-control-plane' ) . '</strong> <code><bdi>' . esc_html( (string) ( isset( $history['previous_known_good_generation_sha256'] ) ? $history['previous_known_good_generation_sha256'] : '' ) ) . '</bdi></code></p><p><strong>' . esc_html__( 'Open drift alerts:', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html( (string) ( isset( $history['open_alert_count'] ) ? (int) $history['open_alert_count'] : 0 ) ) . '</p></details>';

		echo '<form method="get" class="mad4b-workspace-filter" aria-label="' . esc_attr__( 'Competitive evidence filters', 'mad4b-site-control-plane' ) . '">';
		foreach ( $_GET as $key => $value ) {
			if ( in_array( $key, array( 'mad4b_ce_status', 'mad4b_ce_class', 'mad4b_ce_workstream', 'mad4b_ce_parity', 'mad4b_ce_q' ), true ) || is_array( $value ) ) continue;
			echo '<input type="hidden" name="' . esc_attr( sanitize_key( $key ) ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( $value ) ) ) . '">';
		}
		echo '<label>' . esc_html__( 'Search', 'mad4b-site-control-plane' ) . ' <input type="search" name="mad4b_ce_q" value="' . esc_attr( $query ) . '"></label> ';
		echo '<label>' . esc_html__( 'Status', 'mad4b-site-control-plane' ) . ' <select name="mad4b_ce_status">';
		foreach ( array( '' => __( 'All', 'mad4b-site-control-plane' ), 'open' => 'OPEN', 'partial' => 'PARTIAL' ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $status_filter, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Evidence class', 'mad4b-site-control-plane' ) . ' <select name="mad4b_ce_class">';
		$classes = array( '' => __( 'All', 'mad4b-site-control-plane' ) );
		foreach ( $data['capabilities'] as $row ) {
			foreach ( isset( $row['evidence_classes'] ) && is_array( $row['evidence_classes'] ) ? $row['evidence_classes'] : array() as $class ) {
				$classes[ sanitize_key( (string) $class ) ] = (string) $class;
			}
		}
		ksort( $classes, SORT_STRING );
		foreach ( $classes as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $class_filter, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Workstream', 'mad4b-site-control-plane' ) . ' <select name="mad4b_ce_workstream">';
		$workstreams = array( '' => __( 'All', 'mad4b-site-control-plane' ) );
		foreach ( $data['capabilities'] as $row ) if ( ! empty( $row['workstream_id'] ) ) $workstreams[ sanitize_key( (string) $row['workstream_id'] ) ] = (string) $row['workstream_id'];
		ksort( $workstreams, SORT_STRING );
		foreach ( $workstreams as $value => $label ) echo '<option value="' . esc_attr( $value ) . '"' . selected( $workstream_filter, $value, false ) . '>' . esc_html( $label ) . '</option>';
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Runtime parity', 'mad4b-site-control-plane' ) . ' <select name="mad4b_ce_parity">';
		foreach ( array( '' => __( 'All', 'mad4b-site-control-plane' ), 'claimed' => __( 'Claimed', 'mad4b-site-control-plane' ), 'not_claimed' => __( 'Not claimed', 'mad4b-site-control-plane' ) ) as $value => $label ) echo '<option value="' . esc_attr( $value ) . '"' . selected( $parity_filter, $value, false ) . '>' . esc_html( $label ) . '</option>';
		echo '</select></label> ';
		submit_button( __( 'Filter', 'mad4b-site-control-plane' ), 'secondary', '', false );
		echo '</form>';

		$rows = array();
		foreach ( isset( $data['capabilities'] ) && is_array( $data['capabilities'] ) ? $data['capabilities'] : array() as $row ) {
			if ( ! is_array( $row ) ) continue;
			$status = sanitize_key( isset( $row['status'] ) ? (string) $row['status'] : '' );
			$row_classes = array_map( 'sanitize_key', isset( $row['evidence_classes'] ) && is_array( $row['evidence_classes'] ) ? $row['evidence_classes'] : array() );
			$haystack = strtolower( implode( ' ', array(
				isset( $row['id'] ) ? (string) $row['id'] : '',
				isset( $row['title'] ) ? (string) $row['title'] : '',
				implode( ' ', isset( $row['task_ids'] ) && is_array( $row['task_ids'] ) ? $row['task_ids'] : array() ),
				implode( ' ', isset( $row['evidence_ids'] ) && is_array( $row['evidence_ids'] ) ? $row['evidence_ids'] : array() ),
			) ) );
			if ( '' !== $status_filter && $status_filter !== $status ) continue;
			if ( '' !== $class_filter && ! in_array( $class_filter, $row_classes, true ) ) continue;
			if ( '' !== $workstream_filter && $workstream_filter !== sanitize_key( isset( $row['workstream_id'] ) ? (string) $row['workstream_id'] : '' ) ) continue;
			if ( 'claimed' === $parity_filter && empty( $row['runtime_parity_claimed'] ) ) continue;
			if ( 'not_claimed' === $parity_filter && ! empty( $row['runtime_parity_claimed'] ) ) continue;
			if ( '' !== $query_lc && false === strpos( $haystack, $query_lc ) ) continue;
			$rows[] = $row;
		}

		echo '<p><strong>' . esc_html( sprintf( __( '%1$d of %2$d capabilities shown', 'mad4b-site-control-plane' ), count( $rows ), (int) $data['capability_count'] ) ) . '</strong></p>';
		echo '<div class="mad4b-workspace-table" tabindex="0" role="region" aria-label="' . esc_attr__( 'Competitive capability evidence', 'mad4b-site-control-plane' ) . '">';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Capability', 'mad4b-site-control-plane' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'mad4b-site-control-plane' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Evidence', 'mad4b-site-control-plane' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Runtime boundary', 'mad4b-site-control-plane' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( array_slice( $rows, 0, self::MAX_CAPABILITIES ) as $row ) {
			$classes_text = isset( $row['evidence_classes'] ) && is_array( $row['evidence_classes'] ) ? implode( ', ', array_map( 'strval', $row['evidence_classes'] ) ) : '';
			$evidence_ids = isset( $row['evidence_ids'] ) && is_array( $row['evidence_ids'] ) ? implode( ', ', array_map( 'strval', array_slice( $row['evidence_ids'], 0, 32 ) ) ) : '';
			$tasks = isset( $row['task_ids'] ) && is_array( $row['task_ids'] ) ? implode( ', ', array_map( 'strval', $row['task_ids'] ) ) : '';
			$runtime_parity = ! empty( $row['runtime_parity_claimed'] ) ? __( 'Claimed; validate against runtime evidence separately.', 'mad4b-site-control-plane' ) : __( 'Not claimed', 'mad4b-site-control-plane' );

			echo '<tr>';
			echo '<th scope="row"><bdi>' . esc_html( (string) ( isset( $row['id'] ) ? $row['id'] : '' ) ) . '</bdi> — ' . esc_html( (string) ( isset( $row['title'] ) ? $row['title'] : '' ) ) . '<br><small><code>' . esc_html( (string) ( isset( $row['workstream_id'] ) ? $row['workstream_id'] : '' ) ) . '</code> · ' . esc_html( (string) ( isset( $row['family'] ) ? $row['family'] : '' ) ) . '</small></th>';
			echo '<td>' . esc_html( (string) ( isset( $row['status'] ) ? $row['status'] : 'OPEN' ) ) . '<br><small>' . esc_html( (string) ( isset( $row['baseline_assessment'] ) ? $row['baseline_assessment'] : '' ) ) . '</small></td>';
			$open_tasks = isset( $row['open_task_ids'] ) && is_array( $row['open_task_ids'] ) ? implode( ', ', $row['open_task_ids'] ) : '';
			$partial_tasks = isset( $row['partial_task_ids'] ) && is_array( $row['partial_task_ids'] ) ? implode( ', ', $row['partial_task_ids'] ) : '';
			$acceptance = isset( $row['acceptance_requirements'] ) && is_array( $row['acceptance_requirements'] ) ? $row['acceptance_requirements'] : array();
			echo '<td><details><summary>' . esc_html( $classes_text ) . '</summary><p><code><bdi>' . esc_html( $evidence_ids ) . '</bdi></code></p><p><strong>' . esc_html__( 'Tasks:', 'mad4b-site-control-plane' ) . '</strong> <code><bdi>' . esc_html( $tasks ) . '</bdi></code></p><p><strong>' . esc_html__( 'Why not done:', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html( trim( ( $open_tasks ? 'OPEN: ' . $open_tasks . '. ' : '' ) . ( $partial_tasks ? 'PARTIAL: ' . $partial_tasks . '.' : '' ) ) ) . '</p><p><strong>' . esc_html__( 'Acceptance still required:', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html( implode( ' ', array_map( 'strval', array_slice( $acceptance, 0, 8 ) ) ) ) . '</p><p><strong>' . esc_html__( 'Foundation:', 'mad4b-site-control-plane' ) . '</strong> <code><bdi>' . esc_html( implode( ', ', array_map( 'strval', isset( $row['mad4b_foundation_paths'] ) && is_array( $row['mad4b_foundation_paths'] ) ? array_slice( $row['mad4b_foundation_paths'], 0, 16 ) : array() ) ) ) . '</bdi></code></p></details></td>';
			echo '<td>' . esc_html( $runtime_parity ) . '<br><small>' . esc_html( implode( ' ', array_map( 'strval', isset( $row['acceptance_requirements'] ) && is_array( $row['acceptance_requirements'] ) ? array_slice( $row['acceptance_requirements'], 0, 8 ) : array() ) ) ) . '</small></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$keys = array_keys( $value );
		$is_list = $keys === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}

MAD4B_SCP_Competitive_Evidence::boot();
