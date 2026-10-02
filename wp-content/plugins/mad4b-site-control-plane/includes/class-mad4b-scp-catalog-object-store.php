<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Private durable objects. Atomic directory publication; immutable payloads are never autoloaded. */
final class MAD4B_SCP_Catalog_Object_Store {
	const DIRECTORY = 'mad4b_scp_catalog_objects_v2';
	private $pending = array();
	private $metrics = array( 'reads' => 0, 'writes' => 0, 'bytes_read' => 0 );
	public function get( $key ) {
		if ( isset( $this->pending[ $key ] ) ) return $this->pending[ $key ]['value'];
		$directory = get_option( self::DIRECTORY, array() );
		if ( ! isset( $directory[ $key ] ) || $directory[ $key ]['expires'] <= time() ) return false;
		$value = get_option( $directory[ $key ]['option'], false );
		++$this->metrics['reads']; $this->metrics['bytes_read'] += strlen( serialize( $value ) );
		return $value;
	}
	public function put( $key, $value, $ttl ) {
		$directory = get_option( self::DIRECTORY, array() );
		if ( isset( $directory[ $key ] ) && $directory[ $key ]['expires'] > time() + (int) ( $ttl / 2 ) && $this->get( $key ) === $value ) return;
		$this->pending[ $key ] = array( 'value' => $value, 'expires' => time() + $ttl );
	}
	public function expires( $key ) { $d = get_option( self::DIRECTORY, array() ); return $d[ $key ]['expires'] ?? 0; }
	public function metrics() { return $this->metrics; }
	private function directory() {
		wp_cache_delete( self::DIRECTORY, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return get_option( self::DIRECTORY, false );
	}
	public function flush() {
		global $wpdb;
		$drafts = array(); $published = false;
		$deadline = microtime( true ) + 30;
		try {
			foreach ( $this->pending as $key => $entry ) {
				$option = 'mad4b_ct2_' . time() . '_' . bin2hex( random_bytes( 16 ) );
				if ( ! add_option( $option, $entry['value'], '', false ) ) throw new RuntimeException( 'catalog_storage_write_failed' );
				$drafts[ $key ] = array( 'option' => $option, 'expires' => $entry['expires'], 'bytes' => strlen( serialize( $entry['value'] ) ) );
				++$this->metrics['writes'];
			}
			for ( $attempt = 0; $attempt < 6 && microtime( true ) < $deadline; ++$attempt ) {
				$old = $this->directory(); $next = is_array( $old ) ? $old : array(); $garbage = array();
				foreach ( $next as $key => $entry ) if ( $entry['expires'] <= time() ) { $garbage[] = $entry['option']; unset( $next[ $key ] ); }
				foreach ( $drafts as $key => $entry ) {
					if ( isset( $next[ $key ] ) ) $garbage[] = $next[ $key ]['option'];
					$next[ $key ] = $entry;
				}
				$bytes = array_sum( array_column( $next, 'bytes' ) ) + strlen( serialize( $next ) );
				if ( $next === $old ) { $published = true; return; }
				$capacity = max( 1048576, (int) apply_filters( 'mad4b_scp_catalog_storage_capacity_bytes', 134217728 ) );
				if ( $bytes > $capacity ) throw new RuntimeException( 'catalog_storage_capacity_exhausted' );
				if ( false === $old ) $ok = add_option( self::DIRECTORY, $next, '', false );
				else $ok = 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", maybe_serialize( $next ), self::DIRECTORY, maybe_serialize( $old ) ) );
				if ( ! $ok ) continue;
				$published = true; wp_cache_delete( self::DIRECTORY, 'options' ); wp_cache_delete( 'notoptions', 'options' );
				$this->pending = array();
				foreach ( array_unique( $garbage ) as $option ) if ( ! in_array( $option, array_column( $next, 'option' ), true ) ) delete_option( $option );
				return;
			}
			throw new RuntimeException( 'catalog_storage_publication_conflict' );
		} finally {
			if ( ! $published ) foreach ( $drafts as $entry ) delete_option( $entry['option'] );
		}
	}
	public static function collect_expired() {
		$store = new self(); $store->flush();
		global $wpdb;
		// Crash drafts cannot be active after the publication deadline. Never remove live objects.
		if ( ! method_exists( $wpdb, 'get_col' ) ) return;
		$live = array_column( get_option( self::DIRECTORY, array() ), 'option' );
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500", $wpdb->esc_like( 'mad4b_ct2_' ) . '%' ) );
		foreach ( $names as $name ) if ( preg_match( '/^mad4b_ct2_([0-9]+)_/', $name, $m ) && (int) $m[1] < time() - 3600 && ! in_array( $name, $live, true ) ) delete_option( $name );
	}
}
