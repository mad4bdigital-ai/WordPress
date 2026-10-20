<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Filesystem mutation boundary for governed Ability callbacks.
 * Retains existing policy, optimistic SHA, protected backup and atomic
 * rename rules while keeping these operations out of the Ability registry.
 */
final class MAD4B_SCP_Filesystem_Mutation_Service {
	public static function filesystem_write( $input ) {
		$path = MAD4B_SCP_Policy::resolve_path( $input['root'], $input['path'], false ); if ( is_wp_error( $path ) ) return $path;
		$mutation_allowed = MAD4B_SCP_Policy::can_mutate_file( $input['root'], $path ); if ( is_wp_error( $mutation_allowed ) ) return $mutation_allowed;
		$content = (string) $input['content']; if ( false !== strpos( $content, "\0" ) ) return new WP_Error( 'mad4b_binary_write_denied', 'NUL bytes are not allowed.' );
		$exists = file_exists( $path ); $expected = isset( $input['expected_sha256'] ) ? strtolower( trim( $input['expected_sha256'] ) ) : '';
		if ( $exists ) { if ( ! is_file( $path ) ) return new WP_Error( 'mad4b_not_file', 'Target is not a regular file.' ); $current = hash_file( 'sha256', $path ); if ( ! $expected || ! hash_equals( $current, $expected ) ) return new WP_Error( 'mad4b_stale_file', 'Current SHA-256 is required.', array( 'current_sha256' => $current ) ); }
		elseif ( empty( $input['allow_create'] ) ) return new WP_Error( 'mad4b_create_not_allowed', 'Set allow_create=true to create a new file.' );
		$result = self::atomic_write( $path, $content, ! isset( $input['create_backup'] ) || (bool) $input['create_backup'] ); if ( is_wp_error( $result ) ) return $result;
		MAD4B_SCP_Audit::record( 'mad4b/filesystem-write', array( 'root' => $input['root'], 'path' => $input['path'], 'sha256' => $result['sha256'], 'create' => ! $exists, 'backup_id' => $result['backup_id'] ) ); return $result;
	}

	public static function filesystem_patch( $input ) {
		$path = MAD4B_SCP_Policy::resolve_path( $input['root'], $input['path'], true ); if ( is_wp_error( $path ) ) return $path;
		$mutation_allowed = MAD4B_SCP_Policy::can_mutate_file( $input['root'], $path ); if ( is_wp_error( $mutation_allowed ) ) return $mutation_allowed;
		$content = is_file( $path ) ? file_get_contents( $path ) : false; if ( false === $content ) return new WP_Error( 'mad4b_read_failed', 'Unable to read target.' );
		$current = hash( 'sha256', $content ); if ( ! hash_equals( $current, strtolower( trim( $input['expected_sha256'] ) ) ) ) return new WP_Error( 'mad4b_stale_file', 'Target SHA-256 no longer matches.', array( 'current_sha256' => $current ) );
		$count = substr_count( $content, (string) $input['search'] ); $max = isset( $input['max_replacements'] ) ? max( 1, min( 100, absint( $input['max_replacements'] ) ) ) : 1;
		if ( 0 === $count ) return new WP_Error( 'mad4b_patch_not_found', 'Search text not found.' ); if ( $count > $max ) return new WP_Error( 'mad4b_patch_too_broad', 'Search matches exceed max_replacements.', array( 'matches' => $count ) );
		$patched = str_replace( (string) $input['search'], (string) $input['replace'], $content, $replacements ); $result = self::atomic_write( $path, $patched, ! isset( $input['create_backup'] ) || (bool) $input['create_backup'] ); if ( is_wp_error( $result ) ) return $result;
		$result['replacements'] = $replacements; MAD4B_SCP_Audit::record( 'mad4b/filesystem-patch', array( 'root' => $input['root'], 'path' => $input['path'], 'replacements' => $replacements, 'sha256' => $result['sha256'], 'backup_id' => $result['backup_id'] ) ); return $result;
	}

	private static function atomic_write( $path, $content, $backup ) {
		$dir = dirname( $path ); if ( ! is_writable( $dir ) ) return new WP_Error( 'mad4b_directory_not_writable', 'Target directory is not writable.' );
		$exists = file_exists( $path );
		$mode = $exists ? ( fileperms( $path ) & 0777 ) : 0644;
		$backup_id = '';
		if ( $backup && $exists ) {
			$backup_root = MAD4B_SCP_Policy::prepare_backup_root();
			if ( is_wp_error( $backup_root ) ) return $backup_root;
			$name = sanitize_file_name( basename( $path ) ) . '-' . substr( hash( 'sha256', $path ), 0, 12 ) . '-' . gmdate( 'YmdHis' ) . '.bak';
			$name = wp_unique_filename( $backup_root, $name );
			$backup_path = trailingslashit( $backup_root ) . $name;
			if ( ! copy( $path, $backup_path ) ) return new WP_Error( 'mad4b_backup_failed', 'Unable to create protected backup.' );
			@chmod( $backup_path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$backup_id = $name;
		}

		$temp = tempnam( $dir, '.mad4b-' ); if ( false === $temp ) return new WP_Error( 'mad4b_temp_failed', 'Unable to create temporary file.' );
		$bytes = file_put_contents( $temp, $content, LOCK_EX );
		if ( false === $bytes ) { @unlink( $temp ); return new WP_Error( 'mad4b_write_failed', 'Unable to write temporary file.' ); }
		@chmod( $temp, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! rename( $temp, $path ) ) { @unlink( $temp ); return new WP_Error( 'mad4b_replace_failed', 'Unable to atomically replace target.' ); }
		clearstatcache( true, $path );
		return array( 'written' => true, 'bytes' => $bytes, 'sha256' => hash( 'sha256', $content ), 'backup_id' => $backup_id, 'mode' => sprintf( '%04o', $mode ) );
	}

}
