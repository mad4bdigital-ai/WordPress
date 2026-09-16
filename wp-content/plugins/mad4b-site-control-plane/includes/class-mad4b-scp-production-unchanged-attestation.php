<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reproducible external Production-unchanged attestation.
 *
 * The v1 finalizer accepted opaque before/after digests. This v2 overlay keeps
 * Production read-only and requires the external finalizer to submit the raw,
 * bounded observations from an allowlisted read-only connector. Snapshot hashes
 * and the runtime identity are derived here from normalized data; callers do not
 * supply those derived facts.
 */
final class MAD4B_SCP_Production_Unchanged_Attestation {
	const CONTRACT = 'mad4b.production-unchanged-receipt.v2';
	const PRODUCER_CONTRACT = 'mad4b.external-production-readonly-observer.v1';
	const RUNTIME_CONTRACT = 'mad4b.production-runtime-observation.v1';
	const PLUGIN_CONTRACT = 'mad4b.production-plugin-inventory.v1';
	const FINALIZER_ISSUER = 'chatgpt_external_read_only_finalizer';
	const FINALIZER_PROVENANCE = 'verified_external_readonly_connector';
	const ENVIRONMENT_ABILITY = 'core__get-environment-info';
	const SITE_ABILITY = 'hostinger-ai-assistant__site-info-get';
	const PLUGIN_ABILITY = 'hostinger-ai-assistant__plugin-list';
	const MAX_PLUGINS = 256;
	const MAX_OBSERVATION_WINDOW = 300;
	const TTL = 1800;

	private static $booted = false;

