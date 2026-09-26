<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Internal bounded reader used only when building a portable Skill snapshot.
 * It never returns absolute filesystem paths.
 */
final class MAD4B_SCP_Skill_Resource_Reader {
	const MAX_RESOURCE_BYTES = 1048576;

	public static function read( $level, $target, $slug, $relative_path ) {
		$level = sanitize_key( (string) $level );
		if ( ! in_array( $level, MAD4B_SCP_Skill_Registry::levels(), true ) ) return new WP_Error( 'mad4b_skill_resource_level_invalid', 'Skill resource level is invalid.' );
		if ( 'site' === $level ) $target = '_site';
		else {
			$target = strtolower( trim( (string) $target ) );
			$target = preg_replace( '/[^a-z0-9._-]+/', '-', $target );
			$target = trim( (string) $target, '-._' );
		}
		$slug = strtolower( trim( (string) $slug ) );
		$relative_path = wp_normalize_path( ltrim( (string) $relative_path, '/' ) );
		if ( '' === $target || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) || '' === $relative_path || false !== strpos( $relative_path, '..' ) ) return new WP_Error( 'mad4b_skill_resource_path_invalid', 'Skill resource path is invalid.' );

		$parts = explode( '/', $relative_path );
		$top = isset( $parts[0] ) ? $parts[0] : '';
		if ( ! in_array( $top, array( 'references', 'assets', 'scripts' ), true ) ) return new WP_Error( 'mad4b_skill_resource_root_invalid', 'Skill resource is outside an allowed supporting directory.' );
		foreach ( $parts as $part ) if ( '' === $part || $part !== sanitize_file_name( $part ) ) return new WP_Error( 'mad4b_skill_resource_path_invalid', 'Skill resource contains an unsafe path segment.' );

		$root = MAD4B_SCP_Skill_Registry::storage_root();
		$skill_dir = $root . '/' . $level . '/' . $target . '/' . $slug;
		$file = $skill_dir . '/' . $relative_path;
		if ( ! is_dir( $root ) || ! is_dir( $skill_dir ) || ! is_file( $file ) || is_link( $file ) ) return new WP_Error( 'mad4b_skill_resource_not_found', 'Skill resource was not found.' );

		$root_real = realpath( $root );
		$file_real = realpath( $file );
		if ( false === $root_real || false === $file_real ) return new WP_Error( 'mad4b_skill_resource_resolve_failed', 'Skill resource path could not be resolved.' );
		$root_real = trailingslashit( wp_normalize_path( $root_real ) );
		$file_real = wp_normalize_path( $file_real );
		if ( 0 !== strpos( $file_real, $root_real ) ) return new WP_Error( 'mad4b_skill_resource_escape_denied', 'Skill resource escaped the managed storage root.' );

		$size = filesize( $file_real );
		if ( false === $size || $size < 0 || $size > self::MAX_RESOURCE_BYTES ) return new WP_Error( 'mad4b_skill_resource_size_invalid', 'Skill resource exceeds the bounded export size.' );
		$content = file_get_contents( $file_real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $content ) ) return new WP_Error( 'mad4b_skill_resource_read_failed', 'Unable to read the skill resource.' );
		return array( 'path' => $relative_path, 'content' => $content, 'bytes' => strlen( $content ), 'sha256' => hash( 'sha256', $content ) );
	}
}
