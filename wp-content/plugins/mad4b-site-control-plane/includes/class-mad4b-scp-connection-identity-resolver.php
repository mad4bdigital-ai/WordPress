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
		$profile_origin_match = ! empty( $profile['origin_match'] );
		$profile_environment_match = ! empty( $profile['environment_match'] );
		$profile_exact = $configured && $profile_origin_match && $profile_environment_match;
		$portable = class_exists( 'MAD4B_SCP_Portable_Readonly_Connection' ) ? MAD4B_SCP_Portable_Readonly_Connection::status() : array();
		$portable_effective = ! $profile_exact && ! empty( $portable['effective'] );
		$portable_blocker = isset( $portable['blocker'] ) ? sanitize_key( (string) $portable['blocker'] ) : '';

		$source = $profile_exact ? 'exact_site_profile' : ( $portable_effective ? 'portable_readonly' : ( $configured ? 'site_profile_blocked' : 'unconfigured' ) );
		$environment = $portable_effective
			? sanitize_key( (string) ( isset( $portable['environment'] ) ? $portable['environment'] : '' ) )
			: ( class_exists( 'MAD4B_SCP_Site_Profile' )
				? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() )
				: ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' ) );
		$origin = $portable_effective
			? untrailingslashit( (string) ( isset( $portable['origin'] ) ? $portable['origin'] : '' ) )
			: ( class_exists( 'MAD4B_SCP_Site_Profile' )
				? untrailingslashit( (string) MAD4B_SCP_Site_Profile::current_origin() )
				: ( function_exists( 'home_url' ) ? untrailingslashit( (string) home_url( '/' ) ) : '' ) );
		$enrolled_origin = class_exists( 'MAD4B_SCP_Site_Profile' ) ? untrailingslashit( (string) MAD4B_SCP_Site_Profile::site_origin() ) : '';

		if ( $portable_effective ) {
			$user_ids = isset( $portable['user_ids'] ) && is_array( $portable['user_ids'] ) ? $portable['user_ids'] : array();
		} else {
			$user_ids = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::oauth_user_ids() : array();
		}
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
		if ( $portable_effective && isset( $portable['primary_user_id'] ) ) {
			$portable_primary = absint( $portable['primary_user_id'] );
			if ( $portable_primary > 0 && in_array( $portable_primary, $user_ids, true ) ) {
				$user = function_exists( 'get_userdata' ) ? get_userdata( $portable_primary ) : false;
				if ( is_object( $user ) && function_exists( 'user_can' ) && user_can( $user, 'manage_options' ) ) $primary_owner = $portable_primary;
			}
		}

		$expected_issuer = $portable_effective && ! empty( $portable['issuer'] )
			? untrailingslashit( (string) $portable['issuer'] )
			: ( '' !== $origin ? $origin . '/oauth/mcp' : '' );
		$expected_resource = '' !== $origin ? $origin . '/wp-json/mcp/mad4b-chatgpt' : '';
		$overrides = self::explicit_override_status( $expected_issuer, $user_ids, $primary_owner );
		if ( $portable_effective && empty( $overrides['blockers'] ) ) $overrides['source'] = 'portable_readonly_projection';

		$blockers = array();
		if ( $profile_exact ) {
			if ( class_exists( 'MAD4B_SCP_Site_Profile' ) && ! MAD4B_SCP_Site_Profile::oauth_enabled() ) $blockers[] = 'site_profile_oauth_disabled';
			if ( empty( $user_ids ) ) $blockers[] = 'site_profile_subject_unavailable';
			foreach ( $subjects as $subject ) if ( empty( $subject['exists'] ) ) $blockers[] = 'site_profile_subject_invalid';
			if ( ! empty( $user_ids ) && $primary_owner < 1 ) $blockers[] = 'site_profile_admin_owner_required';
		} elseif ( $portable_effective ) {
			if ( empty( $user_ids ) ) $blockers[] = 'portable_admin_subject_unavailable';
			foreach ( $subjects as $subject ) if ( empty( $subject['exists'] ) || empty( $subject['manage_options'] ) ) $blockers[] = 'portable_admin_subject_invalid';
			if ( ! empty( $user_ids ) && $primary_owner < 1 ) $blockers[] = 'portable_admin_subject_unavailable';
		} else {
			// Portable read-only is an independent current-origin connection. When it
			// is unavailable, surface its direct blocker rather than misreporting an
			// unconfigured/foreign Site Profile as the OAuth root cause.
			if ( '' !== $portable_blocker && 'site_profile_present' !== $portable_blocker ) {
				$blockers[] = $portable_blocker;
			} elseif ( ! $configured ) {
				$blockers[] = 'site_profile_unconfigured';
			}
			if ( $configured && ! $profile_origin_match ) $blockers[] = 'profile_origin_drift';
			if ( $configured && ! $profile_environment_match ) $blockers[] = 'profile_environment_drift';
		}
		if ( ! empty( $overrides['blockers'] ) ) $blockers = array_merge( $blockers, $overrides['blockers'] );
		$blockers = array_values( array_unique( array_map( 'sanitize_key', $blockers ) ) );

		$subject_digest = self::digest( array_values( array_map( static function ( $id ) { return 'user:' . absint( $id ); }, $user_ids ) ) );
		$root = self::first_blocker( $blockers );
		$site_uuid = $portable_effective && class_exists( 'MAD4B_SCP_Portable_Readonly_Connection' )
			? (string) MAD4B_SCP_Portable_Readonly_Connection::connection_uuid()
			: ( class_exists( 'MAD4B_SCP_Site_Profile' ) ? (string) MAD4B_SCP_Site_Profile::site_uuid() : '' );
		$identity_digest = $portable_effective && class_exists( 'MAD4B_SCP_Portable_Readonly_Connection' )
			? (string) MAD4B_SCP_Portable_Readonly_Connection::connection_digest()
			: ( class_exists( 'MAD4B_SCP_Site_Profile' ) ? (string) MAD4B_SCP_Site_Profile::profile_digest() : '' );
		$profile_revision = $profile_exact && class_exists( 'MAD4B_SCP_Site_Profile' ) ? (int) MAD4B_SCP_Site_Profile::revision() : 0;

		self::$kernel = array(
			'contract' => self::CONTRACT,
			'projection' => 'identity_kernel',
			'source' => $source,
			'eligible' => empty( $blockers ),
			'effective' => empty( $blockers ),
			'root_blocker' => $root,
			'root_blocker_source' => self::blocker_source( $root ),
			'blockers' => $blockers,
			'site' => array(
				'identity_source' => $source,
				'site_uuid' => $site_uuid,
				'profile_revision' => $profile_revision,
				'profile_digest' => $identity_digest,
				'environment' => $environment,
				'origin' => $origin,
				'enrolled_origin' => $enrolled_origin,
				'origin_match' => $portable_effective ? true : $profile_origin_match,
				'environment_match' => $portable_effective ? true : $profile_environment_match,
				'observed_profile_present' => $configured,
				'observed_profile_origin_match' => $profile_origin_match,
				'observed_profile_environment_match' => $profile_environment_match,
				'foreign_profile_quarantined' => $portable_effective && ! empty( $portable['foreign_profile_quarantined'] ),
				'requires_site_enrollment' => $portable_effective && ! empty( $portable['requires_site_enrollment'] ),
				'profile_authority_inherited' => false,
			),
			'oauth' => array(
				'mode' => 'local',
				'issuer' => $expected_issuer,
				'subject_count' => count( $user_ids ),
				'subject_digest' => $subject_digest,
				'subjects' => $subjects,
				'primary_owner_user_id' => $primary_owner,
				'primary_owner_capable' => $primary_owner > 0,
				'portable_readonly' => $portable_effective,
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
		$blockers = array_values( array_unique( array_map( 'sanitize_key', $blockers ) ) );
		$root = self::first_blocker( $blockers );

		// Package provenance proves deployment/certification identity, not whether
		// the current-origin OAuth/MCP endpoint is discoverable. Source-checkout
		// fixtures intentionally have no generated provenance file; production
		// artifacts do. Keep missing/stale provenance visible and fail-closed for
		// certification without converting it into a false OAuth preflight blocker.
		$certification_blockers = $blockers;
		$package_identity_blocker = '';
		if ( empty( $build['identity_ready'] ) ) {
			$package_identity_blocker = 'package_identity_drift';
			$certification_blockers[] = $package_identity_blocker;
		}
		$certification_blockers = array_values( array_unique( array_map( 'sanitize_key', $certification_blockers ) ) );

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
		$result['package_identity_ready'] = ! empty( $build['identity_ready'] );
		$result['package_identity_blocker'] = $package_identity_blocker;
		$result['blockers'] = $blockers;
		$result['root_blocker'] = $root;
		$result['root_blocker_source'] = self::blocker_source( $root );
		$result['eligible'] = empty( $blockers );
		$result['effective'] = empty( $blockers );
		$result['certification_blockers'] = $certification_blockers;
		$result['certification_ready'] = empty( $certification_blockers );
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
			'MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER',
			'MAD4B_MCP_OAUTH_ADVERTISED_ISSUERS',
			'MAD4B_MCP_OAUTH_RESOURCE_POLICY_BY_ISSUER',
			'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS',
			'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS',
		);
		foreach ( $names as $name ) if ( defined( $name ) ) $defined[] = $name;

		if ( defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) && true !== constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) $blockers[] = 'explicit_local_oauth_disabled';
		if ( defined( 'MAD4B_MCP_OAUTH_ENABLED' ) && true !== constant( 'MAD4B_MCP_OAUTH_ENABLED' ) ) $blockers[] = 'explicit_resource_oauth_disabled';
		if ( defined( 'MAD4B_MCP_OAUTH_MODE' ) && 'local' !== sanitize_key( (string) constant( 'MAD4B_MCP_OAUTH_MODE' ) ) ) $blockers[] = 'explicit_non_local_oauth_mode';
		if ( defined( 'MAD4B_MCP_OAUTH_ISSUER' ) ) {
			$value = untrailingslashit( trim( (string) constant( 'MAD4B_MCP_OAUTH_ISSUER' ) ) );
			if ( '' !== $value && '' !== $issuer && ! hash_equals( $issuer, $value ) ) $blockers[] = 'explicit_external_oauth_issuer';
		}
		if ( defined( 'MAD4B_MCP_LOCAL_OAUTH_ISSUER' ) ) {
			$value = untrailingslashit( trim( (string) constant( 'MAD4B_MCP_LOCAL_OAUTH_ISSUER' ) ) );
			if ( '' !== $value && '' !== $issuer && ! hash_equals( $issuer, $value ) ) $blockers[] = 'explicit_local_oauth_issuer_conflict';
		}
		if ( defined( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ) {
			$configured_user = absint( constant( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) );
			if ( $configured_user < 1 || ! in_array( $configured_user, $user_ids, true ) || ( $primary_owner > 0 && $configured_user !== (int) $primary_owner ) ) $blockers[] = 'explicit_wp_user_conflict';
		}
		if ( defined( 'MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER' ) ) {
			$mapping = constant( 'MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER' );
			$mapped_user = 0;
			if ( is_array( $mapping ) ) {
				foreach ( $mapping as $bound_issuer => $user_id ) {
					if ( ! is_string( $bound_issuer ) || ! hash_equals( $issuer, rtrim( trim( $bound_issuer ), '/' ) ) ) continue;
					$mapped_user = absint( $user_id );
					break;
				}
			}
			if ( $primary_owner < 1 || $mapped_user !== (int) $primary_owner ) $blockers[] = 'explicit_wp_user_conflict';
		}
		if ( defined( 'MAD4B_MCP_OAUTH_ADVERTISED_ISSUERS' ) ) {
			$raw = constant( 'MAD4B_MCP_OAUTH_ADVERTISED_ISSUERS' );
			$items = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw );
			$advertised = array();
			foreach ( is_array( $items ) ? array_slice( $items, 0, 32 ) : array() as $candidate ) {
				if ( ! is_string( $candidate ) ) continue;
				$candidate = rtrim( trim( $candidate ), '/' );
				if ( '' !== $candidate ) $advertised[] = $candidate;
			}
			if ( '' !== $issuer && ! in_array( $issuer, array_values( array_unique( $advertised ) ), true ) ) $blockers[] = 'explicit_advertised_issuer_conflict';
		}
		if ( defined( 'MAD4B_MCP_OAUTH_RESOURCE_POLICY_BY_ISSUER' ) ) {
			$policies = constant( 'MAD4B_MCP_OAUTH_RESOURCE_POLICY_BY_ISSUER' );
			if ( is_array( $policies ) ) {
				foreach ( $policies as $bound_issuer => $resources ) {
					if ( ! is_string( $bound_issuer ) || ! hash_equals( $issuer, rtrim( trim( $bound_issuer ), '/' ) ) ) continue;
					$items = is_array( $resources ) ? $resources : preg_split( '/[\s,]+/', (string) $resources );
					$allowed = array();
					foreach ( is_array( $items ) ? array_slice( $items, 0, 16 ) : array() as $server_id ) {
						if ( ! is_string( $server_id ) ) continue;
						$server_id = sanitize_key( $server_id );
						if ( '' !== $server_id ) $allowed[] = $server_id;
					}
					if ( ! in_array( 'mad4b-chatgpt', array_values( array_unique( $allowed ) ), true ) ) $blockers[] = 'explicit_resource_policy_conflict';
					break;
				}
			}
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
		$items = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
		$out = array();
		foreach ( is_array( $items ) ? array_slice( $items, 0, 500 ) : array() as $item ) {
			if ( ! is_string( $item ) ) continue;
			$item = trim( $item );
			if ( '' !== $item ) $out[] = $item;
		}
		return array_values( array_unique( $out ) );
	}

	private static function binding_contains( $bindings, $issuer, $subject ) {
		if ( ! is_array( $bindings ) ) return false;
		foreach ( $bindings as $bound_issuer => $subjects ) {
			if ( ! is_string( $bound_issuer ) || ! hash_equals( $issuer, rtrim( trim( $bound_issuer ), '/' ) ) ) continue;
			return in_array( $subject, self::normalize_subjects( $subjects ), true );
		}
		return false;
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
			'explicit_local_oauth_issuer_conflict',
			'explicit_wp_user_conflict',
			'explicit_advertised_issuer_conflict',
			'explicit_resource_policy_conflict',
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
