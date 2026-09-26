<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deterministic identity for the enabled portable Skill snapshot.
 *
 * The identity intentionally excludes timestamps and mutable presentation-only
 * metadata so a remote client can compare one published package with the live
 * WordPress registry using a stable SHA-256 digest.
 */
final class MAD4B_SCP_Skill_Snapshot_Identity {
	const CONTRACT = 'mad4b.skill-snapshot-identity.v1';
	private static $request_cache = null;

	/**
	 * Request-local memoization for latency-sensitive MCP discovery. This never
	 * persists across HTTP requests, so filesystem/admin changes are visible on
	 * the next request while duplicate scans inside one tools/list lifecycle are
	 * eliminated.
	 */
	public static function build_request_cached() {
		if ( is_array( self::$request_cache ) ) return self::$request_cache;
		$identity = self::build();
		if ( is_array( $identity ) ) self::$request_cache = $identity;
		return $identity;
	}

	public static function build() {
		if ( ! class_exists( 'MAD4B_SCP_Skill_Registry' ) ) return self::empty_identity( 'registry_unavailable' );

		$skills = MAD4B_SCP_Skill_Registry::list_skills( array( 'enabled' => true ) );
		$entries = array();
		foreach ( $skills as $skill ) {
			$entries[] = array(
				'logical_id' => isset( $skill['logical_id'] ) ? (string) $skill['logical_id'] : '',
				'name' => isset( $skill['name'] ) ? (string) $skill['name'] : '',
				'sha256' => isset( $skill['sha256'] ) ? (string) $skill['sha256'] : '',
				'bytes' => isset( $skill['bytes'] ) ? (int) $skill['bytes'] : 0,
				'context_policy_sha256' => isset( $skill['context_policy_sha256'] ) ? (string) $skill['context_policy_sha256'] : '',
				'resources' => isset( $skill['resources'] ) && is_array( $skill['resources'] ) ? $skill['resources'] : array(),
			);
		}

		$result = self::from_entries( $entries, MAD4B_SCP_Skill_Registry::openai_app_id() );
		if ( is_array( $result ) && ( empty( $result['app_id'] ) || empty( $result['skill_count'] ) ) ) {
			// Keep diagnostic context outside the canonical snapshot payload/digest.
			// This is intentionally read-only and secret-free; it lets exact-build CI
			// distinguish Site Profile enrollment failures from seeding/registry
			// failures without changing the portable identity semantics.
			$result['runtime_context'] = self::runtime_context();
		}
		return $result;
	}

