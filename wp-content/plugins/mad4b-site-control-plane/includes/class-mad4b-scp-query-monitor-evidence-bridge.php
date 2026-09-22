<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Query Monitor-backed Live Acceptance warning evidence.
 *
 * Query Monitor already owns the doing_it_wrong/deprecation hooks and preserves
 * the original filtered call stack. MAD4B must not register duplicate listeners
 * on those hooks because its observer frame can contaminate attribution and it
 * appears as a concerned-hook owner in Query Monitor itself. This bridge removes
 * those duplicate listeners and imports Query Monitor's current-request evidence
 * just before the HTML dispatcher processes/output its collectors.
 */
final class MAD4B_SCP_Query_Monitor_Evidence_Bridge {
	const CONTRACT = 'mad4b.query-monitor-collector-bridge.v1';
	const ATTRIBUTION_CONTRACT = 'mad4b.query-monitor-db-attribution.v1';
	const ATTRIBUTION_OPTION = 'mad4b_scp_qm_db_attribution_v1';
	const ATTRIBUTION_LOADER_MARKER = 'MAD4B bounded Query Monitor db.php loader v1';
	const COLLECTOR_ID = 'doing_it_wrong';

	private static $booted = false;
	private static $captured = false;

	public static function boot_early() {
		if ( self::$booted ) return;
		self::$booted = true;
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ) return;

		// Query Monitor already owns these concern hooks. Keeping MAD4B listeners on
		// them makes MAD4B show up in "Hooks in Use" and makes a self-frame available
		// to the legacy classifier. Remove only our exact callbacks/priorities.
		foreach ( self::legacy_observer_hooks() as $hook => $callback ) {
			remove_action( $hook, array( 'MAD4B_SCP_Live_Acceptance_Observer', $callback ), PHP_INT_MAX );
		}

		// The legacy observer dirties request-local telemetry during bootstrap. The
		// bridge owns persistence now so that collector-derived evidence cannot be
		// overwritten later by the legacy shutdown flush.
		remove_action( 'shutdown', array( 'MAD4B_SCP_Live_Acceptance_Observer', 'flush_observation' ), PHP_INT_MAX );
		add_action( 'shutdown', array( __CLASS__, 'capture_and_flush' ), 8 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_enable_db_attribution' ), 1 );
	}


