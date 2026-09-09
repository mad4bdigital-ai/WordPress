<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Zero-touch Staging seed pack provisioner.
 *
 * Canonical seed documents ship with the Control Plane and are copied byte-for-
 * byte into the runtime registry. User-owned files always win. Seeder-owned
 * files may be refreshed only when their current bytes still match the digest
 * recorded by the seeder/provider metadata, preventing silent overwrite of a
 * locally modified Skill.
 */
final class MAD4B_SCP_Skill_Seeder {
	const CONTRACT = 'mad4b.skill-seeder.v1';
	const OPTION = 'mad4b_scp_skill_seed_v1';
	const SEED_VERSION = 2;
	const SEED_DIR = 'skill-seeds';

	private static $ran = false;

	public static function bootstrap() {
		if ( self::$ran ) return self::status();
		self::$ran = true;

		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		if ( 'staging' !== $environment || ! MAD4B_SCP_Skill_Registry::editor_enabled() ) return self::status( 'not_eligible' );

		$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit_status['ready'] ) ) return self::status( 'audit_unavailable' );

		$root = MAD4B_SCP_Skill_Registry::storage_root();
		if ( '' === $root && defined( 'WP_CONTENT_DIR' ) ) $root = wp_normalize_path( WP_CONTENT_DIR . '/mad4b-skills' );
		if ( '' === $root || ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) ) return self::status( 'storage_unavailable' );

		$created = array();
		$refreshed = array();
		$skipped = array();
		foreach ( self::seeds() as $seed ) {
			$result = self::seed_one( $root, $seed );
			if ( is_wp_error( $result ) ) return self::status( $result->get_error_code(), $created, $refreshed, $skipped );
			if ( ! empty( $result['created'] ) ) $created[] = $seed['name'];
			elseif ( ! empty( $result['refreshed'] ) ) $refreshed[] = $seed['name'];
			else $skipped[] = $seed['name'];
		}

		update_option(
			self::OPTION,
			array(
				'contract' => self::CONTRACT,
				'version' => self::SEED_VERSION,
				'created' => $created,
				'refreshed_managed' => $refreshed,
				'skipped_existing' => $skipped,
				'updated_at' => gmdate( 'c' ),
			),
			false
		);
		return self::status( 'ready', $created, $refreshed, $skipped );
	}

	public static function status( $state = '', array $created = array(), array $refreshed = array(), array $skipped = array() ) {
		$stored = get_option( self::OPTION, array() );
		return array(
			'contract' => self::CONTRACT,
			'seed_version' => self::SEED_VERSION,
			'state' => '' !== $state ? $state : ( is_array( $stored ) && ! empty( $stored['updated_at'] ) && isset( $stored['version'] ) && self::SEED_VERSION === (int) $stored['version'] ? 'ready' : 'pending' ),
			'created' => ! empty( $created ) ? $created : ( is_array( $stored ) && isset( $stored['created'] ) && is_array( $stored['created'] ) ? $stored['created'] : array() ),
			'refreshed_managed' => ! empty( $refreshed ) ? $refreshed : ( is_array( $stored ) && isset( $stored['refreshed_managed'] ) && is_array( $stored['refreshed_managed'] ) ? $stored['refreshed_managed'] : array() ),
			'skipped_existing' => ! empty( $skipped ) ? $skipped : ( is_array( $stored ) && isset( $stored['skipped_existing'] ) && is_array( $stored['skipped_existing'] ) ? $stored['skipped_existing'] : array() ),
			'overwrites_user_owned' => false,
			'refreshes_only_digest_clean_managed' => true,
			'production_auto_seed' => false,
		);
	}

	private static function seed_one( $root, array $seed ) {
		$level = isset( $seed['level'] ) ? sanitize_key( (string) $seed['level'] ) : '';
		$target = isset( $seed['target'] ) ? sanitize_key( (string) $seed['target'] ) : '';
		$name = isset( $seed['name'] ) ? sanitize_key( (string) $seed['name'] ) : '';
		$enabled = ! array_key_exists( 'enabled', $seed ) || (bool) $seed['enabled'];
		if ( ! in_array( $level, MAD4B_SCP_Skill_Registry::levels(), true ) || '' === $target || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name ) ) {
			return new WP_Error( 'mad4b_skill_seed_definition_invalid', 'Canonical seed definition is invalid.' );
		}

		$document = self::canonical_document( $name );
		if ( is_wp_error( $document ) ) return $document;
		$sha = hash( 'sha256', $document );
		$dir = wp_normalize_path( $root . '/' . $level . '/' . $target . '/' . $name );
		$file = $dir . '/SKILL.md';
		$meta_file = $dir . '/.mad4b.json';

		if ( is_file( $file ) ) return self::refresh_existing_if_managed( $file, $meta_file, $level, $target, $name, $document, $sha );
		if ( ! wp_mkdir_p( $dir ) ) return new WP_Error( 'mad4b_skill_seed_directory_failed', 'Unable to create the seed Skill directory.' );
		if ( ! self::within_root( $root, $dir ) ) return new WP_Error( 'mad4b_skill_seed_escape_denied', 'Seed Skill path escaped the managed Skill root.' );

		$meta = array(
			'contract' => MAD4B_SCP_Skill_Registry::CONTRACT,
			'level' => $level,
			'target' => $target,
			'name' => $name,
			'enabled' => $enabled,
			'sha256' => $sha,
			'previous_sha256' => '',
			'updated_at' => gmdate( 'c' ),
			'updated_by' => 0,
			'provisioned_by' => self::CONTRACT,
			'seed_version' => self::SEED_VERSION,
			'canonical_source' => self::SEED_DIR . '/' . $name . '/SKILL.md',
		);

		$written = self::atomic_write( $file, $document );
		if ( is_wp_error( $written ) ) return $written;
		$written_meta = self::atomic_write( $meta_file, wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		if ( is_wp_error( $written_meta ) ) { @unlink( $file ); return $written_meta; }

		$audit = MAD4B_SCP_Audit::record(
			'mad4b/skill-seed-provision',
			array( 'logical_id' => $level . ':' . $target . ':' . $name, 'enabled' => $enabled, 'seed_version' => self::SEED_VERSION, 'after_sha256' => $sha, 'bytes' => strlen( $document ) ),
			'ok'
		);
		if ( is_wp_error( $audit ) ) { @unlink( $file ); @unlink( $meta_file ); return new WP_Error( 'mad4b_skill_seed_audit_failed', 'Seed Skill provisioning rolled back because audit commit failed.' ); }
		return array( 'created' => true, 'refreshed' => false );
	}

	private static function refresh_existing_if_managed( $file, $meta_file, $level, $target, $name, $document, $canonical_sha ) {
		if ( is_link( $file ) || ! is_file( $meta_file ) || is_link( $meta_file ) ) return array( 'created' => false, 'refreshed' => false );
		$before_skill = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$before_meta = file_get_contents( $meta_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$meta = is_string( $before_meta ) ? json_decode( $before_meta, true ) : null;
		if ( ! is_string( $before_skill ) || ! is_array( $meta ) || self::CONTRACT !== ( isset( $meta['provisioned_by'] ) ? (string) $meta['provisioned_by'] : '' ) ) {
			return array( 'created' => false, 'refreshed' => false );
		}

		$current_sha = hash( 'sha256', $before_skill );
		$recorded_sha = isset( $meta['sha256'] ) ? strtolower( trim( (string) $meta['sha256'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $recorded_sha ) || ! hash_equals( $recorded_sha, $current_sha ) ) {
			// A user or external process changed the bytes after MAD4B provisioned
			// them. Treat the file as user-owned and never overwrite it automatically.
			return array( 'created' => false, 'refreshed' => false );
		}
		if ( hash_equals( $current_sha, $canonical_sha ) ) return array( 'created' => false, 'refreshed' => false );

		$meta['previous_sha256'] = $current_sha;
		$meta['sha256'] = $canonical_sha;
		$meta['updated_at'] = gmdate( 'c' );
		$meta['updated_by'] = 0;
		$meta['seed_version'] = self::SEED_VERSION;
		$meta['canonical_source'] = self::SEED_DIR . '/' . $name . '/SKILL.md';
		$meta_json = wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

		$written = self::atomic_write( $file, $document );
		if ( is_wp_error( $written ) ) return $written;
		$written_meta = self::atomic_write( $meta_file, $meta_json );
		if ( is_wp_error( $written_meta ) ) {
			self::atomic_write( $file, $before_skill );
			return $written_meta;
		}

		$audit = MAD4B_SCP_Audit::record(
			'mad4b/skill-seed-refresh',
			array( 'logical_id' => $level . ':' . $target . ':' . $name, 'seed_version' => self::SEED_VERSION, 'before_sha256' => $current_sha, 'after_sha256' => $canonical_sha, 'bytes' => strlen( $document ) ),
			'ok'
		);
		if ( is_wp_error( $audit ) ) {
			$restore_skill = self::atomic_write( $file, $before_skill );
			$restore_meta = self::atomic_write( $meta_file, $before_meta );
			if ( is_wp_error( $restore_skill ) || is_wp_error( $restore_meta ) || ! is_file( $file ) || ! hash_equals( $current_sha, (string) hash_file( 'sha256', $file ) ) ) {
				return new WP_Error( 'mad4b_skill_seed_refresh_rollback_failed', 'Canonical seed refresh audit failed and the previous bytes could not be verified after rollback.' );
			}
			return new WP_Error( 'mad4b_skill_seed_refresh_audit_failed', 'Canonical seed refresh was rolled back and verified because its audit commit failed.' );
		}
		return array( 'created' => false, 'refreshed' => true );
	}

	private static function canonical_document( $name ) {
		$seed_root = wp_normalize_path( MAD4B_SCP_DIR . self::SEED_DIR );
		$path = wp_normalize_path( $seed_root . '/' . $name . '/SKILL.md' );
		if ( ! is_dir( $seed_root ) || ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) return new WP_Error( 'mad4b_skill_seed_source_missing', 'Canonical seed Skill source is missing or unreadable.' );
		$root_real = realpath( $seed_root );
		$path_real = realpath( $path );
		if ( false === $root_real || false === $path_real || 0 !== strpos( wp_normalize_path( $path_real ), trailingslashit( wp_normalize_path( $root_real ) ) ) ) return new WP_Error( 'mad4b_skill_seed_source_escape_denied', 'Canonical seed source escaped the packaged seed directory.' );
		$size = filesize( $path_real );
		if ( false === $size || $size < 1 || $size > MAD4B_SCP_Skill_Registry::MAX_SKILL_BYTES ) return new WP_Error( 'mad4b_skill_seed_source_size_invalid', 'Canonical seed Skill source has an invalid size.' );
		$document = file_get_contents( $path_real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $document ) || false !== strpos( $document, "\0" ) ) return new WP_Error( 'mad4b_skill_seed_source_invalid', 'Canonical seed Skill source is invalid.' );
		if ( function_exists( 'seems_utf8' ) && ! seems_utf8( $document ) ) return new WP_Error( 'mad4b_skill_seed_source_encoding_invalid', 'Canonical seed Skill source must be valid UTF-8.' );
		if ( ! preg_match( '/\A---\Rname:\s*' . preg_quote( $name, '/' ) . '\Rdescription:\s*.+?\R---\R/s', $document ) ) return new WP_Error( 'mad4b_skill_seed_source_frontmatter_invalid', 'Canonical seed Skill frontmatter is invalid.' );
		return $document;
	}

	private static function within_root( $root, $dir ) {
		$root_real = realpath( $root );
		$dir_real = realpath( $dir );
		if ( false === $root_real || false === $dir_real ) return false;
		$root_real = trailingslashit( wp_normalize_path( $root_real ) );
		$dir_real = trailingslashit( wp_normalize_path( $dir_real ) );
		return 0 === strpos( $dir_real, $root_real );
	}

	private static function atomic_write( $file, $content ) {
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) ) return new WP_Error( 'mad4b_skill_seed_directory_missing', 'Seed Skill target directory does not exist.' );
		if ( is_link( $file ) ) return new WP_Error( 'mad4b_skill_seed_symlink_denied', 'Seed Skill cannot be written through a symbolic link.' );
		$tmp = $file . '.tmp-' . wp_generate_uuid4();
		$bytes = file_put_contents( $tmp, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $bytes || $bytes !== strlen( (string) $content ) ) { @unlink( $tmp ); return new WP_Error( 'mad4b_skill_seed_write_failed', 'Unable to write the complete seed Skill file.' ); }
		if ( ! @rename( $tmp, $file ) ) { @unlink( $tmp ); return new WP_Error( 'mad4b_skill_seed_replace_failed', 'Unable to atomically publish seed Skill file.' ); }
		return true;
	}

	private static function seeds() {
		return array(
			array( 'level' => 'site', 'target' => '_site', 'name' => 'wordpress-site-diagnostics' ),
			array( 'level' => 'connection', 'target' => 'mad4b-chatgpt', 'name' => 'wordpress-connection-diagnostics' ),
			array( 'level' => 'provider', 'target' => 'elementor', 'name' => 'elementor-dynamic-content', 'enabled' => false ),
			array( 'level' => 'provider', 'target' => 'jet-engine', 'name' => 'jetengine-content-modeling', 'enabled' => false ),
			array( 'level' => 'workflow', 'target' => 'archive-audit', 'name' => 'wordpress-archive-audit' ),
			array( 'level' => 'workflow', 'target' => 'change-safety', 'name' => 'wordpress-change-safety' ),
		);
	}
}

// Provider Skill packs are discovered only after the Control Plane has registered
// its adapter defaults at plugins_loaded priority 20. This hook is read-only with
// respect to provider plugins; it only reconciles MAD4B-managed Skill files.
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-provider-discovery.php';
add_action( 'plugins_loaded', array( 'MAD4B_SCP_Skill_Provider_Discovery', 'bootstrap' ), 30 );
