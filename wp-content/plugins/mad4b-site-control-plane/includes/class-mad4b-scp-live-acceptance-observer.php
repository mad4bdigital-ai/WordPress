<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Passive, read-only Live Acceptance evidence for the MAD4B Control Plane.
 *
 * The early bootstrap only wires observers. It never materializes the Abilities
 * registry, grants authority, exposes a server, initializes a provider, or
 * performs an outbound request. Cross-request persistence is bounded to the
 * exact Egypt Tour Gates Staging origin and stores sanitized summaries only.
 */
final class MAD4B_SCP_Live_Acceptance_Observer {
	const CONTRACT = 'mad4b.live-acceptance-observer.v1';
	const QUERY_MONITOR_CONTRACT = 'mad4b.query-monitor-regression.v1';
	const PROVENANCE_CONTRACT = 'mad4b.build-provenance.v1';
	const EXTERNAL_ATTESTATION_CONTRACT = 'mad4b.external-handshake-attestation.v1';
	const WPML_RECEIPT_CONTRACT = 'mad4b.external-wpml-receipt.v1';
	const SNAPSHOT_VERIFY_CONTRACT = 'mad4b.snapshot-verify.v1';
	const AGGREGATE_CONTRACT = 'mad4b.live-acceptance-status.v1';
	const TELEMETRY_OPTION = 'mad4b_scp_live_acceptance_observation_v1';
	const EXTERNAL_OPTION = 'mad4b_scp_external_inventory_attestation_v1';
	const WPML_OPTION = 'mad4b_scp_external_wpml_receipt_v1';
	const PENDING_PREFIX = 'mad4b_lae_hs_';
	const STAGING_HOST = 'staging.egypttourgates.com';
	const MAX_EVENTS = 32;
	const TELEMETRY_TTL = 21600; // Six hours.
	const EXTERNAL_TTL = 2592000; // 30 days.
	const PENDING_TTL = 900;
	const CHATGPT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
	const SERVER_ID = 'mad4b-chatgpt';
	const REQUIRED_SCOPE = 'mad4b:read';

	private static $booted = false;
	private static $telemetry = null;
	private static $telemetry_dirty = false;
	private static $request_marked = false;

