<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed remote write authority.
 *
 * New installations use MAD4B_SCP_Site_Profile as the canonical tenant/origin
 * boundary. The historical exact ETG Staging origin remains a compatibility
 * fallback only while no Site Profile has been enrolled. OAuth remains identity
 * only; mutation authority still requires an enabled site-local NHI, exact
 * mad4b-write grants, provider/runtime checks, budgets and one-time approvals.
 */
final class MAD4B_SCP_Staging_Write_Authority {
	const CONTRACT = 'mad4b.staging-write-authority.v1';
	const OPTION = 'mad4b_scp_staging_write_authority_v1';
	const VERSION = 1;
	// Legacy migration fallback. New generic sites never derive authority from it.
	const STAGING_HOST = 'staging.egypttourgates.com';
	const AGENT_SLUG = 'chatgpt-staging-write';
	const APPROVAL_INPUT_KEY = '_mad4b_approval_ticket_id';

	private static $booted = false;
	private static $reconciling = false;
	private static $status = array();

	public static function bootstrap() {
		$status = self::base_status();
		if ( ! $status['eligible'] ) { self::$status = $status; return $status; }

		if ( defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true !== constant( 'MAD4B_MCP_MUTATION_ENABLED' ) ) {
			$status['blocker'] = 'explicit_mutation_disabled';
			self::$status = $status;
			return $status;
		}
		if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) ) define( 'MAD4B_MCP_MUTATION_ENABLED', true );
		$status['mutation_gate_configured'] = true;
		$status['configuration_source'] = ! empty( $status['profile_enrolled'] ) ? 'site_profile_explicit' : 'staging_exact_origin_auto';
		self::$status = $status;
		return $status;
	}

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		self::bootstrap();

		add_filter( 'wp_register_ability_args', array( __CLASS__, 'augment_write_ability' ), 70, 2 );
		add_filter( 'mad4b_scp_low_impact_requires_approval', array( __CLASS__, 'force_remote_write_approval' ), 100, 4 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_status_ability' ), 35 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'reconcile' ), 95 );
		add_action( 'admin_init', array( __CLASS__, 'reconcile' ), 20 );
	}

	public static function eligible() {
		$status = self::base_status();
		return ! empty( $status['eligible'] );
	}

	public static function effective() {
		$status = self::status();
		return ! empty( $status['ready'] );
	}

	public static function status() {
		if ( ! empty( self::$status ) && isset( self::$status['contract'] ) ) return self::$status;
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && isset( $stored['contract'] ) && self::CONTRACT === $stored['contract'] ) return $stored;
		return self::base_status();
	}

	public static function write_tools() {
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) return array();
		$tools = MAD4B_SCP_Servers::write_tools();
		$tools = array_values( array_unique( array_filter( array_map( 'strval', is_array( $tools ) ? $tools : array() ) ) ) );
		return array_values( array_diff( $tools, array( 'mad4b/database-raw-query' ) ) );
	}

	public static function is_write_ability( $ability_name ) {
		return in_array( (string) $ability_name, self::write_tools(), true );
	}

	public static function approval_ticket_from_input( $input ) {
		if ( ! is_array( $input ) || ! isset( $input[ self::APPROVAL_INPUT_KEY ] ) ) return '';
		$value = strtolower( trim( (string) $input[ self::APPROVAL_INPUT_KEY ] ) );
		return preg_match( '/^[a-f0-9-]{36}$/', $value ) ? $value : '';
	}

	public static function authorization_input( $input ) {
		if ( ! is_array( $input ) ) return $input;
		$clean = $input;
		unset( $clean[ self::APPROVAL_INPUT_KEY ] );
		return $clean;
	}

	public static function remote_scope_delegation_allowed( array $identity, $server_id, $ability_name, $input ) {
		if ( ! self::effective() || 'mad4b-write' !== sanitize_key( (string) $server_id ) || ! self::is_write_ability( $ability_name ) ) return false;
		if ( empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return false;
		$scopes = isset( $identity['token_scopes'] ) && is_array( $identity['token_scopes'] ) ? $identity['token_scopes'] : array();
		if ( ! in_array( 'mad4b:read', $scopes, true ) ) return false;
		return '' !== self::approval_ticket_from_input( $input );
	}

	public static function force_remote_write_approval( $required, $ability_name, $provider, $input ) {
		if ( $required ) return true;
		if ( ! self::effective() || ! self::is_write_ability( $ability_name ) ) return $required;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return $required;
		$current = class_exists( 'MAD4B_SCP_Transport_Context' ) ? MAD4B_SCP_Transport_Context::current_server_id() : '';
		return in_array( $current, array( 'mad4b-chatgpt', 'mad4b-write' ), true ) ? true : $required;
	}

	public static function augment_write_ability( $args, $name ) {
		if ( ! is_array( $args ) || 'mad4b/approval-plan' === (string) $name ) return $args;
		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		if ( ! array_key_exists( 'readonly', $annotations ) || false !== $annotations['readonly'] ) return $args;

		if ( isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ) {
			if ( ! isset( $args['input_schema']['properties'] ) || ! is_array( $args['input_schema']['properties'] ) ) $args['input_schema']['properties'] = array();
			$args['input_schema']['properties'][ self::APPROVAL_INPUT_KEY ] = array(
				'type' => 'string',
				'minLength' => 36,
				'maxLength' => 36,
				'pattern' => '^[A-Fa-f0-9-]{36}$',
				'description' => 'One-time exact MAD4B approval ticket required for remote governed writes.',
			);
		}

		if ( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
			$original = $args['execute_callback'];
			$args['execute_callback'] = static function ( $input = null ) use ( $original ) {
				$clean = MAD4B_SCP_Staging_Write_Authority::authorization_input( $input );
				return call_user_func( $original, $clean );
			};
		}
		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
		$args['meta']['mcp']['mad4b_governed_write_authority'] = self::CONTRACT;
		$args['meta']['mcp']['mad4b_remote_write_approval_required'] = true;
		return $args;
	}

	public static function reconcile() {
		if ( self::$reconciling ) return self::status();
		self::$reconciling = true;
		$status = self::base_status();
		$status['mutation_gate_configured'] = defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true === constant( 'MAD4B_MCP_MUTATION_ENABLED' );
		if ( ! $status['eligible'] || ! $status['mutation_gate_configured'] ) {
			$status['blocker'] = $status['eligible'] ? 'mutation_gate_disabled' : $status['blocker'];
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::is_ready() ) {
			$status['blocker'] = 'governance_schema_unavailable';
			self::$status = $status; self::$reconciling = false; return $status;
		}
		$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit['ready'] ) ) { $status['blocker'] = 'audit_unavailable'; self::$status = $status; self::$reconciling = false; return $status; }
		if ( ! function_exists( 'wp_get_ability' ) || ! class_exists( 'MAD4B_SCP_Servers' ) ) { $status['blocker'] = 'abilities_runtime_unavailable'; self::$status = $status; self::$reconciling = false; return $status; }

		$oauth = class_exists( 'MAD4B_SCP_Staging_OAuth_Autoconfig' ) ? MAD4B_SCP_Staging_OAuth_Autoconfig::status() : array();
		$profile = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::enrolled() ? MAD4B_SCP_Site_Profile::status() : array();
		$user_id = ! empty( $profile ) ? absint( $profile['subject_user_id'] ) : ( isset( $oauth['wp_user_id'] ) ? absint( $oauth['wp_user_id'] ) : 0 );
		$issuer = class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? rtrim( (string) MAD4B_SCP_Local_OAuth_Server::issuer(), '/' ) : '';
		if ( ! empty( $profile ) ) {
			if ( empty( $profile['ready'] ) || empty( $profile['write_allowed'] ) || $user_id < 1 || '' === $issuer ) { $status['blocker'] = 'site_profile_write_identity_unavailable'; self::$status = $status; self::$reconciling = false; return $status; }
		} elseif ( empty( $oauth['configured'] ) || $user_id < 1 || '' === $issuer ) {
			$status['blocker'] = 'staging_oauth_subject_unavailable'; self::$status = $status; self::$reconciling = false; return $status;
		}
		$user = get_userdata( $user_id );
		$subject_capability = apply_filters( 'mad4b_scp_connection_subject_capability', 'manage_options', $user_id );
		if ( ! $user || ! is_string( $subject_capability ) || '' === $subject_capability || ! user_can( $user, $subject_capability ) ) { $status['blocker'] = 'oauth_subject_capability_denied'; self::$status = $status; self::$reconciling = false; return $status; }

		$environment = isset( $status['environment'] ) ? sanitize_key( (string) $status['environment'] ) : 'unknown';
		$agent = self::agent_by_slug();
		if ( ! $agent ) {
			$agent = MAD4B_SCP_Agent_Registry::create_agent( array(
				'slug' => self::AGENT_SLUG,
				'label' => 'ChatGPT Governed Write',
				'status' => 'enabled',
				'wp_user_id' => $user_id,
				'environment' => $environment,
			) );
			if ( is_wp_error( $agent ) ) { $status['blocker'] = $agent->get_error_code(); self::$status = $status; self::$reconciling = false; return $status; }
		} else {
			$changes = array();
			if ( 'enabled' !== $agent['status'] ) $changes['status'] = 'enabled';
			if ( $environment !== $agent['environment'] ) $changes['environment'] = $environment;
			if ( (int) $agent['wp_user_id'] !== $user_id ) $changes['wp_user_id'] = $user_id;
			if ( $changes ) {
				$updated = MAD4B_SCP_Agent_Registry::update_agent( $agent['public_id'], $changes, (int) $agent['revision'] );
				if ( is_wp_error( $updated ) ) { $status['blocker'] = $updated->get_error_code(); self::$status = $status; self::$reconciling = false; return $status; }
				$agent = $updated;
			}
		}

		$fingerprint = hash( 'sha256', 'oauth' . "\0" . $issuer . "\0" . 'user:' . $user_id );
		$identity = array( 'authenticated' => true, 'subject_type' => 'oauth', 'subject_fingerprint' => $fingerprint );
		$bound = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $bound ) ) {
			if ( 'mad4b_nhi_subject_unbound' !== $bound->get_error_code() ) { $status['blocker'] = $bound->get_error_code(); self::$status = $status; self::$reconciling = false; return $status; }
			$binding_label = ! empty( $profile['canonical_origin'] ) ? 'Local OAuth ' . $profile['canonical_origin'] : 'Local OAuth ' . self::home_host();
			$binding = MAD4B_SCP_Agent_Registry::bind_subject( $agent['public_id'], 'oauth', $fingerprint, $binding_label );
			if ( is_wp_error( $binding ) ) { $status['blocker'] = $binding->get_error_code(); self::$status = $status; self::$reconciling = false; return $status; }
		} elseif ( (int) $bound['id'] !== (int) $agent['id'] ) {
			$status['blocker'] = 'oauth_subject_bound_to_other_agent'; self::$status = $status; self::$reconciling = false; return $status;
		}

		$tools = self::write_tools();
		if ( empty( $tools ) ) { $status['blocker'] = 'write_tool_inventory_empty'; self::$status = $status; self::$reconciling = false; return $status; }
		$granted = 0;
		$existing = 0;
		$grant_blockers = array();
		$inventory_rows = array();
		foreach ( $tools as $ability ) {
			if ( 'mad4b/database-raw-query' === $ability ) { $grant_blockers[] = 'breakglass_leak'; continue; }
			$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
			if ( null === $provider ) { $grant_blockers[] = 'unmounted:' . $ability; continue; }
			$inventory_rows[] = array( 'ability' => (string) $ability, 'provider' => (string) $provider );
			$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $ability, $provider );
			if ( ! is_wp_error( $grant ) ) { ++$existing; continue; }
			if ( 'mad4b_nhi_grant_missing' !== $grant->get_error_code() ) { $grant_blockers[] = $grant->get_error_code() . ':' . $ability; continue; }
			$created = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-write', $ability, $provider, array(), 'allow', $environment );
			if ( is_wp_error( $created ) ) $grant_blockers[] = $created->get_error_code() . ':' . $ability;
			else ++$granted;
		}
		usort( $inventory_rows, static function ( $a, $b ) { return strcmp( $a['ability'] . "\0" . $a['provider'], $b['ability'] . "\0" . $b['provider'] ); } );

		$status['agent_public_id'] = (string) $agent['public_id'];
		$status['subject_fingerprint_prefix'] = substr( $fingerprint, 0, 16 );
		$status['write_tool_count'] = count( $tools );
		$status['write_inventory_fingerprint'] = hash( 'sha256', wp_json_encode( $inventory_rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$status['exact_grants_existing'] = $existing;
		$status['exact_grants_created'] = $granted;
		$status['grant_blockers'] = $grant_blockers;
		$status['all_remote_writes_require_exact_approval'] = true;
		$status['breakglass_included'] = in_array( 'mad4b/database-raw-query', $tools, true );
		$status['ready'] = empty( $grant_blockers ) && ! $status['breakglass_included'];
		$status['state'] = $status['ready'] ? 'ready' : 'blocked';
		$status['blocker'] = $status['ready'] ? '' : ( ! empty( $grant_blockers ) ? 'grant_reconciliation_incomplete' : 'breakglass_leak' );
		$status['updated_at'] = gmdate( 'c' );
		$status['remote_transport'] = 'mad4b-chatgpt';
		$status['authority_server'] = 'mad4b-write';
		$status['oauth_role'] = 'identity_only';
		$status['write_authority_components'] = array( 'site_or_legacy_origin', 'oauth_identity', 'nhi_subject_binding', 'exact_mad4b_write_grant', 'provider_runtime', 'global_mutation_gate', 'budget_reservation', 'one_time_exact_approval', 'audit' );
		if ( ! empty( $profile ) ) {
			$status['site_uuid'] = (string) $profile['site_uuid'];
			$status['profile_revision'] = (int) $profile['profile_revision'];
			$status['profile_digest'] = (string) $profile['profile_digest'];
		}

		if ( $status['ready'] ) {
			$stored = get_option( self::OPTION, array() );
			$changed = ! is_array( $stored )
				|| empty( $stored['ready'] )
				|| ! empty( $stored['blocker'] )
				|| ! isset( $stored['agent_public_id'] )
				|| ! hash_equals( (string) $status['agent_public_id'], (string) $stored['agent_public_id'] )
				|| ! isset( $stored['write_tool_count'] )
				|| (int) $stored['write_tool_count'] !== (int) $status['write_tool_count']
				|| ! isset( $stored['write_inventory_fingerprint'] )
				|| ! hash_equals( (string) $status['write_inventory_fingerprint'], (string) $stored['write_inventory_fingerprint'] )
				|| ! empty( $stored['breakglass_included'] )
				|| ( isset( $status['profile_digest'] ) && ( ! isset( $stored['profile_digest'] ) || ! hash_equals( (string) $status['profile_digest'], (string) $stored['profile_digest'] ) ) );
			if ( $changed ) {
				MAD4B_SCP_Audit::record( 'mad4b/staging-write-authority-reconciled', array(
					'agent_public_id' => $status['agent_public_id'],
					'write_tool_count' => $status['write_tool_count'],
					'write_inventory_fingerprint' => $status['write_inventory_fingerprint'],
					'exact_grants_created' => $granted,
					'exact_grants_existing' => $existing,
					'remote_transport' => 'mad4b-chatgpt',
					'authority_server' => 'mad4b-write',
					'environment' => $environment,
					'profile_revision' => isset( $status['profile_revision'] ) ? (int) $status['profile_revision'] : 0,
					'breakglass_included' => false,
				), 'ok' );
				update_option( self::OPTION, $status, false );
				$status['persistence'] = 'recorded';
			} else {
				$status['persistence'] = 'unchanged';
				$status['persisted_updated_at'] = isset( $stored['updated_at'] ) ? (string) $stored['updated_at'] : '';
			}
		}
		self::$status = $status;
		self::$reconciling = false;
		return $status;
	}

	public static function register_status_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( 'mad4b/write-authority-status' ) ) return;
		wp_register_ability( 'mad4b/write-authority-status', array(
			'label' => 'Get Governed Write Authority Status',
			'description' => 'Read the enrolled-site or legacy exact-origin NHI/grant/approval state for governed writes.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, 'status' ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	private static function agent_by_slug() {
		global $wpdb;
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) ) return null;
		$t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['agents']} WHERE slug = %s LIMIT 1", self::AGENT_SLUG ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $row ? $row : null;
	}

	private static function base_status() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$host = self::home_host();
		$profile_enrolled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::enrolled();
		$profile = $profile_enrolled ? MAD4B_SCP_Site_Profile::status() : array();
		$legacy_eligible = ! $profile_enrolled && 'staging' === $environment && self::STAGING_HOST === $host;
		$profile_eligible = $profile_enrolled && ! empty( $profile['ready'] ) && ! empty( $profile['write_allowed'] );
		$eligible = $profile_eligible || $legacy_eligible;
		$blocker = '';
		if ( ! $eligible ) {
			if ( $profile_enrolled ) {
				$blocker = ! empty( $profile['blockers'] ) ? (string) reset( $profile['blockers'] ) : 'site_profile_write_disabled';
			} else {
				$blocker = 'staging' !== $environment ? 'environment_not_staging' : 'origin_not_governed_staging';
			}
		}
		$status = array(
			'contract' => self::CONTRACT,
			'version' => self::VERSION,
			'environment' => $environment,
			'host' => $host,
			'eligible' => $eligible,
			'profile_enrolled' => $profile_enrolled,
			'legacy_exact_origin' => $legacy_eligible,
			'ready' => false,
			'state' => $eligible ? 'pending' : 'ineligible',
			'blocker' => $blocker,
			'mutation_gate_configured' => false,
			'configuration_source' => 'none',
			'production_auto_enable' => false,
			'breakglass_auto_enable' => false,
			'breakglass_included' => false,
			'all_remote_writes_require_exact_approval' => true,
			'remote_transport' => 'mad4b-chatgpt',
			'authority_server' => 'mad4b-write',
			'oauth_role' => 'identity_only',
		);
		if ( $profile_enrolled ) {
			$status['site_uuid'] = isset( $profile['site_uuid'] ) ? (string) $profile['site_uuid'] : '';
			$status['profile_revision'] = isset( $profile['profile_revision'] ) ? (int) $profile['profile_revision'] : 0;
			$status['profile_digest'] = isset( $profile['profile_digest'] ) ? (string) $profile['profile_digest'] : '';
			$status['canonical_origin'] = isset( $profile['canonical_origin'] ) ? (string) $profile['canonical_origin'] : '';
		}
		return $status;
	}

	private static function home_host() {
		$parts = wp_parse_url( home_url( '/' ) );
		return is_array( $parts ) && ! empty( $parts['host'] ) ? strtolower( rtrim( (string) $parts['host'], '.' ) ) : '';
	}
}
