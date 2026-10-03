<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Private immutable payloads with CAS publication and deferred reclamation. */
final class MAD4B_SCP_Catalog_Object_Store {
	const DIRECTORY = 'mad4b_scp_catalog_objects_v2';
	const GC_CURSOR = 'mad4b_scp_catalog_gc_cursor_v2';
	const READER_GRACE_SECONDS = 3600;
	private $pending = array();
	private $metrics = array( 'reads' => 0, 'writes' => 0, 'bytes_read' => 0 );
	private $scope = '';
	private $table_backend = null;

	public function __construct( $storage_scope_sha256 = '' ) {
		$scope = strtolower( trim( (string) $storage_scope_sha256 ) );
		if ( '' === $scope && class_exists( 'MAD4B_SCP_Catalog_Backend_Controller' ) ) $scope = MAD4B_SCP_Catalog_Backend_Controller::storage_scope();
		$this->scope = 1 === preg_match( '/^[a-f0-9]{64}$/D', $scope ) ? $scope : hash( 'sha256', 'mad4b.catalog-table.default-scope.v1' );
	}

	private function authority_backend() {
		if ( ! class_exists( 'MAD4B_SCP_Catalog_Backend_Controller' ) ) return 'options';
		return MAD4B_SCP_Catalog_Backend_Controller::authority_backend( $this->scope );
	}
	private function table_backend() {
		if ( null === $this->table_backend ) $this->table_backend = new MAD4B_SCP_Catalog_Table_Backend( $this->scope );
		return $this->table_backend;
	}

