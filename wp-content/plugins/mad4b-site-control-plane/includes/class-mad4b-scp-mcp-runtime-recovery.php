<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Explicit, audited next-request recovery; no MCP/OAuth authority changes. */
final class MAD4B_SCP_MCP_Runtime_Recovery {
	const ACTION = 'mad4b_repair_mcp_runtime';
	const CONFLICT_ACTION = 'mad4b_repair_mcp_runtime_conflict';
	const CONFLICT_CONFIRMATION = 'REPLACE UNKNOWN MCP BOOTSTRAP';
	const HOOK = 'mad4b_scp_mcp_runtime_recovery';
	const OPTION = 'mad4b_scp_mcp_runtime_recovery_v1';
	private static $active = false;
	private static $conflict_expected_sha256 = '';
	private static $conflict_recovery_source = '';

	public static function boot() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_' . self::CONFLICT_ACTION, array( __CLASS__, 'handle_conflict' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 20, 2 );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_conflict_recovery' ), 50 );
		add_action( self::HOOK, array( __CLASS__, 'run_cron' ) );
		add_action( 'mad4b_scp_site_profile_saved', array( __CLASS__, 'profile_saved' ) );
		register_activation_hook( MAD4B_SCP_FILE, array( __CLASS__, 'schedule' ) );
	}

	public static function active() { return self::$active; }
	public static function conflict_expected_sha256() { return self::$active ? self::$conflict_expected_sha256 : ''; }
	public static function conflict_recovery_source() { return self::$active ? self::$conflict_recovery_source : ''; }

	private static function configured_topology_id( $constant_name ) {
		if ( ! defined( $constant_name ) ) return '';
		$value = trim( (string) constant( $constant_name ) );
		return 1 === preg_match( '/^[A-Za-z0-9._:-]{1,128}$/D', $value ) ? $value : '';
	}

    /**
     * PERMANENT TOPOLOGY CONTRACT:
     * Host Registry, not the number of visible containers, determines whether
     * the company uses one node or multiple required nodes. No fake cluster,
     * no auto-created node ID and no inferred inventory membership.
     * An UNKNOWN topology NEVER gets a multi-node PASS. This readback never
     * issues grants, authorizes publication, or changes Broker/Executor jobs.
     */
    public static function topology_verdict( array $local, array $registry = array(), array $receipts = array() ) {
        $local_verified = ! empty( $local['runtime_execution_verified'] )
            && ! empty( $local['site_binding_ready'] )
            && ! empty( $local['target_build'] );
        $result = array(
            'contract' => 'mad4b.mcp-topology-verdict.v1',
            'local_runtime' => $local_verified ? 'LOCAL_VERIFIED' : 'LOCAL_NOT_VERIFIED',
            'topology' => 'UNKNOWN',
            'cluster_aggregation_required' => null,
            'cluster_readback' => 'NOT_APPLICABLE_UNTIL_TOPOLOGY_DISCOVERED',
            'required_node_count' => null,
            'verified_node_count' => 0,
            'missing_node_ids' => array(),
            'deployment_complete' => false,
            'business_completion_allowed' => false,
            'read_only' => true,
            'mutation_performed' => false,
        );
        $expected_origin = (string) ( $local['origin_sha256'] ?? '' );
        $expected_site = (string) ( $local['site_uuid'] ?? '' );
        $expected_env = (string) ( $local['environment'] ?? '' );
        $expected_build = (string) ( $local['target_build'] ?? '' );
        $status = (string) ( $registry['state'] ?? '' );
        $kind = (string) ( $registry['topology'] ?? '' );
        $required = $registry['required_node_ids'] ?? null;
        $registry_valid = ( $registry['contract'] ?? '' ) === 'mad4b.company-host-topology-registry.v1'
            && $status === 'VERIFIED'
            && ! empty( $registry['registration_readback_verified'] )
            && ( $registry['site_uuid'] ?? '' ) === $expected_site
            && ( $registry['environment'] ?? '' ) === $expected_env
            && ( $registry['origin_sha256'] ?? '' ) === $expected_origin
            && ( $registry['target_build'] ?? '' ) === $expected_build
            && is_array( $required ) && ! empty( $required )
            && count( $required ) <= 64 && count( $required ) === count( array_unique( $required ) );
        if ( $registry_valid ) {
            foreach ( $required as $id ) {
                if ( ! is_string( $id ) || ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/D', $id ) ) {
                    $registry_valid = false;
                    break;
                }
            }
        }
        if ( ! $registry_valid || ! in_array( $kind, array( 'single_node', 'multi_node' ), true ) ) {
            $result['topology_reason'] = 'HOST_REGISTRY_MEMBERSHIP_NOT_VERIFIED';
            return $result;
        }
        $local_node = (string) ( $local['node_id'] ?? '' );
        if ( $local_node === '' || ! in_array( $local_node, $required, true ) ) {
            $result['topology_reason'] = 'LOCAL_NODE_NOT_IN_VERIFIED_REGISTRY';
            return $result;
        }
        if ( $kind === 'single_node' && count( $required ) === 1 ) {
            $result['topology'] = 'SINGLE_NODE_VERIFIED';
            $result['cluster_aggregation_required'] = false;
            $result['cluster_readback'] = 'NOT_APPLICABLE_SINGLE_NODE';
            $result['required_node_count'] = 1;
            $result['verified_node_count'] = $local_verified ? 1 : 0;
            $result['deployment_complete'] = $local_verified;
            return $result;
        }
        $cluster_id = (string) ( $registry['cluster_id'] ?? '' );
        if ( $kind !== 'multi_node' || count( $required ) < 2
            || $cluster_id === ''
            || $cluster_id !== (string) ( $local['cluster_id'] ?? '' ) ) {
            $result['topology_reason'] = 'MULTI_NODE_CLUSTER_MEMBERSHIP_MISMATCH';
            return $result;
        }
        $result['topology'] = 'MULTI_NODE_REGISTERED';
        $result['cluster_aggregation_required'] = true;
        $result['required_node_count'] = count( $required );
        $verified = array();
        $seen = array();
        $invalid_receipts = 0;
        $duplicate_node_receipts = 0;
        foreach ( $receipts as $receipt ) {
            if ( ! is_array( $receipt ) ) continue;
            $node = (string) ( $receipt['node_id'] ?? '' );
            if ( ! in_array( $node, $required, true ) ) {
                ++$invalid_receipts;
                continue;
            }
            if ( isset( $seen[ $node ] ) ) {
                ++$duplicate_node_receipts;
                continue;
            }
            $seen[ $node ] = true;
            $fingerprint = $receipt;
            foreach ( array( 'verified_at', 'evidence_sha256',
                'local_runtime_verdict', 'topology_verdict',
                'cluster_aggregation_required', 'cluster_readback',
                'deployment_complete', 'host_registry_topology_evidence'
            ) as $computed_field ) unset( $fingerprint[ $computed_field ] );
            $encoded = wp_json_encode( $fingerprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            $receipt_digest_valid = is_string( $encoded )
                && preg_match( '/^[a-f0-9]{64}$/D', (string) ( $receipt['evidence_sha256'] ?? '' ) )
                && hash_equals( hash( 'sha256', $encoded ), $receipt['evidence_sha256'] );
            if ( ! $receipt_digest_valid ) {
                ++$invalid_receipts;
                continue;
            }
            if ( empty( $receipt['runtime_execution_verified'] )
                || empty( $receipt['site_binding_ready'] )
                || ( $receipt['site_uuid'] ?? '' ) !== $expected_site
                || ( $receipt['environment'] ?? '' ) !== $expected_env
                || ( $receipt['cluster_id'] ?? '' ) !== $cluster_id
                || ( $receipt['target_build'] ?? '' ) !== $expected_build
                || ( $receipt['origin_sha256'] ?? '' ) !== $expected_origin
                || ( $receipt['expected_mu_sha256'] ?? '' ) === ''
                || ( $receipt['expected_mu_sha256'] ?? '' ) !== ( $receipt['observed_mu_sha256'] ?? '' )
                || ( $receipt['expected_mu_sha256'] ?? '' ) !== ( $local['expected_mu_sha256'] ?? '' ) ) {
                ++$invalid_receipts;
                continue;
            }
            $verified[ $node ] = true;
        }
        $result['verified_node_count'] = count( $verified );
        $result['missing_node_ids'] = array_values( array_diff( $required, array_keys( $verified ) ) );
        $result['duplicate_node_receipt_count'] = $duplicate_node_receipts;
        $result['invalid_node_receipt_count'] = $invalid_receipts;
        $result['cluster_readback'] = $duplicate_node_receipts || $invalid_receipts
            ? 'INVALID_OR_DUPLICATE_NODE_RECEIPTS'
            : ( empty( $result['missing_node_ids'] )
                ? 'CLUSTER_VERIFIED' : 'PENDING_REQUIRED_NODE_RECEIPTS' );
        $result['deployment_complete'] = $local_verified
            && $result['cluster_readback'] === 'CLUSTER_VERIFIED';
        return $result;
    }

	/**
	 * Current-node evidence is deliberately separate from cluster certification.
	 * A node without explicit cluster/node IDs can be healthy but cannot be
	 * aggregated into a multi-webhead production certificate.
	 */
	public static function node_evidence() {
		$cluster_id = self::configured_topology_id( 'MAD4B_SCP_CLUSTER_ID' );
		$node_id = self::configured_topology_id( 'MAD4B_SCP_NODE_ID' );
		$site_status = class_exists( 'MAD4B_SCP_Site_Profile', false ) && method_exists( 'MAD4B_SCP_Site_Profile', 'status' )
			? MAD4B_SCP_Site_Profile::status()
			: array();
		$site_uuid = isset( $site_status['site_uuid'] ) ? strtolower( trim( (string) $site_status['site_uuid'] ) ) : '';
		$site_profile_revision = isset( $site_status['revision'] ) ? max( 0, (int) $site_status['revision'] ) : 0;
		$site_profile_digest = isset( $site_status['profile_digest'] ) ? strtolower( trim( (string) $site_status['profile_digest'] ) ) : '';
		$environment = isset( $site_status['environment'] ) ? sanitize_key( (string) $site_status['environment'] ) : '';
		$canonical_origin = isset( $site_status['canonical_origin'] ) ? trim( (string) $site_status['canonical_origin'] ) : '';
		$site_binding_ready = 1 === preg_match( '/^[a-f0-9-]{36}$/D', $site_uuid )
			&& $site_profile_revision > 0
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $site_profile_digest )
			&& '' !== $canonical_origin
			&& ! empty( $site_status['authority_ready'] )
			&& MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' );
		$target_build = class_exists( 'MAD4B_SCP_Endpoint_Diagnostic', false )
			? (string) MAD4B_SCP_Endpoint_Diagnostic::build_fingerprint()
			: '';
		$source = defined( 'MAD4B_SCP_DIR' ) ? trailingslashit( MAD4B_SCP_DIR ) . 'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php' : '';
		$destination = defined( 'WPMU_PLUGIN_DIR' ) ? trailingslashit( WPMU_PLUGIN_DIR ) . '000-mad4b-mcp-adapter-bootstrap.php' : '';
		$expected_mu_sha256 = $source && is_readable( $source ) ? (string) hash_file( 'sha256', $source ) : '';
		$observed_mu_sha256 = $destination && is_readable( $destination ) ? (string) hash_file( 'sha256', $destination ) : '';
		$guard = class_exists( 'MAD4B_SCP_MCP_Runtime_Conflict_Guard', false ) ? MAD4B_SCP_MCP_Runtime_Conflict_Guard::status() : array();
		// PERMANENT NODE READBACK CONTRACT:
		// The observational Runtime_Conflict_Guard now intentionally returns
		// runtime_source_observed rather than canonical_runtime outside an
		// explicit repair lifecycle. Do not make this legacy state name hide a
		// fully proven canonical MCP request after the next-request MU refresh.
		// The early MU bootstrap + independently inspected complete class set
		// are the execution evidence; versions, optional release certificates and
		// the guard's label never create authority. Ordinary WP/Rank Math
		// requests must NOT certify this MCP node.
		$mu = isset( $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] )
			&& is_array( $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] )
			? $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] : array();
		$classes = class_exists( 'MAD4B_SCP_MCP_Class_Provenance', false )
			? MAD4B_SCP_MCP_Class_Provenance::status( false, false ) : array();
		$canonical_execution_proven = ! empty( $mu['eligible'] )
			&& ! empty( $mu['request_requires_mcp_runtime'] )
			&& empty( $mu['request_scope_bypassed'] )
			&& empty( $mu['transaction_pending'] )
			&& empty( $mu['runtime_preclaimed'] )
			&& ! empty( $mu['canonical_symbols_pinned'] )
			&& ! empty( $mu['critical_class_set_pinned'] )
			&& ! empty( $mu['adapter_init_hook_bound'] )
			&& ! empty( $mu['runtime_from_official_plugin'] )
			&& (int) ( $mu['critical_class_pin_count'] ?? 0 ) > 0
			&& ! empty( $classes['ready'] )
			&& ! empty( $classes['complete'] )
			&& empty( $classes['mixed_runtime'] )
			&& empty( $classes['failure_count'] )
			&& (int) ( $classes['class_count'] ?? 0 ) >= (int) $mu['critical_class_pin_count'];
		$runtime_execution_verified = $canonical_execution_proven
			&& ! empty( $guard['runtime_from_official_plugin'] )
			&& ! empty( $guard['mu_bootstrap_executed'] )
			&& ! empty( $guard['mu_bootstrap_runtime_from_official_plugin'] )
			&& '' !== $expected_mu_sha256
			&& '' !== $observed_mu_sha256
			&& hash_equals( $expected_mu_sha256, $observed_mu_sha256 );
		$identity_configured = '' !== $cluster_id && '' !== $node_id;
		// PERMANENT ORIGINAL HOST-REGISTRY TRUST BOUNDARY:
		// NEVER trust an option's state=VERIFIED or its SHA alone.
		// Company Manager independently revalidates the original signed
		// Host Bridge inventory on this request. Existing read/MCP/WP-CLI/SSH
		// routes remain independent if no provider inventory is registered.
		$registry = class_exists( 'MAD4B_Company_Host_Topology_Registry_V1', false )
			? MAD4B_Company_Host_Topology_Registry_V1::verified_local_registry(
				$site_uuid, $environment, $canonical_origin
			) : array();
		$registry = is_array( $registry ) ? $registry : array();
		// PERMANENT DYNAMIC NODE IDENTITY:
		// A registered node is selected only by a UNIQUE match against the
		// current Host Bridge installation fingerprint inside the signed
		// inventory. Two webheads with the same filesystem/config fingerprint
		// are ambiguous; never invent their identities from Docker IDs.
		$node_identity_source = $node_id !== '' ? 'explicit_host_configuration' : 'not_verified';
		if ( $registry && class_exists( 'MAD4B_SCP_Host_Bridge', false ) ) {
			$fingerprint = MAD4B_SCP_Host_Bridge::target_fingerprint_readonly();
			$matches = array();
			foreach ( (array) ( $registry['required_nodes'] ?? array() ) as $node ) {
				if ( ! is_array( $node ) || ! in_array(
					(string) ( $node['runtime_role'] ?? '' ),
					array( 'wordpress_mcp', 'wordpress_webhead' ), true
				) ) continue;
				if ( $fingerprint !== '' && hash_equals(
					$fingerprint, (string) ( $node['target_fingerprint'] ?? '' )
				) ) $matches[] = (string) ( $node['node_id'] ?? '' );
			}
			if ( count( $matches ) === 1 ) {
				$verified_node_id = $matches[0];
				if ( $node_id === '' ) {
					$node_id = $verified_node_id;
					$node_identity_source = 'original_signed_host_inventory_fingerprint';
				}
				if ( $node_id === $verified_node_id && $cluster_id === ''
					&& ( $registry['topology'] ?? '' ) === 'multi_node' )
					$cluster_id = (string) ( $registry['cluster_id'] ?? '' );
			}
		}
		$identity_configured = $node_id !== '' &&
			( $cluster_id !== '' || ( $registry['topology'] ?? '' ) === 'single_node' );
		$evidence = array(
			'contract' => 'mad4b.mcp-runtime-node-evidence.v2',
			'cluster_id' => $cluster_id,
			'node_id' => $node_id,
			'cluster_identity_configured' => $identity_configured,
			'node_identity_source' => $node_identity_source,
			'site_uuid' => $site_uuid,
			'site_profile_revision' => $site_profile_revision,
			'site_profile_digest' => $site_profile_digest,
			'environment' => $environment,
			'origin_sha256' => '' !== $canonical_origin ? hash( 'sha256', $canonical_origin ) : '',
			'site_binding_ready' => $site_binding_ready,
			'target_build' => $target_build,
			'expected_mu_sha256' => $expected_mu_sha256,
			'observed_mu_sha256' => $observed_mu_sha256,
			'executed_runtime_provenance' => array(
				'state' => isset( $guard['state'] ) ? sanitize_key( (string) $guard['state'] ) : '',
				'runtime_source' => isset( $guard['runtime_source'] ) ? sanitize_text_field( (string) $guard['runtime_source'] ) : '',
				'runtime_version' => isset( $guard['runtime_version'] ) ? sanitize_text_field( (string) $guard['runtime_version'] ) : '',
				'runtime_from_official_plugin' => ! empty( $guard['runtime_from_official_plugin'] ),
				'mu_bootstrap_executed' => ! empty( $guard['mu_bootstrap_executed'] ),
				'mu_runtime_source' => isset( $guard['mu_bootstrap_runtime_source'] ) ? sanitize_text_field( (string) $guard['mu_bootstrap_runtime_source'] ) : '',
				'mu_runtime_from_official_plugin' => ! empty( $guard['mu_bootstrap_runtime_from_official_plugin'] ),
			),
			'runtime_execution_verified' => $runtime_execution_verified,
			'runtime_execution_verification_source' => $runtime_execution_verified
				? 'canonical_mu_pin_plus_complete_class_provenance_and_exact_hash'
				: 'not_verified_for_this_request',
			'verified_at' => $runtime_execution_verified ? gmdate( 'c' ) : '',
			'eligible_for_cluster_aggregation' => $runtime_execution_verified && $identity_configured && $site_binding_ready && '' !== $target_build,
			'runtime_node_scope' => 'current_node',
			'shared_filesystem_certified' => false,
		);
		$fingerprint = $evidence;
		unset( $fingerprint['verified_at'] );
		$encoded = function_exists( 'wp_json_encode' )
			? wp_json_encode( $fingerprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $fingerprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$evidence['evidence_sha256'] = hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
		// Per-node receipt is valid independent of topology. Whole-deployment
		// success is conditional on verified Host Registry membership and, for
		// multi-node, independently collected Broker receipts.
		$topology = self::topology_verdict( $evidence, $registry );
		$evidence['local_runtime_verdict'] = $topology['local_runtime'];
		$evidence['topology_verdict'] = $topology['topology'];
		$evidence['cluster_aggregation_required'] = $topology['cluster_aggregation_required'];
		$evidence['cluster_readback'] = $topology['cluster_readback'];
		$evidence['deployment_complete'] = $topology['deployment_complete'];
		$evidence['host_registry_topology_evidence'] = ( $topology['topology'] !== 'UNKNOWN' );
		$evidence['host_registry_source'] = $registry
			? 'original_signed_host_inventory_readback' : 'unverified_or_unavailable';
		$evidence['unknown_topology_does_not_disable_local_mcp'] = true;
		$evidence['other_execution_routes_are_independent'] = true;
		return $evidence;
	}

	public static function maybe_schedule_conflict_recovery() {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) return;
		if ( MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()
			|| MAD4B_SCP_MCP_Request_Scope::current_request_is_endpoint_diagnostic_job() ) return;
		// Automatic scheduling is limited to already-proven owned MU generations.
		$managed_stale = false;
		if ( class_exists( 'MAD4B_SCP_Site_Profile', false )
			&& 'staging' === MAD4B_SCP_Site_Profile::current_environment()
			&& MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' )
			&& class_exists( 'MAD4B_SCP_MCP_MU_Bootstrap_Refresh', false ) ) {
			$observed = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::conflict_status();
			$managed_stale = is_array( $observed )
				&& 'managed_mu_stale' === ( $observed['state'] ?? '' )
				&& ! empty( $observed['managed'] )
				&& empty( $observed['blocker'] )
				&& 1 === preg_match( '/^[a-f0-9]{64}$/D', (string) ( $observed['source_sha256'] ?? '' ) )
				&& 1 === preg_match( '/^[a-f0-9]{64}$/D', (string) ( $observed['destination_sha256_before'] ?? '' ) );
		}
		if ( ! $managed_stale ) return;
		// A manual ZIP/FTP update can bypass upgrader_process_complete. Only a
		// previously recognized MAD4B-owned stale MU loader is auto-refreshed.
		// The existing cron recovery retains its lease, audit, CAS and rollback.
		// Never repair an absent or unknown file from an ordinary GET request.
		self::schedule();
	}

	public static function run_cron() {
		$result = self::run();
		if ( is_wp_error( $result ) ) update_option( self::OPTION, array( 'state' => 'blocked', 'blocker' => sanitize_key( $result->get_error_code() ), 'connection_certified' => false ), false );
	}

	public static function after_upgrade( $upgrader, $extra ) {
		if ( ! is_array( $extra ) || 'plugin' !== ( $extra['type'] ?? '' ) ) return;
		$plugins = isset( $extra['plugins'] ) && is_array( $extra['plugins'] ) ? $extra['plugins'] : array( $extra['plugin'] ?? '' );
		$adapter_file = '';
		if ( class_exists( 'MAD4B_SCP_Dependency_Manager' ) && method_exists( 'MAD4B_SCP_Dependency_Manager', 'mcp_adapter_plugin_identity' ) ) {
			$identity = MAD4B_SCP_Dependency_Manager::mcp_adapter_plugin_identity( true );
			if ( is_array( $identity ) && empty( $identity['ambiguous'] ) && ! empty( $identity['plugin_file'] ) ) $adapter_file = (string) $identity['plugin_file'];
		}
		$targets = array( plugin_basename( MAD4B_SCP_FILE ) );
		if ( '' !== $adapter_file ) $targets[] = $adapter_file;
		if ( ! array_intersect( $targets, array_values( array_filter( array_map( 'strval', $plugins ) ) ) ) ) return;
		// This request still runs the old PHP classes. Schedule new-code recovery.
		self::schedule();
	}

	public static function profile_saved() {
		if ( ! MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' ) ) {
			wp_clear_scheduled_hook( self::HOOK );
			return;
		}
		self::schedule();
	}

	public static function schedule() {
		if ( ! MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' ) ) return;
		if ( MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath() ) return;
		// Make the event due now. Detection runs during init, before WordPress' wp_loaded
		// cron spawning phase, so the browser request only schedules while the isolated
		// cron request performs the filesystem transaction.
		if ( ! wp_next_scheduled( self::HOOK ) ) wp_schedule_single_event( time(), self::HOOK );
	}

	public static function authorize() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_admin() ) return new WP_Error( 'mad4b_mcp_repair_post_required', 'Use the repair form.' );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'update_plugins' ) ) return new WP_Error( 'mad4b_mcp_repair_capability_denied', 'Plugin update capability is required.' );
		foreach ( array( 'action', 'nonce', 'build' ) as $key ) {
			if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) return new WP_Error( 'mad4b_mcp_repair_input_invalid', 'Reload the repair form.' );
		}
		if ( self::ACTION !== $_POST['action'] || ! wp_verify_nonce( wp_unslash( $_POST['nonce'] ), self::ACTION ) ) return new WP_Error( 'mad4b_mcp_repair_nonce_invalid', 'Reload the repair form.' );
		$build = MAD4B_SCP_Endpoint_Diagnostic::build_fingerprint();
		if ( '' === $build || ! hash_equals( $build, wp_unslash( $_POST['build'] ) ) ) return new WP_Error( 'mad4b_mcp_repair_build_changed', 'Reload after the plugin update.' );
		return true;
	}

	public static function authorize_conflict() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_admin() ) return new WP_Error( 'mad4b_mcp_conflict_repair_post_required', 'Use the conflict recovery form.' );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'update_plugins' ) ) return new WP_Error( 'mad4b_mcp_conflict_repair_capability_denied', 'Plugin update capability is required.' );
		foreach ( array( 'action', 'nonce', 'build', 'observed_sha256', 'confirmation' ) as $key ) {
			if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) return new WP_Error( 'mad4b_mcp_conflict_repair_input_invalid', 'Reload the conflict recovery form.' );
		}
		if ( self::CONFLICT_ACTION !== $_POST['action'] || ! wp_verify_nonce( wp_unslash( $_POST['nonce'] ), self::CONFLICT_ACTION ) ) return new WP_Error( 'mad4b_mcp_conflict_repair_nonce_invalid', 'Reload the conflict recovery form.' );
		$build = MAD4B_SCP_Endpoint_Diagnostic::build_fingerprint();
		if ( '' === $build || ! hash_equals( $build, wp_unslash( $_POST['build'] ) ) ) return new WP_Error( 'mad4b_mcp_conflict_repair_build_changed', 'Reload after the plugin update.' );
		$observed = strtolower( trim( wp_unslash( $_POST['observed_sha256'] ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $observed ) ) return new WP_Error( 'mad4b_mcp_conflict_repair_hash_invalid', 'Observed MU bootstrap fingerprint is invalid.' );
		$confirmation = trim( wp_unslash( $_POST['confirmation'] ) );
		if ( ! hash_equals( self::CONFLICT_CONFIRMATION, $confirmation ) ) return new WP_Error( 'mad4b_mcp_conflict_repair_confirmation_required', 'Exact conflict recovery confirmation is required.' );
		$conflict = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::conflict_status();
		if ( 'unmanaged_mu_bootstrap_path_conflict' !== ( $conflict['blocker'] ?? '' ) ) return new WP_Error( 'mad4b_mcp_conflict_repair_not_required', 'The unmanaged MU bootstrap conflict is no longer present.' );
		$current = strtolower( trim( (string) ( $conflict['destination_sha256_before'] ?? '' ) ) );
		if ( '' === $current || ! hash_equals( $observed, $current ) ) return new WP_Error( 'mad4b_mcp_conflict_repair_stale_plan', 'MU bootstrap bytes changed; reload before conflict recovery.' );
		return $observed;
	}

	public static function handle() {
		$allowed = self::authorize();
		if ( is_wp_error( $allowed ) ) wp_die( esc_html( $allowed->get_error_message() ), '', array( 'response' => 403 ) );
		$result = self::run( '', true );
		if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_code() ), '', array( 'response' => 409 ) );
		wp_safe_redirect( admin_url( 'admin.php?page=mad4b-control-plane-connection&tab=endpoints' ) );
		exit;
	}

	public static function handle_conflict() {
		$expected = self::authorize_conflict();
		if ( is_wp_error( $expected ) ) wp_die( esc_html( $expected->get_error_message() ), '', array( 'response' => 409 ) );
		$result = self::run( '', true, $expected );
		if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_code() ), '', array( 'response' => 409 ) );
		wp_safe_redirect( admin_url( 'admin.php?page=mad4b-control-plane-connection&tab=endpoints' ) );
		exit;
	}

	/** A convergence caller may pass its already-owned shared lease. */
	public static function run( $convergence_lease = '', $authorized_admin = false, $expected_unmanaged_sha256 = '' ) {
		if ( MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()
			|| MAD4B_SCP_MCP_Request_Scope::current_request_is_endpoint_diagnostic_job() ) return new WP_Error( 'mad4b_mcp_repair_hotpath_denied', 'Repair requires a separate lifecycle request.' );
		$manual_expected_sha256 = strtolower( trim( (string) $expected_unmanaged_sha256 ) );
		$cli = defined( 'WP_CLI' ) && WP_CLI
			&& defined( 'MAD4B_SCP_MCP_CLI_REQUEST' ) && true === constant( 'MAD4B_SCP_MCP_CLI_REQUEST' );
		$cron = function_exists( 'wp_doing_cron' ) && wp_doing_cron()
			&& function_exists( 'current_filter' ) && self::HOOK === current_filter();
		// Unknown historical files require an exact administrator-approved
		// conflict replacement. A scheduled/leased request never authorizes
		// arbitrary filesystem bytes merely because the site is Staging.
		$expected_unmanaged_sha256 = $manual_expected_sha256;
		$conflict_mode = '' !== $expected_unmanaged_sha256;
		$automatic_conflict = false;
		$explicit_conflict = $conflict_mode;
		if ( $explicit_conflict && ( $cli || $cron || '' !== $convergence_lease ) ) return new WP_Error( 'mad4b_mcp_conflict_repair_admin_only', 'Explicit unknown MU bootstrap replacement requires an administrator request.' );
		$authorization = $authorized_admin ? ( $explicit_conflict ? self::authorize_conflict() : self::authorize() ) : new WP_Error( 'mad4b_mcp_repair_not_authorized', 'Repair is not authorized.' );
		if ( ! $cli && ! $cron && '' === $convergence_lease && ( ! $authorized_admin || is_wp_error( $authorization ) ) ) return new WP_Error( 'mad4b_mcp_repair_lifecycle_required', 'An authorized repair lifecycle is required.' );
		if ( $explicit_conflict && ( is_wp_error( $authorization ) || ! is_string( $authorization ) || ! hash_equals( $expected_unmanaged_sha256, strtolower( $authorization ) ) ) ) return new WP_Error( 'mad4b_mcp_conflict_repair_stale_plan', 'Conflict recovery authorization no longer matches the observed MU bootstrap.' );
		if ( ! MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' ) ) return new WP_Error( 'mad4b_mcp_repair_profile_ineligible', 'Exact non-production managed-runtime enrollment is required.' );
        // Updates do not need a pre-registered version. On an unknown version
        // verify the official WordPress.org release on this maintenance
        // lifecycle, not on a live MCP/OAuth protocol request.
		$integrity = MAD4B_SCP_Dependency_Manager::mcp_adapter_disk_integrity( true );
		if ( empty( $integrity['ready'] ) ) return new WP_Error( 'mcp_adapter_integrity_mismatch', 'Installed MCP Adapter files are invalid or differ from the official release.' );
        if ( empty( $integrity['source_verified'] ) )
            return new WP_Error( 'mcp_adapter_official_source_unverified',
                'The installed version is supported for discovery; wait for independent official release verification before privileged runtime recovery.' );
		$own_lease = '' === $convergence_lease;
		$lease = $own_lease ? MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'mcp_runtime_recovery' ) : $convergence_lease;
		if ( is_wp_error( $lease ) ) return $lease;
		try {
			$fence = MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $lease, $own_lease ? 'mcp_runtime_recovery' : 'runtime_convergence' );
			if ( is_wp_error( $fence ) ) return $fence;
			$oauth_store = class_exists( 'MAD4B_SCP_Local_OAuth_Server', false ) && method_exists( 'MAD4B_SCP_Local_OAuth_Server', 'converge_store_for_lifecycle' )
				? MAD4B_SCP_Local_OAuth_Server::converge_store_for_lifecycle()
				: array( 'contract' => 'mad4b.local-oauth-store-convergence.v1', 'required' => false, 'ready' => true, 'changed' => false );
			if ( is_wp_error( $oauth_store ) ) return $oauth_store;
			self::$active = true;
			self::$conflict_expected_sha256 = $conflict_mode ? $expected_unmanaged_sha256 : '';
			self::$conflict_recovery_source = $explicit_conflict ? 'explicit_admin_conflict_recovery' : '';
			$refresh = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::bootstrap();
			if ( ! empty( $refresh['blocker'] ) ) return new WP_Error( $refresh['blocker'], 'Managed bootstrap refresh is blocked.' );
			$guard = MAD4B_SCP_MCP_Runtime_Conflict_Guard::bootstrap( true );
			if ( empty( $guard['mu_bootstrap_present'] ) || empty( $guard['mu_bootstrap_integrity'] )
				|| ! in_array( $guard['blocker'] ?? '', array( '', 'mcp_adapter_class_provenance_mismatch' ), true ) ) return new WP_Error( $guard['blocker'] ?: 'mad4b_mcp_repair_readback_failed', 'Managed bootstrap was not verified.' );
			$runtime_restart_required = ! empty( $refresh['runtime_restart_required'] ) || ! empty( $guard['runtime_restart_required'] );
			$node_evidence = self::node_evidence();
			$result = array(
				'contract' => 'mad4b.mcp-runtime-recovery.v1',
				'state' => 'armed_for_next_request',
				'next_request_required' => true,
				'runtime_execution_verified' => false,
				'runtime_restart_required' => $runtime_restart_required,
				'opcache_invalidation' => isset( $refresh['opcache_invalidation'] ) ? $refresh['opcache_invalidation'] : ( isset( $guard['opcache_invalidation'] ) ? $guard['opcache_invalidation'] : array( 'available' => false, 'verified' => false ) ),
				'connection_certified' => false,
				'runtime_node_scope' => 'current_node',
				'shared_filesystem_certified' => false,
				'cluster_convergence_required' => true,
				'cluster_id' => $node_evidence['cluster_id'],
				'node_id' => $node_evidence['node_id'],
				'site_uuid' => $node_evidence['site_uuid'],
				'site_profile_revision' => $node_evidence['site_profile_revision'],
				'site_profile_digest' => $node_evidence['site_profile_digest'],
				'environment' => $node_evidence['environment'],
				'origin_sha256' => $node_evidence['origin_sha256'],
				'site_binding_ready' => $node_evidence['site_binding_ready'],
				'target_build' => $node_evidence['target_build'],
				'expected_mu_sha256' => $node_evidence['expected_mu_sha256'],
				'node_evidence_sha256' => $node_evidence['evidence_sha256'],
				'executed_runtime_provenance' => $node_evidence['executed_runtime_provenance'],
				'node_verified_at' => $node_evidence['verified_at'],
				'node_runtime_execution_verified' => $node_evidence['runtime_execution_verified'],
				'cluster_identity_configured' => $node_evidence['cluster_identity_configured'],
				'eligible_for_cluster_aggregation' => $node_evidence['eligible_for_cluster_aggregation'],
				'local_oauth_store' => is_array( $oauth_store ) ? $oauth_store : array(),
				'production_mutation' => false,
				'explicit_conflict_recovery' => $explicit_conflict,
				'automatic_conflict_recovery' => $automatic_conflict,
				'conflict_recovery_source' => self::$conflict_recovery_source,
				'conflict_observed_sha256' => $conflict_mode ? $expected_unmanaged_sha256 : '',
				'conflict_backup_sha256' => isset( $refresh['conflict_backup_sha256'] ) ? $refresh['conflict_backup_sha256'] : '',
				'ownership_receipt_valid' => ! empty( $refresh['ownership_receipt_valid'] ),
			);
			// A slow filesystem/audit phase may outlive its fence. Do not publish an
			// armed result after another worker takes ownership or storage drops it.
			$fence = MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $lease, $own_lease ? 'mcp_runtime_recovery' : 'runtime_convergence' );
			if ( is_wp_error( $fence ) ) return $fence;
			update_option( self::OPTION, $result, false );
			if ( $result !== get_option( self::OPTION, array() ) ) return new WP_Error( 'mad4b_mcp_repair_status_readback_failed', 'Recovery result could not be persisted and verified.' );
			return $result;
		} finally {
			self::$conflict_expected_sha256 = '';
			self::$conflict_recovery_source = '';
			self::$active = false;
			if ( $own_lease ) MAD4B_SCP_Runtime_Maintenance_Lease::release( $lease, 'mcp_runtime_recovery' );
		}
	}
}
