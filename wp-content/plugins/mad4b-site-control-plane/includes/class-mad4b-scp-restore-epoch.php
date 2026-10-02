<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * External monotonic restore/authority epoch.
 *
 * The external record lives outside WordPress and the HTTP document root. It
 * advances before provider entry, then binds the database to the new epoch.
 * Restoring an older database snapshot therefore leaves the DB binding behind
 * the external record and governed mutation fails closed.
 */
final class MAD4B_SCP_Restore_Epoch {
	const CONTRACT = 'mad4b.restore-authority-epoch.v1';
	const BINDING_CONTRACT = 'mad4b.restore-authority-epoch-binding.v1';
	const OPTION = 'mad4b_scp_restore_authority_epoch_binding_v1';
	private static $cache = null;

	public static function reset_request_cache() { self::$cache = null; return true; }

	public static function status( $initialize_if_empty = false, $refresh = false ) {
		if ( ! $refresh && is_array( self::$cache ) ) return self::$cache;
		$path = self::path();
		if ( is_wp_error( $path ) ) return self::blocked( array( $path->get_error_code() ), '', array(), array() );
		$external = self::read_external( $path );
		$binding = self::read_binding();
		if ( empty( $external ) && empty( $binding ) && $initialize_if_empty ) {
			$initialized = self::initialize( $path );
			if ( is_wp_error( $initialized ) ) return self::blocked( array( $initialized->get_error_code() ), $path, array(), array() );
			$external = self::read_external( $path );
			$binding = self::read_binding();
		}
		$blockers = array();
		if ( empty( $external ) ) $blockers[] = 'restore_epoch_external_missing';
		if ( empty( $binding ) ) $blockers[] = 'restore_epoch_database_binding_missing';
		if ( ! empty( $external ) && ! empty( $binding ) ) {
			if ( ! self::record_valid( $external ) ) $blockers[] = 'restore_epoch_external_invalid';
			if ( ! self::binding_valid( $binding ) ) $blockers[] = 'restore_epoch_database_binding_invalid';
			if ( empty( $blockers ) ) {
				foreach ( array( 'site_uuid', 'epoch', 'nonce_sha256', 'external_record_sha256' ) as $field ) {
					$a = isset( $external[ $field ] ) ? (string) $external[ $field ] : '';
					$b = isset( $binding[ $field ] ) ? (string) $binding[ $field ] : '';
					if ( ! hash_equals( $a, $b ) ) { $blockers[] = 'restore_epoch_database_snapshot_detected'; break; }
				}
			}
		}
		$status = array(
			'contract' => self::CONTRACT,
			'path_configured' => '' !== $path,
			'path_sha256' => '' !== $path ? hash( 'sha256', $path ) : '',
			'outside_wordpress_root' => '' !== $path && ! self::path_within( $path, ABSPATH ),
			'external_record_present' => ! empty( $external ),
			'database_binding_present' => ! empty( $binding ),
			'site_uuid' => ! empty( $external['site_uuid'] ) ? (string) $external['site_uuid'] : '',
			'epoch' => ! empty( $external['epoch'] ) ? (int) $external['epoch'] : 0,
			'external_record_sha256' => ! empty( $external['external_record_sha256'] ) ? (string) $external['external_record_sha256'] : '',
			'blockers' => array_values( array_unique( $blockers ) ),
			'ready' => empty( $blockers ),
			'quarantined' => ! empty( $blockers ),
			'recovery_requires_write_disabled' => true,
			'authorizing' => false,
			'mutation_performed' => false,
		);
		self::$cache = $status;
		return $status;
	}

	public static function ensure_bound() {
		$status = self::status( true, true );
		if ( ! is_array( $status ) || empty( $status['ready'] ) ) {
			return new WP_Error(
				'mad4b_restore_epoch_not_ready',
				'Restore/authority epoch is not ready; governed mutation is quarantined.',
				array(
					'blockers' => is_array( $status ) && isset( $status['blockers'] ) ? $status['blockers'] : array( 'restore_epoch_unavailable' ),
					'reenrollment_required' => true,
					'blind_retry_allowed' => false,
					'authorizing' => false,
				)
			);
		}
		return $status;
	}