	public function get( $key ) {
		$backend = $this->authority_backend();
		if ( is_wp_error( $backend ) ) throw new RuntimeException( $backend->get_error_code() );
		if ( 'table' === $backend ) return $this->table_backend()->get( $key );
		if ( isset( $this->pending[ $key ] ) ) return $this->pending[ $key ]['value'];
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$directory = 0 === $attempt ? get_option( self::DIRECTORY, array() ) : $this->directory();
			if ( ! isset( $directory[ $key ] ) || $directory[ $key ]['expires'] <= time() ) return false;
			$value = get_option( $directory[ $key ]['option'], false );
			++$this->metrics['reads'];
			$this->metrics['bytes_read'] += strlen( serialize( $value ) );
			if ( false !== $value ) return $value;
			// Recover a stale object-cache directory after deferred reclamation.
			wp_cache_delete( $directory[ $key ]['option'], 'options' );
		}
		return false;
	}

	public function put( $key, $value, $ttl, $minimum_expires = 0 ) {
		$backend = $this->authority_backend();
		if ( is_wp_error( $backend ) ) throw new RuntimeException( $backend->get_error_code() );
		if ( 'table' === $backend ) { $this->table_backend()->put( $key, $value, $ttl, $minimum_expires ); return; }
		$ttl = max( 1, (int) $ttl );
		$minimum_expires = max( 0, (int) $minimum_expires );
		$expires = max( time() + $ttl, $minimum_expires );
		if ( isset( $this->pending[ $key ] ) ) {
			if ( $this->pending[ $key ]['value'] === $value ) {
				$this->pending[ $key ]['expires'] = max( $this->pending[ $key ]['expires'], $expires );
				return;
			}
			$this->pending[ $key ] = array( 'value' => $value, 'expires' => $expires );
			return;
		}
		$directory = get_option( self::DIRECTORY, array() );
		$reuse_floor = max( time() + (int) floor( $ttl / 2 ), $minimum_expires );
		if ( isset( $directory[ $key ] ) && $directory[ $key ]['expires'] >= $reuse_floor && $this->get( $key ) === $value ) return;
		$this->pending[ $key ] = array( 'value' => $value, 'expires' => $expires );
	}

	public function expires( $key ) {
		$backend = $this->authority_backend();
		if ( is_wp_error( $backend ) ) throw new RuntimeException( $backend->get_error_code() );
		if ( 'table' === $backend ) return $this->table_backend()->expires( $key );
		if ( isset( $this->pending[ $key ] ) ) return $this->pending[ $key ]['expires'];
		$d = get_option( self::DIRECTORY, array() );
		return $d[ $key ]['expires'] ?? 0;
	}
	public function metrics() {
		$backend = $this->authority_backend();
		return 'table' === $backend ? $this->table_backend()->metrics() : $this->metrics;
	}
	private function directory() {
		wp_cache_delete( self::DIRECTORY, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return get_option( self::DIRECTORY, false );
	}
	private static function capacity() {
		return max( 1048576, (int) apply_filters( 'mad4b_scp_catalog_storage_capacity_bytes', 134217728 ) );
	}
	private static function physical_bytes( array $directory ) {
		global $wpdb;
		if ( method_exists( $wpdb, 'get_var' ) ) {
			$value = $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(OCTET_LENGTH(option_value)),0) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'mad4b_ct2_' ) . '%'
			) );
			if ( null === $value || ! is_numeric( $value ) ) throw new RuntimeException( 'catalog_storage_measurement_failed' );
			return (int) $value;
		}
		return array_sum( array_column( $directory, 'bytes' ) );
	}
	private static function retire( array &$directory, array $entry ) {
		// A reader may already hold the previous directory. Keep the immutable
		// payload addressable beyond the publication deadline and normal request.
		$entry['expires'] = time() + self::READER_GRACE_SECONDS;
		$directory[ 'retired:' . $entry['option'] ] = $entry;
	}

	public function flush() {
		$backend = $this->authority_backend();
		if ( is_wp_error( $backend ) ) throw new RuntimeException( $backend->get_error_code() );
		if ( 'table' === $backend ) {
			$result = $this->table_backend()->flush();
			if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_code() );
			return;
		}
		global $wpdb;
		$drafts = array(); $created = array(); $published = false;
		$deadline = microtime( true ) + 30;
		try {
			$initial = $this->directory();
			$initial = is_array( $initial ) ? $initial : array();
			if ( empty( $this->pending ) ) {
				$expired = false;
				foreach ( $initial as $entry ) if ( $entry['expires'] <= time() ) { $expired = true; break; }
				if ( ! $expired ) { $published = true; return; }
			}
			$physical = self::physical_bytes( $initial );
			foreach ( $this->pending as $key => $entry ) {
				if ( microtime( true ) >= $deadline ) throw new RuntimeException( 'catalog_storage_publication_timeout' );
				// Extend a lease without copying unchanged schema/block bytes.
				if ( isset( $initial[ $key ] ) && $initial[ $key ]['expires'] > time() && get_option( $initial[ $key ]['option'], false ) === $entry['value'] ) {
					$drafts[ $key ] = $initial[ $key ];
					$drafts[ $key ]['expires'] = max( $initial[ $key ]['expires'], $entry['expires'] );
					continue;
				}
				$bytes = strlen( serialize( $entry['value'] ) );
				if ( $physical + $bytes + strlen( serialize( $initial ) ) > self::capacity() ) throw new RuntimeException( 'catalog_storage_capacity_exhausted' );
				$option = 'mad4b_ct2_' . time() . '_' . bin2hex( random_bytes( 16 ) );
				if ( ! add_option( $option, $entry['value'], '', false ) ) throw new RuntimeException( 'catalog_storage_write_failed' );
				$created[] = $option;
				$physical += $bytes;
				$drafts[ $key ] = array( 'option' => $option, 'expires' => $entry['expires'], 'bytes' => $bytes );
				++$this->metrics['writes'];
			}
			for ( $attempt = 0; $attempt < 6 && microtime( true ) < $deadline; ++$attempt ) {
				$old = $this->directory();
				$next = is_array( $old ) ? $old : array(); $garbage = array();
				foreach ( $next as $key => $entry ) {
					if ( $entry['expires'] > time() ) continue;
					unset( $next[ $key ] );
					if ( 0 === strpos( $key, 'retired:' ) ) $garbage[] = $entry['option'];
					else self::retire( $next, $entry );
				}
				foreach ( $drafts as $key => $entry ) {
					if ( isset( $next[ $key ] ) ) {
						if ( $next[ $key ]['option'] !== $entry['option'] ) self::retire( $next, $next[ $key ] );
						else $entry['expires'] = max( $entry['expires'], $next[ $key ]['expires'] );
					}
					$next[ $key ] = $entry;
				}
				if ( $next === $old ) { $published = true; $this->pending = array(); return; }
				$bytes = max( array_sum( array_column( $next, 'bytes' ) ), self::physical_bytes( $next ) ) + strlen( serialize( $next ) );
				if ( $bytes > self::capacity() && ! empty( $drafts ) ) throw new RuntimeException( 'catalog_storage_capacity_exhausted' );
				if ( false === $old ) $ok = add_option( self::DIRECTORY, $next, '', false );
				else $ok = 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", maybe_serialize( $next ), self::DIRECTORY, maybe_serialize( $old ) ) );
				if ( ! $ok ) continue;
				$published = true;
				wp_cache_delete( self::DIRECTORY, 'options' ); wp_cache_delete( 'notoptions', 'options' );
				if ( class_exists( 'MAD4B_SCP_Catalog_Backend_Controller' ) ) MAD4B_SCP_Catalog_Backend_Controller::shadow_options_directory( $this->scope, $next );
				$this->pending = array();
				foreach ( array_unique( $garbage ) as $option ) if ( ! in_array( $option, array_column( $next, 'option' ), true ) ) delete_option( $option );
				return;
			}
			throw new RuntimeException( 'catalog_storage_publication_conflict' );
		} finally {
			if ( ! $published ) foreach ( $created as $option ) delete_option( $option );
		}
	}

	/** Directory evidence only; orphan bytes remain enforced by physical SQL. */
	public static function status() {
		$directory = get_option( self::DIRECTORY, array() );
		$directory = is_array( $directory ) ? $directory : array();
		return array(
			'indexed_objects' => count( $directory ),
			'indexed_bytes' => array_sum( array_column( $directory, 'bytes' ) ),
			'physical_bytes_measured' => false,
			'capacity_bytes' => self::capacity(),
			'next_gc' => function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( 'mad4b_catalog_gc' ) : false,
			'retention_policy' => 'preserve_on_deactivation_and_uninstall',
			'authority_effect' => 'none',
			'backend_controller' => class_exists( 'MAD4B_SCP_Catalog_Backend_Controller' ) ? MAD4B_SCP_Catalog_Backend_Controller::status() : array(),
		);
	}

	public static function collect_expired() {
		$store = new self(); $store->flush();
		if ( class_exists( 'MAD4B_SCP_Catalog_Table_Backend' ) ) MAD4B_SCP_Catalog_Table_Backend::collect_expired();
		global $wpdb;
		if ( ! method_exists( $wpdb, 'get_col' ) ) return;
		$cursor = (string) get_option( self::GC_CURSOR, '' );
		for ( $page = 0; $page < 20; ++$page ) {
			$names = $wpdb->get_col( $wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name LIMIT 500",
				$wpdb->esc_like( 'mad4b_ct2_' ) . '%', $cursor
			) );
			if ( ! is_array( $names ) ) throw new RuntimeException( 'catalog_gc_scan_failed' );
			// Reload after each page: concurrent publication may have added objects.
			$directory = $store->directory();
			$live = array_flip( array_column( is_array( $directory ) ? $directory : array(), 'option' ) );
			foreach ( $names as $name ) {
				if ( preg_match( '/^mad4b_ct2_([0-9]+)_/', $name, $m ) && (int) $m[1] < time() - self::READER_GRACE_SECONDS && ! isset( $live[ $name ] ) ) delete_option( $name );
			}
			$cursor = count( $names ) < 500 ? '' : (string) end( $names );
			update_option( self::GC_CURSOR, $cursor, false );
			if ( '' === $cursor ) break;
		}
	}
}
