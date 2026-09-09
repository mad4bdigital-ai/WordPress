<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Zero-touch Staging seed pack provisioner.
 *
 * Seeds canonical read-only workflow Skills into the runtime registry without
 * requiring an administrator to create them manually. Existing runtime-authored
 * files always win: the seeder never overwrites an existing SKILL.md.
 */
final class MAD4B_SCP_Skill_Seeder {
	const CONTRACT = 'mad4b.skill-seeder.v1';
	const OPTION = 'mad4b_scp_skill_seed_v1';

	private static $ran = false;

	public static function bootstrap() {
		if ( self::$ran ) return self::status();
		self::$ran = true;

		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		if ( 'staging' !== $environment || ! MAD4B_SCP_Skill_Registry::editor_enabled() ) {
			return self::status( 'not_eligible' );
		}

		$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit_status['ready'] ) ) return self::status( 'audit_unavailable' );

		$root = MAD4B_SCP_Skill_Registry::storage_root();
		if ( '' === $root && defined( 'WP_CONTENT_DIR' ) ) $root = wp_normalize_path( WP_CONTENT_DIR . '/mad4b-skills' );
		if ( '' === $root || ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) ) return self::status( 'storage_unavailable' );

		$created = array();
		$skipped = array();
		foreach ( self::seeds() as $seed ) {
			$result = self::seed_one( $root, $seed );
			if ( is_wp_error( $result ) ) return self::status( $result->get_error_code(), $created, $skipped );
			if ( ! empty( $result['created'] ) ) $created[] = $seed['name']; else $skipped[] = $seed['name'];
		}

		update_option(
			self::OPTION,
			array(
				'contract' => self::CONTRACT,
				'version' => 1,
				'created' => $created,
				'skipped_existing' => $skipped,
				'updated_at' => gmdate( 'c' ),
			),
			false
		);
		return self::status( 'ready', $created, $skipped );
	}

	public static function status( $state = '', array $created = array(), array $skipped = array() ) {
		$stored = get_option( self::OPTION, array() );
		return array(
			'contract' => self::CONTRACT,
			'state' => '' !== $state ? $state : ( is_array( $stored ) && ! empty( $stored['updated_at'] ) ? 'ready' : 'pending' ),
			'created' => ! empty( $created ) ? $created : ( is_array( $stored ) && isset( $stored['created'] ) && is_array( $stored['created'] ) ? $stored['created'] : array() ),
			'skipped_existing' => ! empty( $skipped ) ? $skipped : ( is_array( $stored ) && isset( $stored['skipped_existing'] ) && is_array( $stored['skipped_existing'] ) ? $stored['skipped_existing'] : array() ),
			'overwrites_existing' => false,
			'production_auto_seed' => false,
		);
	}

	private static function seed_one( $root, array $seed ) {
		$level = sanitize_key( $seed['level'] );
		$target = sanitize_key( $seed['target'] );
		$name = sanitize_key( $seed['name'] );
		$dir = wp_normalize_path( $root . '/' . $level . '/' . $target . '/' . $name );
		$file = $dir . '/SKILL.md';
		$meta_file = $dir . '/.mad4b.json';

		if ( is_file( $file ) ) return array( 'created' => false );
		if ( ! wp_mkdir_p( $dir ) ) return new WP_Error( 'mad4b_skill_seed_directory_failed', 'Unable to create the seed Skill directory.' );

		$root_real = realpath( $root );
		$dir_real = realpath( $dir );
		if ( false === $root_real || false === $dir_real || 0 !== strpos( wp_normalize_path( $dir_real ) . '/', trailingslashit( wp_normalize_path( $root_real ) ) ) ) {
			return new WP_Error( 'mad4b_skill_seed_escape_denied', 'Seed Skill path escaped the managed Skill root.' );
		}

		$document = "---\nname: " . $name . "\ndescription: " . self::yaml_scalar( $seed['description'] ) . "\n---\n\n" . trim( $seed['body'] ) . "\n";
		$sha = hash( 'sha256', $document );
		$meta = wp_json_encode(
			array(
				'contract' => MAD4B_SCP_Skill_Registry::CONTRACT,
				'level' => $level,
				'target' => $target,
				'name' => $name,
				'enabled' => true,
				'sha256' => $sha,
				'previous_sha256' => '',
				'updated_at' => gmdate( 'c' ),
				'updated_by' => 0,
				'provisioned_by' => self::CONTRACT,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n";

		$written = self::atomic_write( $file, $document );
		if ( is_wp_error( $written ) ) return $written;
		$written_meta = self::atomic_write( $meta_file, $meta );
		if ( is_wp_error( $written_meta ) ) { @unlink( $file ); return $written_meta; }

		$audit = MAD4B_SCP_Audit::record(
			'mad4b/skill-seed-provision',
			array( 'logical_id' => $level . ':' . $target . ':' . $name, 'after_sha256' => $sha, 'bytes' => strlen( $document ) ),
			'ok'
		);
		if ( is_wp_error( $audit ) ) { @unlink( $file ); @unlink( $meta_file ); return new WP_Error( 'mad4b_skill_seed_audit_failed', 'Seed Skill provisioning rolled back because audit commit failed.' ); }
		return array( 'created' => true );
	}

	private static function atomic_write( $file, $content ) {
		$tmp = $file . '.tmp-' . wp_generate_uuid4();
		$bytes = file_put_contents( $tmp, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $bytes ) return new WP_Error( 'mad4b_skill_seed_write_failed', 'Unable to write seed Skill file.' );
		if ( ! @rename( $tmp, $file ) ) { @unlink( $tmp ); return new WP_Error( 'mad4b_skill_seed_replace_failed', 'Unable to atomically publish seed Skill file.' ); }
		return true;
	}

	private static function yaml_scalar( $value ) {
		$value = str_replace( array( "\r", "\n" ), ' ', trim( (string) $value ) );
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
	}

	private static function seeds() {
		return array(
			array(
				'level' => 'site', 'target' => '_site', 'name' => 'wordpress-site-diagnostics',
				'description' => 'Diagnose WordPress runtime, plugins, providers, and configuration through the MAD4B read gateway.',
				'body' => "1. Start with site/runtime health and authority status.\n2. Inspect the affected provider only when relevant.\n3. Separate confirmed failures from warnings and hypotheses.\n4. Keep OAuth/transport separate from application health unless evidence links them.\n5. Return current state, root cause, evidence, remediation, uncertainty, and risk.\n6. Do not perform mutation from this workflow."
			),
			array(
				'level' => 'connection', 'target' => 'mad4b-chatgpt', 'name' => 'wordpress-connection-diagnostics',
				'description' => 'Diagnose MAD4B WordPress MCP transport, OAuth authority, external handshake, and ChatGPT connection readiness.',
				'body' => "1. Read connection status and exact protected-resource identity.\n2. Check OAuth authority, CIMD/PKCE alignment, and external-handshake evidence.\n3. Distinguish local transport readiness from external certification.\n4. Do not create credentials or widen scopes.\n5. Return the first failing gate and the minimum remediation."
			),
			array(
				'level' => 'provider', 'target' => 'elementor', 'name' => 'elementor-dynamic-content',
				'description' => 'Inspect Elementor templates, widgets, dynamic tags, conditions, and dynamic-content bindings using MAD4B read tools.',
				'body' => "1. Identify the exact Elementor document/template in scope.\n2. Inspect widget structure and dynamic-tag bindings.\n3. Resolve provider-backed fields before recommending shortcode fallbacks.\n4. Check display conditions and archive/singular context.\n5. Return broken bindings, viable dynamic-tag sources, and safe remediation."
			),
			array(
				'level' => 'provider', 'target' => 'jet-engine', 'name' => 'jetengine-content-modeling',
				'description' => 'Analyze JetEngine CPTs, meta fields, relations, listings, queries, and dynamic-content models through MAD4B.',
				'body' => "1. Map CPT/taxonomy/meta/relation ownership.\n2. Inspect listing/query dependencies and provider field keys.\n3. Prefer Dynamic Tags and provider-native data contracts over hard-coded shortcodes.\n4. Identify model drift, missing relations, and rendering gaps.\n5. Return the canonical content model and required changes without applying mutation."
			),
			array(
				'level' => 'workflow', 'target' => 'archive-audit', 'name' => 'wordpress-archive-audit',
				'description' => 'Audit a WordPress archive end-to-end across query context, template assignment, filters, dynamic content, and provider dependencies.',
				'body' => "1. Identify archive type, query context, and assigned template.\n2. Inspect listing/grid source and dynamic fields.\n3. Inspect JetSmartFilters/provider filters when present.\n4. Detect missing, duplicated, stale, or non-contextual content.\n5. Return coverage gaps and prioritized remediation."
			),
			array(
				'level' => 'workflow', 'target' => 'change-safety', 'name' => 'wordpress-change-safety',
				'description' => 'Assess the safety, blast radius, reversibility, and authority requirements of a proposed WordPress change before execution.',
				'body' => "1. Classify the proposed change and affected providers.\n2. Determine whether the current gateway is read-only or mutation-capable.\n3. Identify rollback, backup, and verification requirements.\n4. Refuse implicit authority escalation.\n5. Return GO/NO-GO, prerequisites, rollback plan, and post-change verification."
			),
		);
	}
}
