<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Non-destructive scheduling continuity; no authority/configuration deletion. */
final class MAD4B_SCP_Catalog_Lifecycle {
	public static function deactivate( $network_wide = false ) {
		if ( ! $network_wide || ! is_multisite() ) {
			wp_clear_scheduled_hook( 'mad4b_catalog_gc' );
			return;
		}
		// Per-site cron lives in each site's options table. Restore the caller's
		// blog even if a callback fails; never load an Ability registry here.
		$offset = 0;
		do {
			$ids = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC' ) );
			foreach ( $ids as $id ) {
				switch_to_blog( (int) $id );
				try { wp_clear_scheduled_hook( 'mad4b_catalog_gc' ); }
				finally { restore_current_blog(); }
			}
			$offset += count( $ids );
		} while ( 100 === count( $ids ) );
	}
}
