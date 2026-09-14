<?php
if ( ! function_exists( 'etg_dfsb_value' ) ) {
    function etg_dfsb_value( string $token, array $context = null ) {
        return \ETG\DynamicFilterSEOBridge\Bootstrap::instance()->presentationValue( $token, $context );
    }
}
if ( ! function_exists( 'etg_dfsb_slot' ) ) {
    function etg_dfsb_slot( string $slotId, array $context = null ): string {
        return \ETG\DynamicFilterSEOBridge\Bootstrap::instance()->presentationSlot( $slotId, $context );
    }
}
if ( ! function_exists( 'etg_dfsb_gallery_ids' ) ) {
    function etg_dfsb_gallery_ids( array $context = null ): array {
        $value = \ETG\DynamicFilterSEOBridge\Bootstrap::instance()->presentationValue( 'gallery_ids', $context );
        if ( is_array( $value ) ) { return array_values( array_filter( array_map( 'absint', $value ) ) ); }
        if ( ! is_string( $value ) || '' === trim( $value ) ) { return array(); }
        return array_values( array_filter( array_map( 'absint', explode( ',', $value ) ) ) );
    }
}
if ( ! function_exists( 'etg_dfsb_term_value' ) ) {
    function etg_dfsb_term_value( string $role, string $field = 'name', array $context = null ) {
        return etg_dfsb_value( 'term:' . sanitize_key( $role ) . ':' . sanitize_key( $field ), $context );
    }
}
if ( ! function_exists( 'etg_dfsb_term_meta' ) ) {
    function etg_dfsb_term_meta( string $role, string $metaKey, array $context = null ) {
        return etg_dfsb_value( 'termmeta:' . sanitize_key( $role ) . ':' . sanitize_key( $metaKey ), $context );
    }
}
