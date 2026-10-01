<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical, read-only connection identity projection.
 *
 * This class never persists configuration, generates keys, performs outbound I/O,
 * reconciles providers, or mutates Site Profile state. It gives admin, protocol,
 * canary and external acceptance surfaces one deterministic connection contract.
 */
final class MAD4B_SCP_Connection_Identity_Resolver {
	const CONTRACT = 'mad4b.connection-contract.v1';
	const FINGERPRINT_CONTRACT = 'mad4b.connection-fingerprint.v1';
	const EDGE_CONTRACT = 'mad4b.connection-edge-contract.v1';

	private static $kernel = null;
	private static $resolved = null;

	public static function reset_request_cache() {
		self::$kernel = null;
		self::$resolved = null;
	}

	public static function kernel() {
		if ( is_array( self::$kernel ) ) return self::$kernel;

		$profile = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$configured = ! empty( $profile['configured'] );
		$origin_match = ! empty( $profile['origin_match'] );
		$environment_match = ! empty( $profile['environment_match'] );
		$environment = class_exists( 'MAD4B_SCP_Site_Profile' )
			? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() )
			: ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' );
		$origin = class_exists( 'MAD4B_SCP_Site_Profile' )
			? untrailingslashit( (string) MAD4B_SCP_Site_Profile::current_origin() )
			: ( function_exists( 'home_url' ) ? untrailingslashit( (string) home_url( '/' ) ) : '' );
		$enrolled_origin = class_exists( 'MAD4B_SCP_Site_Profile' ) ? untrailingslashit( (string) MAD4B_SCP_Site_Profile::site_origin() ) : '';
		$user_ids = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::oauth_user_ids() : array();
		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $user_ids ) ? $user_ids : array() ) ) ) );

		$subjects = array();
		$primary_owner = 0;
		foreach ( $user_ids as $user_id ) {
			$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : false;
			$exists = is_object( $user );
			$capable = $exists && function_exists( 'user_can' ) && user_can( $user, 'manage_options' );
			$subjects[] = array(
				'user_id' => $user_id,
				'subject' => 'user:' . $user_id,
				'exists' => $exists,
				'manage_options' => $capable,
			);
			if ( $primary_owner < 1 && $capable ) $primary_owner = $user_id;
		}

		$expected_issuer = '' !== $origin ? $origin . '/oauth/mcp' : '';
		$expected_resource = '' !== $origin ? $origin . '/wp-json/mcp/mad4b-chatgpt' : '';
		$overrides = self::explicit_override_status( $expected_issuer, $user_ids, $primary_owner );

		$blockers = array();
		if ( ! $configured ) $blockers[] = 'site_profile_unconfigured';
		if ( $configured && ! $origin_match ) $blockers[] = 'profile_origin_drift';
		if ( $configured && ! $environment_match ) $blockers[] = 'profile_environment_drift';
		if ( $configured && class_exists( 'MAD4B_SCP_Site_Profile' ) && ! MAD4B_SCP_Site_Profile::oauth_enabled() ) $blockers[] = 'site_profile_oauth_disabled';
		if ( $configured && $origin_match && $environment_match && empty( $user_ids ) ) $blockers[] = 'site_profile_subject_unavailable';
		foreach ( $subjects as $subject ) if ( empty( $subject['exists'] ) ) $blockers[] = 'site_profile_subject_invalid';
		if ( ! empty( $user_ids ) && $primary_owner < 1 ) $blockers[] = 'site_profile_admin_owner_required';
		if ( ! empty( $overrides['blockers'] ) ) $blockers = array_merge( $blockers, $overrides['blockers'] );
		$blockers = array_values( array_unique( array_map( 'sanitize_key', $blockers ) ) );

		$subject_digest = self::digest( array_values( array_map( static function ( $id ) { return 'user:' . absint( $id ); }, $user_ids ) ) );
		$root = self::first_blocker( $blockers );

		self::$kernel = array(
			'contract' => self::CONTRACT,
			'projection' => 'identity_kernel',
			'eligible' => empty( $blockers ),
			'effective' => empty( $blockers ),
			'root_blocker' => $root,
			'root_blocker_source' => self::blocker_source( $root ),
			'blockers' => $blockers,
			'site' => array(
				'site_uuid' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? (string) MAD4B_SCP_Site_Profile::site_uuid() : '',
				'profile_revision' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? (int) MAD4B_SCP_Site_Profile::revision() : 0,
				'profile_digest' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? (string) MAD4B_SCP_Site_Profile::profile_digest() : '',
				'environment' => $environment,
				'origin' => $origin,
				'enrolled_origin' => $enrolled_origin,
				'origin_match' => $origin_match,
				'environment_match' => $environment_match,
			),
			'oauth' => array(
				'mode' => 'local',
				'issuer' => $expected_issuer,
				'subject_count' => count( $user_ids ),
				'subject_digest' => $subject_digest,
				'subjects' => $subjects,
				'primary_owner_user_id' => $primary_owner,
				'primary_owner_capable' => $primary_owner > 0,
			),
			'resource' => array(
				'url' => $expected_resource,
				'protected_resource_metadata_url' => '' !== $origin ? $origin . '/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-chatgpt' : '',
			),
			'explicit_overrides' => $overrides,
			'read_only' => true,
			'mutation_performed' => false,
			'outbound_io_performed' => false,
			'provider_discovery_performed' => false,
		);
		self::$kernel['kernel_fingerprint'] = self::kernel_fingerprint( self::$kernel );
		return self::$kernel;
	}

	public static function resolve() {
		if ( is_array( self::$resolved ) ) return self::$resolved;
		$kernel = self::kernel();

		$issuer = isset( $kernel['oauth']['issuer'] ) ? (string) $kernel['oauth']['issuer'] : '';
		$resource = isset( $kernel['resource']['url'] ) ? (string) $kernel['resource']['url'] : '';
		if ( class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) && method_exists( 'MAD4B_SCP_Local_OAuth_Server', 'issuer' ) ) {
			$issuer = untrailingslashit( (string) MAD4B_SCP_Local_OAuth_Server::issuer() );
		}
		if ( class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) && method_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', 'resource_identifier' ) ) {
			$resource = untrailingslashit( (string) MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier() );
		}

		$key = class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) && method_exists( 'MAD4B_SCP_Local_OAuth_Server', 'public_key_fingerprint_status' )
			? MAD4B_SCP_Local_OAuth_Server::public_key_fingerprint_status()
			: array( 'ready' => false, 'fingerprint' => '', 'blocker' => 'oauth_public_key_identity_unavailable' );
		$build = self::build_identity();
		$adapter_version = defined( 'WP_MCP_VERSION' ) ? (string) WP_MCP_VERSION : '';
		$runtime_issuer_match = ! empty( $kernel['oauth']['issuer'] ) && '' !== $issuer && hash_equals( untrailingslashit( (string) $kernel['oauth']['issuer'] ), $issuer );
		$runtime_resource_match = ! empty( $kernel['resource']['url'] ) && '' !== $resource && hash_equals( untrailingslashit( (string) $kernel['resource']['url'] ), $resource );

		$blockers = isset( $kernel['blockers'] ) && is_array( $kernel['blockers'] ) ? $kernel['blockers'] : array();
		if ( ! $runtime_issuer_match ) $blockers[] = 'connection_projection_drift';
		if ( ! $runtime_resource_match ) $blockers[] = 'connection_projection_drift';
		if ( empty( $key['ready'] ) ) $blockers[] = isset( $key['blocker'] ) ? sanitize_key( (string) $key['blocker'] ) : 'oauth_public_key_not_ready';
		if ( empty( $build['identity_ready'] ) ) $blockers[] = 'package_identity_drift';
		$blockers = array_values( array_unique( array_map( 'sanitize_key', $blockers ) ) );
		$root = self::first_blocker( $blockers );

		$result = $kernel;
		$result['projection'] = 'canonical_connection';
		$result['oauth']['issuer'] = $issuer;
		$result['resource']['url'] = $resource;
		$result['oauth']['public_key_fingerprint'] = isset( $key['fingerprint'] ) ? (string) $key['fingerprint'] : '';
		$result['oauth']['key_ready'] = ! empty( $key['ready'] );
		$result['runtime_projection'] = array(
			'issuer_match' => $runtime_issuer_match,
			'resource_match' => $runtime_resource_match,
			'adapter_version' => $adapter_version,
		);
		$result['package_identity'] = $build;
		$result['blockers'] = $blockers;
		$result['root_blocker'] = $root;
		$result['root_blocker_source'] = self::blocker_source( $root );
		$result['eligible'] = empty( $blockers );
		$result['effective'] = empty( $blockers );
		$result['connection_fingerprint'] = self::connection_fingerprint( $result );
		$result['edge_contract'] = self::expected_edge_contract( $result );
		self::$resolved = $result;
		return self::$resolved;
	}

	public static function fingerprint() {
		$status = self::resolve();
		return isset( $status['connection_fingerprint'] ) ? (string) $status['connection_fingerprint'] : '';
	}

	public static function root_blocker() {
		$status = self::resolve();
		return isset( $status['root_blocker'] ) ? sanitize_key( (string) $status['root_blocker'] ) : '';
	}

	public static function expected_edge_contract( $resolved = null ) {
		$resolved = is_array( $resolved ) ? $resolved : self::resolve();
		$origin = isset( $resolved['site']['origin'] ) ? untrailingslashit( (string) $resolved['site']['origin'] ) : '';
		$issuer = isset( $resolved['oauth']['issuer'] ) ? untrailingslashit( (string) $resolved['oauth']['issuer'] ) : '';
		$resource = isset( $resolved['resource']['url'] ) ? untrailingslashit( (string) $resolved['resource']['url'] ) : '';
		$metadata = '' !== $issuer ? $origin . '/.well-known/oauth-authorization-server/' . ltrim( (string) wp_parse_url( $issuer, PHP_URL_PATH ), '/' ) : '';
		return array(
			'contract' => self::EDGE_CONTRACT,
			'origin' => $origin,
			'resource' => $resource,
			'issuer' => $issuer,
			'connection_fingerprint' => isset( $resolved['connection_fingerprint'] ) ? (string) $resolved['connection_fingerprint'] : '',
			'endpoints' => array(
				'protected_resource_metadata' => '' !== $origin ? $origin . '/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-chatgpt' : '',
				'authorization_server_metadata' => $metadata,
				'jwks' => '' !== $issuer ? $issuer . '/jwks' : '',
				'mcp' => $resource,
			),
			'assertions' => array(
				'resource_exact_match',
				'authorization_servers_contains_exact_issuer',
				'issuer_exact_match',
				'jwks_uri_same_issuer',
				'pkce_s256_advertised',
				'no_wp_login_redirect',
				'no_html_or_waf_challenge',
				'no_private_or_basic_auth_gate',
				'no_wrong_host_redirect',
				'json_content_type_for_discovery',
				'connection_fingerprint_match',
			),
			'outbound_probe_performed' => false,
		);
	}

	public static function differential( array $left, array $right ) {
		$paths = array(
			'site.environment',
			'site.origin',
			'site.profile_revision',
			'site.profile_digest',
			'oauth.subject_count',
			'oauth.primary_owner_capable',
			'oauth.issuer',
			'oauth.public_key_fingerprint',
			'resource.url',
			'runtime_projection.adapter_version',
			'package_identity.source_commit_sha',
			'package_identity.build_fingerprint',
			'package_identity.package_manifest_digest',
			'package_identity.artifact_identity',
			'connection_fingerprint',
			'root_blocker',
		);
		$differences = array();
		foreach ( $paths as $path ) {
			$a = self::value_at_path( $left, $path );
			$b = self::value_at_path( $right, $path );
			if ( serialize( $a ) === serialize( $b ) ) continue;
			$differences[] = array( 'path' => $path, 'left' => $a, 'right' => $b );
		}
		return array(
			'contract' => 'mad4b.connection-differential.v1',
			'match' => empty( $differences ),
			'difference_count' => count( $differences ),
			'differences' => $differences,
		);
	}

	private static function explicit_override_status( $issuer, array $user_ids, $primary_owner ) {
		$blockers = array();
		$defined = array();
		$names = array(
			'MAD4B_MCP_LOCAL_OAUTH_ENABLED',
			'MAD4B_MCP_OAUTH_ENABLED',
			'MAD4B_MCP_OAUTH_MODE',
			'MAD4B_MCP_OAUTH_ISSUER',
			'MAD4B_MCP_LOCAL_OAUTH_ISSUER',
			'MAD4B_MCP_OAUTH_WP_USER_ID',
			'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS',
			'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS',
		);
		foreach ( $names as $name ) if ( defined( $name ) ) $defined[] = $name;

		if ( defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) && true !== constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) $blockers[] = 'explicit_local_oauth_disabled';
		if ( defined( 'MAD4B_MCP_OAUTH_ENABLED' ) && true !== constant( 'MAD4B_MCP_OAUTH_ENABLED' ) ) $blockers[] = 'explicit_resource_oauth_disabled';
		if ( defined( 'MAD4B_MCP_OAUTH_MODE' ) && 'local' !== sanitize_key( (string) constant( 'MAD4B_MCP_OAUTH_MODE' ) ) ) $blockers[] = 'explicit_non_local_oauth_mode';
		foreach ( array( 'MAD4B_MCP_OAUTH_ISSUER', 'MAD4B_MCP_LOCAL_OAUTH_ISSUER' ) as $name ) {
			if ( ! defined( $name ) ) continue;
			$value = untrailingslashit( trim( (string) constant( $name ) ) );
			if ( '' !== $value && '' !== $issuer && ! hash_equals( $issuer, $value ) ) $blockers[] = 'explicit_external_oauth_issuer';
		}
		if ( defined( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ) {
			$configured_user = absint( constant( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) );
			if ( $configured_user < 1 || ! in_array( $configured_user, $user_ids, true ) || ( $primary_owner > 0 && $configured_user !== (int) $primary_owner ) ) $blockers[] = 'explicit_wp_user_conflict';
		}
		$expected_subjects = array_values( array_map( static function ( $id ) { return 'user:' . absint( $id ); }, $user_ids ) );
		if ( defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) ) {
			$configured = self::normalize_subjects( constant( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) );
			foreach ( $expected_subjects as $subject ) if ( ! in_array( $subject, $configured, true ) ) $blockers[] = 'explicit_subject_policy_conflict';
		}
		if ( defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' ) ) {
			$bindings = constant( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' );
			foreach ( $expected_subjects as $subject ) if ( ! self::binding_contains( $bindings, $issuer, $subject ) ) $blockers[] = 'explicit_subject_binding_conflict';
		}
		return array(
			'source' => empty( $defined ) ? 'site_profile_projection' : 'wp-config.php',
			'defined_constants' => $defined,
			'conflict' => ! empty( $blockers ),
			'blockers' => array_values( array_unique( array_map( 'sanitize_key', $blockers ) ) ),
			'automatic_repair' => false,
		);
	}

	private static function normalize_subjects( $value ) {
		if ( is_array( $value ) ) $items = $value;
		else $items = preg_split( '/[\s,]+/', trim( (string) $value ) );
		$out = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$item = trim( (string) $item );
			if ( '' !== $item ) $out[] = $item;
		}
		return array_values( array_unique( $out ) );
	}

	private static function binding_contains( $bindings, $issuer, $subject ) {
		if ( is_string( $bindings ) ) {
			$decoded = json_decode( $bindings, true );
			if ( is_array( $decoded ) ) $bindings = $decoded;
		}
		if ( ! is_array( $bindings ) ) return false;
		$subjects = isset( $bindings[ $issuer ] ) ? self::normalize_subjects( $bindings[ $issuer ] ) : array();
		return in_array( $subject, $subjects, true );
	}

	private static function build_identity() {
		$base = array(
			'identity_ready' => false,
			'source_commit_sha' => '',
			'build_fingerprint' => '',
			'package_manifest_digest' => '',
			'artifact_identity' => '',
		);
		if ( class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) && method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_identity_status' ) ) {
			$status = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status();
			if ( is_array( $status ) ) return array_merge( $base, array_intersect_key( $status, array_flip( array_keys( $base ) ) ) );
		}
		$path = defined( 'MAD4B_SCP_DIR' ) ? rtrim( (string) MAD4B_SCP_DIR, "/\\" ) . '/MAD4B-BUILD-PROVENANCE.json' : '';
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) return $base;
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) ) return $base;
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $field ) {
			if ( isset( $data[ $field ] ) ) $base[ $field ] = strtolower( trim( (string) $data[ $field ] ) );
		}
		$base['identity_ready'] = 1 === preg_match( '/^[a-f0-9]{40}$/', $base['source_commit_sha'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $base['build_fingerprint'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $base['package_manifest_digest'] )
			&& '' !== $base['artifact_identity'];
		return $base;
	}

	private static function kernel_fingerprint( array $status ) {
		$parts = array(
			'site_uuid' => isset( $status['site']['site_uuid'] ) ? $status['site']['site_uuid'] : '',
			'profile_revision' => isset( $status['site']['profile_revision'] ) ? $status['site']['profile_revision'] : 0,
			'profile_digest' => isset( $status['site']['profile_digest'] ) ? $status['site']['profile_digest'] : '',
			'environment' => isset( $status['site']['environment'] ) ? $status['site']['environment'] : '',
			'origin' => isset( $status['site']['origin'] ) ? $status['site']['origin'] : '',
			'resource' => isset( $status['resource']['url'] ) ? $status['resource']['url'] : '',
			'issuer' => isset( $status['oauth']['issuer'] ) ? $status['oauth']['issuer'] : '',
			'oauth_subject_digest' => isset( $status['oauth']['subject_digest'] ) ? $status['oauth']['subject_digest'] : '',
			'primary_owner' => isset( $status['oauth']['primary_owner_user_id'] ) ? $status['oauth']['primary_owner_user_id'] : 0,
		);
		return self::digest( $parts );
	}

	private static function connection_fingerprint( array $status ) {
		$parts = array(
			'contract' => self::FINGERPRINT_CONTRACT,
			'kernel_fingerprint' => isset( $status['kernel_fingerprint'] ) ? $status['kernel_fingerprint'] : '',
			'adapter_version' => isset( $status['runtime_projection']['adapter_version'] ) ? $status['runtime_projection']['adapter_version'] : '',
			'source_commit_sha' => isset( $status['package_identity']['source_commit_sha'] ) ? $status['package_identity']['source_commit_sha'] : '',
			'build_fingerprint' => isset( $status['package_identity']['build_fingerprint'] ) ? $status['package_identity']['build_fingerprint'] : '',
			'package_manifest_digest' => isset( $status['package_identity']['package_manifest_digest'] ) ? $status['package_identity']['package_manifest_digest'] : '',
			'artifact_identity' => isset( $status['package_identity']['artifact_identity'] ) ? $status['package_identity']['artifact_identity'] : '',
			'public_key_fingerprint' => isset( $status['oauth']['public_key_fingerprint'] ) ? $status['oauth']['public_key_fingerprint'] : '',
		);
		return self::digest( $parts );
	}

	private static function first_blocker( array $blockers ) {
		$priority = array(
			'site_profile_unconfigured',
			'profile_origin_drift',
			'profile_environment_drift',
			'site_profile_oauth_disabled',
			'site_profile_subject_unavailable',
			'site_profile_subject_invalid',
			'site_profile_admin_owner_required',
			'explicit_local_oauth_disabled',
			'explicit_resource_oauth_disabled',
			'explicit_non_local_oauth_mode',
			'explicit_external_oauth_issuer',
			'explicit_wp_user_conflict',
			'explicit_subject_policy_conflict',
			'explicit_subject_binding_conflict',
			'connection_projection_drift',
			'oauth_public_key_not_ready',
			'package_identity_drift',
		);
		foreach ( $priority as $candidate ) if ( in_array( $candidate, $blockers, true ) ) return $candidate;
		return empty( $blockers ) ? '' : sanitize_key( (string) reset( $blockers ) );
	}

	private static function blocker_source( $blocker ) {
		$blocker = sanitize_key( (string) $blocker );
		if ( 0 === strpos( $blocker, 'explicit_' ) ) return 'wp-config.php';
		if ( 0 === strpos( $blocker, 'site_profile_' ) || 0 === strpos( $blocker, 'profile_' ) ) return 'site_profile';
		if ( 'connection_projection_drift' === $blocker ) return 'runtime_projection';
		if ( 0 === strpos( $blocker, 'oauth_public_key_' ) ) return 'local_oauth_key';
		if ( 'package_identity_drift' === $blocker ) return 'deployment';
		return '' === $blocker ? '' : 'runtime';
	}

	private static function value_at_path( array $value, $path ) {
		$current = $value;
		foreach ( explode( '.', (string) $path ) as $part ) {
			if ( ! is_array( $current ) || ! array_key_exists( $part, $current ) ) return null;
			$current = $current[ $part ];
		}
		return $current;
	}

	private static function digest( $value ) {
		$encoded = wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}
}
