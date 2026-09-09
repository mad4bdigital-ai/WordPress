<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Builds an ephemeral portable Agent Plugins package from the enabled runtime
 * Skill registry. The ZIP is created in the system temp directory and is meant
 * to be streamed by the authenticated admin UI, then deleted.
 */
final class MAD4B_SCP_Skill_Exporter {
	const CONTRACT = 'mad4b.skill-export.v1';
	const MAX_EXPORT_SKILLS = 250;
	const MAX_EXPORT_RESOURCES = 2000;
	const MAX_EXPORT_UNCOMPRESSED_BYTES = 67108864; // 64 MiB across Skill/resource payloads.

	public static function build_temp_zip() {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_skill_export_capability_denied', 'Administrator capability is required to export skills.' );
		if ( ! class_exists( 'ZipArchive' ) ) return new WP_Error( 'mad4b_skill_export_zip_unavailable', 'PHP ZipArchive is required to export a portable Plugin package.' );

		// Establish the exact live identity before taking the export work-list. The
		// exported bytes are independently re-hashed below and must reproduce this
		// identity, closing stale-list and transient mixed-read races.
		$identity = class_exists( 'MAD4B_SCP_Skill_Snapshot_Identity' ) ? MAD4B_SCP_Skill_Snapshot_Identity::build() : array();
		if ( empty( $identity['ready'] ) || empty( $identity['identity_token'] ) ) return new WP_Error( 'mad4b_skill_snapshot_identity_unavailable', 'Portable snapshot identity is unavailable; export is denied until a deterministic identity can be computed.' );
		$skills = MAD4B_SCP_Skill_Registry::list_skills( array( 'enabled' => true ) );
		if ( empty( $skills ) ) return new WP_Error( 'mad4b_skill_export_empty', 'No enabled runtime skills are available to export.' );
		if ( count( $skills ) > self::MAX_EXPORT_SKILLS ) return new WP_Error( 'mad4b_skill_export_skill_limit_exceeded', 'Enabled Skill count exceeds the bounded portable export limit.' );
		$app_id = isset( $identity['app_id'] ) ? (string) $identity['app_id'] : '';

		// A manifest declaration is not authority. Export Write only after a fresh
		// exact-origin authority reconciliation AND a fresh runtime certification.
		// Stale stored certification therefore cannot keep Write in a new package.
		$write_authority = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::reconcile() : array();
		$write_certification = class_exists( 'MAD4B_SCP_Write_Runtime_Certification' ) ? MAD4B_SCP_Write_Runtime_Certification::observe() : array();
		$write_ready = ! empty( $write_authority['ready'] ) && ! empty( $write_certification['ready'] );
		$capabilities = $write_ready ? array( 'Read', 'Write' ) : array( 'Read' );

		$tmp = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'mad4b-wordpress-plugin.zip' ) : tempnam( sys_get_temp_dir(), 'mad4b-plugin-' );
		if ( ! is_string( $tmp ) || '' === $tmp ) return new WP_Error( 'mad4b_skill_export_temp_failed', 'Unable to create the temporary export file.' );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			@unlink( $tmp );
			return new WP_Error( 'mad4b_skill_export_open_failed', 'Unable to initialize the portable Plugin ZIP.' );
		}

		$openai = array(
			'interface' => array(
				'displayName' => 'MAD4B WordPress — Egypt Tour Gates',
				'shortDescription' => $write_ready ? 'Governed WordPress diagnostics, workflows and Staging changes.' : 'Governed WordPress diagnostics and workflow skills.',
				'longDescription' => $write_ready
					? 'WordPress diagnostics and governed Staging update/write/mutation workflows backed by exact mad4b-write NHI grants, one-time approvals and runtime certification.'
					: 'WordPress diagnostics, Elementor, JetEngine, archive and governed workflow guidance backed by the MAD4B MCP connection.',
				'developerName' => 'MAD4B',
				'category' => 'Productivity',
				'capabilities' => $capabilities,
				'defaultPrompt' => array(
					'Diagnose my WordPress site.',
					'Show the governed Staging write authority status.',
					'Plan a safe approved Staging change.',
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
			'description' => $write_ready
				? 'Governed WordPress operations, diagnostics, reusable workflow skills and exact-approval Staging writes.'
				: 'Governed WordPress operations, diagnostics and reusable workflow skills.',
			'author' => array( 'name' => 'MAD4B' ),
			'repository' => 'https://github.com/mad4bdigital-ai/WordPress',
			'license' => 'GPL-2.0-or-later',
			'keywords' => array( 'wordpress', 'mcp', 'skills', 'elementor', 'jetengine', 'governance' ),
			'extensions' => array( 'com.openai' => $openai ),
		);
		if ( false === $zip->addFromString( 'plugin.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) return self::abort_zip( $zip, $tmp, 'mad4b_skill_export_manifest_write_failed', 'Unable to write plugin.json into the portable package.' );

		if ( '' !== $app_id ) {
			$app_mapping = array( 'apps' => array( 'mad4b-wordpress' => array( 'id' => $app_id ) ) );
			if ( false === $zip->addFromString( '.app.json', wp_json_encode( $app_mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) return self::abort_zip( $zip, $tmp, 'mad4b_skill_export_app_mapping_write_failed', 'Unable to write .app.json into the portable package.' );
		}

		$index = array();
		$observed_entries = array();
		$total_payload_bytes = 0;
		$resource_count = 0;
		foreach ( $skills as $summary ) {
			$skill = MAD4B_SCP_Skill_Registry::get_skill( $summary['level'], $summary['target'], $summary['name'] );
			if ( is_wp_error( $skill ) ) { $zip->close(); @unlink( $tmp ); return $skill; }
			$name = (string) $skill['name'];
			$content = (string) $skill['content'];
			$skill_sha = hash( 'sha256', $content );
			$skill_bytes = strlen( $content );
			$total_payload_bytes += $skill_bytes;
			if ( $total_payload_bytes > self::MAX_EXPORT_UNCOMPRESSED_BYTES ) return self::abort_zip( $zip, $tmp, 'mad4b_skill_export_size_limit_exceeded', 'Portable Skill payload exceeds the bounded uncompressed export size.' );
			if ( false === $zip->addFromString( 'skills/' . $name . '/SKILL.md', $content ) ) return self::abort_zip( $zip, $tmp, 'mad4b_skill_export_skill_write_failed', 'Unable to write a Skill document into the portable package.' );

			$observed_resources = array();
			foreach ( isset( $skill['resources'] ) && is_array( $skill['resources'] ) ? $skill['resources'] : array() as $resource ) {
				++$resource_count;
				if ( $resource_count > self::MAX_EXPORT_RESOURCES ) return self::abort_zip( $zip, $tmp, 'mad4b_skill_export_resource_limit_exceeded', 'Portable Skill resource count exceeds the bounded export limit.' );
				$relative = isset( $resource['path'] ) ? (string) $resource['path'] : '';
				$data = MAD4B_SCP_Skill_Resource_Reader::read( $skill['level'], $skill['target'], $name, $relative );
				if ( is_wp_error( $data ) ) { $zip->close(); @unlink( $tmp ); return $data; }
				$resource_content = isset( $data['content'] ) ? (string) $data['content'] : '';
				$resource_bytes = strlen( $resource_content );
				$total_payload_bytes += $resource_bytes;
				if ( $total_payload_bytes > self::MAX_EXPORT_UNCOMPRESSED_BYTES ) return self::abort_zip( $zip, $tmp, 'mad4b_skill_export_size_limit_exceeded', 'Portable Skill payload exceeds the bounded uncompressed export size.' );
				if ( false === $zip->addFromString( 'skills/' . $name . '/' . $relative, $resource_content ) ) return self::abort_zip( $zip, $tmp, 'mad4b_skill_export_resource_write_failed', 'Unable to write a Skill resource into the portable package.' );
				$observed_resources[] = array(
					'path' => $relative,
					'sha256' => hash( 'sha256', $resource_content ),
					'bytes' => $resource_bytes,
				);
			}

			$observed_entries[] = array(
				'logical_id' => (string) $skill['logical_id'],
				'name' => $name,
				'sha256' => $skill_sha,
				'bytes' => $skill_bytes,
				'resources' => $observed_resources,
			);
			$index[] = array(
				'name' => $name,
				'logical_id' => (string) $skill['logical_id'],
				'sha256' => $skill_sha,
				'bytes' => $skill_bytes,
			);
		}

		// Prove that the bytes actually placed into the ZIP reproduce the original
		// live identity. This catches a stale work-list even if the live registry has
		// already converged to a new stable state before the final re-read.
		$export_identity = MAD4B_SCP_Skill_Snapshot_Identity::from_entries( $observed_entries, $app_id );
		if ( empty( $export_identity['ready'] ) || empty( $export_identity['identity_token'] ) || ! hash_equals( (string) $identity['identity_token'], (string) $export_identity['identity_token'] ) ) {
			return self::abort_zip( $zip, $tmp, 'mad4b_skill_export_observed_identity_mismatch', 'The bytes observed during export do not match the initial enabled Skill snapshot. Retry from the new stable snapshot.' );
		}

		// Also require the live registry to remain on the same identity after all
		// bytes were read. No stale/mixed package is published across either race.
		$identity_after = MAD4B_SCP_Skill_Snapshot_Identity::build();
		if ( empty( $identity_after['ready'] ) || empty( $identity_after['identity_token'] ) || ! hash_equals( (string) $identity['identity_token'], (string) $identity_after['identity_token'] ) ) {
			return self::abort_zip( $zip, $tmp, 'mad4b_skill_snapshot_changed_during_export', 'The enabled Skill snapshot changed while the portable package was being built. Retry the export from the new stable snapshot.' );
		}

		$export_meta = array(
			'contract' => self::CONTRACT,
			'generated_at' => gmdate( 'c' ),
			'control_plane_version' => defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '',
			'app_mapping_included' => '' !== $app_id,
			'plugin_capabilities' => $capabilities,
			'governed_write_ready' => $write_ready,
			'governed_write_contract' => isset( $write_authority['contract'] ) ? $write_authority['contract'] : '',
			'write_certification_contract' => isset( $write_certification['contract'] ) ? $write_certification['contract'] : '',
			'write_certification_ready' => ! empty( $write_certification['ready'] ),
			'skill_count' => count( $index ),
			'resource_count' => $resource_count,
			'uncompressed_payload_bytes' => $total_payload_bytes,
			'export_limits' => array(
				'max_skills' => self::MAX_EXPORT_SKILLS,
				'max_resources' => self::MAX_EXPORT_RESOURCES,
				'max_uncompressed_payload_bytes' => self::MAX_EXPORT_UNCOMPRESSED_BYTES,
			),
			'skills' => $index,
			'publication_semantics' => 'snapshot',
			'snapshot_identity_contract' => isset( $identity['contract'] ) ? $identity['contract'] : '',
			'snapshot_digest' => isset( $identity['snapshot_digest'] ) ? $identity['snapshot_digest'] : '',
			'identity_token' => isset( $identity['identity_token'] ) ? $identity['identity_token'] : '',
		);
		if ( false === $zip->addFromString( 'MAD4B-SNAPSHOT.json', wp_json_encode( $export_meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) return self::abort_zip( $zip, $tmp, 'mad4b_skill_export_snapshot_meta_write_failed', 'Unable to write snapshot metadata into the portable package.' );
		if ( false === $zip->addFromString( 'MAD4B-SNAPSHOT-ID.txt', (string) $identity['identity_token'] . "\n" ) ) return self::abort_zip( $zip, $tmp, 'mad4b_skill_export_snapshot_id_write_failed', 'Unable to write snapshot identity into the portable package.' );
		$zip->close();

		if ( ! is_file( $tmp ) || filesize( $tmp ) < 1 ) { @unlink( $tmp ); return new WP_Error( 'mad4b_skill_export_empty_zip', 'Portable Plugin ZIP was not created correctly.' ); }
		return array(
			'contract' => self::CONTRACT,
			'path' => $tmp,
			'filename' => 'mad4b-wordpress-skills-' . gmdate( 'Ymd-His' ) . '.zip',
			'sha256' => hash_file( 'sha256', $tmp ),
			'bytes' => filesize( $tmp ),
			'skill_count' => count( $index ),
			'resource_count' => $resource_count,
			'uncompressed_payload_bytes' => $total_payload_bytes,
			'app_mapping_included' => '' !== $app_id,
			'plugin_capabilities' => $capabilities,
			'governed_write_ready' => $write_ready,
			'write_certification_ready' => ! empty( $write_certification['ready'] ),
			'snapshot_digest' => (string) $identity['snapshot_digest'],
			'identity_token' => (string) $identity['identity_token'],
		);
	}

	private static function abort_zip( $zip, $tmp, $code, $message ) {
		if ( is_object( $zip ) ) $zip->close();
		if ( is_string( $tmp ) && '' !== $tmp ) @unlink( $tmp );
		return new WP_Error( sanitize_key( (string) $code ), (string) $message );
	}
}
