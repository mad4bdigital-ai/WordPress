<?php
namespace ETG\DynamicFilterSEOBridge\CLI;

use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Diagnostics\InventoryReconciler;
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;

final class Commands {
	const MAX_PREVIOUS_BYTES = 2097152;
	private $inventory;
	private $reconciler;
	private $profiles;

	public function __construct( RuntimeInventory $inventory, InventoryReconciler $reconciler, ProfileRegistry $profiles ) {
		$this->inventory = $inventory;
		$this->reconciler = $reconciler;
		$this->profiles = $profiles;
	}

	public function register(): void {
		\WP_CLI::add_command( 'etg-dfsb inventory', array( $this, 'inventoryCommand' ) );
		\WP_CLI::add_command( 'etg-dfsb reconcile', array( $this, 'reconcileCommand' ) );
	}

	/**
	 * Export the bounded non-authorizing runtime inventory to stdout.
	 */
	public function inventoryCommand( array $args, array $assocArgs ): void {
		unset( $args, $assocArgs );
		\WP_CLI::line( $this->encode( $this->inventory->collect() ) );
	}

	/**
	 * Reconcile current runtime inventory with configured profiles.
	 *
	 * ## OPTIONS
	 *
	 * [--previous=<file>]
	 * : Optional prior runtime inventory JSON. Limited to 2 MiB and used only for structural drift comparison.
	 */
	public function reconcileCommand( array $args, array $assocArgs ): void {
		unset( $args );
		$previous = array();
		if ( ! empty( $assocArgs['previous'] ) ) {
			$previous = $this->readPrevious( (string) $assocArgs['previous'] );
		}
		$current = $this->inventory->collect();
		$result = $this->reconciler->analyze( $current, $this->profiles->all(), $previous );
		\WP_CLI::line( $this->encode( $result ) );
		if ( 'blocked_drift' === (string) ( $result['state'] ?? '' ) ) {
			\WP_CLI::warning( 'Inventory reconciliation found blocking drift. No profile mutation was performed.' );
		}
	}

	private function readPrevious( string $path ): array {
		$path = trim( $path );
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			\WP_CLI::error( 'Previous inventory file is not readable.' );
		}
		$size = filesize( $path );
		if ( false === $size || $size > self::MAX_PREVIOUS_BYTES ) {
			\WP_CLI::error( 'Previous inventory file exceeds the 2 MiB safety limit.' );
		}
		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) ) { \WP_CLI::error( 'Previous inventory file could not be read.' ); }
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) { \WP_CLI::error( 'Previous inventory file is not valid JSON.' ); }
		return $decoded;
	}

	private function encode( array $payload ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '{}';
	}
}