	public static function boot_early() {
		if ( self::$booted ) return;
		self::$booted = true;

		// Observation only. None of these callbacks initializes the Ability API.
		add_action( 'doing_it_wrong_run', array( __CLASS__, 'observe_doing_it_wrong' ), PHP_INT_MAX, 3 );
		add_action( 'deprecated_function_run', array( __CLASS__, 'observe_deprecated_function' ), PHP_INT_MAX, 3 );
		add_action( 'deprecated_argument_run', array( __CLASS__, 'observe_deprecated_argument' ), PHP_INT_MAX, 3 );
		add_action( 'deprecated_hook_run', array( __CLASS__, 'observe_deprecated_hook' ), PHP_INT_MAX, 4 );
		add_action( 'deprecated_class_run', array( __CLASS__, 'observe_deprecated_class' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'observe_rest_response' ), PHP_INT_MAX - 20, 3 );
		add_action( 'shutdown', array( __CLASS__, 'flush_observation' ), PHP_INT_MAX );

		// Registration hooks are armed early but execute only at the canonical API lifecycle.
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 38 );
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_read_adapter' ), 20 );
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'augment_write_runtime_ability' ), 120, 2 );

		self::mark_current_request();
	}

	public static function register_read_adapter( $registry ) {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) || ! class_exists( 'MAD4B_SCP_Adapter_Base' ) ) return;
		$adapter = new class extends MAD4B_SCP_Adapter_Base {
			public function id() { return 'live-acceptance'; }
			public function label() { return 'Live Acceptance Evidence'; }
			public function is_available() { return true; }
			public function ability_names() {
				return array(
					'read' => array(
						'mad4b/query-monitor-regression-status',
						'mad4b/build-provenance-status',
						'mad4b/external-handshake-attestation-status',
						'mad4b/external-wpml-receipt-status',
						'mad4b/snapshot-verify',
						'mad4b/live-acceptance-status',
					),
					'content' => array(),
					'admin' => array(),
				);
			}
			public function register_abilities() {}
			protected function mutation_requires_certification() { return false; }
			protected function provider_certification( $available ) { return null; }
		};
		$registry->register( $adapter );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::add_read_ability( 'mad4b/query-monitor-regression-status', 'Get Query Monitor Regression Evidence', array( __CLASS__, 'query_monitor_status' ) );
		self::add_read_ability( 'mad4b/build-provenance-status', 'Get Exact Build Provenance', array( __CLASS__, 'build_provenance_status' ) );
		self::add_read_ability( 'mad4b/external-handshake-attestation-status', 'Get External Tool Inventory Attestation', array( __CLASS__, 'external_handshake_attestation_status' ) );
		self::add_read_ability( 'mad4b/external-wpml-receipt-status', 'Get External WPML Acceptance Receipt', array( __CLASS__, 'external_wpml_receipt_status' ) );
		self::add_read_ability(
			'mad4b/snapshot-verify',
			'Compare Client Snapshot Identity',
			array( __CLASS__, 'snapshot_verify' ),
			array(
				'type' => 'object',
				'properties' => array( 'client_snapshot_token' => array( 'type' => 'string', 'maxLength' => 80 ) ),
				'required' => array( 'client_snapshot_token' ),
				'additionalProperties' => false,
			)
		);
		self::add_read_ability(
			'mad4b/live-acceptance-status',
			'Get Aggregate Live Acceptance Status',
			array( __CLASS__, 'live_acceptance_status' ),
			array(
				'type' => 'object',
				'properties' => array( 'client_snapshot_token' => array( 'type' => 'string', 'maxLength' => 80 ) ),
				'additionalProperties' => false,
			)
		);
	}

	private static function add_read_ability( $name, $label, $callback, $input_schema = null ) {
		$args = array(
			'label' => $label,
			'description' => $label . ' from passive, non-authorizing MAD4B evidence.',
			'category' => 'mad4b-read',
			'execute_callback' => $callback,
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		);
		$args['input_schema'] = is_array( $input_schema ) ? $input_schema : array( 'type' => 'object', 'additionalProperties' => false );
		wp_register_ability( $name, $args );
	}

	public static function augment_write_runtime_ability( $args, $name ) {
		if ( 'mad4b/write-runtime-certification' !== (string) $name || ! is_array( $args ) ) return $args;
		$args['execute_callback'] = array( __CLASS__, 'write_runtime_certification_status' );
		return $args;
	}

	public static function write_runtime_certification_status() {
		$status = class_exists( 'MAD4B_SCP_Live_Truth' )
			? MAD4B_SCP_Live_Truth::current_write_certification()
			: ( class_exists( 'MAD4B_SCP_Write_Runtime_Certification' ) ? MAD4B_SCP_Write_Runtime_Certification::status() : array() );
		if ( ! is_array( $status ) ) $status = array();
		$wpml = self::external_wpml_receipt_status();
		$external = self::external_handshake_attestation_status();
		$status['external_wpml_acceptance_verified'] = ! empty( $wpml['verified'] );
		$status['external_client_tools_verified'] = ! empty( $external['verified'] );
		$status['external_wpml_evidence_contract'] = self::WPML_RECEIPT_CONTRACT;
		$status['external_client_tools_evidence_contract'] = self::EXTERNAL_ATTESTATION_CONTRACT;
		$status['external_evidence_affects_local_ready'] = false;
		return $status;
	}

	public static function observe_doing_it_wrong( $function_name, $message, $error_level = null ) {
		self::record_warning( 'doing_it_wrong', $function_name, $message );
	}

	public static function observe_deprecated_function( $function_name, $replacement = null, $version = null ) {
		$message = 'Deprecated function ' . (string) $function_name;
		if ( is_string( $replacement ) && '' !== $replacement ) $message .= '; replacement=' . $replacement;
		self::record_warning( 'deprecated_function', $function_name, $message );
	}

	public static function observe_deprecated_argument( $function_name, $message = null, $version = null ) {
		self::record_warning( 'deprecated_argument', $function_name, (string) $message );
	}

	public static function observe_deprecated_hook( $hook_name, $replacement = null, $version = null, $message = null ) {
		self::record_warning( 'deprecated_hook', $hook_name, (string) $message );
	}

	public static function observe_deprecated_class( $class_name, $replacement = null, $version = null ) {
		self::record_warning( 'deprecated_class', $class_name, 'Deprecated class ' . (string) $class_name );
	}

	private static function record_warning( $event_type, $function_name, $message ) {
		if ( ! self::staging_capture_allowed() ) return;
		self::mark_current_request();
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 );
		$classification = self::classify_warning( (string) $event_type, (string) $function_name, (string) $message, $trace );
		$telemetry =& self::telemetry();
		$bucket = isset( $classification['bucket'] ) ? $classification['bucket'] : 'unknown';
		if ( ! isset( $telemetry['counters'][ $bucket ][ $event_type ] ) ) $telemetry['counters'][ $bucket ][ $event_type ] = 0;
		$telemetry['counters'][ $bucket ][ $event_type ]++;
		if ( ! empty( $classification['ability_not_found'] ) ) $telemetry['counters']['mad4b']['ability_not_found']++;
		if ( ! empty( $classification['wp_get_ability_missing'] ) ) $telemetry['counters']['mad4b']['wp_get_ability_missing']++;
		if ( ! empty( $classification['pre_init_abilities_violation'] ) ) $telemetry['counters']['mad4b']['pre_init_abilities_violation']++;
		if ( ! empty( $classification['fluentform_action_scheduler'] ) ) $telemetry['counters']['third_party']['fluentform_action_scheduler']++;
		$telemetry['events'][] = array(
			'type' => sanitize_key( (string) $event_type ),
			'classification' => $bucket,
			'severity' => isset( $classification['severity'] ) ? $classification['severity'] : 'observed',
			'function' => self::safe_identifier( $function_name ),
			'message' => self::sanitize_warning_message( $message ),
			'component' => isset( $classification['component'] ) ? $classification['component'] : '',
			'plugin_slug' => isset( $classification['plugin_slug'] ) ? $classification['plugin_slug'] : '',
			'lifecycle_phase' => did_action( 'init' ) ? 'post_init' : 'pre_init',
			'request_class' => self::request_class(),
			'observed_at' => gmdate( 'Y-m-d H:i:s' ),
			'build_fingerprint' => self::current_build_fingerprint(),
			'callers' => isset( $classification['callers'] ) ? $classification['callers'] : array(),
		);
		if ( count( $telemetry['events'] ) > self::MAX_EVENTS ) $telemetry['events'] = array_slice( $telemetry['events'], -1 * self::MAX_EVENTS );
		$telemetry['last_observed_at'] = gmdate( 'Y-m-d H:i:s' );
		self::$telemetry_dirty = true;
	}

	/** @internal Pure classification seam used by regression tests. */
	public static function classify_warning_for_test( $event_type, $function_name, $message, array $trace = array() ) {
		return self::classify_warning( $event_type, $function_name, $message, $trace );
	}

	private static function classify_warning( $event_type, $function_name, $message, array $trace ) {
		$function_name = (string) $function_name;
		$message = (string) $message;
		$mad4b = 0 === strpos( $function_name, 'MAD4B_' );
		$core = false;
		$callers = array();
		foreach ( array_slice( $trace, 0, 12 ) as $frame ) {
			$file = isset( $frame['file'] ) ? wp_normalize_path( (string) $frame['file'] ) : '';
			if ( defined( 'MAD4B_SCP_DIR' ) && '' !== $file && 0 === strpos( $file, wp_normalize_path( MAD4B_SCP_DIR ) ) ) $mad4b = true;
			if ( defined( 'ABSPATH' ) && '' !== $file && false !== strpos( $file, wp_normalize_path( ABSPATH . 'wp-includes/' ) ) ) $core = true;
			$caller = '';
			if ( ! empty( $frame['class'] ) ) $caller .= self::safe_identifier( $frame['class'] ) . '::';
			if ( ! empty( $frame['function'] ) ) $caller .= self::safe_identifier( $frame['function'] );
			if ( '' !== $caller && ! in_array( $caller, $callers, true ) ) $callers[] = $caller;
			if ( count( $callers ) >= 6 ) break;
		}
		$fluent = in_array( $function_name, array( 'as_next_scheduled_action', 'as_schedule_single_action' ), true ) || false !== stripos( $message, 'Action Scheduler' );
		$ability_not_found = false !== stripos( $message, 'Ability') && false !== stripos( $message, 'not found' );
		$wp_get_missing = false !== stripos( $message, 'wp_get_ability' );
		$pre_init = ( false !== stripos( $message, 'Abilities' ) || false !== stripos( $function_name, 'WP_Abilities_Registry' ) ) && ! did_action( 'init' );
		if ( $fluent ) {
			return array( 'bucket' => 'third_party', 'severity' => 'third_party_non_blocking', 'component' => 'fluentform', 'plugin_slug' => 'fluentform', 'ability_not_found' => false, 'wp_get_ability_missing' => false, 'pre_init_abilities_violation' => false, 'fluentform_action_scheduler' => true, 'callers' => $callers );
		}
		$bucket = $mad4b ? 'mad4b' : ( $core ? 'wordpress_core' : 'unknown' );
		return array(
			'bucket' => $bucket,
			'severity' => 'mad4b' === $bucket ? 'blocking_regression' : 'observed',
			'component' => 'mad4b' === $bucket ? 'mad4b-site-control-plane' : ( 'wordpress_core' === $bucket ? 'wordpress-core' : '' ),
			'plugin_slug' => 'mad4b' === $bucket ? 'mad4b-site-control-plane' : '',
			'ability_not_found' => $mad4b && $ability_not_found,
			'wp_get_ability_missing' => $mad4b && $wp_get_missing,
			'pre_init_abilities_violation' => $mad4b && $pre_init,
			'fluentform_action_scheduler' => false,
			'callers' => $callers,
		);
	}

	public static function sanitize_warning_message( $message ) {
		$message = preg_replace( '/[\r\n\t]+/', ' ', (string) $message );
		$message = preg_replace( '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [redacted]', $message );
		$message = preg_replace( '/\b(authorization|cookie|password|passwd|token|secret|api[_-]?key|db[_-]?(?:pass|password))\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $message );
		$message = preg_replace( '#(?:[A-Za-z]:[\\\\/]|/)(?:[^\s:]+[\\\\/]){2,}[^\s:]*#', '[path-redacted]', $message );
		$message = trim( (string) $message );
		return strlen( $message ) > 280 ? substr( $message, 0, 280 ) : $message;
	}

	private static function safe_identifier( $value ) {
		$value = preg_replace( '/[^A-Za-z0-9_\\\\:\-\.]/', '', (string) $value );
		return strlen( $value ) > 120 ? substr( $value, 0, 120 ) : $value;
	}

	private static function mark_current_request() {
		if ( self::$request_marked || ! self::staging_capture_allowed() ) return;
		self::$request_marked = true;
		$telemetry =& self::telemetry();
		$class = self::request_class();
		$telemetry['observed_request_count']++;
		if ( ! isset( $telemetry['request_coverage'][ $class ] ) ) $telemetry['request_coverage'][ $class ] = 0;
		$telemetry['request_coverage'][ $class ]++;
		$telemetry['last_observed_at'] = gmdate( 'Y-m-d H:i:s' );
		self::$telemetry_dirty = true;
	}

	private static function request_class() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( false !== strpos( $uri, '/mcp/' ) || false !== strpos( $uri, '/wp-json/mcp/' ) ) return 'mcp';
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return 'rest';
		if ( function_exists( 'is_admin' ) && is_admin() ) return 'wp_admin';
		return 'frontend';
	}

	private static function &telemetry() {
		if ( null !== self::$telemetry ) return self::$telemetry;
		$current_build = self::current_build_fingerprint();
		$stored = self::staging_capture_allowed() ? get_option( self::TELEMETRY_OPTION, array() ) : array();
		$valid = is_array( $stored ) && isset( $stored['build_fingerprint'], $stored['capture_started_at'] ) && hash_equals( (string) $stored['build_fingerprint'], $current_build );
		$started = $valid ? strtotime( (string) $stored['capture_started_at'] . ' UTC' ) : false;
		if ( ! $valid || false === $started || ( time() - $started ) > self::TELEMETRY_TTL ) $stored = self::empty_telemetry( $current_build );
		self::$telemetry = $stored;
		return self::$telemetry;
	}

	private static function empty_telemetry( $build ) {
		return array(
			'contract' => self::QUERY_MONITOR_CONTRACT,
			'control_plane_version' => defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '',
			'build_fingerprint' => (string) $build,
			'capture_started_at' => gmdate( 'Y-m-d H:i:s' ),
			'last_observed_at' => '',
			'observed_request_count' => 0,
			'request_coverage' => array( 'mcp' => 0, 'rest' => 0, 'wp_admin' => 0, 'frontend' => 0 ),
			'counters' => array(
				'mad4b' => array( 'doing_it_wrong' => 0, 'deprecated_function' => 0, 'deprecated_argument' => 0, 'deprecated_hook' => 0, 'deprecated_class' => 0, 'ability_not_found' => 0, 'wp_get_ability_missing' => 0, 'pre_init_abilities_violation' => 0 ),
				'third_party' => array( 'doing_it_wrong' => 0, 'deprecated_function' => 0, 'deprecated_argument' => 0, 'deprecated_hook' => 0, 'deprecated_class' => 0, 'fluentform_action_scheduler' => 0 ),
				'wordpress_core' => array(),
				'unknown' => array(),
			),
			'events' => array(),
		);
	}

	public static function flush_observation() {
		if ( ! self::$telemetry_dirty || ! self::staging_capture_allowed() || ! is_array( self::$telemetry ) ) return;
		self::$telemetry['events'] = array_slice( isset( self::$telemetry['events'] ) ? self::$telemetry['events'] : array(), -1 * self::MAX_EVENTS );
		update_option( self::TELEMETRY_OPTION, self::$telemetry, false );
		self::$telemetry_dirty = false;
	}

	public static function query_monitor_status() {
		self::mark_current_request();
		$telemetry = self::telemetry();
		$current_build = self::current_build_fingerprint();
		$current_match = isset( $telemetry['build_fingerprint'] ) && '' !== $current_build && hash_equals( $current_build, (string) $telemetry['build_fingerprint'] );
		$count = isset( $telemetry['observed_request_count'] ) ? (int) $telemetry['observed_request_count'] : 0;
		$fresh = self::staging_capture_allowed() && $current_match && $count > 0;
		$mad4b = isset( $telemetry['counters']['mad4b'] ) ? $telemetry['counters']['mad4b'] : array();
		$blocking = 0;
		foreach ( array( 'doing_it_wrong', 'deprecated_function', 'deprecated_argument', 'deprecated_hook', 'deprecated_class', 'ability_not_found', 'wp_get_ability_missing', 'pre_init_abilities_violation' ) as $key ) $blocking += isset( $mad4b[ $key ] ) ? (int) $mad4b[ $key ] : 0;
		$active = defined( 'QM_VERSION' );
		$ready = $fresh && $active && 0 === $blocking;
		return array(
			'contract' => self::QUERY_MONITOR_CONTRACT,
			'query_monitor' => array( 'installed' => $active || defined( 'QM_DIR' ), 'active' => $active, 'version' => $active ? (string) QM_VERSION : '' ),
			'current_build' => array( 'version' => defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '', 'build_fingerprint' => $current_build ),
			'evidence' => array( 'fresh' => $fresh, 'current_build_match' => $current_match, 'capture_started_at' => isset( $telemetry['capture_started_at'] ) ? $telemetry['capture_started_at'] : '', 'last_observed_at' => isset( $telemetry['last_observed_at'] ) ? $telemetry['last_observed_at'] : '', 'observed_request_count' => $count, 'request_coverage' => isset( $telemetry['request_coverage'] ) ? $telemetry['request_coverage'] : array() ),
			'mad4b' => array(
				'deprecated_function_count' => isset( $mad4b['deprecated_function'] ) ? (int) $mad4b['deprecated_function'] : 0,
				'doing_it_wrong_count' => isset( $mad4b['doing_it_wrong'] ) ? (int) $mad4b['doing_it_wrong'] : 0,
				'ability_not_found_count' => isset( $mad4b['ability_not_found'] ) ? (int) $mad4b['ability_not_found'] : 0,
				'wp_get_ability_missing_count' => isset( $mad4b['wp_get_ability_missing'] ) ? (int) $mad4b['wp_get_ability_missing'] : 0,
				'pre_init_abilities_violation_count' => isset( $mad4b['pre_init_abilities_violation'] ) ? (int) $mad4b['pre_init_abilities_violation'] : 0,
			),
			'third_party' => array( 'fluentform_action_scheduler_count' => isset( $telemetry['counters']['third_party']['fluentform_action_scheduler'] ) ? (int) $telemetry['counters']['third_party']['fluentform_action_scheduler'] : 0 ),
			'events' => isset( $telemetry['events'] ) ? $telemetry['events'] : array(),
			'ready' => $ready,
			'state' => $ready ? 'ready' : ( ! $fresh ? 'insufficient_current_build_observation' : ( ! $active ? 'query_monitor_inactive' : 'mad4b_regression_observed' ) ),
			'production_capture_persistence_enabled' => false,
		);
	}

	public static function build_provenance_status() {
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json' : '';
		$base = array(
			'contract' => self::PROVENANCE_CONTRACT,
			'version' => defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '',
			'source_commit_sha' => '', 'build_fingerprint' => '', 'package_manifest_digest' => '',
			'build_workflow' => '', 'build_run_id' => '', 'artifact_identity' => '', 'mcp_adapter_version' => '',
			'manifest_present' => false, 'manifest_valid' => false, 'runtime_manifest_match' => false,
			'stale' => true, 'provenance_mismatch' => array( 'manifest_missing' ),
		);
		if ( '' === $path || ! is_readable( $path ) ) return $base;
		$base['manifest_present'] = true;
		$raw = file_get_contents( $path );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || self::PROVENANCE_CONTRACT !== ( isset( $data['contract'] ) ? $data['contract'] : '' ) ) { $base['provenance_mismatch'] = array( 'manifest_invalid' ); return $base; }
		$required_hashes = array( 'build_fingerprint', 'package_manifest_digest' );
		foreach ( $required_hashes as $key ) if ( empty( $data[ $key ] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $data[ $key ] ) ) { $base['provenance_mismatch'] = array( 'manifest_hash_invalid' ); return $base; }
		if ( empty( $data['source_commit_sha'] ) || ! preg_match( '/^[a-f0-9]{40}$/', (string) $data['source_commit_sha'] ) || empty( $data['package_files'] ) || ! is_array( $data['package_files'] ) ) { $base['provenance_mismatch'] = array( 'manifest_fields_invalid' ); return $base; }
		$base['manifest_valid'] = true;
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'build_workflow', 'build_run_id', 'artifact_identity', 'mcp_adapter_version' ) as $key ) $base[ $key ] = isset( $data[ $key ] ) ? sanitize_text_field( (string) $data[ $key ] ) : '';
		$lines = array();
		$mismatch = array();
		foreach ( $data['package_files'] as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['path'] ) || false !== strpos( (string) $entry['path'], '..' ) || ! isset( $entry['bytes'], $entry['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $entry['sha256'] ) ) { $mismatch[] = 'package_entry_invalid'; continue; }
			$relative = ltrim( wp_normalize_path( (string) $entry['path'] ), '/' );
			$file = MAD4B_SCP_DIR . $relative;
			if ( ! is_readable( $file ) || (int) filesize( $file ) !== (int) $entry['bytes'] || ! hash_equals( (string) $entry['sha256'], (string) hash_file( 'sha256', $file ) ) ) { $mismatch[] = 'runtime_file_mismatch:' . self::safe_identifier( $relative ); continue; }
			$lines[] = $relative . "\0" . (int) $entry['bytes'] . "\0" . strtolower( (string) $entry['sha256'] ) . "\n";
		}
		sort( $lines, SORT_STRING );
		$digest = hash( 'sha256', implode( '', $lines ) );
		if ( ! hash_equals( (string) $data['package_manifest_digest'], $digest ) ) $mismatch[] = 'package_manifest_digest_mismatch';
		if ( (string) $base['version'] !== (string) ( isset( $data['control_plane_version'] ) ? $data['control_plane_version'] : '' ) ) $mismatch[] = 'control_plane_version_mismatch';
		$base['runtime_manifest_match'] = empty( $mismatch );
		$base['stale'] = ! $base['runtime_manifest_match'];
		$base['provenance_mismatch'] = array_values( array_unique( $mismatch ) );
		return $base;
	}

	private static function provenance_manifest() {
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json' : '';
		if ( '' === $path || ! is_readable( $path ) ) return array();
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : array();
	}

	private static function current_build_fingerprint() {
		$manifest = self::provenance_manifest();
		if ( isset( $manifest['build_fingerprint'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $manifest['build_fingerprint'] ) ) return strtolower( (string) $manifest['build_fingerprint'] );
		$legacy = class_exists( 'MAD4B_SCP_External_Handshake_Evidence' ) ? MAD4B_SCP_External_Handshake_Evidence::build_fingerprint() : '';
		$self_hash = is_readable( __FILE__ ) ? hash_file( 'sha256', __FILE__ ) : '';
		return hash( 'sha256', self::CONTRACT . "\n" . ( defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '' ) . "\n" . $legacy . "\n" . $self_hash );
	}

	public static function observe_rest_response( $response, $server, $request ) {
		self::observe_wpml_response( $response, $request );
		self::observe_external_handshake( $response, $request );
		return $response;
	}

	private static function observe_wpml_response( $response, $request ) {
		if ( ! self::staging_capture_allowed() || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return;
		$route = '/' . ltrim( rtrim( (string) $request->get_route(), '/' ), '/' );
		if ( '/wpml/v1/rest/status' !== $route ) return;
		$present = method_exists( $request, 'get_param' ) && '1' === (string) $request->get_param( 'test_get_parameter' );
		$rest = rest_ensure_response( $response );
		$data = $rest instanceof WP_REST_Response ? $rest->get_data() : array();
		$data = is_array( $data ) ? $data : array();
		$receipt = self::evaluate_wpml_receipt( $present, $data, self::current_build_fingerprint() );
		$receipt['observed_at'] = gmdate( 'Y-m-d H:i:s' );
		$receipt['request_route'] = '/wpml/v1/rest/status';
		update_option( self::WPML_OPTION, $receipt, false );
	}

	/** @internal Pure WPML receipt evaluator used by regression tests. */
	public static function evaluate_wpml_receipt( $parameter_present, array $data, $build_fingerprint ) {
		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : '';
		$get_parameters = isset( $data['get_parameters'] ) ? sanitize_key( (string) $data['get_parameters'] ) : '';
		$success = (bool) $parameter_present && 'valid' === $status && 'valid' === $get_parameters;
		return array(
			'contract' => self::WPML_RECEIPT_CONTRACT,
			'observed' => true,
			'observed_at' => '',
			'build_fingerprint' => preg_match( '/^[a-f0-9]{64}$/', (string) $build_fingerprint ) ? strtolower( (string) $build_fingerprint ) : '',
			'request_route' => '/wpml/v1/rest/status',
			'test_get_parameter_present' => (bool) $parameter_present,
			'success' => $success,
			'status' => $status,
			'get_parameters' => $get_parameters,
		);
	}

	public static function external_wpml_receipt_status() {
		$stored = self::staging_capture_allowed() ? get_option( self::WPML_OPTION, array() ) : array();
		$current_build = self::current_build_fingerprint();
		$base = array( 'contract' => self::WPML_RECEIPT_CONTRACT, 'observed' => false, 'verified' => false, 'stale' => true, 'state' => 'pending_external_evidence', 'observed_at' => '', 'build_fingerprint' => '', 'current_build_fingerprint' => $current_build, 'request_route' => '/wpml/v1/rest/status', 'test_get_parameter_present' => false, 'success' => false, 'status' => '', 'get_parameters' => '' );
		if ( ! is_array( $stored ) || empty( $stored['observed'] ) ) return $base;
		foreach ( array( 'observed', 'observed_at', 'build_fingerprint', 'request_route', 'test_get_parameter_present', 'success', 'status', 'get_parameters' ) as $key ) if ( array_key_exists( $key, $stored ) ) $base[ $key ] = $stored[ $key ];
		$match = ! empty( $stored['build_fingerprint'] ) && '' !== $current_build && hash_equals( $current_build, (string) $stored['build_fingerprint'] );
		$base['stale'] = ! $match;
		$base['verified'] = $match && ! empty( $stored['success'] ) && 'valid' === (string) $stored['status'] && 'valid' === (string) $stored['get_parameters'];
		$base['state'] = $base['verified'] ? 'verified_external_wpml' : ( $match ? 'external_wpml_failed' : 'stale_build_evidence' );
		return $base;
	}

	private static function observe_external_handshake( $response, $request ) {
		if ( ! self::external_capture_allowed( $request ) ) return;
		$method = self::jsonrpc_method( $request );
		$rest = rest_ensure_response( $response );
		if ( ! $rest instanceof WP_REST_Response || 200 !== (int) $rest->get_status() ) return;
		if ( 'initialize' === $method ) self::capture_external_initialize( $rest, $request );
		elseif ( 'tools/list' === $method ) self::capture_external_tools_list( $rest, $request );
	}

	private static function capture_external_initialize( $response, $request ) {
		$data = self::normalize_value( $response->get_data() );
		if ( ! is_array( $data ) || isset( $data['error'] ) || empty( $data['result']['capabilities']['tools'] ) ) return;
		$session_id = self::response_session_id( $response );
		if ( '' === $session_id ) return;
		$context = self::verified_request_context( $request );
		if ( ! is_array( $context ) ) return;
		$expected = self::expected_tool_names();
		$expected_write = self::expected_write_tool_names();
		$context['session_fingerprint'] = hash( 'sha256', $session_id );
		$context['build_fingerprint'] = self::current_build_fingerprint();
		$context['expected_tool_inventory_fingerprint'] = self::inventory_fingerprint( $expected );
		$context['expected_write_inventory_fingerprint'] = self::inventory_fingerprint( $expected_write );
		$context['initialized_at'] = gmdate( 'Y-m-d H:i:s' );
		set_transient( self::pending_key( $context['session_fingerprint'] ), $context, self::PENDING_TTL );
	}

	private static function capture_external_tools_list( $response, $request ) {
		$session_id = method_exists( $request, 'get_header' ) ? trim( (string) $request->get_header( 'mcp-session-id' ) ) : '';
		if ( '' === $session_id || strlen( $session_id ) > 512 ) return;
		$fingerprint = hash( 'sha256', $session_id );
		$pending = get_transient( self::pending_key( $fingerprint ) );
		$current = self::verified_request_context( $request );
		if ( ! is_array( $pending ) || ! is_array( $current ) || empty( $pending['session_fingerprint'] ) || ! hash_equals( (string) $pending['session_fingerprint'], $fingerprint ) || ! self::same_context( $pending, $current ) ) return;
		$data = self::normalize_value( $response->get_data() );
		$tools = isset( $data['result']['tools'] ) && is_array( $data['result']['tools'] ) ? $data['result']['tools'] : array();
		$names = array();
		foreach ( $tools as $tool ) if ( is_array( $tool ) && isset( $tool['name'] ) && is_string( $tool['name'] ) ) $names[] = $tool['name'];
		$attestation = self::inventory_attestation_from_names( $names, isset( $pending['build_fingerprint'] ) ? $pending['build_fingerprint'] : '' );
		$attestation['initialized_at'] = isset( $pending['initialized_at'] ) ? sanitize_text_field( (string) $pending['initialized_at'] ) : '';
		$attestation['observed_at'] = gmdate( 'Y-m-d H:i:s' );
		$attestation['real_external_session'] = true;
		$attestation['client_id'] = self::CHATGPT_CLIENT_ID;
		$attestation['server_id'] = self::SERVER_ID;
		$attestation['session_fingerprint_present'] = true;
		$attestation['pending_expected_tool_inventory_fingerprint'] = isset( $pending['expected_tool_inventory_fingerprint'] ) ? $pending['expected_tool_inventory_fingerprint'] : '';
		$attestation['pending_expected_write_inventory_fingerprint'] = isset( $pending['expected_write_inventory_fingerprint'] ) ? $pending['expected_write_inventory_fingerprint'] : '';
		update_option( self::EXTERNAL_OPTION, $attestation, false );
		delete_transient( self::pending_key( $fingerprint ) );
	}

	/** @internal Deterministic inventory evaluator used by runtime and tests. */
	public static function inventory_attestation_from_names( array $external_names, $captured_build_fingerprint = '' ) {
		$external = self::normalize_tool_names( $external_names );
		$expected = self::expected_tool_names();
		$expected_write = self::expected_write_tool_names();
		$blocked = self::blocked_write_tool_names();
		$breakglass = self::breakglass_tool_names();
		$raw_sql = self::ability_to_mcp_tool_name( 'mad4b/database-raw-query' );
		$missing = array_values( array_diff( $expected, $external ) );
		$unexpected = array_values( array_diff( $external, $expected ) );
		$blocked_leaks = array_values( array_intersect( $external, $blocked ) );
		$breakglass_leaks = array_values( array_intersect( $external, $breakglass ) );
		$observed_write = array_values( array_intersect( $external, $expected_write ) );
		$external_fp = self::inventory_fingerprint( $external );
		$expected_fp = self::inventory_fingerprint( $expected );
		$write_fp = self::inventory_fingerprint( $observed_write );
		$expected_write_fp = self::inventory_fingerprint( $expected_write );
		$current_build = self::current_build_fingerprint();
		$build_match = '' !== $captured_build_fingerprint && '' !== $current_build && hash_equals( $current_build, (string) $captured_build_fingerprint );
		$inventory_match = empty( $missing ) && empty( $unexpected ) && ! empty( $expected ) && hash_equals( $expected_fp, $external_fp );
		$write_match = count( $observed_write ) === count( $expected_write ) && ( empty( $expected_write ) || hash_equals( $expected_write_fp, $write_fp ) );
		$raw_sql_exposed = '' !== $raw_sql && in_array( $raw_sql, $external, true );
		return array(
			'contract' => self::EXTERNAL_ATTESTATION_CONTRACT,
			'real_external_session' => false,
			'observed_at' => '',
			'build_fingerprint' => (string) $captured_build_fingerprint,
			'current_build_fingerprint' => $current_build,
			'build_fingerprint_match' => $build_match,
			'external_tool_count' => count( $external ),
			'external_tool_inventory_fingerprint' => $external_fp,
			'expected_tool_count' => count( $expected ),
			'expected_tool_inventory_fingerprint' => $expected_fp,
			'inventory_match' => $inventory_match,
			'missing_expected_tools' => $missing,
			'unexpected_tools' => $unexpected,
			'external_write_tool_count' => count( $observed_write ),
			'external_write_inventory_fingerprint' => $write_fp,
			'expected_write_tool_count' => count( $expected_write ),
			'expected_write_inventory_fingerprint' => $expected_write_fp,
			'write_inventory_fingerprint_match' => $write_match,
			'raw_sql_exposed' => $raw_sql_exposed,
			'breakglass_exposed' => ! empty( $breakglass_leaks ),
			'foreign_write_tool_exposed' => ! empty( $unexpected ),
			'provider_blocked_tool_leaks' => $blocked_leaks,
			'verified' => false,
			'status' => $build_match ? 'inventory_evaluated' : 'stale_build_evidence',
		);
	}

	public static function external_handshake_attestation_status() {
		$stored = self::staging_capture_allowed() ? get_option( self::EXTERNAL_OPTION, array() ) : array();
		if ( ! is_array( $stored ) || empty( $stored ) ) return array( 'contract' => self::EXTERNAL_ATTESTATION_CONTRACT, 'verified' => false, 'status' => 'pending_external_evidence', 'real_external_session' => false, 'inventory_match' => false, 'missing_expected_tools' => array(), 'unexpected_tools' => array(), 'provider_blocked_tool_leaks' => array(), 'raw_sql_exposed' => false, 'breakglass_exposed' => false, 'foreign_write_tool_exposed' => false, 'build_fingerprint_match' => false, 'write_inventory_fingerprint_match' => false );
		$current_expected = self::inventory_fingerprint( self::expected_tool_names() );
		$current_write = self::inventory_fingerprint( self::expected_write_tool_names() );
		$current_build = self::current_build_fingerprint();
		$build_match = ! empty( $stored['build_fingerprint'] ) && hash_equals( $current_build, (string) $stored['build_fingerprint'] );
		$expected_match = ! empty( $stored['expected_tool_inventory_fingerprint'] ) && hash_equals( $current_expected, (string) $stored['expected_tool_inventory_fingerprint'] );
		$write_match = ! empty( $stored['expected_write_inventory_fingerprint'] ) && hash_equals( $current_write, (string) $stored['expected_write_inventory_fingerprint'] ) && ! empty( $stored['write_inventory_fingerprint_match'] );
		$observed_at = isset( $stored['observed_at'] ) ? (string) $stored['observed_at'] : '';
		$ts = '' !== $observed_at ? strtotime( $observed_at . ' UTC' ) : false;
		$fresh_time = false !== $ts && ( time() - $ts ) <= self::EXTERNAL_TTL;
		$verified = ! empty( $stored['real_external_session'] ) && $build_match && $expected_match && $write_match && $fresh_time && ! empty( $stored['inventory_match'] ) && empty( $stored['missing_expected_tools'] ) && empty( $stored['unexpected_tools'] ) && empty( $stored['provider_blocked_tool_leaks'] ) && empty( $stored['raw_sql_exposed'] ) && empty( $stored['breakglass_exposed'] ) && empty( $stored['foreign_write_tool_exposed'] );
		$stored['current_build_fingerprint'] = $current_build;
		$stored['build_fingerprint_match'] = $build_match;
		$stored['current_expected_tool_inventory_fingerprint_match'] = $expected_match;
		$stored['write_inventory_fingerprint_match'] = $write_match;
		$stored['verified'] = $verified;
		$stored['status'] = $verified ? 'verified_external_inventory' : ( ! $build_match || ! $expected_match || ! $write_match ? 'stale_build_evidence' : 'external_inventory_mismatch' );
		return $stored;
	}

	private static function normalize_tool_names( array $names ) {
		$names = array_values( array_unique( array_filter( array_map( static function ( $name ) { return is_string( $name ) ? trim( $name ) : ''; }, $names ) ) ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	private static function ability_to_mcp_tool_name( $ability_name ) {
		if ( ! class_exists( '\\WP\\MCP\\Domain\\Utils\\McpNameSanitizer' ) ) return '';
		$name = \WP\MCP\Domain\Utils\McpNameSanitizer::sanitize_name( (string) $ability_name );
		return is_wp_error( $name ) || ! is_string( $name ) ? '' : trim( $name );
	}

	private static function abilities_to_mcp_names( array $abilities ) {
		$names = array();
		foreach ( $abilities as $ability ) { $name = self::ability_to_mcp_tool_name( $ability ); if ( '' === $name ) return array(); $names[] = $name; }
		return self::normalize_tool_names( $names );
	}

	private static function expected_tool_names() { return class_exists( 'MAD4B_SCP_Servers' ) ? self::abilities_to_mcp_names( MAD4B_SCP_Servers::chatgpt_tools() ) : array(); }
	private static function expected_write_tool_names() { return class_exists( 'MAD4B_SCP_Servers' ) ? self::abilities_to_mcp_names( MAD4B_SCP_Servers::write_tools() ) : array(); }
	private static function blocked_write_tool_names() {
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) return array();
		$abilities = array();
		foreach ( MAD4B_SCP_Servers::blocked_write_tools() as $entry ) if ( is_array( $entry ) && isset( $entry['ability'] ) ) $abilities[] = $entry['ability'];
		return self::abilities_to_mcp_names( $abilities );
	}
	private static function breakglass_tool_names() { return class_exists( 'MAD4B_SCP_Servers' ) ? self::abilities_to_mcp_names( MAD4B_SCP_Servers::core_tools( 'mad4b-breakglass' ) ) : array(); }
	private static function inventory_fingerprint( array $names ) { $names = self::normalize_tool_names( $names ); return empty( $names ) ? '' : hash( 'sha256', implode( "\n", $names ) ); }

	private static function external_capture_allowed( $request ) {
		if ( ! self::staging_capture_allowed() || ! defined( 'REST_REQUEST' ) || true !== REST_REQUEST || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) return false;
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return false;
		$route = '/' . ltrim( rtrim( (string) $request->get_route(), '/' ), '/' );
		return '/mcp/' . self::SERVER_ID === $route && class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) && MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active();
	}

	private static function verified_request_context( $request ) {
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : null;
		$oauth = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::status() : array();
		if ( ! is_array( $identity ) || empty( $identity['authenticated'] ) || 'oauth2_bearer' !== (string) $identity['auth_method'] || empty( $identity['subject_fingerprint'] ) ) return null;
		$scopes = isset( $identity['token_scopes'] ) && is_array( $identity['token_scopes'] ) ? array_values( array_unique( array_map( 'sanitize_text_field', $identity['token_scopes'] ) ) ) : array();
		if ( ! in_array( self::REQUIRED_SCOPE, $scopes, true ) ) return null;
		$claims = self::verified_jwt_claims( $request );
		if ( ! is_array( $claims ) ) return null;
		$issuer = isset( $oauth['issuer'] ) ? rtrim( (string) $oauth['issuer'], '/' ) : '';
		$resource = isset( $oauth['resource'] ) ? untrailingslashit( (string) $oauth['resource'] ) : '';
		$client_id = isset( $claims['client_id'] ) ? trim( (string) $claims['client_id'] ) : '';
		if ( '' === $issuer || '' === $resource || empty( $claims['iss'] ) || empty( $claims['resource'] ) || ! hash_equals( $issuer, rtrim( (string) $claims['iss'], '/' ) ) || ! hash_equals( $resource, untrailingslashit( (string) $claims['resource'] ) ) || ! hash_equals( self::CHATGPT_CLIENT_ID, $client_id ) ) return null;
		return array( 'environment' => 'staging', 'resource' => $resource, 'issuer' => $issuer, 'client_id' => $client_id, 'auth_method' => 'oauth2_bearer', 'wp_user_id' => isset( $identity['wp_user_id'] ) ? absint( $identity['wp_user_id'] ) : 0, 'subject_fingerprint' => strtolower( (string) $identity['subject_fingerprint'] ), 'scope_set' => $scopes );
	}

	private static function verified_jwt_claims( $request ) {
		$authorization = method_exists( $request, 'get_header' ) ? trim( (string) $request->get_header( 'authorization' ) ) : '';
		if ( ! preg_match( '/^Bearer\s+([^\s]+)$/i', $authorization, $matches ) ) return null;
		$parts = explode( '.', $matches[1] );
		unset( $authorization, $matches );
		if ( 3 !== count( $parts ) || strlen( $parts[1] ) > 32768 ) return null;
		$payload = self::base64url_decode( $parts[1] );
		unset( $parts );
		if ( ! is_string( $payload ) || '' === $payload || strlen( $payload ) > 24576 ) return null;
		$claims = json_decode( $payload, true );
		unset( $payload );
		return is_array( $claims ) ? $claims : null;
	}

	private static function jsonrpc_method( $request ) { $params = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null; return is_array( $params ) && isset( $params['method'] ) && is_string( $params['method'] ) ? trim( $params['method'] ) : ''; }
	private static function response_session_id( $response ) {
		foreach ( $response->get_headers() as $name => $value ) if ( 'mcp-session-id' === strtolower( (string) $name ) ) { $value = is_array( $value ) ? reset( $value ) : $value; $value = is_string( $value ) ? trim( $value ) : ''; return strlen( $value ) <= 512 ? $value : ''; }
		return '';
	}
	private static function pending_key( $fingerprint ) { return self::PENDING_PREFIX . substr( (string) $fingerprint, 0, 40 ); }
	private static function same_context( array $left, array $right ) { foreach ( array( 'environment', 'resource', 'issuer', 'client_id', 'auth_method', 'subject_fingerprint' ) as $key ) if ( ! isset( $left[ $key ], $right[ $key ] ) || ! hash_equals( (string) $left[ $key ], (string) $right[ $key ] ) ) return false; return isset( $left['wp_user_id'], $right['wp_user_id'] ) && (int) $left['wp_user_id'] === (int) $right['wp_user_id']; }
	private static function normalize_value( $value ) { $encoded = wp_json_encode( $value ); return false === $encoded ? null : json_decode( $encoded, true ); }
	private static function base64url_decode( $value ) { if ( ! is_string( $value ) || ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) return null; $pad = strlen( $value ) % 4; if ( $pad ) $value .= str_repeat( '=', 4 - $pad ); $decoded = base64_decode( strtr( $value, '-_', '+/' ), true ); return false === $decoded ? null : $decoded; }

	public static function snapshot_verify( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$client = isset( $input['client_snapshot_token'] ) ? trim( (string) $input['client_snapshot_token'] ) : '';
		$live = class_exists( 'MAD4B_SCP_Skill_Snapshot_Identity' ) ? MAD4B_SCP_Skill_Snapshot_Identity::build() : array();
		$token = isset( $live['identity_token'] ) ? (string) $live['identity_token'] : '';
		$exact = preg_match( '/^sha256:[a-f0-9]{64}$/', $client ) && '' !== $token && hash_equals( $token, $client );
		return array( 'contract' => self::SNAPSHOT_VERIFY_CONTRACT, 'live_snapshot_token' => $token, 'client_snapshot_token' => preg_match( '/^sha256:[a-f0-9]{64}$/', $client ) ? $client : '', 'exact_match' => (bool) $exact, 'skill_count' => isset( $live['skill_count'] ) ? (int) $live['skill_count'] : 0, 'app_id' => isset( $live['app_id'] ) ? (string) $live['app_id'] : '', 'control_plane_version' => defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '' );
	}

	public static function live_acceptance_status( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$provenance = self::build_provenance_status();
		$qm = self::query_monitor_status();
		$wpml = self::external_wpml_receipt_status();
		$external = self::external_handshake_attestation_status();
		$rest = class_exists( 'MAD4B_SCP_REST_Compatibility' ) ? MAD4B_SCP_REST_Compatibility::status() : array();
		$skills = class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' ) ? MAD4B_SCP_Skill_Runtime_Certification::status() : array();
		$write_authority = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::status() : array();
		$write_runtime = self::write_runtime_certification_status();
		$snapshot_local = class_exists( 'MAD4B_SCP_Skill_Snapshot_Identity' ) ? MAD4B_SCP_Skill_Snapshot_Identity::build() : array();
		$snapshot_external = ! empty( $input['client_snapshot_token'] ) ? self::snapshot_verify( $input ) : array( 'contract' => self::SNAPSHOT_VERIFY_CONTRACT, 'exact_match' => false, 'state' => 'pending_external_evidence' );
		$provider_leaks = isset( $external['provider_blocked_tool_leaks'] ) ? $external['provider_blocked_tool_leaks'] : array();
		$gates = array(
			'environment_guard' => self::gate( self::staging_capture_allowed(), self::staging_capture_allowed() ? 'ready' : 'blocked', true, 'mad4b.environment-guard', self::staging_capture_allowed() ? array() : array( 'not_exact_staging_origin' ) ),
			'build_provenance' => self::gate( ! empty( $provenance['runtime_manifest_match'] ), ! empty( $provenance['runtime_manifest_match'] ) ? 'ready' : 'stale_or_missing', empty( $provenance['stale'] ), self::PROVENANCE_CONTRACT, isset( $provenance['provenance_mismatch'] ) ? $provenance['provenance_mismatch'] : array() ),
			'ability_registration' => self::gate( function_exists( 'wp_get_ability' ), function_exists( 'wp_get_ability' ) ? 'ready' : 'not_materialized', true, 'wordpress-abilities-api', function_exists( 'wp_get_ability' ) ? array() : array( 'wp_get_ability_unavailable' ) ),
			'query_monitor_regression' => self::gate( ! empty( $qm['ready'] ), isset( $qm['state'] ) ? $qm['state'] : 'unknown', ! empty( $qm['evidence']['fresh'] ), self::QUERY_MONITOR_CONTRACT, ! empty( $qm['ready'] ) ? array() : array( isset( $qm['state'] ) ? $qm['state'] : 'not_ready' ) ),
			'local_rest_isolation' => self::gate( ! empty( $rest['ready'] ), ! empty( $rest['ready'] ) ? 'ready' : 'blocked', true, isset( $rest['contract'] ) ? $rest['contract'] : 'mad4b.rest-compatibility.v2', ! empty( $rest['ready'] ) ? array() : array( 'local_rest_isolation_not_ready' ) ),
			'external_wpml' => self::gate( ! empty( $wpml['verified'] ), isset( $wpml['state'] ) ? $wpml['state'] : 'pending_external_evidence', empty( $wpml['stale'] ), self::WPML_RECEIPT_CONTRACT, ! empty( $wpml['verified'] ) ? array() : array( 'external_wpml_evidence_required' ) ),
			'skills_runtime' => self::gate( ! empty( $skills['ready'] ), ! empty( $skills['ready'] ) ? 'ready' : 'blocked', true, isset( $skills['contract'] ) ? $skills['contract'] : 'mad4b.skill-runtime-certification.v2', ! empty( $skills['ready'] ) ? array() : array( 'skills_runtime_not_ready' ) ),
			'snapshot_local' => self::gate( ! empty( $snapshot_local['ready'] ), ! empty( $snapshot_local['ready'] ) ? 'ready' : 'blocked', true, isset( $snapshot_local['contract'] ) ? $snapshot_local['contract'] : 'mad4b.skill-snapshot-identity.v1', ! empty( $snapshot_local['ready'] ) ? array() : array( 'local_snapshot_unavailable' ) ),
			'snapshot_external' => self::gate( ! empty( $snapshot_external['exact_match'] ), ! empty( $snapshot_external['exact_match'] ) ? 'ready' : 'pending_external_evidence', ! empty( $snapshot_external['exact_match'] ), self::SNAPSHOT_VERIFY_CONTRACT, ! empty( $snapshot_external['exact_match'] ) ? array() : array( 'client_snapshot_token_required_or_mismatch' ) ),
			'provider_projection' => self::gate( empty( $provider_leaks ), empty( $provider_leaks ) ? 'ready' : 'provider_leak_detected', ! empty( $external['build_fingerprint_match'] ), self::EXTERNAL_ATTESTATION_CONTRACT, empty( $provider_leaks ) ? array() : array( 'provider_blocked_tool_leak' ) ),
			'write_authority' => self::gate( ! empty( $write_authority['ready'] ), ! empty( $write_authority['ready'] ) ? 'ready' : 'blocked', true, isset( $write_authority['contract'] ) ? $write_authority['contract'] : 'mad4b.write-authority', ! empty( $write_authority['ready'] ) ? array() : array( 'write_authority_not_ready' ) ),
			'write_runtime_certification' => self::gate( ! empty( $write_runtime['ready'] ), ! empty( $write_runtime['ready'] ) ? 'ready' : 'blocked', true, isset( $write_runtime['contract'] ) ? $write_runtime['contract'] : 'mad4b.write-runtime-certification.v2', ! empty( $write_runtime['ready'] ) ? array() : array( 'write_runtime_not_ready' ) ),
			'external_handshake' => self::gate( ! empty( $external['verified'] ), isset( $external['status'] ) ? $external['status'] : 'pending_external_evidence', ! empty( $external['build_fingerprint_match'] ), self::EXTERNAL_ATTESTATION_CONTRACT, ! empty( $external['verified'] ) ? array() : array( 'real_external_session_required' ) ),
			'external_inventory_parity' => self::gate( ! empty( $external['verified'] ) && ! empty( $external['inventory_match'] ), ! empty( $external['verified'] ) ? 'ready' : 'pending_external_evidence', ! empty( $external['build_fingerprint_match'] ), self::EXTERNAL_ATTESTATION_CONTRACT, ! empty( $external['verified'] ) ? array() : array( 'exact_external_inventory_required' ) ),
			'mutation_acceptance' => self::gate( false, 'pending_external_evidence', false, 'external-live-acceptance', array( 'one_time_mutation_acceptance_not_observed_locally' ) ),
			'production_unchanged' => self::gate( false, 'pending_external_evidence', false, 'external-production-proof', array( 'production_unchanged_requires_external_read_only_proof' ) ),
		);
		$all_ready = true;
		foreach ( $gates as $gate ) if ( empty( $gate['ready'] ) ) { $all_ready = false; break; }
		return array( 'contract' => self::AGGREGATE_CONTRACT, 'ready' => $all_ready, 'state' => $all_ready ? 'ready' : 'pending_or_blocked', 'gates' => $gates, 'external_facts_self_certified' => false );
	}

	private static function gate( $ready, $state, $fresh, $source_contract, array $blockers ) {
		return array( 'state' => (string) $state, 'ready' => (bool) $ready, 'fresh' => (bool) $fresh, 'source_contract' => (string) $source_contract, 'blockers' => array_values( $blockers ), 'observed_at' => gmdate( 'Y-m-d H:i:s' ) );
	}

	public static function staging_capture_allowed() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) return false;
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) return false;
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$host = function_exists( 'home_url' ) ? wp_parse_url( home_url( '/' ), PHP_URL_HOST ) : '';
		return 'staging' === $environment && self::STAGING_HOST === strtolower( (string) $host );
	}
}
