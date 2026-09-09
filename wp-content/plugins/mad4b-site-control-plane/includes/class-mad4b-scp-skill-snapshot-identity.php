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
			$resources = array();
			foreach ( isset( $skill['resources'] ) && is_array( $skill['resources'] ) ? $skill['resources'] : array() as $resource ) {
				$resources[] = array(
					'path' => isset( $resource['path'] ) ? (string) $resource['path'] : '',
					'sha256' => isset( $resource['sha256'] ) ? (string) $resource['sha256'] : '',
					'bytes' => isset( $resource['bytes'] ) ? (int) $resource['bytes'] : 0,
				);
			}
			usort( $resources, function ( $a, $b ) { return strcmp( (string) $a['path'], (string) $b['path'] ); } );
			$entries[] = array(
				'logical_id' => isset( $skill['logical_id'] ) ? (string) $skill['logical_id'] : '',
				'name' => isset( $skill['name'] ) ? (string) $skill['name'] : '',
				'sha256' => isset( $skill['sha256'] ) ? (string) $skill['sha256'] : '',
				'bytes' => isset( $skill['bytes'] ) ? (int) $skill['bytes'] : 0,
				'resources' => $resources,
			);
		}
		usort( $entries, function ( $a, $b ) { return strcmp( (string) $a['logical_id'], (string) $b['logical_id'] ); } );

		$payload = array(
			'contract' => self::CONTRACT,
			'plugin_name' => 'mad4b-wordpress',
			'plugin_version' => defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '0.0.0',
			'app_id' => MAD4B_SCP_Skill_Registry::openai_app_id(),
			'skill_count' => count( $entries ),
			'skills' => $entries,
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || '' === $json ) return self::empty_identity( 'identity_encode_failed' );
		$digest = hash( 'sha256', $json );

		return array(
			'contract' => self::CONTRACT,
			'ready' => true,
			'snapshot_digest' => $digest,
			'identity_token' => 'sha256:' . $digest,
			'skill_count' => count( $entries ),
			'app_id' => MAD4B_SCP_Skill_Registry::openai_app_id(),
			'entries' => $entries,
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
