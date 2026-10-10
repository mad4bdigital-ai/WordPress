<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Use the certified CSO01 catalog as the only source of read descriptors. */
final class MAD4B_SCP_CSO_Registry {
    public static function catalog( $query = '', $limit = 12, $offset = 0 ) {
        if ( ! class_exists( 'MAD4B_SCP_CSO01_Read_Foundation', false ) ||
            ! MAD4B_SCP_CSO01_Read_Foundation::can_read() ||
            ! is_string( $query ) || ! is_int( $limit ) || ! is_int( $offset ) ||
            $limit < 1 || $limit > 12 || $offset < 0 || $offset % 12 !== 0 || $offset > 180 )
            return MAD4B_SCP_CSO_Scope::error( 'CATALOG_UNAVAILABLE_OR_UNBOUNDED' );
        return MAD4B_SCP_CSO01_Read_Foundation::form_catalog(
            array( 'query' => $query, 'page' => (int) ( $offset / 12 ) ) );
    }
}
