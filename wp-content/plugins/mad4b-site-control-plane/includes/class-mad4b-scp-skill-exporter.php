<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Builds an ephemeral portable Agent Plugins package from the enabled runtime
 * Skill registry. The ZIP is created in the system temp directory and is meant
 * to be streamed by the authenticated admin UI, then deleted.
 */
final class MAD4B_SCP_Skill_Exporter {
	const CONTRACT = 'mad4b.skill-export.v1';

	public static function build_temp_zip() {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_skill_export_capability_denied', 'Administrator capability is required to export skills.' );
		if ( ! class_exists( 'ZipArchive' ) ) return new WP_Error( 'mad4b_skill_export_zip_unavailable', 'PHP ZipArchive is required to export a portable Plugin package.' );

		$skills = MAD4B_SCP_Skill_Registry::list_skills( array( 'enabled' => true ) );
		if ( empty( $skills ) ) return new WP_Error( 'mad4b_skill_export_empty', 'No enabled runtime skills are available to export.' );
		$identity = class_exists( 'MAD4B_SCP_Skill_Snapshot_Identity' ) ? MAD4B_SCP_Skill_Snapshot_Identity::build() : array();
		if ( empty( $identity['ready'] ) || empty( $identity['identity_token'] ) ) return new WP_Error( 'mad4b_skill_snapshot_identity_unavailable', 'Portable snapshot identity is unavailable; export is denied until a deterministic identity can be computed.' );

		$tmp = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'mad4b-wordpress-plugin.zip' ) : tempnam( sys_get_temp_dir(), 'mad4b-plugin-' );
		if ( ! is_string( $tmp ) || '' === $tmp ) return new WP_Error( 'mad4b_skill_export_temp_failed', 'Unable to create the temporary export file.' );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			@unlink( $tmp );
			return new WP_Error( 'mad4b_skill_export_open_failed', 'Unable to initialize the portable Plugin ZIP.' );
		}

		$app_id = MAD4B_SCP_Skill_Registry::openai_app_id();
		$openai = array(
			'interface' => array(
				'displayName' => 'MAD4B WordPress — Egypt Tour Gates',
				'shortDescription' => 'Governed WordPress diagnostics and workflow skills.',
				'longDescription' => 'WordPress diagnostics, Elementor, JetEngine, archive and governed workflow guidance backed by the MAD4B MCP connection.',
				'developerName' => 'MAD4B',
				'category' => 'Productivity',
				'capabilities' => array( 'Read' ),
				'defaultPrompt' => array(
					'Diagnose my WordPress site.',
					'Audit this Elementor template.',
					'Analyze my JetEngine dynamic content setup.',
				),
			),
		);
		if ( '' !== $app_id ) $openai['apps'] = './.app.json';

		$manifest = array(
			'$schema' => 'https://agent-plugins.org/schemas/1.0.0/plugin.schema.json',
			'name' => 'mad4b-wordpress',
			'version' => defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '0.1.0',
			'description' => 'Governed WordPress operations, diagnostics and reusable workflow skills.',
			'author' => array( 'name' => 'MAD4B' ),
			'repository' => 'https://github.com/mad4bdigital-ai/WordPress',
			'license' => 'GPL-2.0-or-later',
			'keywords' => array( 'wordpress', 'mcp', 'skills', 'elementor', 'jetengine', 'governance' ),
			'extensions' => array( 'com.openai' => $openai ),
		);
		$zip->addFromString( 'plugin.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

		if ( '' !== $app_id ) {
			$app_mapping = array( 'apps' => array( 'mad4b-wordpress' => array( 'id' => $app_id ) ) );
			$zip->addFromString( '.app.json', wp_json_encode( $app_mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		}

		$index = array();
		foreach ( $skills as $summary ) {
			$skill = MAD4B_SCP_Skill_Registry::get_skill( $summary['level'], $summary['target'], $summary['name'] );
			if ( is_wp_error( $skill ) ) { $zip->close(); @unlink( $tmp ); return $skill; }
			$name = (string) $skill['name'];
			$zip->addFromString( 'skills/' . $name . '/SKILL.md', (string) $skill['content'] );
			foreach ( isset( $skill['resources'] ) && is_array( $skill['resources'] ) ? $skill['resources'] : array() as $resource ) {
				$relative = isset( $resource['path'] ) ? (string) $resource['path'] : '';
				$data = MAD4B_SCP_Skill_Resource_Reader::read( $skill['level'], $skill['target'], $name, $relative );
				if ( is_wp_error( $data ) ) { $zip->close(); @unlink( $tmp ); return $data; }
				$zip->addFromString( 'skills/' . $name . '/' . $relative, $data['content'] );
			}
			$index[] = array(
				'name' => $name,
				'logical_id' => $skill['logical_id'],
				'sha256' => $skill['sha256'],
				'bytes' => $skill['bytes'],
			);
		}

		// Recompute after reading every file so an administrator edit racing the
		// export cannot produce a ZIP whose embedded identity describes a different
		// runtime state. Fail closed and require a fresh export instead.
		$identity_after = MAD4B_SCP_Skill_Snapshot_Identity::build();
		if ( empty( $identity_after['ready'] ) || empty( $identity_after['identity_token'] ) || ! hash_equals( (string) $identity['identity_token'], (string) $identity_after['identity_token'] ) ) {
			$zip->close();
			@unlink( $tmp );
			return new WP_Error( 'mad4b_skill_snapshot_changed_during_export', 'The enabled Skill snapshot changed while the portable package was being built. Retry the export from the new stable snapshot.' );
		}

		$export_meta = array(
			'contract' => self::CONTRACT,
			'generated_at' => gmdate( 'c' ),
			'control_plane_version' => defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '',
			'app_mapping_included' => '' !== $app_id,
			'skill_count' => count( $index ),
			'skills' => $index,
			'publication_semantics' => 'snapshot',
			'snapshot_identity_contract' => isset( $identity['contract'] ) ? $identity['contract'] : '',
			'snapshot_digest' => isset( $identity['snapshot_digest'] ) ? $identity['snapshot_digest'] : '',
			'identity_token' => isset( $identity['identity_token'] ) ? $identity['identity_token'] : '',
		);
		$zip->addFromString( 'MAD4B-SNAPSHOT.json', wp_json_encode( $export_meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		$zip->addFromString( 'MAD4B-SNAPSHOT-ID.txt', (string) $identity['identity_token'] . "\n" );
		$zip->close();

		if ( ! is_file( $tmp ) || filesize( $tmp ) < 1 ) { @unlink( $tmp ); return new WP_Error( 'mad4b_skill_export_empty_zip', 'Portable Plugin ZIP was not created correctly.' ); }
		return array(
			'contract' => self::CONTRACT,
			'path' => $tmp,
			'filename' => 'mad4b-wordpress-skills-' . gmdate( 'Ymd-His' ) . '.zip',
			'sha256' => hash_file( 'sha256', $tmp ),
			'bytes' => filesize( $tmp ),
			'skill_count' => count( $index ),
			'app_mapping_included' => '' !== $app_id,
			'snapshot_digest' => (string) $identity['snapshot_digest'],
			'identity_token' => (string) $identity['identity_token'],
		);
	}
}