	public static function db_attribution_status() {
		$dropin = defined( 'WP_CONTENT_DIR' ) ? trailingslashit( WP_CONTENT_DIR ) . 'db.php' : '';
		$source = defined( 'WP_PLUGIN_DIR' ) ? trailingslashit( WP_PLUGIN_DIR ) . 'query-monitor/wp-content/db.php' : '';
		$dropin_exists = '' !== $dropin && ( file_exists( $dropin ) || is_link( $dropin ) );
		$source_exists = '' !== $source && is_readable( $source );
		$ownership = 'none';
		$owned = false;
		if ( $dropin_exists && $source_exists ) {
			$dropin_real = realpath( $dropin );
			$source_real = realpath( $source );
			if ( false !== $dropin_real && false !== $source_real && hash_equals( wp_normalize_path( $source_real ), wp_normalize_path( $dropin_real ) ) ) {
				$owned = true;
				$ownership = 'query_monitor_symlink';
			}
		}
		if ( $dropin_exists && ! $owned && is_readable( $dropin ) ) {
			$prefix = file_get_contents( $dropin, false, null, 0, 32768 );
			if ( is_string( $prefix ) && false !== strpos( $prefix, self::ATTRIBUTION_LOADER_MARKER ) ) {
				$owned = true;
				$ownership = 'mad4b_bounded_loader';
			} elseif ( is_string( $prefix ) && false !== strpos( $prefix, 'QM_DB' ) && false !== strpos( $prefix, 'Query Monitor' ) ) {
				$owned = true;
				$ownership = 'query_monitor_native_dropin';
			}
		}
		$environment = class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'current_environment' )
			? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() )
			: ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' );
		$file_mods_allowed = ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
		$symlink_available = function_exists( 'symlink' ) && ( ! function_exists( 'apply_filters' ) || (bool) apply_filters( 'mad4b_qm_symlink_available', true ) );
		$loader_available = function_exists( 'fopen' ) && function_exists( 'fwrite' ) && ( ! function_exists( 'apply_filters' ) || (bool) apply_filters( 'mad4b_qm_bounded_loader_available', true ) );
		$writable = defined( 'WP_CONTENT_DIR' ) && is_dir( WP_CONTENT_DIR ) && is_writable( WP_CONTENT_DIR );
		$ready = class_exists( 'QM_DB', false );
		$bootstrap_available = $symlink_available || $loader_available;
		$state = $ready ? 'ready'
			: ( $dropin_exists && ! $owned ? 'conflicting_db_dropin'
			: ( $dropin_exists && $owned ? 'query_monitor_dropin_reload_required'
			: ( ! defined( 'QM_VERSION' ) ? 'query_monitor_inactive'
			: ( ! $source_exists ? 'query_monitor_dropin_source_missing'
			: ( ! $file_mods_allowed ? 'file_modifications_disabled'
			: ( ! $bootstrap_available ? 'attribution_bootstrap_unavailable'
			: ( ! $writable ? 'wp_content_not_writable' : 'enablement_available' ) ) ) ) ) ) );
		return array(
			'contract' => self::ATTRIBUTION_CONTRACT,
			'read_only' => true,
			'mutation_performed' => false,
			'environment' => $environment,
			'query_monitor_active' => defined( 'QM_VERSION' ),
			'qm_db_active' => $ready,
			'dropin_exists' => $dropin_exists,
			'dropin_owned_by_query_monitor' => $owned,
			'dropin_ownership' => $ownership,
			'dropin_conflict' => $dropin_exists && ! $owned,
			'dropin_source_exists' => $source_exists,
			'file_modifications_allowed' => $file_mods_allowed,
			'symlink_available' => $symlink_available,
			'bounded_loader_available' => $loader_available,
			'bootstrap_available' => $bootstrap_available,
			'wp_content_writable' => $writable,
			'safe_to_enable' => 'staging' === $environment && defined( 'QM_VERSION' ) && $source_exists && ! $dropin_exists && $file_mods_allowed && $bootstrap_available && $writable,
			'ready' => $ready,
			'state' => $state,
			'caller_component_trace_expected' => $ready,
			'production_changed' => false,
		);
	}

	public static function maybe_enable_db_attribution() {
		$status = self::db_attribution_status();
		if ( 'staging' !== (string) $status['environment'] || ! is_admin() || ! current_user_can( 'manage_options' ) ) return $status;
		if ( ! empty( $status['ready'] ) || empty( $status['safe_to_enable'] ) ) return $status;
		if ( ! defined( 'WP_CONTENT_DIR' ) || ! defined( 'WP_PLUGIN_DIR' ) ) return $status;
		$dropin = trailingslashit( WP_CONTENT_DIR ) . 'db.php';
		$source = trailingslashit( WP_PLUGIN_DIR ) . 'query-monitor/wp-content/db.php';
		if ( file_exists( $dropin ) || is_link( $dropin ) || ! is_readable( $source ) ) return self::db_attribution_status();

		$created = false;
		$method = '';
		$error = '';
		if ( ! empty( $status['symlink_available'] ) ) {
			$created = @symlink( $source, $dropin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- bounded best-effort Staging bootstrap.
			if ( $created ) $method = 'symlink';
		}
		if ( ! $created && ! empty( $status['bounded_loader_available'] ) && ! file_exists( $dropin ) && ! is_link( $dropin ) ) {
			$loader = "<?php\n/* " . self::ATTRIBUTION_LOADER_MARKER . " */\n"
				. "\$mad4b_qm_dropin = __DIR__ . '/plugins/query-monitor/wp-content/db.php';\n"
				. "if ( is_readable( \$mad4b_qm_dropin ) ) { require \$mad4b_qm_dropin; }\n";
			$handle = @fopen( $dropin, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- exclusive create guarantees no existing db.php is replaced.
			if ( false !== $handle ) {
				$bytes = @fwrite( $handle, $loader ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- bounded loader content only.
				@fclose( $handle );
				$created = is_int( $bytes ) && strlen( $loader ) === $bytes;
				$method = $created ? 'bounded_loader' : '';
				if ( ! $created && file_exists( $dropin ) ) @unlink( $dropin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- remove only the incomplete file created by this call.
			} else {
				$error = 'exclusive_loader_create_failed';
			}
		}

		clearstatcache( true, $dropin );
		$record = array(
			'contract' => 'mad4b.query-monitor-db-attribution-bootstrap.v2',
			'environment' => 'staging',
			'created' => (bool) $created,
			'method' => $method,
			'error' => $error,
			'created_at' => gmdate( 'c' ),
			'reload_required' => (bool) $created,
			'foreign_dropin_replaced' => false,
			'production_changed' => false,
		);
		update_option( self::ATTRIBUTION_OPTION, $record, false );
		$status = self::db_attribution_status();
		$status['bootstrap'] = $record;
		return $status;
	}

	/** @internal Pure wiring map used by regression tests. */
	public static function legacy_observer_hooks() {
		return array(
			'doing_it_wrong_run' => 'observe_doing_it_wrong',
			'deprecated_function_run' => 'observe_deprecated_function',
			'deprecated_argument_run' => 'observe_deprecated_argument',
			'deprecated_hook_run' => 'observe_deprecated_hook',
			'deprecated_class_run' => 'observe_deprecated_class',
		);
	}

	public static function capture_and_flush() {
		if ( self::$captured ) return;
		self::$captured = true;
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! MAD4B_SCP_Live_Acceptance_Observer::staging_capture_allowed() ) return;

		$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		$build = isset( $provenance['build_fingerprint'] ) ? strtolower( (string) $provenance['build_fingerprint'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $build ) ) return;

		$telemetry = self::load_telemetry( $build );
		$class = self::request_class();
		$telemetry['observed_request_count'] = isset( $telemetry['observed_request_count'] ) ? (int) $telemetry['observed_request_count'] + 1 : 1;
		if ( ! isset( $telemetry['request_coverage'][ $class ] ) ) $telemetry['request_coverage'][ $class ] = 0;
		$telemetry['request_coverage'][ $class ]++;
		$telemetry['last_observed_at'] = gmdate( 'Y-m-d H:i:s' );
		$sample = self::performance_sample( $class );
		if ( ! isset( $telemetry['performance'] ) || ! is_array( $telemetry['performance'] ) ) $telemetry['performance'] = self::empty_performance();
		if ( ! isset( $telemetry['performance']['samples'] ) || ! is_array( $telemetry['performance']['samples'] ) ) $telemetry['performance']['samples'] = array();
		$telemetry['performance']['samples'][] = $sample;
		$telemetry['performance']['samples'] = array_slice( $telemetry['performance']['samples'], -32 );
		$telemetry['performance']['last_by_class'][ $class ] = $sample;
		if ( 'frontend' === $class ) $telemetry['performance']['frontend_observed'] = true;
		if ( 'rest' === $class ) $telemetry['performance']['rest_observed'] = true;

		foreach ( self::query_monitor_events() as $entry ) {
			$event_type = isset( $entry['type'] ) ? sanitize_key( (string) $entry['type'] ) : '';
			$message = isset( $entry['message'] ) ? (string) $entry['message'] : '';
			$function_name = isset( $entry['subject'] ) ? (string) $entry['subject'] : '';
			$trace = isset( $entry['trace'] ) && is_array( $entry['trace'] ) ? $entry['trace'] : array();
			if ( '' === $event_type ) continue;

			$classification = MAD4B_SCP_Live_Acceptance_Observer::classify_warning_for_test( $event_type, $function_name, $message, $trace );
			if ( self::message_indicates_pre_init( $message ) ) $classification['pre_init_abilities_violation'] = true;
			$bucket = isset( $classification['bucket'] ) ? sanitize_key( (string) $classification['bucket'] ) : 'unknown';
			if ( ! isset( $telemetry['counters'][ $bucket ] ) || ! is_array( $telemetry['counters'][ $bucket ] ) ) $telemetry['counters'][ $bucket ] = array();
			if ( ! isset( $telemetry['counters'][ $bucket ][ $event_type ] ) ) $telemetry['counters'][ $bucket ][ $event_type ] = 0;
			$telemetry['counters'][ $bucket ][ $event_type ]++;
			if ( ! empty( $classification['ability_not_found'] ) ) self::increment_counter( $telemetry, 'mad4b', 'ability_not_found' );
			if ( ! empty( $classification['wp_get_ability_missing'] ) ) self::increment_counter( $telemetry, 'mad4b', 'wp_get_ability_missing' );
			if ( ! empty( $classification['pre_init_abilities_violation'] ) ) self::increment_counter( $telemetry, 'mad4b', 'pre_init_abilities_violation' );
			if ( ! empty( $classification['fluentform_action_scheduler'] ) ) self::increment_counter( $telemetry, 'third_party', 'fluentform_action_scheduler' );

			$telemetry['events'][] = array(
				'type' => $event_type,
				'classification' => $bucket,
				'severity' => isset( $classification['severity'] ) ? sanitize_key( (string) $classification['severity'] ) : 'observed',
				'function' => self::safe_identifier( $function_name ),
				'message' => MAD4B_SCP_Live_Acceptance_Observer::sanitize_warning_message( $message ),
				'component' => isset( $classification['component'] ) ? self::safe_identifier( $classification['component'] ) : '',
				'plugin_slug' => isset( $classification['plugin_slug'] ) ? self::safe_identifier( $classification['plugin_slug'] ) : '',
				'lifecycle_phase' => self::message_indicates_pre_init( $message ) ? 'pre_init' : 'post_init',
				'request_class' => $class,
				'observed_at' => gmdate( 'Y-m-d H:i:s' ),
				'build_fingerprint' => $build,
				'callers' => isset( $classification['callers'] ) && is_array( $classification['callers'] ) ? array_slice( $classification['callers'], 0, 6 ) : array(),
				'evidence_source' => self::CONTRACT,
			);
		}

		$telemetry['events'] = array_slice( isset( $telemetry['events'] ) && is_array( $telemetry['events'] ) ? $telemetry['events'] : array(), -1 * MAD4B_SCP_Live_Acceptance_Observer::MAX_EVENTS );
		update_option( MAD4B_SCP_Live_Acceptance_Observer::TELEMETRY_OPTION, $telemetry, false );
	}

	/** @internal Pure seam for runtime regressions. */
	public static function normalize_qm_event_for_test( $wrong ) {
		return self::normalize_qm_event( $wrong );
	}

	private static function query_monitor_events() {
		if ( ! defined( 'QM_VERSION' ) || ! class_exists( 'QM_Collectors' ) || ! method_exists( 'QM_Collectors', 'get' ) ) return array();
		$collector = QM_Collectors::get( self::COLLECTOR_ID );
		if ( ! is_object( $collector ) || ! method_exists( $collector, 'get_data' ) ) return array();
		$data = $collector->get_data();
		$actions = is_object( $data ) && isset( $data->actions ) && is_array( $data->actions ) ? $data->actions : array();
		$events = array();
		foreach ( $actions as $wrong ) {
			$event = self::normalize_qm_event( $wrong );
			if ( is_array( $event ) ) $events[] = $event;
		}
		return $events;
	}

	private static function normalize_qm_event( $wrong ) {
		if ( ! is_object( $wrong ) || ! method_exists( $wrong, 'get_message' ) || ! method_exists( $wrong, 'get_trace' ) ) return null;
		$type = self::event_type_for_class( get_class( $wrong ) );
		if ( '' === $type ) return null;
		$message = (string) $wrong->get_message();
		$trace_object = $wrong->get_trace();
		$trace = self::trace_to_array( $trace_object );
		$subject = self::subject_from_message( $type, $message );
		if ( '' === $subject && is_object( $trace_object ) && method_exists( $trace_object, 'get_caller' ) ) {
			$caller = $trace_object->get_caller();
			if ( is_object( $caller ) && isset( $caller->id ) ) $subject = self::safe_identifier( self::strip_call_syntax( (string) $caller->id ) );
		}
		return array( 'type' => $type, 'subject' => $subject, 'message' => $message, 'trace' => $trace );
	}

	private static function event_type_for_class( $class ) {
		$map = array(
			'QM_Doing_It_Wrong_Run' => 'doing_it_wrong',
			'QM_Deprecated_Function_Run' => 'deprecated_function',
			'QM_Deprecated_Argument_Run' => 'deprecated_argument',
			'QM_Deprecated_Hook_Run' => 'deprecated_hook',
			'QM_Deprecated_Class_Run' => 'deprecated_class',
		);
		return isset( $map[ $class ] ) ? $map[ $class ] : '';
	}

	private static function subject_from_message( $type, $message ) {
		$patterns = array(
			'doing_it_wrong' => '/^Function\\s+([^\\s]+)\\s+was called incorrectly\\./i',
			'deprecated_function' => '/^Function\\s+([^\\s]+)\\s+is deprecated/i',
			'deprecated_argument' => '/^Function\\s+([^\\s]+)\\s+was called with an argument/i',
			'deprecated_hook' => '/^Hook\\s+([^\\s]+)\\s+is deprecated/i',
			'deprecated_class' => '/^Class\\s+([^\\s]+)\\s+is deprecated/i',
		);
		if ( ! isset( $patterns[ $type ] ) || ! preg_match( $patterns[ $type ], (string) $message, $matches ) ) return '';
		return self::safe_identifier( self::strip_call_syntax( $matches[1] ) );
	}

	private static function trace_to_array( $trace_object ) {
		if ( ! is_object( $trace_object ) || ! method_exists( $trace_object, 'get_filtered_trace' ) ) return array();
		$frames = $trace_object->get_filtered_trace();
		if ( ! is_array( $frames ) ) return array();
		$out = array();
		foreach ( array_slice( $frames, 0, 12 ) as $frame ) {
			if ( ! is_object( $frame ) ) continue;
			$id = isset( $frame->id ) ? self::strip_call_syntax( (string) $frame->id ) : '';
			$class = '';
			$function = $id;
			if ( false !== strpos( $id, '::' ) ) list( $class, $function ) = explode( '::', $id, 2 );
			elseif ( false !== strpos( $id, '->' ) ) list( $class, $function ) = explode( '->', $id, 2 );
			$out[] = array(
				'file' => isset( $frame->file ) ? (string) $frame->file : '',
				'class' => self::safe_identifier( $class ),
				'function' => self::safe_identifier( $function ),
			);
		}
		return $out;
	}

	private static function load_telemetry( $build ) {
		$stored = get_option( MAD4B_SCP_Live_Acceptance_Observer::TELEMETRY_OPTION, array() );
		$valid = is_array( $stored )
			&& isset( $stored['build_fingerprint'], $stored['capture_started_at'] )
			&& hash_equals( (string) $stored['build_fingerprint'], (string) $build );
		$started = $valid ? strtotime( (string) $stored['capture_started_at'] . ' UTC' ) : false;
		if ( ! $valid || false === $started || ( time() - $started ) > MAD4B_SCP_Live_Acceptance_Observer::TELEMETRY_TTL ) {
			return self::empty_telemetry( $build );
		}
		return $stored;
	}

	private static function empty_telemetry( $build ) {
		return array(
			'contract' => MAD4B_SCP_Live_Acceptance_Observer::QUERY_MONITOR_CONTRACT,
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
			'performance' => self::empty_performance(),
		);
	}

	private static function empty_performance() {
		return array(
			'contract' => 'mad4b.frontend-performance-evidence.v1',
			'frontend_observed' => false,
			'rest_observed' => false,
			'samples' => array(),
			'last_by_class' => array(),
		);
	}

	private static function performance_sample( $class ) {
		$started = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) && is_numeric( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0.0;
		$elapsed = $started > 0 ? max( 0.0, ( microtime( true ) - $started ) * 1000.0 ) : 0.0;
		$queries = function_exists( 'get_num_queries' ) ? max( 0, (int) get_num_queries() ) : 0;
		$peak = function_exists( 'memory_get_peak_usage' ) ? max( 0, (int) memory_get_peak_usage( true ) ) : 0;
		$current_memory = function_exists( 'memory_get_usage' ) ? max( 0, (int) memory_get_usage( true ) ) : 0;
		return array(
			'request_class' => (string) $class,
			'server_elapsed_ms' => round( $elapsed, 3 ),
			'db_queries' => $queries,
			'peak_memory_bytes' => $peak,
			'current_memory_bytes' => $current_memory,
			'db_profile' => self::query_performance_profile(),
			'observed_at' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	private static function query_performance_profile() {
		global $wpdb;
		$profile = array(
			'contract' => 'mad4b.query-performance-profile.v1',
			'available' => false,
			'query_rows_observed' => 0,
			'total_db_time_ms' => 0.0,
			'slow_query_threshold_ms' => 50.0,
			'slow_query_count' => 0,
			'duplicate_query_count' => 0,
			'duplicate_group_count' => 0,
			'extended_trace_count' => 0,
			'top_callers' => array(),
			'top_components' => array(),
			'slow_queries' => array(),
			'duplicate_groups' => array(),
			'raw_sql_returned' => false,
		);
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->queries ) || ! is_array( $wpdb->queries ) || empty( $wpdb->queries ) ) return $profile;

		$callers = array();
		$components = array();
		$fingerprints = array();
		$slow = array();
		$total_time = 0.0;
		$observed = 0;
		$extended = 0;

		foreach ( $wpdb->queries as $query ) {
			$sql = '';
			$seconds = 0.0;
			$stack = '';
			$trace = null;
			if ( is_array( $query ) && isset( $query[0], $query[1], $query[2] ) ) {
				$sql = (string) $query[0];
				$seconds = is_numeric( $query[1] ) ? max( 0.0, (float) $query[1] ) : 0.0;
				$stack = (string) $query[2];
				if ( isset( $query['trace'] ) && is_object( $query['trace'] ) ) $trace = $query['trace'];
			} elseif ( is_array( $query ) && isset( $query['query'], $query['elapsed'] ) ) {
				$sql = (string) $query['query'];
				$seconds = is_numeric( $query['elapsed'] ) ? max( 0.0, (float) $query['elapsed'] ) : 0.0;
				$stack = isset( $query['debug'] ) ? (string) $query['debug'] : '';
			} else {
				continue;
			}
			++$observed;
			$total_time += $seconds;
			$fingerprint = hash( 'sha256', trim( $sql ) );
			if ( ! isset( $fingerprints[ $fingerprint ] ) ) $fingerprints[ $fingerprint ] = array( 'count' => 0, 'time_ms' => 0.0 );
			$fingerprints[ $fingerprint ]['count']++;
			$fingerprints[ $fingerprint ]['time_ms'] += $seconds * 1000.0;

			$caller = '';
			$component = '';
			if ( is_object( $trace ) ) {
				++$extended;
				if ( method_exists( $trace, 'get_caller' ) ) {
					$caller_frame = $trace->get_caller();
					if ( is_object( $caller_frame ) && isset( $caller_frame->id ) ) $caller = self::safe_identifier( self::strip_call_syntax( (string) $caller_frame->id ) );
				}
				if ( method_exists( $trace, 'get_component' ) ) {
					try {
						$component_object = $trace->get_component();
						if ( is_object( $component_object ) ) {
							$type = isset( $component_object->type ) ? sanitize_key( (string) $component_object->type ) : '';
							$name = method_exists( $component_object, 'get_name' ) ? sanitize_text_field( (string) $component_object->get_name() ) : '';
							$component = trim( $type . ( '' !== $name ? ':' . $name : '' ), ':' );
						}
					} catch ( Throwable $e ) {
						$component = '';
					}
				}
			}
			if ( '' === $caller && '' !== $stack ) {
				$parts = array_values( array_filter( array_map( 'trim', explode( ',', $stack ) ) ) );
				if ( ! empty( $parts ) ) $caller = self::safe_identifier( self::strip_call_syntax( (string) end( $parts ) ) );
			}
			if ( '' === $caller ) $caller = 'unknown';
			if ( '' === $component ) $component = 'unknown';

			if ( ! isset( $callers[ $caller ] ) ) $callers[ $caller ] = array( 'caller' => $caller, 'count' => 0, 'time_ms' => 0.0 );
			$callers[ $caller ]['count']++;
			$callers[ $caller ]['time_ms'] += $seconds * 1000.0;
			if ( ! isset( $components[ $component ] ) ) $components[ $component ] = array( 'component' => $component, 'count' => 0, 'time_ms' => 0.0 );
			$components[ $component ]['count']++;
			$components[ $component ]['time_ms'] += $seconds * 1000.0;

			if ( $seconds >= 0.05 ) {
				$slow[] = array(
					'query_fingerprint' => $fingerprint,
					'elapsed_ms' => round( $seconds * 1000.0, 3 ),
					'caller' => $caller,
					'component' => $component,
				);
			}
		}

		$duplicates = array();
		$duplicate_count = 0;
		foreach ( $fingerprints as $fingerprint => $entry ) {
			if ( $entry['count'] <= 1 ) continue;
			$duplicate_count += (int) $entry['count'] - 1;
			$duplicates[] = array(
				'query_fingerprint' => $fingerprint,
				'count' => (int) $entry['count'],
				'total_time_ms' => round( (float) $entry['time_ms'], 3 ),
			);
		}
		usort( $duplicates, static function ( $a, $b ) {
			if ( $a['count'] === $b['count'] ) return $b['total_time_ms'] <=> $a['total_time_ms'];
			return $b['count'] <=> $a['count'];
		} );
		usort( $slow, static function ( $a, $b ) { return $b['elapsed_ms'] <=> $a['elapsed_ms']; } );
		$sort_rank = static function ( $a, $b ) {
			if ( $a['count'] === $b['count'] ) return $b['time_ms'] <=> $a['time_ms'];
			return $b['count'] <=> $a['count'];
		};
		$callers = array_values( $callers );
		$components = array_values( $components );
		usort( $callers, $sort_rank );
		usort( $components, $sort_rank );
		foreach ( $callers as &$entry ) $entry['time_ms'] = round( (float) $entry['time_ms'], 3 );
		unset( $entry );
		foreach ( $components as &$entry ) $entry['time_ms'] = round( (float) $entry['time_ms'], 3 );
		unset( $entry );

		$profile['available'] = true;
		$profile['query_rows_observed'] = $observed;
		$profile['total_db_time_ms'] = round( $total_time * 1000.0, 3 );
		$profile['slow_query_count'] = count( $slow );
		$profile['duplicate_query_count'] = $duplicate_count;
		$profile['duplicate_group_count'] = count( $duplicates );
		$profile['extended_trace_count'] = $extended;
		$profile['top_callers'] = array_slice( $callers, 0, 8 );
		$profile['top_components'] = array_slice( $components, 0, 8 );
		$profile['slow_queries'] = array_slice( $slow, 0, 12 );
		$profile['duplicate_groups'] = array_slice( $duplicates, 0, 12 );
		return $profile;
	}

	private static function increment_counter( array &$telemetry, $bucket, $key ) {
		if ( ! isset( $telemetry['counters'][ $bucket ] ) || ! is_array( $telemetry['counters'][ $bucket ] ) ) $telemetry['counters'][ $bucket ] = array();
		if ( ! isset( $telemetry['counters'][ $bucket ][ $key ] ) ) $telemetry['counters'][ $bucket ][ $key ] = 0;
		$telemetry['counters'][ $bucket ][ $key ]++;
	}

	private static function message_indicates_pre_init( $message ) {
		$message = strtolower( (string) $message );
		foreach ( array( 'before init', 'before the init action', 'before `init`', 'before abilities init', 'before the abilities api' ) as $needle ) {
			if ( false !== strpos( $message, $needle ) ) return true;
		}
		return false;
	}

	private static function request_class() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( false !== strpos( $uri, '/mcp/' ) || false !== strpos( $uri, '/wp-json/mcp/' ) ) return 'mcp';
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return 'rest';
		if ( function_exists( 'is_admin' ) && is_admin() ) return 'wp_admin';
		return 'frontend';
	}

	private static function strip_call_syntax( $value ) {
		return preg_replace( '/\\(.*$/', '', trim( (string) $value ) );
	}

	private static function safe_identifier( $value ) {
		$value = preg_replace( '/[^A-Za-z0-9_\\\\:\-\.]/', '', (string) $value );
		return strlen( $value ) > 120 ? substr( $value, 0, 120 ) : $value;
	}
}
