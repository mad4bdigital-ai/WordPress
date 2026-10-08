<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-resilience-context.php';

/** Site-local monotonic latch and rollout fence outside the restorable WordPress DB/files. */
final class MAD4B_SCP_Resilience_Anchor {
	const CONTRACT = 'mad4b.resilience-anchor.v1';
	const MIRROR_OPTION = 'mad4b_scp_resilience_anchor_seen_v1';
	const MAX_BYTES = 524288;

	public static function read( array $binding ) {
		$path = self::path( $binding );
		if ( is_wp_error( $path ) ) return $path;
		return self::read_path( $path, $binding );
	}

	/** Internal typed services own the closure; there is no request-selected callback. */
	public static function transact( array $binding, $expected_revision, $transform ) {
		$path = self::path( $binding );
		if ( is_wp_error( $path ) ) return $path;
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) return self::error( 'directory_unavailable', 'External resilience directory is unavailable.' );
		$lock_path = $path . '.lock';
		if ( is_link( $lock_path ) ) return self::error( 'symlink_denied', 'External resilience lock may not be a symlink.' );
		$lock = @fopen( $lock_path, 'c+' );
		if ( false === $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) { if ( is_resource( $lock ) ) fclose( $lock ); return self::error( 'locked', 'Another worker owns this site resilience fence.' ); }
		@chmod( $lock_path, 0600 );
		try {
			$current = self::read_path( $path, $binding );
			if ( is_wp_error( $current ) ) return $current;
			if ( ! is_int( $expected_revision ) || $expected_revision < 0 || $expected_revision !== (int) $current['revision'] ) return self::error( 'revision_conflict', 'External resilience revision changed; reread before proceeding.' );
			if ( ! is_callable( $transform ) ) return self::error( 'transition_invalid', 'Resilience transaction requires a pinned internal handler.' );
            $next = $transform( $current );
			if ( is_wp_error( $next ) ) return $next;
			if ( ! is_array( $next ) || ! isset( $next['scopes'] ) || ! is_array( $next['scopes'] ) ) return self::error( 'transition_invalid', 'Resilience transition did not return a bounded state document.' );
			// The transaction owns identity, revision and monotonic time, not its transform.
			foreach ( $current['scopes'] as $scope => $_ ) {
				if ( ! array_key_exists( $scope, $next['scopes'] )
                    || MAD4B_SCP_Resilience_Context::digest( $next['scopes'][ $scope ] )
                        !== MAD4B_SCP_Resilience_Context::digest( $_ ) )
                    return self::error( 'history_truncation', 'Existing resilience scope history is append-only and cannot be removed or rewritten.' );
			}
			// Ignore every caller-supplied top-level field except append-only scopes.
            // The anchor owns clock, identity, revision and all metadata.
            $next = array( 'scopes' => $next['scopes'] );
            $keys = array_keys( $next['scopes'] );
            foreach ( $keys as $scope_key ) {
                if ( ! is_string( $scope_key ) || 1 !== preg_match( '/^[a-z][a-z0-9.:-]{1,126}$/D', $scope_key ) )
                    return self::error( 'scope_invalid', 'Resilience scope key must be a bounded code-owned identifier.' );
            }
            $next['contract'] = self::CONTRACT;
			$next['site'] = self::site_identity( $binding );
			$next['revision'] = (int) $current['revision'] + 1;
			$next['clock_floor'] = max( (int) $current['clock_floor'], MAD4B_SCP_Resilience_Context::now() );
			unset( $next['anchor_sha256'] );
			$next['anchor_sha256'] = MAD4B_SCP_Resilience_Context::digest( $next );
			$json = json_encode( $next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $json ) || strlen( $json ) + 1 > self::MAX_BYTES ) return self::error( 'capacity', 'Resilience history is full; old fences must not be evicted automatically.' );
			$tmp = @tempnam( $dir, '.mad4b-resilience-' );
			if ( false === $tmp ) return self::error( 'write_failed', 'Could not stage the external resilience record.' );
			@chmod( $tmp, 0600 );
			$written = @file_put_contents( $tmp, $json . "\n", LOCK_EX );
			if ( false === $written || $written !== strlen( $json ) + 1 || ! @rename( $tmp, $path ) ) { @unlink( $tmp ); return self::error( 'write_failed', 'External resilience record could not be committed.' ); }
			@chmod( $path, 0600 );
			$readback = self::read_path( $path, $binding );
			if ( is_wp_error( $readback ) || ! hash_equals( $next['anchor_sha256'], (string) ( $readback['anchor_sha256'] ?? '' ) ) ) return self::error( 'readback_failed', 'External resilience commit is uncertain; reconciliation is required.' );
			if ( function_exists( 'update_option' ) && function_exists( 'get_option' ) ) {
				$mirror = array( 'site' => $next['site'], 'anchor_seen' => true );
				update_option( self::MIRROR_OPTION, $mirror, false );
				if ( get_option( self::MIRROR_OPTION, false ) !== $mirror ) return self::error( 'mirror_failed', 'External record committed but the database loss marker could not be confirmed.' );
			}
			return $readback;
		} finally { flock( $lock, LOCK_UN ); fclose( $lock ); }
	}

	private static function read_path( $path, array $binding ) {
		if ( is_link( $path ) ) return self::error( 'symlink_denied', 'External resilience record may not be a symlink.' );
		if ( ! is_file( $path ) ) {
			$seen = function_exists( 'get_option' ) ? get_option( self::MIRROR_OPTION, false ) : false;
			if ( false !== $seen ) return self::error( 'lost', 'Previously initialized external resilience state is missing; automatic recreation is denied.' );
			return array( 'contract'=>self::CONTRACT, 'site'=>self::site_identity( $binding ), 'revision'=>0, 'clock_floor'=>0, 'scopes'=>array(), 'anchor_sha256'=>'' );
		}
		if ( ! is_readable( $path ) || false === @filesize( $path ) || filesize( $path ) > self::MAX_BYTES ) return self::error( 'unreadable', 'External resilience record is unavailable or oversized.' );
		$raw = file_get_contents( $path );
		$record = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $record ) || self::CONTRACT !== ( $record['contract'] ?? '' ) || ! isset( $record['site'], $record['scopes'], $record['revision'], $record['clock_floor'] ) || ! is_array( $record['scopes'] ) || ! is_int( $record['revision'] ) || ! is_int( $record['clock_floor'] ) || $record['revision'] < 1 || $record['clock_floor'] < 1 ) return self::error( 'corrupt', 'External resilience record is malformed.' );
		$declared = (string) ( $record['anchor_sha256'] ?? '' );
		$core = $record; unset( $core['anchor_sha256'] );
		if ( ! MAD4B_SCP_Resilience_Context::is_hash( $declared ) || ! hash_equals( $declared, MAD4B_SCP_Resilience_Context::digest( $core ) ) ) return self::error( 'corrupt', 'External resilience digest is invalid.' );
		if ( ! hash_equals( MAD4B_SCP_Resilience_Context::digest( self::site_identity( $binding ) ), MAD4B_SCP_Resilience_Context::digest( $record['site'] ) ) ) return self::error( 'foreign_site', 'External resilience state belongs to another site, origin or environment.' );
		if ( MAD4B_SCP_Resilience_Context::now() < (int) $record['clock_floor'] ) return self::error( 'clock_rollback', 'Clock rollback cannot expire or replay a rollout or recovery fence.' );
		return $record;
	}

	private static function site_identity( array $binding ) { return array_intersect_key( $binding, array_flip( array( 'site_uuid', 'blog_id', 'environment', 'canonical_origin' ) ) ); }
	private static function path( array $binding ) {
		$valid = MAD4B_SCP_Resilience_Context::validate_binding( $binding ); if ( is_wp_error( $valid ) ) return $valid;
		if ( defined( 'MAD4B_SCP_RESILIENCE_ANCHOR_DIRECTORY' ) ) $dir = rtrim( (string) MAD4B_SCP_RESILIENCE_ANCHOR_DIRECTORY, '/\\' );
		elseif ( defined( 'MAD4B_SCP_RESTORE_EPOCH_PATH' ) ) $dir = dirname( (string) MAD4B_SCP_RESTORE_EPOCH_PATH );
		elseif ( class_exists( 'MAD4B_SCP_Local_OAuth_Key_Path_Policy' ) ) {
			$key = MAD4B_SCP_Local_OAuth_Key_Path_Policy::safe_default_path_for_roots( ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? '' );
			if ( is_wp_error( $key ) ) return self::error( 'path_unavailable', 'An external resilience directory must be configured.' );
			$dir = dirname( $key );
		} else return self::error( 'path_unavailable', 'An external resilience directory must be configured.' );
		if ( 1 !== preg_match( '#^(?:[A-Za-z]:[\\\\/]|/)#', $dir ) || preg_match( '#(?:^|[/\\\\])\\.\\.?(?:[/\\\\]|$)#', $dir ) || false !== strpos( $dir, "\0" ) ) return self::error( 'path_invalid', 'Resilience state requires a canonical absolute directory.' );
		$normal = rtrim( str_replace( '\\', '/', $dir ), '/' );
		foreach ( array( ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? '' ) as $root ) {
			$root = rtrim( str_replace( '\\', '/', (string) $root ), '/' );
			if ( '' !== $root && ( $normal === $root || 0 === strpos( $normal . '/', $root . '/' ) ) ) return self::error( 'path_inside_runtime', 'Resilience state must live outside WordPress and the HTTP document root.' );
		}
		$walk = $dir;
		while ( dirname( $walk ) !== $walk ) { if ( is_link( $walk ) ) return self::error( 'symlink_denied', 'Resilience directory ancestors may not be symlinks.' ); $walk = dirname( $walk ); }
		$path = $dir . '/resilience-' . $binding['site_uuid'] . '-' . $binding['blog_id'] . '-' . $binding['environment'] . '.json';
		if ( is_link( $path ) ) return self::error( 'symlink_denied', 'Resilience state may not be a symlink.' );
		return $path;
	}
	private static function error( $suffix, $message ) { return new WP_Error( 'mad4b_resilience_anchor_' . $suffix, $message, array( 'authorizing'=>false, 'reconciliation_required'=>true, 'blind_retry_allowed'=>false ) ); }
}
