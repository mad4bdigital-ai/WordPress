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
				'resources' => isset( $skill['resources'] ) && is_array( $skill['resources'] ) ? $skill['resources'] : array(),
			);
		}

		return self::from_entries( $entries, MAD4B_SCP_Skill_Registry::openai_app_id() );
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
			'comparison_semantics' => 'Exact token match proves the enabled Skill contents/resources and App mapping match this WordPress snapshot identity.',
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