	public static function material() {
		$status = self::ensure_bound();
		if ( is_wp_error( $status ) ) return $status;
		return array(
			'contract' => self::CONTRACT,
			'site_uuid' => (string) $status['site_uuid'],
			'epoch' => (int) $status['epoch'],
			'external_record_sha256' => (string) $status['external_record_sha256'],
		);
	}

	public static function advance( $reason = 'governed_execution' ) {
		$current = self::ensure_bound();
		if ( is_wp_error( $current ) ) return $current;
		$path = self::path();
		if ( is_wp_error( $path ) ) return $path;
		$external = self::read_external( $path );
		if ( ! self::record_valid( $external ) ) return new WP_Error( 'mad4b_restore_epoch_external_invalid', 'Restore epoch external record is invalid.' );
		$next = self::make_record(
			(string) $external['site_uuid'],
			(int) $external['epoch'] + 1,
			(string) $external['external_record_sha256'],
			sanitize_key( (string) $reason )
		);
		if ( is_wp_error( $next ) ) return $next;
		$write = self::write_external( $path, $next );
		if ( is_wp_error( $write ) ) return $write;
		$bound = self::write_binding( $next );
		self::$cache = null;
		if ( is_wp_error( $bound ) ) {
			return new WP_Error(
				'mad4b_restore_epoch_database_binding_failed',
				'External restore epoch advanced but the database binding did not; governed mutation remains quarantined.',
				array( 'reconciliation_required' => true, 'blind_retry_allowed' => false, 'authorizing' => false )
			);
		}
		return self::material();
	}