	/**
	 * Build the same canonical identity from an explicitly observed set of bytes.
	 * Exporters use this after reading the actual SKILL.md/resource contents so a
	 * stale registry listing or a transient file race cannot be hidden by a later
	 * live-registry re-read.
	 */
	public static function from_entries( array $entries, $app_id = '' ) {
		$app_id = trim( (string) $app_id );
		if ( '' !== $app_id && ! preg_match( '/^plugin_asdk_app_[A-Za-z0-9]+$/', $app_id ) ) return self::empty_identity( 'app_id_invalid' );

		$canonical = array();
		$seen_logical = array();
		$seen_names = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) return self::empty_identity( 'entry_invalid' );
			$logical_id = isset( $entry['logical_id'] ) ? trim( (string) $entry['logical_id'] ) : '';
			$name = isset( $entry['name'] ) ? trim( (string) $entry['name'] ) : '';
			$sha = isset( $entry['sha256'] ) ? strtolower( trim( (string) $entry['sha256'] ) ) : '';
			$bytes = isset( $entry['bytes'] ) ? (int) $entry['bytes'] : -1;
			$context_policy_sha256 = isset( $entry['context_policy_sha256'] ) ? strtolower( trim( (string) $entry['context_policy_sha256'] ) ) : '';
			if ( '' !== $context_policy_sha256 && ! preg_match( '/^[a-f0-9]{64}$/', $context_policy_sha256 ) ) return self::empty_identity( 'context_policy_digest_invalid' );
			if ( '' === $logical_id || '' === $name || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name ) || ! preg_match( '/^[a-f0-9]{64}$/', $sha ) || $bytes < 1 ) {
				return self::empty_identity( 'entry_fields_invalid' );
			}
			if ( isset( $seen_logical[ $logical_id ] ) || isset( $seen_names[ $name ] ) ) return self::empty_identity( 'entry_collision' );
			$seen_logical[ $logical_id ] = true;
			$seen_names[ $name ] = true;

			$resources = array();
			$seen_resource = array();
			foreach ( isset( $entry['resources'] ) && is_array( $entry['resources'] ) ? $entry['resources'] : array() as $resource ) {
				if ( ! is_array( $resource ) ) return self::empty_identity( 'resource_invalid' );
				$path = isset( $resource['path'] ) ? wp_normalize_path( ltrim( (string) $resource['path'], '/' ) ) : '';
				$resource_sha = isset( $resource['sha256'] ) ? strtolower( trim( (string) $resource['sha256'] ) ) : '';
				$resource_bytes = isset( $resource['bytes'] ) ? (int) $resource['bytes'] : -1;
				if ( '' === $path || false !== strpos( $path, '..' ) || ! preg_match( '/^[a-f0-9]{64}$/', $resource_sha ) || $resource_bytes < 0 ) return self::empty_identity( 'resource_fields_invalid' );
				if ( isset( $seen_resource[ $path ] ) ) return self::empty_identity( 'resource_collision' );
				$seen_resource[ $path ] = true;
				$resources[] = array( 'path' => $path, 'sha256' => $resource_sha, 'bytes' => $resource_bytes );
			}
			usort( $resources, function ( $a, $b ) { return strcmp( (string) $a['path'], (string) $b['path'] ); } );

			$canonical[] = array(
				'logical_id' => $logical_id,
				'name' => $name,
				'sha256' => $sha,
				'bytes' => $bytes,
				'context_policy_sha256' => $context_policy_sha256,
				'resources' => $resources,
			);
		}
		usort( $canonical, function ( $a, $b ) { return strcmp( (string) $a['logical_id'], (string) $b['logical_id'] ); } );

		$payload = array(
			'contract' => self::CONTRACT,
			'plugin_name' => 'mad4b-wordpress',
			'plugin_version' => defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '0.0.0',
			'app_id' => $app_id,
			'skill_count' => count( $canonical ),
			'skills' => $canonical,
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || '' === $json ) return self::empty_identity( 'identity_encode_failed' );
		$digest = hash( 'sha256', $json );

		return array(
			'contract' => self::CONTRACT,
			'ready' => true,
			'snapshot_digest' => $digest,
			'identity_token' => 'sha256:' . $digest,
			'skill_count' => count( $canonical ),
			'app_id' => $app_id,
			'entries' => $canonical,
			'comparison_semantics' => 'Exact token match proves the enabled Skill contents/resources, governed Context policies and App mapping match this WordPress snapshot identity.',
		);
	}

	private static function runtime_context() {
		$profile = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$autoconfig = class_exists( 'MAD4B_SCP_Skill_Autoconfig' ) ? MAD4B_SCP_Skill_Autoconfig::status() : array();
		$seed = class_exists( 'MAD4B_SCP_Skill_Seeder' ) ? MAD4B_SCP_Skill_Seeder::status() : array();
		$registry = class_exists( 'MAD4B_SCP_Skill_Registry' ) ? MAD4B_SCP_Skill_Registry::status() : array();
		return array(
			'profile' => array(
				'configured' => ! empty( $profile['configured'] ),
				'source' => isset( $profile['source'] ) ? (string) $profile['source'] : '',
				'environment' => isset( $profile['environment'] ) ? (string) $profile['environment'] : '',
				'configured_environment' => isset( $profile['configured_environment'] ) ? (string) $profile['configured_environment'] : '',
				'current_origin' => isset( $profile['current_origin'] ) ? (string) $profile['current_origin'] : '',
				'canonical_origin' => isset( $profile['canonical_origin'] ) ? (string) $profile['canonical_origin'] : '',
				'environment_match' => ! empty( $profile['environment_match'] ),
				'origin_match' => ! empty( $profile['origin_match'] ),
				'skills_enabled' => ! empty( $profile['skills_enabled'] ),
				'site_uuid' => isset( $profile['site_uuid'] ) ? (string) $profile['site_uuid'] : '',
				'revision' => isset( $profile['revision'] ) ? (int) $profile['revision'] : 0,
				'profile_digest' => isset( $profile['profile_digest'] ) ? (string) $profile['profile_digest'] : '',
				'blockers' => isset( $profile['blockers'] ) && is_array( $profile['blockers'] ) ? array_values( $profile['blockers'] ) : array(),
			),
			'autoconfig' => array(
				'eligible' => ! empty( $autoconfig['eligible'] ),
				'configured' => ! empty( $autoconfig['configured'] ),
				'configuration_source' => isset( $autoconfig['configuration_source'] ) ? (string) $autoconfig['configuration_source'] : '',
				'blocker' => isset( $autoconfig['blocker'] ) ? (string) $autoconfig['blocker'] : '',
				'app_mapping_configured' => ! empty( $autoconfig['app_mapping_configured'] ),
				'app_mapping_source' => isset( $autoconfig['app_mapping_source'] ) ? (string) $autoconfig['app_mapping_source'] : '',
				'app_mapping_matches_profile' => ! empty( $autoconfig['app_mapping_matches_profile'] ),
			),
			'seeder' => array(
				'state' => isset( $seed['state'] ) ? (string) $seed['state'] : '',
				'current_request_observed' => ! empty( $seed['current_request_observed'] ),
				'previous_persisted_ready' => ! empty( $seed['previous_persisted_ready'] ),
			),
			'registry' => array(
				'editor_enabled' => ! empty( $registry['editor_enabled'] ),
				'storage_initialized' => ! empty( $registry['storage_initialized'] ),
				'storage_writable' => ! empty( $registry['storage_writable'] ),
				'skill_count' => isset( $registry['skill_count'] ) ? (int) $registry['skill_count'] : 0,
				'portable_app_id_configured' => ! empty( $registry['portable_app_id_configured'] ),
			),
		);
	}

	private static function empty_identity( $blocker ) {
		return array(
			'contract' => self::CONTRACT,
			'ready' => false,
			'blocker' => sanitize_key( (string) $blocker ),
			'snapshot_digest' => '',
			'identity_token' => '',
			'skill_count' => 0,
			'app_id' => '',
			'entries' => array(),
		);
	}
}
