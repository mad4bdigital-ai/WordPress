<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * File-backed Skill registry for MAD4B workflows.
 *
 * Runtime-authored skills are intentionally stored outside vendor plugin roots
 * under wp-content/mad4b-skills. This keeps authored workflows durable across
 * plugin upgrades while still preserving provider/adapter/workflow ownership in
 * the directory hierarchy. The portable exporter can flatten enabled skills
 * into the Agent Plugins root skills/ directory.
 */
final class MAD4B_SCP_Skill_Registry {
	const CONTRACT = 'mad4b.skill-registry.v1';
	const ROOT_DIRNAME = 'mad4b-skills';
	const META_FILE = '.mad4b.json';
	const MAX_SKILL_BYTES = 262144;
	const MAX_SCAN_SKILLS = 500;

	private static $levels = array( 'site', 'connection', 'provider', 'adapter', 'workflow' );

	public static function levels() {
		return self::$levels;
	}

	public static function storage_root() {
		$root = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/' . self::ROOT_DIRNAME : '';
		$root = apply_filters( 'mad4b_scp_skill_storage_root', $root );
		return untrailingslashit( wp_normalize_path( (string) $root ) );
	}

	public static function editor_enabled() {
		if ( ! defined( 'MAD4B_SKILLS_EDITOR_ENABLED' ) || true !== constant( 'MAD4B_SKILLS_EDITOR_ENABLED' ) ) return false;
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( 'production' !== $environment ) return true;
		return defined( 'MAD4B_SKILLS_PRODUCTION_EDITOR_ENABLED' ) && true === constant( 'MAD4B_SKILLS_PRODUCTION_EDITOR_ENABLED' );
	}

	public static function scripts_enabled() {
		return self::editor_enabled()
			&& defined( 'MAD4B_SKILLS_SCRIPTS_EDITOR_ENABLED' )
			&& true === constant( 'MAD4B_SKILLS_SCRIPTS_EDITOR_ENABLED' );
	}

	public static function status() {
		$root = self::storage_root();
		$exists = '' !== $root && is_dir( $root );
		return array(
			'contract' => self::CONTRACT,
			'editor_enabled' => self::editor_enabled(),
			'scripts_editor_enabled' => self::scripts_enabled(),
			'storage_initialized' => $exists,
			'storage_writable' => $exists ? is_writable( $root ) : ( '' !== $root && is_writable( dirname( $root ) ) ),
			'levels' => self::levels(),
			'skill_count' => count( self::list_skills() ),
			'portable_app_id_configured' => '' !== self::openai_app_id(),
			'portable_app_id' => self::masked_app_id(),
		);
	}

	public static function openai_app_id() {
		if ( ! defined( 'MAD4B_OPENAI_PLUGIN_APP_ID' ) ) return '';
		$value = trim( (string) constant( 'MAD4B_OPENAI_PLUGIN_APP_ID' ) );
		return preg_match( '/^plugin_asdk_app_[A-Za-z0-9]+$/', $value ) ? $value : '';
	}

	private static function masked_app_id() {
		$id = self::openai_app_id();
		if ( '' === $id ) return '';
		return substr( $id, 0, 20 ) . '…' . substr( $id, -6 );
	}

	public static function list_skills( array $filters = array() ) {
		$root = self::storage_root();
		if ( '' === $root || ! is_dir( $root ) ) return array();

		$want_level = isset( $filters['level'] ) ? sanitize_key( (string) $filters['level'] ) : '';
		$want_target = isset( $filters['target'] ) ? self::sanitize_target( $want_level, $filters['target'] ) : '';
		$enabled_filter = array_key_exists( 'enabled', $filters ) ? (bool) $filters['enabled'] : null;
		$items = array();

		foreach ( self::$levels as $level ) {
			if ( '' !== $want_level && $want_level !== $level ) continue;
			$level_dir = $root . '/' . $level;
			if ( ! is_dir( $level_dir ) ) continue;
			$targets = self::bounded_directories( $level_dir );
			foreach ( $targets as $target ) {
				if ( '' !== $want_target && $target !== $want_target ) continue;
				$target_dir = $level_dir . '/' . $target;
				foreach ( self::bounded_directories( $target_dir ) as $slug ) {
					$dir = $target_dir . '/' . $slug;
					$file = $dir . '/SKILL.md';
					if ( ! is_file( $file ) || is_link( $file ) ) continue;
					$entry = self::read_entry( $level, $target, $slug, false );
					if ( is_wp_error( $entry ) ) continue;
					if ( null !== $enabled_filter && $enabled_filter !== ! empty( $entry['enabled'] ) ) continue;
					$items[] = self::summary( $entry );
					if ( count( $items ) >= self::MAX_SCAN_SKILLS ) break 3;
				}
			}
		}

		usort( $items, function ( $a, $b ) {
			return strcmp( (string) $a['logical_id'], (string) $b['logical_id'] );
		} );
		return $items;
	}