	public static function acknowledge_restore_after_quarantine( $expected_external_record_sha256 ) {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'mad4b_restore_epoch_admin_required', 'Administrator capability is required to acknowledge a database restore.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::origin_enrolled() ) {
			return new WP_Error( 'mad4b_restore_epoch_reenrollment_required', 'Exact Site Profile enrollment is required before restore acknowledgement.' );
		}
		if ( MAD4B_SCP_Site_Profile::write_enabled() ) {
			return new WP_Error( 'mad4b_restore_epoch_write_must_be_disabled', 'Disable governed write authority before acknowledging a restored database.' );
		}
		$path = self::path();
		if ( is_wp_error( $path ) ) return $path;
		$external = self::read_external( $path );
		if ( ! self::record_valid( $external ) ) return new WP_Error( 'mad4b_restore_epoch_external_invalid', 'Restore epoch external record is invalid.' );
		$expected = strtolower( trim( (string) $expected_external_record_sha256 ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $expected, (string) $external['external_record_sha256'] ) ) {
			return new WP_Error( 'mad4b_restore_epoch_acknowledgement_stale', 'Restore acknowledgement does not match the current external epoch.' );
		}
		$bound = self::write_binding( $external );
		self::$cache = null;
		if ( is_wp_error( $bound ) ) return $bound;
		return self::status( false, true );
	}

	public static function external_path_for_test() { return self::path(); }

	private static function initialize( $path ) {
		$site_uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $site_uuid ) ) return new WP_Error( 'mad4b_restore_epoch_site_identity_unavailable', 'Exact enrolled Site UUID is required to initialize restore epoch.' );
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) && ! MAD4B_SCP_Site_Profile::origin_enrolled() ) return new WP_Error( 'mad4b_restore_epoch_site_not_enrolled', 'Exact enrolled origin is required to initialize restore epoch.' );
		$record = self::make_record( $site_uuid, 1, '', 'initialize' );
		if ( is_wp_error( $record ) ) return $record;
		$write = self::write_external( $path, $record );
		if ( is_wp_error( $write ) ) return $write;
		return self::write_binding( $record );
	}

	private static function make_record( $site_uuid, $epoch, $previous_sha, $reason ) {
		try { $nonce = bin2hex( random_bytes( 32 ) ); }
		catch ( Throwable $error ) { return new WP_Error( 'mad4b_restore_epoch_nonce_unavailable', 'Restore epoch nonce could not be generated.' ); }
		$record = array(
			'contract' => self::CONTRACT,
			'site_uuid' => strtolower( (string) $site_uuid ),
			'epoch' => max( 1, (int) $epoch ),
			'nonce_sha256' => hash( 'sha256', $nonce ),
			'previous_external_record_sha256' => preg_match( '/^[a-f0-9]{64}$/', (string) $previous_sha ) ? strtolower( (string) $previous_sha ) : '',
			'reason' => sanitize_key( (string) $reason ),
			'updated_at' => gmdate( 'c' ),
		);
		$record['external_record_sha256'] = self::record_digest( $record );
		return $record;
	}

	private static function record_digest( array $record ) {
		$copy = $record;
		unset( $copy['external_record_sha256'] );
		ksort( $copy, SORT_STRING );
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}

	private static function record_valid( $record ) {
		return is_array( $record )
			&& self::CONTRACT === ( isset( $record['contract'] ) ? (string) $record['contract'] : '' )
			&& preg_match( '/^[a-f0-9-]{36}$/', isset( $record['site_uuid'] ) ? (string) $record['site_uuid'] : '' )
			&& ! empty( $record['epoch'] )
			&& preg_match( '/^[a-f0-9]{64}$/', isset( $record['nonce_sha256'] ) ? (string) $record['nonce_sha256'] : '' )
			&& preg_match( '/^[a-f0-9]{64}$/', isset( $record['external_record_sha256'] ) ? (string) $record['external_record_sha256'] : '' )
			&& hash_equals( (string) $record['external_record_sha256'], self::record_digest( $record ) );
	}

	private static function binding_valid( $binding ) {
		return is_array( $binding )
			&& self::BINDING_CONTRACT === ( isset( $binding['contract'] ) ? (string) $binding['contract'] : '' )
			&& preg_match( '/^[a-f0-9-]{36}$/', isset( $binding['site_uuid'] ) ? (string) $binding['site_uuid'] : '' )
			&& ! empty( $binding['epoch'] )
			&& preg_match( '/^[a-f0-9]{64}$/', isset( $binding['nonce_sha256'] ) ? (string) $binding['nonce_sha256'] : '' )
			&& preg_match( '/^[a-f0-9]{64}$/', isset( $binding['external_record_sha256'] ) ? (string) $binding['external_record_sha256'] : '' );
	}

	private static function read_binding() {
		$value = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return is_array( $value ) ? $value : array();
	}

	private static function write_binding( array $record ) {
		$binding = array(
			'contract' => self::BINDING_CONTRACT,
			'site_uuid' => (string) $record['site_uuid'],
			'epoch' => (int) $record['epoch'],
			'nonce_sha256' => (string) $record['nonce_sha256'],
			'external_record_sha256' => (string) $record['external_record_sha256'],
			'bound_at' => gmdate( 'c' ),
		);
		$ok = function_exists( 'update_option' ) ? update_option( self::OPTION, $binding, false ) : false;
		$readback = self::read_binding();
		if ( false === $ok && empty( $readback ) ) return new WP_Error( 'mad4b_restore_epoch_database_binding_failed', 'Restore epoch database binding could not be persisted.' );
		foreach ( array( 'site_uuid', 'epoch', 'nonce_sha256', 'external_record_sha256' ) as $field ) {
			if ( (string) $binding[ $field ] !== (string) ( isset( $readback[ $field ] ) ? $readback[ $field ] : '' ) ) {
				return new WP_Error( 'mad4b_restore_epoch_database_binding_readback_failed', 'Restore epoch database binding readback mismatch.' );
			}
		}
		return true;
	}

	private static function read_external( $path ) {
		if ( '' === (string) $path || ! is_file( $path ) || ! is_readable( $path ) ) return array();
		$raw = file_get_contents( $path );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function write_external( $path, array $record ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) return new WP_Error( 'mad4b_restore_epoch_directory_unavailable', 'Restore epoch directory could not be created.' );
		@chmod( $dir, 0700 );
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) return new WP_Error( 'mad4b_restore_epoch_encode_failed', 'Restore epoch record could not be encoded.' );
		$tmp = $path . '.tmp.' . ( function_exists( 'getmypid' ) ? (int) getmypid() : 0 );
		if ( false === @file_put_contents( $tmp, $json . "\n", LOCK_EX ) ) return new WP_Error( 'mad4b_restore_epoch_write_failed', 'Restore epoch external record could not be written.' );
		@chmod( $tmp, 0600 );
		if ( ! @rename( $tmp, $path ) ) { @unlink( $tmp ); return new WP_Error( 'mad4b_restore_epoch_rename_failed', 'Restore epoch external record could not be atomically installed.' ); }
		@chmod( $path, 0600 );
		$readback = self::read_external( $path );
		if ( ! self::record_valid( $readback ) || ! hash_equals( (string) $record['external_record_sha256'], (string) $readback['external_record_sha256'] ) ) {
			return new WP_Error( 'mad4b_restore_epoch_external_readback_failed', 'Restore epoch external readback mismatch.' );
		}
		return true;
	}

	private static function path() {
		if ( defined( 'MAD4B_SCP_RESTORE_EPOCH_PATH' ) ) {
			$path = trim( (string) constant( 'MAD4B_SCP_RESTORE_EPOCH_PATH' ) );
		} else {
			if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Key_Path_Policy' ) ) return new WP_Error( 'mad4b_restore_epoch_path_policy_unavailable', 'Safe external path policy is unavailable.' );
			$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? trim( (string) $_SERVER['DOCUMENT_ROOT'] ) : '';
			$key_path = MAD4B_SCP_Local_OAuth_Key_Path_Policy::safe_default_path_for_roots( ABSPATH, $document_root );
			if ( is_wp_error( $key_path ) ) return new WP_Error( 'mad4b_restore_epoch_path_unavailable', 'Safe restore epoch path could not be derived.' );
			$path = dirname( $key_path ) . '/restore-authority-epoch.json';
		}
		if ( ! self::absolute_path( $path ) ) return new WP_Error( 'mad4b_restore_epoch_path_invalid', 'Restore epoch path must be absolute.' );
		if ( self::path_within( $path, ABSPATH ) ) return new WP_Error( 'mad4b_restore_epoch_path_inside_wordpress', 'Restore epoch must live outside the WordPress root.' );
		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? trim( (string) $_SERVER['DOCUMENT_ROOT'] ) : '';
		if ( '' !== $document_root && self::path_within( $path, $document_root ) ) return new WP_Error( 'mad4b_restore_epoch_path_inside_document_root', 'Restore epoch must live outside the HTTP document root.' );
		return $path;
	}

	private static function blocked( array $blockers, $path, array $external, array $binding ) {
		return array(
			'contract' => self::CONTRACT,
			'path_configured' => '' !== (string) $path,
			'external_record_present' => ! empty( $external ),
			'database_binding_present' => ! empty( $binding ),
			'blockers' => array_values( array_unique( $blockers ) ),
			'ready' => false,
			'quarantined' => true,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	private static function absolute_path( $path ) { return 1 === preg_match( '#^(?:[A-Za-z]:[\\\\/]|/)#', (string) $path ); }
	private static function path_within( $path, $root ) {
		$path = str_replace( '\\', '/', (string) $path );
		$root = rtrim( str_replace( '\\', '/', (string) $root ), '/' ) . '/';
		if ( preg_match( '#^[A-Za-z]:/#', $path ) ) { $path = strtolower( $path ); $root = strtolower( $root ); }
		return 0 === strpos( $path, $root );
	}
}