	public static function boot_early() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'bind_callbacks' ), 170, 2 );
	}

	public static function bind_callbacks( $args, $name ) {
		if ( ! is_array( $args ) || 'mad4b/live-acceptance-status' !== (string) $name ) return $args;
		$args['execute_callback'] = array( __CLASS__, 'live_acceptance_status' );
		$args['input_schema'] = array(
			'type' => 'object',
			'properties' => array(
				'client_snapshot_token' => array( 'type' => 'string', 'maxLength' => 80 ),
				'production_receipt' => self::receipt_schema(),
			),
			'additionalProperties' => false,
		);
		return $args;
	}

	public static function live_acceptance_status( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$base_input = $input;
		unset( $base_input['production_receipt'] );
		$base = MAD4B_SCP_Live_Acceptance_Finalizer::live_acceptance_status( $base_input );
		if ( ! is_array( $base ) ) $base = array( 'gates' => array() );
		if ( ! isset( $base['gates'] ) || ! is_array( $base['gates'] ) ) $base['gates'] = array();
		$receipt = isset( $input['production_receipt'] ) && is_array( $input['production_receipt'] ) ? $input['production_receipt'] : array();
		$candidate = self::current_candidate();
		$base['gates']['production_unchanged'] = self::evaluate_receipt( $receipt, $candidate, self::trusted_external_finalizer_context() );
		$base['ready'] = MAD4B_SCP_Live_Acceptance_Finalizer::aggregate_ready( $base['gates'] );
		$base['state'] = $base['ready'] ? 'ready' : 'pending_or_blocked';
		$base['production_attestation_contract'] = self::CONTRACT;
		$base['production_opaque_digest_receipt_accepted'] = false;
		return $base;
	}

	public static function receipt_schema() {
		$plugin = array(
			'type' => 'object',
			'properties' => array(
				'plugin_file' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 240 ),
				'version' => array( 'type' => 'string', 'maxLength' => 80 ),
				'active' => array( 'type' => 'boolean' ),
			),
			'required' => array( 'plugin_file', 'version', 'active' ),
			'additionalProperties' => false,
		);
		$runtime = array(
			'type' => 'object',
			'properties' => array(
				'contract' => array( 'type' => 'string', 'enum' => array( self::RUNTIME_CONTRACT ) ),
				'environment' => array( 'type' => 'string', 'enum' => array( 'production' ) ),
				'site_url' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 2048 ),
				'wordpress_version' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 40 ),
				'php_version' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 80 ),
				'db_server_info' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 160 ),
				'active_theme_stylesheet' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 120 ),
				'active_theme_version' => array( 'type' => 'string', 'maxLength' => 80 ),
			),
			'required' => array( 'contract','environment','site_url','wordpress_version','php_version','db_server_info','active_theme_stylesheet','active_theme_version' ),
			'additionalProperties' => false,
		);
		$observation = array(
			'type' => 'object',
			'properties' => array(
				'observed_at' => array( 'type' => 'string', 'minLength' => 20, 'maxLength' => 40 ),
				'runtime' => $runtime,
				'plugin_inventory_contract' => array( 'type' => 'string', 'enum' => array( self::PLUGIN_CONTRACT ) ),
				'plugins' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_PLUGINS, 'items' => $plugin ),
			),
			'required' => array( 'observed_at','runtime','plugin_inventory_contract','plugins' ),
			'additionalProperties' => false,
		);
		return array(
			'type' => 'object',
			'properties' => array(
				'contract' => array( 'type' => 'string', 'enum' => array( self::CONTRACT ) ),
				'candidate_sha' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{40}$' ),
				'build_fingerprint' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'target' => array( 'type' => 'string', 'enum' => array( 'production' ) ),
				'origin' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 2048 ),
				'environment' => array( 'type' => 'string', 'enum' => array( 'production' ) ),
				'producer' => array(
					'type' => 'object',
					'properties' => array(
						'contract' => array( 'type' => 'string', 'enum' => array( self::PRODUCER_CONTRACT ) ),
						'read_only' => array( 'type' => 'boolean', 'enum' => array( true ) ),
						'environment_ability' => array( 'type' => 'string', 'enum' => array( self::ENVIRONMENT_ABILITY ) ),
						'site_ability' => array( 'type' => 'string', 'enum' => array( self::SITE_ABILITY ) ),
						'plugin_inventory_ability' => array( 'type' => 'string', 'enum' => array( self::PLUGIN_ABILITY ) ),
					),
					'required' => array( 'contract','read_only','environment_ability','site_ability','plugin_inventory_ability' ),
					'additionalProperties' => false,
				),
				'baseline' => $observation,
				'observed' => $observation,
				'issued_at' => array( 'type' => 'string', 'minLength' => 20, 'maxLength' => 40 ),
				'issuer' => array( 'type' => 'string', 'enum' => array( self::FINALIZER_ISSUER ) ),
				'provenance' => array( 'type' => 'string', 'enum' => array( self::FINALIZER_PROVENANCE ) ),
				'evidence_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
			),
			'required' => array( 'contract','candidate_sha','build_fingerprint','target','origin','environment','producer','baseline','observed','issued_at','issuer','provenance','evidence_digest' ),
			'additionalProperties' => false,
		);
	}

	public static function evaluate_receipt( array $receipt, array $candidate, $trusted_context, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( empty( $receipt ) ) return self::gate( false, 'pending_external_evidence', false, array( 'trusted_external_production_receipt_v2_required' ) );
		$blockers = array();
		$state = 'invalid_evidence';
		if ( ! $trusted_context ) { $blockers[] = 'untrusted_finalizer_context'; $state = 'untrusted_finalizer_context'; }
		if ( self::CONTRACT !== ( isset( $receipt['contract'] ) ? (string) $receipt['contract'] : '' ) ) $blockers[] = 'production_receipt_v2_required';
		if ( empty( $candidate['source_commit_sha'] ) || empty( $receipt['candidate_sha'] ) || ! hash_equals( (string) $candidate['source_commit_sha'], (string) $receipt['candidate_sha'] ) ) { $blockers[] = 'candidate_mismatch'; $state = 'candidate_mismatch'; }
		if ( empty( $candidate['build_fingerprint'] ) || empty( $receipt['build_fingerprint'] ) || ! hash_equals( (string) $candidate['build_fingerprint'], (string) $receipt['build_fingerprint'] ) ) { $blockers[] = 'build_fingerprint_mismatch'; if ( 'candidate_mismatch' !== $state ) $state = 'build_fingerprint_mismatch'; }
		$production_origin = self::production_origin();
		if ( '' === $production_origin || 'production' !== ( isset( $receipt['target'] ) ? (string) $receipt['target'] : '' ) || 'production' !== ( isset( $receipt['environment'] ) ? (string) $receipt['environment'] : '' ) || ! hash_equals( $production_origin, rtrim( isset( $receipt['origin'] ) ? (string) $receipt['origin'] : '', '/' ) ) ) $blockers[] = 'wrong_production_identity';
		if ( self::FINALIZER_ISSUER !== ( isset( $receipt['issuer'] ) ? (string) $receipt['issuer'] : '' ) || self::FINALIZER_PROVENANCE !== ( isset( $receipt['provenance'] ) ? (string) $receipt['provenance'] : '' ) ) $blockers[] = 'untrusted_receipt_provenance';
		if ( ! self::producer_valid( isset( $receipt['producer'] ) && is_array( $receipt['producer'] ) ? $receipt['producer'] : array() ) ) $blockers[] = 'untrusted_production_observation_producer';

		$baseline = self::normalize_observation( isset( $receipt['baseline'] ) && is_array( $receipt['baseline'] ) ? $receipt['baseline'] : array() );
		$observed = self::normalize_observation( isset( $receipt['observed'] ) && is_array( $receipt['observed'] ) ? $receipt['observed'] : array() );
		if ( is_wp_error( $baseline ) || is_wp_error( $observed ) ) {
			$blockers[] = 'production_observation_invalid';
		} else {
			$baseline_runtime = self::runtime_digest( $baseline['runtime'] );
			$observed_runtime = self::runtime_digest( $observed['runtime'] );
			$baseline_plugins = self::plugin_digest( $baseline['plugins'] );
			$observed_plugins = self::plugin_digest( $observed['plugins'] );
			if ( ! hash_equals( $baseline_runtime, $observed_runtime ) ) $blockers[] = 'production_snapshot_changed';
			if ( ! hash_equals( $baseline_plugins, $observed_plugins ) ) $blockers[] = 'production_plugin_snapshot_changed';
		}

		$baseline_at = ! is_wp_error( $baseline ) ? self::parse_time( $baseline['observed_at'] ) : false;
		$observed_at = ! is_wp_error( $observed ) ? self::parse_time( $observed['observed_at'] ) : false;
		$issued_at = self::parse_time( isset( $receipt['issued_at'] ) ? $receipt['issued_at'] : '' );
		$fresh = false !== $baseline_at && false !== $observed_at && false !== $issued_at
			&& $baseline_at <= $observed_at && $observed_at <= $issued_at
			&& $issued_at <= $now + 60 && $baseline_at <= $now + 60
			&& ( $now - $baseline_at ) <= self::TTL
			&& ( $observed_at - $baseline_at ) <= self::MAX_OBSERVATION_WINDOW
			&& ( $issued_at - $observed_at ) <= self::MAX_OBSERVATION_WINDOW;
		if ( ! $fresh ) { $blockers[] = 'stale_evidence'; $state = 'stale_evidence'; }

		$provided_digest = isset( $receipt['evidence_digest'] ) ? strtolower( (string) $receipt['evidence_digest'] ) : '';
		$computed_digest = self::receipt_digest( $receipt );
		if ( ! self::valid_hash( $provided_digest ) || ! hash_equals( $computed_digest, $provided_digest ) ) $blockers[] = 'evidence_digest_mismatch';
		$blockers = array_values( array_unique( $blockers ) );
		$ready = empty( $blockers );
		if ( $ready ) $state = 'ready'; elseif ( 'invalid_evidence' === $state && $blockers ) $state = $blockers[0];
		$gate = self::gate( $ready, $state, $fresh, $blockers, ! is_wp_error( $observed ) ? $observed['observed_at'] : '' );
		if ( ! is_wp_error( $baseline ) && ! is_wp_error( $observed ) ) {
			$gate['production_runtime_identity'] = 'sha256:' . self::runtime_digest( $observed['runtime'] );
			$gate['runtime_snapshot_digest'] = self::runtime_digest( $observed['runtime'] );
			$gate['plugin_snapshot_digest'] = self::plugin_digest( $observed['plugins'] );
			$gate['plugin_count'] = count( $observed['plugins'] );
		}
		$gate['producer_verified'] = ! in_array( 'untrusted_production_observation_producer', $blockers, true );
		return $gate;
	}

	public static function receipt_digest( array $receipt ) {
		unset( $receipt['evidence_digest'] );
		return hash( 'sha256', self::canonical_json( $receipt ) );
	}

	public static function normalize_observation( array $observation ) {
		if ( self::PLUGIN_CONTRACT !== ( isset( $observation['plugin_inventory_contract'] ) ? (string) $observation['plugin_inventory_contract'] : '' ) ) return new WP_Error( 'mad4b_production_plugin_contract_invalid' );
		$observed_at = isset( $observation['observed_at'] ) ? trim( (string) $observation['observed_at'] ) : '';
		$runtime = self::normalize_runtime( isset( $observation['runtime'] ) && is_array( $observation['runtime'] ) ? $observation['runtime'] : array() );
		$plugins = self::normalize_plugins( isset( $observation['plugins'] ) && is_array( $observation['plugins'] ) ? $observation['plugins'] : array() );
		if ( false === self::parse_time( $observed_at ) || is_wp_error( $runtime ) || is_wp_error( $plugins ) ) return new WP_Error( 'mad4b_production_observation_invalid' );
		return array( 'observed_at' => $observed_at, 'runtime' => $runtime, 'plugin_inventory_contract' => self::PLUGIN_CONTRACT, 'plugins' => $plugins );
	}

	public static function normalize_runtime( array $runtime ) {
		if ( self::RUNTIME_CONTRACT !== ( isset( $runtime['contract'] ) ? (string) $runtime['contract'] : '' ) ) return new WP_Error( 'mad4b_production_runtime_contract_invalid' );
		$out = array(
			'contract' => self::RUNTIME_CONTRACT,
			'environment' => isset( $runtime['environment'] ) ? strtolower( trim( (string) $runtime['environment'] ) ) : '',
			'site_url' => isset( $runtime['site_url'] ) ? rtrim( trim( (string) $runtime['site_url'] ), '/' ) : '',
			'wordpress_version' => isset( $runtime['wordpress_version'] ) ? trim( (string) $runtime['wordpress_version'] ) : '',
			'php_version' => isset( $runtime['php_version'] ) ? trim( (string) $runtime['php_version'] ) : '',
			'db_server_info' => isset( $runtime['db_server_info'] ) ? trim( (string) $runtime['db_server_info'] ) : '',
			'active_theme_stylesheet' => isset( $runtime['active_theme_stylesheet'] ) ? strtolower( trim( (string) $runtime['active_theme_stylesheet'] ) ) : '',
			'active_theme_version' => isset( $runtime['active_theme_version'] ) ? trim( (string) $runtime['active_theme_version'] ) : '',
		);
		$production_origin = self::production_origin();
		if ( '' === $production_origin || 'production' !== $out['environment'] || ! hash_equals( $production_origin, $out['site_url'] ) ) return new WP_Error( 'mad4b_production_runtime_identity_invalid' );
		foreach ( array( 'wordpress_version','php_version','db_server_info','active_theme_stylesheet' ) as $key ) if ( '' === $out[ $key ] ) return new WP_Error( 'mad4b_production_runtime_incomplete' );
		return $out;
	}

	public static function normalize_plugins( array $plugins ) {
		if ( empty( $plugins ) || count( $plugins ) > self::MAX_PLUGINS ) return new WP_Error( 'mad4b_production_plugin_inventory_size_invalid' );
		$out = array(); $seen = array();
		foreach ( $plugins as $plugin ) {
			if ( ! is_array( $plugin ) ) return new WP_Error( 'mad4b_production_plugin_entry_invalid' );
			$file = isset( $plugin['plugin_file'] ) ? trim( wp_normalize_path( (string) $plugin['plugin_file'] ) ) : '';
			if ( '' === $file || strlen( $file ) > 240 || false !== strpos( $file, '..' ) || '/' === substr( $file, 0, 1 ) || ! preg_match( '#^[A-Za-z0-9._-]+/[A-Za-z0-9._/-]+\.php$#', $file ) ) return new WP_Error( 'mad4b_production_plugin_file_invalid' );
			if ( isset( $seen[ $file ] ) ) return new WP_Error( 'mad4b_production_plugin_duplicate' );
			$seen[ $file ] = true;
			$out[] = array( 'plugin_file' => $file, 'version' => isset( $plugin['version'] ) ? trim( (string) $plugin['version'] ) : '', 'active' => ! empty( $plugin['active'] ) );
		}
		usort( $out, static function ( $a, $b ) { return strcmp( $a['plugin_file'], $b['plugin_file'] ); } );
		return $out;
	}

	public static function runtime_digest( array $runtime ) { return hash( 'sha256', self::canonical_json( $runtime ) ); }
	public static function plugin_digest( array $plugins ) { return hash( 'sha256', self::canonical_json( $plugins ) ); }


	private static function production_origin() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return '';
		return rtrim( (string) MAD4B_SCP_Site_Profile::related_origin( 'production' ), '/' );
	}

	private static function producer_valid( array $producer ) {
		return self::PRODUCER_CONTRACT === ( isset( $producer['contract'] ) ? (string) $producer['contract'] : '' )
			&& true === ( isset( $producer['read_only'] ) ? $producer['read_only'] : null )
			&& self::ENVIRONMENT_ABILITY === ( isset( $producer['environment_ability'] ) ? (string) $producer['environment_ability'] : '' )
			&& self::SITE_ABILITY === ( isset( $producer['site_ability'] ) ? (string) $producer['site_ability'] : '' )
			&& self::PLUGIN_ABILITY === ( isset( $producer['plugin_inventory_ability'] ) ? (string) $producer['plugin_inventory_ability'] : '' );
	}

	private static function trusted_external_finalizer_context() {
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return false;
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : null;
		if ( is_wp_error( $identity ) || ! is_array( $identity ) || empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return false;
		$scopes = isset( $identity['token_scopes'] ) && is_array( $identity['token_scopes'] ) ? $identity['token_scopes'] : array();
		if ( ! in_array( 'mad4b:read', $scopes, true ) ) return false;
		$external = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::external_handshake_attestation_status() : array();
		return ! empty( $external['verified'] ) && ( empty( $external['client_id'] ) || 'https://chatgpt.com/oauth/client.json' === (string) $external['client_id'] );
	}

	private static function current_candidate() {
		$p = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
		$sha = isset( $p['source_commit_sha'] ) ? strtolower( (string) $p['source_commit_sha'] ) : '';
		$fingerprint = isset( $p['build_fingerprint'] ) ? strtolower( (string) $p['build_fingerprint'] ) : '';
		return array( 'ready' => ! empty( $p['runtime_manifest_match'] ) && preg_match( '/^[a-f0-9]{40}$/', $sha ) && self::valid_hash( $fingerprint ), 'source_commit_sha' => $sha, 'build_fingerprint' => $fingerprint );
	}

	private static function gate( $ready, $state, $fresh, array $blockers, $observed_at = '' ) {
		return array( 'state' => (string) $state, 'ready' => (bool) $ready, 'fresh' => (bool) $fresh, 'source_contract' => self::CONTRACT, 'blockers' => array_values( array_unique( array_filter( array_map( 'strval', $blockers ) ) ) ), 'observed_at' => $observed_at ? (string) $observed_at : gmdate( 'c' ) );
	}
	private static function parse_time( $value ) { if ( ! is_string( $value ) || '' === trim( $value ) ) return false; $ts = strtotime( $value ); return false === $ts ? false : $ts; }
	private static function valid_hash( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', strtolower( $value ) ); }
	private static function canonical_json( $value ) { $json = json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); return false === $json ? '' : $json; }
	private static function canonicalize( $value ) { if ( ! is_array( $value ) ) return $value; $is_list = array_keys( $value ) === range( 0, count( $value ) - 1 ); if ( $is_list ) return array_map( array( __CLASS__, 'canonicalize' ), $value ); ksort( $value, SORT_STRING ); foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item ); return $value; }
}