	public static function get_skill( $level, $target, $slug ) {
		$level = self::sanitize_level( $level );
		if ( is_wp_error( $level ) ) return $level;
		$target = self::sanitize_target( $level, $target );
		if ( '' === $target ) return new WP_Error( 'mad4b_skill_target_invalid', 'Skill target is invalid.' );
		$slug = self::sanitize_slug( $slug );
		if ( '' === $slug ) return new WP_Error( 'mad4b_skill_slug_invalid', 'Skill slug is invalid.' );
		return self::read_entry( $level, $target, $slug, true );
	}

	public static function save_skill( array $input ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_skill_capability_denied', 'Administrator capability is required to author skills.' );
		if ( ! self::editor_enabled() ) return new WP_Error( 'mad4b_skill_editor_disabled', 'Skill authoring is disabled. Staging is normally auto-configured; Production requires both explicit editor gates.' );

		$level = self::sanitize_level( isset( $input['level'] ) ? $input['level'] : '' );
		if ( is_wp_error( $level ) ) return $level;
		$target = self::sanitize_target( $level, isset( $input['target'] ) ? $input['target'] : '' );
		if ( '' === $target ) return new WP_Error( 'mad4b_skill_target_invalid', 'Skill target is invalid.' );

		$name = self::sanitize_slug( isset( $input['name'] ) ? $input['name'] : '' );
		if ( '' === $name ) return new WP_Error( 'mad4b_skill_name_invalid', 'Skill name must be a non-empty kebab-case identifier.' );
		$description = trim( isset( $input['description'] ) ? (string) $input['description'] : '' );
		$body = isset( $input['body'] ) ? trim( (string) $input['body'] ) : '';
		$enabled = ! array_key_exists( 'enabled', $input ) || (bool) $input['enabled'];
		if ( '' === $description || strlen( $description ) > 2000 ) return new WP_Error( 'mad4b_skill_description_invalid', 'Skill description is required and must be 2000 bytes or fewer.' );
		if ( '' === $body ) return new WP_Error( 'mad4b_skill_body_required', 'Skill workflow instructions are required.' );

		$document = "---\nname: " . $name . "\ndescription: " . self::yaml_scalar( $description ) . "\n---\n\n" . $body . "\n";
		$valid = self::validate_document( $document, $name );
		if ( is_wp_error( $valid ) ) return $valid;
		if ( $enabled ) {
			$duplicate = self::enabled_name_collision( $name, $level, $target );
			if ( is_wp_error( $duplicate ) ) return $duplicate;
		}

		$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit_status['ready'] ) ) return new WP_Error( 'mad4b_skill_audit_unavailable', 'Append-only audit storage must be ready before a skill file can be changed.' );

		$dir = self::skill_dir( $level, $target, $name, true );
		if ( is_wp_error( $dir ) ) return $dir;
		$skill_file = $dir . '/SKILL.md';
		$meta_file = $dir . '/' . self::META_FILE;
		$before_skill = is_file( $skill_file ) ? file_get_contents( $skill_file ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$before_meta = is_file( $meta_file ) ? file_get_contents( $meta_file ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $before_skill || false === $before_meta ) return new WP_Error( 'mad4b_skill_before_state_read_failed', 'Unable to capture the complete pre-change Skill state.' );
		$previous_sha = is_string( $before_skill ) ? hash( 'sha256', $before_skill ) : '';
		$sha = hash( 'sha256', $document );
		$meta = array(
			'contract' => self::CONTRACT,
			'level' => $level,
			'target' => $target,
			'name' => $name,
			'enabled' => $enabled,
			'sha256' => $sha,
			'previous_sha256' => $previous_sha,
			'updated_at' => gmdate( 'c' ),
			'updated_by' => get_current_user_id(),
		);

		$written = self::atomic_write( $skill_file, $document );
		if ( is_wp_error( $written ) ) return $written;
		$meta_json = wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
		$written_meta = self::atomic_write( $meta_file, $meta_json );
		if ( is_wp_error( $written_meta ) ) {
			$restore_skill = self::restore_file( $skill_file, $before_skill );
			$verify_meta = self::verify_file_state( $meta_file, $before_meta );
			if ( is_wp_error( $restore_skill ) || is_wp_error( $verify_meta ) ) {
				return new WP_Error( 'mad4b_skill_rollback_failed', 'Skill metadata write failed and the previous file state could not be verified after rollback.', array( 'write_error' => $written_meta->get_error_code() ) );
			}
			return $written_meta;
		}

		$audit = MAD4B_SCP_Audit::record(
			'mad4b/skill-file-save',
			array(
				'logical_id' => self::logical_id( $level, $target, $name ),
				'enabled' => $enabled,
				'before_sha256' => $previous_sha,
				'after_sha256' => $sha,
				'bytes' => strlen( $document ),
			),
			'ok'
		);
		if ( is_wp_error( $audit ) ) {
			$restore_skill = self::restore_file( $skill_file, $before_skill );
			$restore_meta = self::restore_file( $meta_file, $before_meta );
			if ( is_wp_error( $restore_skill ) || is_wp_error( $restore_meta ) ) {
				return new WP_Error(
					'mad4b_skill_rollback_failed',
					'Skill audit commit failed and the complete previous state could not be verified after rollback.',
					array(
						'audit_error' => $audit->get_error_code(),
						'skill_restore_error' => is_wp_error( $restore_skill ) ? $restore_skill->get_error_code() : '',
						'meta_restore_error' => is_wp_error( $restore_meta ) ? $restore_meta->get_error_code() : '',
					)
				);
			}
			return new WP_Error( 'mad4b_skill_audit_failed', 'Skill change was rolled back and verified because its audit event could not be committed.', array( 'audit_error' => $audit->get_error_code() ) );
		}

		return self::read_entry( $level, $target, $name, true );
	}

	/**
	 * Save a bounded text resource next to SKILL.md. scripts/ is separately gated
	 * and nothing stored here is executed by WordPress.
	 */
	public static function save_text_resource( $level, $target, $slug, $relative_path, $content ) {
		if ( ! current_user_can( 'manage_options' ) || ! self::editor_enabled() ) return new WP_Error( 'mad4b_skill_editor_disabled', 'Skill resource authoring is disabled.' );
		$level = self::sanitize_level( $level );
		if ( is_wp_error( $level ) ) return $level;
		$target = self::sanitize_target( $level, $target );
		$slug = self::sanitize_slug( $slug );
		$relative_path = wp_normalize_path( ltrim( (string) $relative_path, '/' ) );
		if ( '' === $target || '' === $slug || '' === $relative_path || false !== strpos( $relative_path, '..' ) ) return new WP_Error( 'mad4b_skill_resource_path_invalid', 'Skill resource path is invalid.' );
		$parts = explode( '/', $relative_path );
		$top = isset( $parts[0] ) ? $parts[0] : '';
		if ( ! in_array( $top, array( 'references', 'assets', 'scripts' ), true ) ) return new WP_Error( 'mad4b_skill_resource_root_invalid', 'Resources must live under references/, assets/, or scripts/.' );
		if ( 'scripts' === $top && ! self::scripts_enabled() ) return new WP_Error( 'mad4b_skill_scripts_disabled', 'Skill script authoring requires MAD4B_SKILLS_SCRIPTS_EDITOR_ENABLED.' );
		if ( strlen( (string) $content ) > self::MAX_SKILL_BYTES || false !== strpos( (string) $content, "\0" ) ) return new WP_Error( 'mad4b_skill_resource_invalid', 'Skill resource is too large or contains invalid bytes.' );
		foreach ( $parts as $part ) if ( '' === $part || $part !== sanitize_file_name( $part ) ) return new WP_Error( 'mad4b_skill_resource_path_invalid', 'Every skill resource path segment must be a safe filename.' );

		$dir = self::skill_dir( $level, $target, $slug, false );
		if ( is_wp_error( $dir ) || ! is_dir( $dir ) ) return new WP_Error( 'mad4b_skill_not_found', 'Create the skill before adding supporting resources.' );
		$file = $dir . '/' . $relative_path;
		$parent = dirname( $file );
		if ( ! wp_mkdir_p( $parent ) ) return new WP_Error( 'mad4b_skill_resource_directory_failed', 'Unable to create the skill resource directory.' );
		if ( ! self::within_root( $parent ) ) return new WP_Error( 'mad4b_skill_resource_escape_denied', 'Skill resource path escaped the managed storage root.' );
		$written = self::atomic_write( $file, (string) $content );
		if ( is_wp_error( $written ) ) return $written;
		return array( 'logical_id' => self::logical_id( $level, $target, $slug ), 'resource' => $relative_path, 'sha256' => hash( 'sha256', (string) $content ), 'bytes' => strlen( (string) $content ) );
	}

	public static function portable_snapshot() {
		$skills = self::list_skills( array( 'enabled' => true ) );
		return array(
			'contract' => 'mad4b.portable-plugin-snapshot.v1',
			'plugin_name' => 'mad4b-wordpress',
			'plugin_version' => defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '0.0.0',
			'app_mapping_configured' => '' !== self::openai_app_id(),
			'app_id' => self::masked_app_id(),
			'skill_count' => count( $skills ),
			'skills' => $skills,
			'publication_semantics' => 'snapshot_requires_repackage_or_scan',
		);
	}

	private static function read_entry( $level, $target, $slug, $include_content ) {
		$dir = self::skill_dir( $level, $target, $slug, false );
		if ( is_wp_error( $dir ) ) return $dir;
		$file = $dir . '/SKILL.md';
		if ( ! is_file( $file ) || is_link( $file ) ) return new WP_Error( 'mad4b_skill_not_found', 'Skill file was not found.' );
		$size = filesize( $file );
		if ( false === $size || $size < 1 || $size > self::MAX_SKILL_BYTES ) return new WP_Error( 'mad4b_skill_file_size_invalid', 'Skill file is empty or exceeds the bounded size.' );
		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $content ) ) return new WP_Error( 'mad4b_skill_read_failed', 'Unable to read the skill file.' );
		$parsed = self::validate_document( $content, $slug );
		if ( is_wp_error( $parsed ) ) return $parsed;
		$meta = self::read_meta( $dir . '/' . self::META_FILE );
		$entry = array(
			'contract' => self::CONTRACT,
			'logical_id' => self::logical_id( $level, $target, $slug ),
			'level' => $level,
			'target' => $target,
			'name' => $parsed['name'],
			'description' => $parsed['description'],
			'enabled' => ! array_key_exists( 'enabled', $meta ) || (bool) $meta['enabled'],
			'sha256' => hash( 'sha256', $content ),
			'bytes' => strlen( $content ),
			'updated_at' => isset( $meta['updated_at'] ) ? (string) $meta['updated_at'] : '',
			'resources' => self::resource_inventory( $dir ),
		);
		if ( $include_content ) $entry['content'] = $content;
		return $entry;
	}

	private static function summary( array $entry ) {
		unset( $entry['content'] );
		return $entry;
	}

	private static function resource_inventory( $dir ) {
		$out = array();
		foreach ( array( 'references', 'assets', 'scripts' ) as $root ) {
			$base = $dir . '/' . $root;
			if ( ! is_dir( $base ) || is_link( $base ) ) continue;
			try {
				$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
				foreach ( $iterator as $file ) {
					if ( ! $file->isFile() || $file->isLink() ) continue;
					$relative = ltrim( str_replace( wp_normalize_path( $dir ), '', wp_normalize_path( $file->getPathname() ) ), '/' );
					$sha = hash_file( 'sha256', $file->getPathname() );
					if ( false === $sha ) continue;
					$out[] = array( 'path' => $relative, 'bytes' => $file->getSize(), 'sha256' => $sha );
					if ( count( $out ) >= 100 ) break 2;
				}
			} catch ( UnexpectedValueException $e ) {
				continue;
			}
		}
		return $out;
	}

	private static function validate_document( $content, $expected_name = '' ) {
		$content = (string) $content;
		if ( '' === trim( $content ) || strlen( $content ) > self::MAX_SKILL_BYTES || false !== strpos( $content, "\0" ) ) return new WP_Error( 'mad4b_skill_document_invalid', 'SKILL.md is empty, too large, or contains invalid bytes.' );
		if ( function_exists( 'seems_utf8' ) && ! seems_utf8( $content ) ) return new WP_Error( 'mad4b_skill_encoding_invalid', 'SKILL.md must be valid UTF-8 text.' );
		if ( ! preg_match( '/\A---\R(.*?)\R---\R/s', $content, $match ) ) return new WP_Error( 'mad4b_skill_frontmatter_required', 'SKILL.md must start with YAML frontmatter.' );
		$name = '';
		$description = '';
		foreach ( preg_split( '/\R/', $match[1] ) as $line ) {
			if ( preg_match( '/^name:\s*(.+)$/', $line, $m ) ) $name = trim( $m[1], " \t\n\r\0\x0B\"'" );
			if ( preg_match( '/^description:\s*(.+)$/', $line, $m ) ) $description = trim( $m[1], " \t\n\r\0\x0B\"'" );
		}
		$name = self::sanitize_slug( $name );
		if ( '' === $name || '' === $description ) return new WP_Error( 'mad4b_skill_frontmatter_invalid', 'SKILL.md frontmatter requires name and description.' );
		if ( '' !== $expected_name && $expected_name !== $name ) return new WP_Error( 'mad4b_skill_name_mismatch', 'SKILL.md name must match its skill folder.' );
		return array( 'name' => $name, 'description' => $description );
	}

	private static function enabled_name_collision( $name, $level, $target ) {
		foreach ( self::list_skills( array( 'enabled' => true ) ) as $skill ) {
			if ( $name !== $skill['name'] ) continue;
			if ( $level === $skill['level'] && $target === $skill['target'] ) continue;
			return new WP_Error( 'mad4b_skill_portable_name_collision', 'Enabled skill names must be globally unique so the portable skills/ snapshot cannot collide.', array( 'existing_logical_id' => $skill['logical_id'] ) );
		}
		return false;
	}

	private static function sanitize_level( $level ) {
		$level = sanitize_key( (string) $level );
		if ( ! in_array( $level, self::$levels, true ) ) return new WP_Error( 'mad4b_skill_level_invalid', 'Skill level is not supported.' );
		return $level;
	}

	private static function sanitize_target( $level, $target ) {
		if ( 'site' === $level ) return '_site';
		$target = strtolower( trim( (string) $target ) );
		$target = preg_replace( '/[^a-z0-9._-]+/', '-', $target );
		$target = trim( (string) $target, '-._' );
		return strlen( $target ) <= 120 ? $target : '';
	}

	private static function sanitize_slug( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) return '';
		return strlen( $slug ) <= 100 ? $slug : '';
	}

	private static function skill_dir( $level, $target, $slug, $create ) {
		$root = self::storage_root();
		if ( '' === $root ) return new WP_Error( 'mad4b_skill_storage_unavailable', 'Skill storage root is unavailable.' );
		if ( $create && ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) return new WP_Error( 'mad4b_skill_storage_create_failed', 'Unable to create the managed skill storage root.' );
		$path = $root . '/' . $level . '/' . $target . '/' . $slug;
		if ( $create && ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) return new WP_Error( 'mad4b_skill_directory_create_failed', 'Unable to create the managed skill directory.' );
		if ( is_dir( $path ) && ! self::within_root( $path ) ) return new WP_Error( 'mad4b_skill_path_escape_denied', 'Skill path escaped the managed storage root.' );
		return $path;
	}

	private static function within_root( $path ) {
		$root = self::storage_root();
		if ( '' === $root || ! is_dir( $root ) || ! is_dir( $path ) ) return false;
		$root_real = realpath( $root );
		$path_real = realpath( $path );
		if ( false === $root_real || false === $path_real ) return false;
		$root_real = trailingslashit( wp_normalize_path( $root_real ) );
		$path_real = trailingslashit( wp_normalize_path( $path_real ) );
		return 0 === strpos( $path_real, $root_real );
	}

	private static function atomic_write( $file, $content ) {
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) return new WP_Error( 'mad4b_skill_write_directory_failed', 'Unable to create the target directory.' );
		if ( ! self::within_root( $dir ) ) return new WP_Error( 'mad4b_skill_write_escape_denied', 'Write path escaped the managed skill storage root.' );
		if ( is_link( $file ) ) return new WP_Error( 'mad4b_skill_symlink_denied', 'Writing through a symbolic link is not allowed.' );
		$tmp = tempnam( $dir, '.mad4b-skill-' );
		if ( false === $tmp ) return new WP_Error( 'mad4b_skill_tempfile_failed', 'Unable to create a temporary skill file.' );
		$bytes = file_put_contents( $tmp, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $bytes || $bytes !== strlen( $content ) ) { @unlink( $tmp ); return new WP_Error( 'mad4b_skill_write_failed', 'Unable to write the complete skill file.' ); }
		if ( defined( 'FS_CHMOD_FILE' ) ) @chmod( $tmp, FS_CHMOD_FILE );
		if ( ! @rename( $tmp, $file ) ) { @unlink( $tmp ); return new WP_Error( 'mad4b_skill_atomic_replace_failed', 'Unable to atomically replace the skill file.' ); }
		return true;
	}

	private static function restore_file( $file, $before ) {
		if ( null === $before ) {
			if ( is_link( $file ) ) return new WP_Error( 'mad4b_skill_restore_symlink_denied', 'Rollback target became a symbolic link.' );
			if ( is_file( $file ) && ! @unlink( $file ) ) return new WP_Error( 'mad4b_skill_restore_remove_failed', 'Rollback could not remove the newly created file.' );
			return self::verify_file_state( $file, null );
		}
		$restored = self::atomic_write( $file, (string) $before );
		if ( is_wp_error( $restored ) ) return $restored;
		return self::verify_file_state( $file, (string) $before );
	}

	private static function verify_file_state( $file, $before ) {
		if ( null === $before ) return ! file_exists( $file ) ? true : new WP_Error( 'mad4b_skill_restore_remove_mismatch', 'Rollback expected the file to be absent.' );
		if ( ! is_file( $file ) || is_link( $file ) ) return new WP_Error( 'mad4b_skill_restore_file_missing', 'Rollback target is missing or unsafe.' );
		$after = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $after ) || ! hash_equals( hash( 'sha256', (string) $before ), hash( 'sha256', $after ) ) || (string) $before !== $after ) {
			return new WP_Error( 'mad4b_skill_restore_digest_mismatch', 'Rollback target does not match its exact pre-change bytes.' );
		}
		return true;
	}

	private static function read_meta( $file ) {
		if ( ! is_file( $file ) || is_link( $file ) ) return array();
		$json = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $data ) ? $data : array();
	}

	private static function bounded_directories( $path ) {
		$out = array();
		$entries = @scandir( $path );
		if ( ! is_array( $entries ) ) return $out;
		foreach ( array_slice( $entries, 0, self::MAX_SCAN_SKILLS + 2 ) as $entry ) {
			if ( '.' === $entry || '..' === $entry || ! is_dir( $path . '/' . $entry ) || is_link( $path . '/' . $entry ) ) continue;
			$out[] = $entry;
		}
		return $out;
	}

	private static function logical_id( $level, $target, $slug ) {
		return $level . ':' . $target . ':' . $slug;
	}

	private static function yaml_scalar( $value ) {
		$value = str_replace( array( "\r", "\n" ), ' ', (string) $value );
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
	}
}
