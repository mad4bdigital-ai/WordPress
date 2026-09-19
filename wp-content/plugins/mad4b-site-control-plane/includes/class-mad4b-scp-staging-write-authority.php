<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Tenant-bound governed write authority for ChatGPT/MCP.
 *
 * Installation is never authority. The write plane becomes eligible only after
 * an exact MAD4B Site Profile is enrolled for the live origin/environment and
 * its governed-write feature is enabled. OAuth remains identity only; execution
 * still requires an enabled NHI, exact provider/ability grant, runtime/provider
 * certification, mutation budget, one-time exact human approval, and audit.
 *
 * Breakglass/raw SQL are never included in this authority.
 */
final class MAD4B_SCP_Staging_Write_Authority {
	const CONTRACT = 'mad4b.governed-write-authority.v2';
	const OPTION = 'mad4b_scp_staging_write_authority_v1';
	const VERSION = 2;
	const APPROVAL_INPUT_KEY = '_mad4b_approval_ticket_id';
	const CONTEXT_RECEIPT_INPUT_KEY = '_mad4b_context_receipt';

	private static $booted = false;
	private static $reconciling = false;
	private static $status = array();

	public static function bootstrap() {
		$status = self::base_status();
		if ( empty( $status['eligible'] ) ) {
			self::$status = $status;
			return $status;
		}

		if ( defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true !== constant( 'MAD4B_MCP_MUTATION_ENABLED' ) ) {
			$status['blocker'] = 'explicit_mutation_disabled';
			$status['state'] = 'blocked';
			self::$status = $status;
			return $status;
		}

		if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) ) {
			define( 'MAD4B_MCP_MUTATION_ENABLED', true );
		}
		$status['mutation_gate_configured'] = true;
		$status['configuration_source'] = 'site_profile';
		$status = self::restore_persisted_ready_status( $status );
		self::$status = $status;
		return $status;
	}

	private static function restore_persisted_ready_status( array $current ) {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored )
			|| ! isset( $stored['contract'] )
			|| self::CONTRACT !== (string) $stored['contract']
			|| empty( $stored['ready'] )
			|| 'ready' !== ( isset( $stored['state'] ) ? (string) $stored['state'] : '' )
			|| ! empty( $stored['blocker'] )
			|| empty( $stored['agent_public_id'] )
			|| ! preg_match( '/^[a-f0-9-]{36}$/i', (string) $stored['agent_public_id'] )
			|| empty( $stored['write_tool_count'] )
			|| empty( $stored['write_inventory_fingerprint'] )
			|| ! preg_match( '/^[a-f0-9]{64}$/', (string) $stored['write_inventory_fingerprint'] ) ) {
			return $current;
		}

		foreach ( array( 'site_uuid', 'site_profile_revision', 'site_profile_digest', 'environment', 'origin' ) as $key ) {
			if ( ! array_key_exists( $key, $stored ) || ! array_key_exists( $key, $current ) || (string) $stored[ $key ] !== (string) $current[ $key ] ) return $current;
		}

		$stored['mutation_gate_configured'] = true;
		$stored['configuration_source'] = 'site_profile';
		$stored['restored_from_persisted_authority'] = true;
		return $stored;
	}

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		self::bootstrap();

		add_filter( 'wp_register_ability_args', array( __CLASS__, 'augment_write_ability' ), 70, 2 );
		add_filter( 'mad4b_scp_low_impact_requires_approval', array( __CLASS__, 'force_remote_write_approval' ), 100, 4 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_status_ability' ), 35 );

		// Grant/subject reconciliation is mutation authority and must never run
		// automatically during Abilities bootstrap or ordinary wp-admin lifecycle.
		// Explicit bounded bootstrap surfaces call reconcile() after exact validation.
	}

	public static function eligible() {
		$status = self::base_status();
		return ! empty( $status['eligible'] );
	}

	public static function effective() {
		// Authorization hot paths must not recursively rebuild the provider/write
		// inventory. Only an explicitly reconciled persisted authority may enable
		// this predicate; current read/status truth is evaluated separately by
		// MAD4B_SCP_Live_Truth and is bound to the exact inventory fingerprint.
		$status = self::status();
		return ! empty( $status['ready'] );
	}

	public static function status() {
		if ( ! empty( self::$status ) && isset( self::$status['contract'] ) ) return self::$status;
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && isset( $stored['contract'] ) && self::CONTRACT === (string) $stored['contract'] ) return $stored;
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

	public static function context_receipt_from_input( $input ) {
		if ( ! is_array( $input ) || ! isset( $input[ self::CONTEXT_RECEIPT_INPUT_KEY ] ) || ! is_array( $input[ self::CONTEXT_RECEIPT_INPUT_KEY ] ) ) return array();
		return $input[ self::CONTEXT_RECEIPT_INPUT_KEY ];
	}

	public static function authorization_input( $input ) {
		if ( ! is_array( $input ) ) return $input;
		$clean = $input;
		unset( $clean[ self::APPROVAL_INPUT_KEY ] );
		return $clean;
	}

	public static function provider_input( $input ) {
		$clean = self::authorization_input( $input );
		if ( is_array( $clean ) ) unset( $clean[ self::CONTEXT_RECEIPT_INPUT_KEY ] );
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
				'pattern' => '^[A-Fa-f0-9-]{36}
		}

		if ( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
			$original = $args['execute_callback'];
			$args['execute_callback'] = static function ( $input = null ) use ( $original ) {
				$clean = MAD4B_SCP_Staging_Write_Authority::provider_input( $input );
				return call_user_func( $original, $clean );
			};
		}
		if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) $args['meta'] = array();
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

		if ( empty( $status['eligible'] ) || empty( $status['mutation_gate_configured'] ) ) {
			if ( ! empty( $status['eligible'] ) && empty( $status['mutation_gate_configured'] ) ) {
				$status['blocker'] = 'mutation_gate_disabled';
				$status['state'] = 'blocked';
			}
			self::deprovision_managed_authority( $status );
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}

		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() ) {
			$status['blocker'] = 'governance_schema_unavailable';
			$status['state'] = 'blocked';
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}

		$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit['ready'] ) ) {
			$status['blocker'] = 'audit_unavailable';
			$status['state'] = 'blocked';
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}

		if ( ! function_exists( 'wp_get_ability' ) || ! class_exists( 'MAD4B_SCP_Servers' ) ) {
			$status['blocker'] = 'abilities_runtime_unavailable';
			$status['state'] = 'blocked';
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}

		$issuer = self::oauth_issuer();
		if ( '' === $issuer ) {
			$status['blocker'] = 'oauth_issuer_unavailable';
			$status['state'] = 'blocked';
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}

		$user_ids = self::enrolled_user_ids();
		if ( empty( $user_ids ) ) {
			$status['blocker'] = 'oauth_subject_unavailable';
			$status['state'] = 'blocked';
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}
		foreach ( $user_ids as $user_id ) {
			if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_connect_user( $user_id ) ) {
				$status['blocker'] = 'oauth_subject_not_enrolled';
				$status['state'] = 'blocked';
				$status['invalid_user_id'] = absint( $user_id );
				self::$status = $status;
				self::$reconciling = false;
				return $status;
			}
		}

		$environment = self::current_environment();
		$agent_slug = self::agent_slug();
		$agent = self::agent_by_slug( $agent_slug );
		$primary_user_id = (int) reset( $user_ids );
		$label = 'ChatGPT Governed Write';
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) ) {
			$display = trim( (string) MAD4B_SCP_Site_Profile::display_name() );
			if ( '' !== $display ) $label .= ' — ' . $display;
		}
		$label = substr( $label, 0, 191 );

		if ( ! $agent ) {
			$agent = MAD4B_SCP_Agent_Registry::create_agent( array(
				'slug' => $agent_slug,
				'label' => $label,
				'status' => 'enabled',
				'wp_user_id' => $primary_user_id,
				'environment' => $environment,
			) );
			if ( is_wp_error( $agent ) ) return self::finish_blocked( $status, $agent->get_error_code() );
		} else {
			$changes = array();
			if ( 'enabled' !== (string) $agent['status'] ) $changes['status'] = 'enabled';
			if ( $environment !== (string) $agent['environment'] ) $changes['environment'] = $environment;
			if ( (int) $agent['wp_user_id'] !== $primary_user_id ) $changes['wp_user_id'] = $primary_user_id;
			if ( $label !== (string) $agent['label'] ) $changes['label'] = $label;
			if ( ! empty( $changes ) ) {
				$updated = MAD4B_SCP_Agent_Registry::update_agent( $agent['public_id'], $changes, (int) $agent['revision'] );
				if ( is_wp_error( $updated ) ) return self::finish_blocked( $status, $updated->get_error_code() );
				$agent = $updated;
			}
		}

		$desired_subjects = array();
		$subject_blockers = array();
		foreach ( $user_ids as $user_id ) {
			$fingerprint = self::subject_fingerprint( $issuer, $user_id );
			$desired_subjects[ $fingerprint ] = true;
			$identity = array( 'authenticated' => true, 'subject_type' => 'oauth', 'subject_fingerprint' => $fingerprint );
			$bound = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
			if ( is_wp_error( $bound ) ) {
				if ( 'mad4b_nhi_subject_unbound' !== $bound->get_error_code() ) {
					$subject_blockers[] = $bound->get_error_code() . ':user:' . $user_id;
					continue;
				}
				$binding = MAD4B_SCP_Agent_Registry::bind_subject( $agent['public_id'], 'oauth', $fingerprint, 'Local OAuth user:' . $user_id );
				if ( is_wp_error( $binding ) ) $subject_blockers[] = $binding->get_error_code() . ':user:' . $user_id;
			} elseif ( (int) $bound['id'] !== (int) $agent['id'] ) {
				$subject_blockers[] = 'oauth_subject_bound_to_other_agent:user:' . $user_id;
			}
		}

		$subjects = MAD4B_SCP_Agent_Registry::subjects_for_agent( $agent['id'], 'oauth' );
		$subjects_disabled = 0;
		foreach ( $subjects as $subject ) {
			$fingerprint = isset( $subject['subject_fingerprint'] ) ? strtolower( (string) $subject['subject_fingerprint'] ) : '';
			if ( isset( $desired_subjects[ $fingerprint ] ) ) continue;
			if ( 'enabled' !== (string) $subject['status'] ) continue;
			$disabled = MAD4B_SCP_Agent_Registry::set_subject_status( $agent['public_id'], 'oauth', $fingerprint, 'disabled' );
			if ( is_wp_error( $disabled ) ) $subject_blockers[] = $disabled->get_error_code() . ':stale_subject';
			else ++$subjects_disabled;
		}

		$tools = self::write_tools();
		if ( empty( $tools ) ) return self::finish_blocked( $status, 'write_tool_inventory_empty' );

		$desired_grants = array();
		$inventory_rows = array();
		$grant_blockers = array();
		foreach ( $tools as $ability ) {
			if ( 'mad4b/database-raw-query' === $ability ) {
				$grant_blockers[] = 'breakglass_leak';
				continue;
			}
			$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
			if ( null === $provider ) {
				$grant_blockers[] = 'unmounted:' . $ability;
				continue;
			}
			$key = (string) $ability . "\0" . (string) $provider;
			$desired_grants[ $key ] = array( 'ability' => (string) $ability, 'provider' => (string) $provider );
			$inventory_rows[] = array( 'ability' => (string) $ability, 'provider' => (string) $provider );
		}

		$grants_revoked = 0;
		$existing_grants = MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], 'mad4b-write' );
		foreach ( $existing_grants as $grant ) {
			if ( 'allow' !== (string) $grant['effect'] ) continue;
			$key = (string) $grant['ability_name'] . "\0" . (string) $grant['provider'];
			$stale = ! isset( $desired_grants[ $key ] ) || $environment !== (string) $grant['environment'];
			if ( ! $stale ) continue;
			$revoked = MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id( $agent['public_id'], (int) $grant['id'], 'mad4b-write' );
			if ( is_wp_error( $revoked ) ) $grant_blockers[] = $revoked->get_error_code() . ':stale_grant';
			else ++$grants_revoked;
		}

		$granted = 0;
		$existing = 0;
		foreach ( $desired_grants as $desired ) {
			$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $desired['ability'], $desired['provider'] );
			if ( ! is_wp_error( $grant ) ) {
				if ( $environment === (string) $grant['environment'] ) ++$existing;
				continue;
			}
			if ( 'mad4b_nhi_grant_denied' === $grant->get_error_code() ) {
				$grant_blockers[] = 'explicit_deny:' . $desired['ability'];
				continue;
			}
			if ( 'mad4b_nhi_grant_missing' !== $grant->get_error_code() ) {
				$grant_blockers[] = $grant->get_error_code() . ':' . $desired['ability'];
				continue;
			}
			$created = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-write', $desired['ability'], $desired['provider'], array(), 'allow', $environment );
			if ( is_wp_error( $created ) ) $grant_blockers[] = $created->get_error_code() . ':' . $desired['ability'];
			else ++$granted;
		}

		usort( $inventory_rows, static function ( $a, $b ) { return strcmp( $a['ability'] . "\0" . $a['provider'], $b['ability'] . "\0" . $b['provider'] ); } );
		$all_blockers = array_values( array_unique( array_merge( $subject_blockers, $grant_blockers ) ) );
		$status['agent_public_id'] = (string) $agent['public_id'];
		$status['agent_slug'] = $agent_slug;
		$status['oauth_user_ids'] = array_values( array_map( 'absint', $user_ids ) );
		$status['subject_count_expected'] = count( $desired_subjects );
		$status['stale_subjects_disabled'] = $subjects_disabled;
		$status['write_tool_count'] = count( $tools );
		$status['write_inventory_fingerprint'] = hash( 'sha256', wp_json_encode( $inventory_rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$status['exact_grants_existing'] = $existing;
		$status['exact_grants_created'] = $granted;
		$status['stale_allow_grants_revoked'] = $grants_revoked;
		$status['grant_blockers'] = $all_blockers;
		$status['all_remote_writes_require_exact_approval'] = true;
		$status['breakglass_included'] = in_array( 'mad4b/database-raw-query', $tools, true );
		$status['ready'] = empty( $all_blockers ) && ! $status['breakglass_included'];
		$status['state'] = $status['ready'] ? 'ready' : 'blocked';
		$status['blocker'] = $status['ready'] ? '' : ( ! empty( $all_blockers ) ? 'authority_reconciliation_incomplete' : 'breakglass_leak' );
		$status['updated_at'] = gmdate( 'c' );
		$status['remote_transport'] = 'mad4b-chatgpt';
		$status['authority_server'] = 'mad4b-write';
		$status['oauth_role'] = 'identity_only';
		$status['write_authority_components'] = array( 'site_profile', 'exact_origin', 'oauth_identity', 'nhi_subject_binding', 'exact_mad4b_write_grant', 'provider_runtime', 'global_mutation_gate', 'budget_reservation', 'one_time_exact_approval', 'audit' );

		if ( $status['ready'] ) self::persist_ready_status( $status, $granted, $existing, $grants_revoked, $subjects_disabled );
		self::$status = $status;
		self::$reconciling = false;
		return $status;
	}

	public static function register_status_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( 'mad4b/write-authority-status' ) ) return;
		wp_register_ability( 'mad4b/write-authority-status', array(
			'label' => 'Get Governed Write Authority Status',
			'description' => 'Read the site-profile-bound NHI/grant/approval status for governed writes.',
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

	private static function persist_ready_status( array &$status, $granted, $existing, $revoked, $subjects_disabled ) {
		$stored = get_option( self::OPTION, array() );
		$keys = array( 'agent_public_id', 'write_tool_count', 'write_inventory_fingerprint', 'site_uuid', 'site_profile_revision', 'site_profile_digest', 'environment', 'origin' );
		$changed = ! is_array( $stored ) || empty( $stored['ready'] ) || ! empty( $stored['blocker'] );
		foreach ( $keys as $key ) {
			if ( $changed ) break;
			if ( ! array_key_exists( $key, $stored ) || ! array_key_exists( $key, $status ) || (string) $stored[ $key ] !== (string) $status[ $key ] ) $changed = true;
		}
		if ( $changed ) {
			if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
				MAD4B_SCP_Audit::record( 'mad4b/governed-write-authority-reconciled', array(
					'agent_public_id' => $status['agent_public_id'],
					'site_uuid' => $status['site_uuid'],
					'site_profile_revision' => $status['site_profile_revision'],
					'site_profile_digest' => $status['site_profile_digest'],
					'write_tool_count' => $status['write_tool_count'],
					'write_inventory_fingerprint' => $status['write_inventory_fingerprint'],
					'exact_grants_created' => (int) $granted,
					'exact_grants_existing' => (int) $existing,
					'stale_allow_grants_revoked' => (int) $revoked,
					'stale_subjects_disabled' => (int) $subjects_disabled,
					'environment' => $status['environment'],
					'origin' => $status['origin'],
					'breakglass_included' => false,
				), 'ok' );
			}
			update_option( self::OPTION, $status, false );
			$status['persistence'] = 'recorded';
		} else {
			$status['persistence'] = 'unchanged';
			$status['persisted_updated_at'] = isset( $stored['updated_at'] ) ? (string) $stored['updated_at'] : '';
		}
	}

	private static function deprovision_managed_authority( array &$status ) {
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return;
		$agent = self::agent_by_slug( self::agent_slug() );
		if ( ! $agent ) return;
		$changed = false;
		$subjects = MAD4B_SCP_Agent_Registry::subjects_for_agent( $agent['id'], 'oauth' );
		foreach ( $subjects as $subject ) {
			if ( 'enabled' !== (string) $subject['status'] ) continue;
			$result = MAD4B_SCP_Agent_Registry::set_subject_status( $agent['public_id'], 'oauth', $subject['subject_fingerprint'], 'disabled' );
			if ( ! is_wp_error( $result ) ) $changed = true;
		}
		$grants = MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], 'mad4b-write' );
		foreach ( $grants as $grant ) {
			if ( 'allow' !== (string) $grant['effect'] ) continue;
			$result = MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id( $agent['public_id'], (int) $grant['id'], 'mad4b-write' );
			if ( ! is_wp_error( $result ) ) $changed = true;
		}
		if ( 'enabled' === (string) $agent['status'] ) {
			$result = MAD4B_SCP_Agent_Registry::disable_agent( $agent['public_id'], (int) $agent['revision'] );
			if ( ! is_wp_error( $result ) ) $changed = true;
		}
		if ( $changed && class_exists( 'MAD4B_SCP_Audit' ) ) {
			MAD4B_SCP_Audit::record( 'mad4b/governed-write-authority-deprovisioned', array(
				'agent_public_id' => (string) $agent['public_id'],
				'site_uuid' => isset( $status['site_uuid'] ) ? (string) $status['site_uuid'] : '',
				'environment' => isset( $status['environment'] ) ? (string) $status['environment'] : '',
				'origin' => isset( $status['origin'] ) ? (string) $status['origin'] : '',
				'blocker' => isset( $status['blocker'] ) ? (string) $status['blocker'] : '',
			), 'ok' );
		}
	}

	private static function finish_blocked( array $status, $blocker ) {
		$status['blocker'] = sanitize_key( (string) $blocker );
		$status['state'] = 'blocked';
		$status['ready'] = false;
		self::$status = $status;
		self::$reconciling = false;
		return $status;
	}

	private static function agent_by_slug( $slug ) {
		global $wpdb;
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) ) return null;
		$t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['agents']} WHERE slug = %s LIMIT 1", sanitize_key( (string) $slug ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $row ? $row : null;
	}

	private static function enrolled_user_ids() {
		$users = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::oauth_user_ids() : array();
		if ( ! empty( $users ) ) return $users;
		$oauth = class_exists( 'MAD4B_SCP_Staging_OAuth_Autoconfig' ) ? MAD4B_SCP_Staging_OAuth_Autoconfig::status() : array();
		$user_id = isset( $oauth['wp_user_id'] ) ? absint( $oauth['wp_user_id'] ) : 0;
		return $user_id > 0 ? array( $user_id ) : array();
	}

	private static function oauth_issuer() {
		return class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? rtrim( (string) MAD4B_SCP_Local_OAuth_Server::issuer(), '/' ) : '';
	}

	private static function subject_fingerprint( $issuer, $user_id ) {
		return hash( 'sha256', 'oauth' . "\0" . (string) $issuer . "\0" . 'user:' . absint( $user_id ) );
	}

	private static function agent_slug() {
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) ) return sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() );
		return 'chatgpt-governed-write';
	}

	private static function current_environment() {
		return function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
	}

	private static function base_status() {
		$environment = self::current_environment();
		$origin = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_origin() : '';
		$profile_status = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$configured = ! empty( $profile_status['configured'] );
		$origin_enrolled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled();
		$site_urls_match = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
		$exact_profile_bound = $origin_enrolled && $site_urls_match;
		$write_enabled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::write_enabled();
		$eligible = $configured && $exact_profile_bound && $write_enabled;
		$blocker = '';
		if ( ! $configured ) $blocker = 'site_profile_unconfigured';
		elseif ( ! $origin_enrolled ) {
			$profile_blockers = isset( $profile_status['blockers'] ) && is_array( $profile_status['blockers'] ) ? $profile_status['blockers'] : array();
			$blocker = ! empty( $profile_blockers ) ? sanitize_key( (string) reset( $profile_blockers ) ) : 'site_profile_drift';
		} elseif ( ! $site_urls_match ) $blocker = 'site_profile_site_urls_mismatch';
		elseif ( ! $write_enabled ) $blocker = 'site_profile_write_disabled';

		return array(
			'contract' => self::CONTRACT,
			'version' => self::VERSION,
			'environment' => $environment,
			'origin' => $origin,
			'host' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_host() : '',
			'site_uuid' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::site_uuid() : '',
			'site_profile_revision' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::revision() : 0,
			'site_profile_digest' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::profile_digest() : '',
			'profile_origin_enrolled' => $origin_enrolled,
			'profile_site_urls_match' => $site_urls_match,
			'exact_profile_bound' => $exact_profile_bound,
			'profile_write_enabled' => $write_enabled,
			'eligible' => $eligible,
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
	}
}
,
				'description' => 'One-time exact MAD4B approval ticket required for remote governed writes.',
			);
			$args['input_schema']['properties'][ self::CONTEXT_RECEIPT_INPUT_KEY ] = array(
				'type' => 'object',
				'additionalProperties' => true,
				'description' => 'Governed Context Receipt returned by mad4b/skill-get. Required automatically for brand-bearing content text mutations and bound into the exact approval payload.',
			);
		}

		if ( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
			$original = $args['execute_callback'];
			$args['execute_callback'] = static function ( $input = null ) use ( $original ) {
				$clean = MAD4B_SCP_Staging_Write_Authority::authorization_input( $input );
				return call_user_func( $original, $clean );
			};
		}
		if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) $args['meta'] = array();
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

		if ( empty( $status['eligible'] ) || empty( $status['mutation_gate_configured'] ) ) {
			if ( ! empty( $status['eligible'] ) && empty( $status['mutation_gate_configured'] ) ) {
				$status['blocker'] = 'mutation_gate_disabled';
				$status['state'] = 'blocked';
			}
			self::deprovision_managed_authority( $status );
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}

		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() ) {
			$status['blocker'] = 'governance_schema_unavailable';
			$status['state'] = 'blocked';
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}

		$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit['ready'] ) ) {
			$status['blocker'] = 'audit_unavailable';
			$status['state'] = 'blocked';
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}

		if ( ! function_exists( 'wp_get_ability' ) || ! class_exists( 'MAD4B_SCP_Servers' ) ) {
			$status['blocker'] = 'abilities_runtime_unavailable';
			$status['state'] = 'blocked';
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}

		$issuer = self::oauth_issuer();
		if ( '' === $issuer ) {
			$status['blocker'] = 'oauth_issuer_unavailable';
			$status['state'] = 'blocked';
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}

		$user_ids = self::enrolled_user_ids();
		if ( empty( $user_ids ) ) {
			$status['blocker'] = 'oauth_subject_unavailable';
			$status['state'] = 'blocked';
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}
		foreach ( $user_ids as $user_id ) {
			if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_connect_user( $user_id ) ) {
				$status['blocker'] = 'oauth_subject_not_enrolled';
				$status['state'] = 'blocked';
				$status['invalid_user_id'] = absint( $user_id );
				self::$status = $status;
				self::$reconciling = false;
				return $status;
			}
		}

		$environment = self::current_environment();
		$agent_slug = self::agent_slug();
		$agent = self::agent_by_slug( $agent_slug );
		$primary_user_id = (int) reset( $user_ids );
		$label = 'ChatGPT Governed Write';
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) ) {
			$display = trim( (string) MAD4B_SCP_Site_Profile::display_name() );
			if ( '' !== $display ) $label .= ' — ' . $display;
		}
		$label = substr( $label, 0, 191 );

		if ( ! $agent ) {
			$agent = MAD4B_SCP_Agent_Registry::create_agent( array(
				'slug' => $agent_slug,
				'label' => $label,
				'status' => 'enabled',
				'wp_user_id' => $primary_user_id,
				'environment' => $environment,
			) );
			if ( is_wp_error( $agent ) ) return self::finish_blocked( $status, $agent->get_error_code() );
		} else {
			$changes = array();
			if ( 'enabled' !== (string) $agent['status'] ) $changes['status'] = 'enabled';
			if ( $environment !== (string) $agent['environment'] ) $changes['environment'] = $environment;
			if ( (int) $agent['wp_user_id'] !== $primary_user_id ) $changes['wp_user_id'] = $primary_user_id;
			if ( $label !== (string) $agent['label'] ) $changes['label'] = $label;
			if ( ! empty( $changes ) ) {
				$updated = MAD4B_SCP_Agent_Registry::update_agent( $agent['public_id'], $changes, (int) $agent['revision'] );
				if ( is_wp_error( $updated ) ) return self::finish_blocked( $status, $updated->get_error_code() );
				$agent = $updated;
			}
		}

		$desired_subjects = array();
		$subject_blockers = array();
		foreach ( $user_ids as $user_id ) {
			$fingerprint = self::subject_fingerprint( $issuer, $user_id );
			$desired_subjects[ $fingerprint ] = true;
			$identity = array( 'authenticated' => true, 'subject_type' => 'oauth', 'subject_fingerprint' => $fingerprint );
			$bound = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
			if ( is_wp_error( $bound ) ) {
				if ( 'mad4b_nhi_subject_unbound' !== $bound->get_error_code() ) {
					$subject_blockers[] = $bound->get_error_code() . ':user:' . $user_id;
					continue;
				}
				$binding = MAD4B_SCP_Agent_Registry::bind_subject( $agent['public_id'], 'oauth', $fingerprint, 'Local OAuth user:' . $user_id );
				if ( is_wp_error( $binding ) ) $subject_blockers[] = $binding->get_error_code() . ':user:' . $user_id;
			} elseif ( (int) $bound['id'] !== (int) $agent['id'] ) {
				$subject_blockers[] = 'oauth_subject_bound_to_other_agent:user:' . $user_id;
			}
		}

		$subjects = MAD4B_SCP_Agent_Registry::subjects_for_agent( $agent['id'], 'oauth' );
		$subjects_disabled = 0;
		foreach ( $subjects as $subject ) {
			$fingerprint = isset( $subject['subject_fingerprint'] ) ? strtolower( (string) $subject['subject_fingerprint'] ) : '';
			if ( isset( $desired_subjects[ $fingerprint ] ) ) continue;
			if ( 'enabled' !== (string) $subject['status'] ) continue;
			$disabled = MAD4B_SCP_Agent_Registry::set_subject_status( $agent['public_id'], 'oauth', $fingerprint, 'disabled' );
			if ( is_wp_error( $disabled ) ) $subject_blockers[] = $disabled->get_error_code() . ':stale_subject';
			else ++$subjects_disabled;
		}

		$tools = self::write_tools();
		if ( empty( $tools ) ) return self::finish_blocked( $status, 'write_tool_inventory_empty' );

		$desired_grants = array();
		$inventory_rows = array();
		$grant_blockers = array();
		foreach ( $tools as $ability ) {
			if ( 'mad4b/database-raw-query' === $ability ) {
				$grant_blockers[] = 'breakglass_leak';
				continue;
			}
			$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
			if ( null === $provider ) {
				$grant_blockers[] = 'unmounted:' . $ability;
				continue;
			}
			$key = (string) $ability . "\0" . (string) $provider;
			$desired_grants[ $key ] = array( 'ability' => (string) $ability, 'provider' => (string) $provider );
			$inventory_rows[] = array( 'ability' => (string) $ability, 'provider' => (string) $provider );
		}

		$grants_revoked = 0;
		$existing_grants = MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], 'mad4b-write' );
		foreach ( $existing_grants as $grant ) {
			if ( 'allow' !== (string) $grant['effect'] ) continue;
			$key = (string) $grant['ability_name'] . "\0" . (string) $grant['provider'];
			$stale = ! isset( $desired_grants[ $key ] ) || $environment !== (string) $grant['environment'];
			if ( ! $stale ) continue;
			$revoked = MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id( $agent['public_id'], (int) $grant['id'], 'mad4b-write' );
			if ( is_wp_error( $revoked ) ) $grant_blockers[] = $revoked->get_error_code() . ':stale_grant';
			else ++$grants_revoked;
		}

		$granted = 0;
		$existing = 0;
		foreach ( $desired_grants as $desired ) {
			$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $desired['ability'], $desired['provider'] );
			if ( ! is_wp_error( $grant ) ) {
				if ( $environment === (string) $grant['environment'] ) ++$existing;
				continue;
			}
			if ( 'mad4b_nhi_grant_denied' === $grant->get_error_code() ) {
				$grant_blockers[] = 'explicit_deny:' . $desired['ability'];
				continue;
			}
			if ( 'mad4b_nhi_grant_missing' !== $grant->get_error_code() ) {
				$grant_blockers[] = $grant->get_error_code() . ':' . $desired['ability'];
				continue;
			}
			$created = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-write', $desired['ability'], $desired['provider'], array(), 'allow', $environment );
			if ( is_wp_error( $created ) ) $grant_blockers[] = $created->get_error_code() . ':' . $desired['ability'];
			else ++$granted;
		}

		usort( $inventory_rows, static function ( $a, $b ) { return strcmp( $a['ability'] . "\0" . $a['provider'], $b['ability'] . "\0" . $b['provider'] ); } );
		$all_blockers = array_values( array_unique( array_merge( $subject_blockers, $grant_blockers ) ) );
		$status['agent_public_id'] = (string) $agent['public_id'];
		$status['agent_slug'] = $agent_slug;
		$status['oauth_user_ids'] = array_values( array_map( 'absint', $user_ids ) );
		$status['subject_count_expected'] = count( $desired_subjects );
		$status['stale_subjects_disabled'] = $subjects_disabled;
		$status['write_tool_count'] = count( $tools );
		$status['write_inventory_fingerprint'] = hash( 'sha256', wp_json_encode( $inventory_rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$status['exact_grants_existing'] = $existing;
		$status['exact_grants_created'] = $granted;
		$status['stale_allow_grants_revoked'] = $grants_revoked;
		$status['grant_blockers'] = $all_blockers;
		$status['all_remote_writes_require_exact_approval'] = true;
		$status['breakglass_included'] = in_array( 'mad4b/database-raw-query', $tools, true );
		$status['ready'] = empty( $all_blockers ) && ! $status['breakglass_included'];
		$status['state'] = $status['ready'] ? 'ready' : 'blocked';
		$status['blocker'] = $status['ready'] ? '' : ( ! empty( $all_blockers ) ? 'authority_reconciliation_incomplete' : 'breakglass_leak' );
		$status['updated_at'] = gmdate( 'c' );
		$status['remote_transport'] = 'mad4b-chatgpt';
		$status['authority_server'] = 'mad4b-write';
		$status['oauth_role'] = 'identity_only';
		$status['write_authority_components'] = array( 'site_profile', 'exact_origin', 'oauth_identity', 'nhi_subject_binding', 'exact_mad4b_write_grant', 'provider_runtime', 'global_mutation_gate', 'budget_reservation', 'one_time_exact_approval', 'audit' );

		if ( $status['ready'] ) self::persist_ready_status( $status, $granted, $existing, $grants_revoked, $subjects_disabled );
		self::$status = $status;
		self::$reconciling = false;
		return $status;
	}

	public static function register_status_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( 'mad4b/write-authority-status' ) ) return;
		wp_register_ability( 'mad4b/write-authority-status', array(
			'label' => 'Get Governed Write Authority Status',
			'description' => 'Read the site-profile-bound NHI/grant/approval status for governed writes.',
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

	private static function persist_ready_status( array &$status, $granted, $existing, $revoked, $subjects_disabled ) {
		$stored = get_option( self::OPTION, array() );
		$keys = array( 'agent_public_id', 'write_tool_count', 'write_inventory_fingerprint', 'site_uuid', 'site_profile_revision', 'site_profile_digest', 'environment', 'origin' );
		$changed = ! is_array( $stored ) || empty( $stored['ready'] ) || ! empty( $stored['blocker'] );
		foreach ( $keys as $key ) {
			if ( $changed ) break;
			if ( ! array_key_exists( $key, $stored ) || ! array_key_exists( $key, $status ) || (string) $stored[ $key ] !== (string) $status[ $key ] ) $changed = true;
		}
		if ( $changed ) {
			if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
				MAD4B_SCP_Audit::record( 'mad4b/governed-write-authority-reconciled', array(
					'agent_public_id' => $status['agent_public_id'],
					'site_uuid' => $status['site_uuid'],
					'site_profile_revision' => $status['site_profile_revision'],
					'site_profile_digest' => $status['site_profile_digest'],
					'write_tool_count' => $status['write_tool_count'],
					'write_inventory_fingerprint' => $status['write_inventory_fingerprint'],
					'exact_grants_created' => (int) $granted,
					'exact_grants_existing' => (int) $existing,
					'stale_allow_grants_revoked' => (int) $revoked,
					'stale_subjects_disabled' => (int) $subjects_disabled,
					'environment' => $status['environment'],
					'origin' => $status['origin'],
					'breakglass_included' => false,
				), 'ok' );
			}
			update_option( self::OPTION, $status, false );
			$status['persistence'] = 'recorded';
		} else {
			$status['persistence'] = 'unchanged';
			$status['persisted_updated_at'] = isset( $stored['updated_at'] ) ? (string) $stored['updated_at'] : '';
		}
	}

	private static function deprovision_managed_authority( array &$status ) {
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return;
		$agent = self::agent_by_slug( self::agent_slug() );
		if ( ! $agent ) return;
		$changed = false;
		$subjects = MAD4B_SCP_Agent_Registry::subjects_for_agent( $agent['id'], 'oauth' );
		foreach ( $subjects as $subject ) {
			if ( 'enabled' !== (string) $subject['status'] ) continue;
			$result = MAD4B_SCP_Agent_Registry::set_subject_status( $agent['public_id'], 'oauth', $subject['subject_fingerprint'], 'disabled' );
			if ( ! is_wp_error( $result ) ) $changed = true;
		}
		$grants = MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], 'mad4b-write' );
		foreach ( $grants as $grant ) {
			if ( 'allow' !== (string) $grant['effect'] ) continue;
			$result = MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id( $agent['public_id'], (int) $grant['id'], 'mad4b-write' );
			if ( ! is_wp_error( $result ) ) $changed = true;
		}
		if ( 'enabled' === (string) $agent['status'] ) {
			$result = MAD4B_SCP_Agent_Registry::disable_agent( $agent['public_id'], (int) $agent['revision'] );
			if ( ! is_wp_error( $result ) ) $changed = true;
		}
		if ( $changed && class_exists( 'MAD4B_SCP_Audit' ) ) {
			MAD4B_SCP_Audit::record( 'mad4b/governed-write-authority-deprovisioned', array(
				'agent_public_id' => (string) $agent['public_id'],
				'site_uuid' => isset( $status['site_uuid'] ) ? (string) $status['site_uuid'] : '',
				'environment' => isset( $status['environment'] ) ? (string) $status['environment'] : '',
				'origin' => isset( $status['origin'] ) ? (string) $status['origin'] : '',
				'blocker' => isset( $status['blocker'] ) ? (string) $status['blocker'] : '',
			), 'ok' );
		}
	}

	private static function finish_blocked( array $status, $blocker ) {
		$status['blocker'] = sanitize_key( (string) $blocker );
		$status['state'] = 'blocked';
		$status['ready'] = false;
		self::$status = $status;
		self::$reconciling = false;
		return $status;
	}

	private static function agent_by_slug( $slug ) {
		global $wpdb;
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) ) return null;
		$t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['agents']} WHERE slug = %s LIMIT 1", sanitize_key( (string) $slug ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $row ? $row : null;
	}

	private static function enrolled_user_ids() {
		$users = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::oauth_user_ids() : array();
		if ( ! empty( $users ) ) return $users;
		$oauth = class_exists( 'MAD4B_SCP_Staging_OAuth_Autoconfig' ) ? MAD4B_SCP_Staging_OAuth_Autoconfig::status() : array();
		$user_id = isset( $oauth['wp_user_id'] ) ? absint( $oauth['wp_user_id'] ) : 0;
		return $user_id > 0 ? array( $user_id ) : array();
	}

	private static function oauth_issuer() {
		return class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? rtrim( (string) MAD4B_SCP_Local_OAuth_Server::issuer(), '/' ) : '';
	}

	private static function subject_fingerprint( $issuer, $user_id ) {
		return hash( 'sha256', 'oauth' . "\0" . (string) $issuer . "\0" . 'user:' . absint( $user_id ) );
	}

	private static function agent_slug() {
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) ) return sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() );
		return 'chatgpt-governed-write';
	}

	private static function current_environment() {
		return function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
	}

	private static function base_status() {
		$environment = self::current_environment();
		$origin = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_origin() : '';
		$profile_status = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$configured = ! empty( $profile_status['configured'] );
		$origin_enrolled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled();
		$site_urls_match = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
		$exact_profile_bound = $origin_enrolled && $site_urls_match;
		$write_enabled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::write_enabled();
		$eligible = $configured && $exact_profile_bound && $write_enabled;
		$blocker = '';
		if ( ! $configured ) $blocker = 'site_profile_unconfigured';
		elseif ( ! $origin_enrolled ) {
			$profile_blockers = isset( $profile_status['blockers'] ) && is_array( $profile_status['blockers'] ) ? $profile_status['blockers'] : array();
			$blocker = ! empty( $profile_blockers ) ? sanitize_key( (string) reset( $profile_blockers ) ) : 'site_profile_drift';
		} elseif ( ! $site_urls_match ) $blocker = 'site_profile_site_urls_mismatch';
		elseif ( ! $write_enabled ) $blocker = 'site_profile_write_disabled';

		return array(
			'contract' => self::CONTRACT,
			'version' => self::VERSION,
			'environment' => $environment,
			'origin' => $origin,
			'host' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_host() : '',
			'site_uuid' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::site_uuid() : '',
			'site_profile_revision' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::revision() : 0,
			'site_profile_digest' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::profile_digest() : '',
			'profile_origin_enrolled' => $origin_enrolled,
			'profile_site_urls_match' => $site_urls_match,
			'exact_profile_bound' => $exact_profile_bound,
			'profile_write_enabled' => $write_enabled,
			'eligible' => $eligible,
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
	}
}
