<?php
// Canonical site-independent presentation API.
if ( ! function_exists( 'dfsb_value' ) ) {
    function dfsb_value( string $token, ?array $context = null ) {
        return \DynamicFilterSEOBridge\Bootstrap::instance()->presentationValue( $token, $context );
    }
}
if ( ! function_exists( 'dfsb_slot' ) ) {
    function dfsb_slot( string $slotId, ?array $context = null ): string {
        return \DynamicFilterSEOBridge\Bootstrap::instance()->presentationSlot( $slotId, $context );
    }
}
if ( ! function_exists( 'dfsb_gallery_ids' ) ) {
    function dfsb_gallery_ids( ?array $context = null ): array {
        $value = dfsb_value( 'gallery_ids', $context );
        if ( is_array( $value ) ) { return array_values( array_filter( array_map( 'absint', $value ) ) ); }
        if ( ! is_string( $value ) || '' === trim( $value ) ) { return array(); }
        return array_values( array_filter( array_map( 'absint', explode( ',', $value ) ) ) );
    }
}
if ( ! function_exists( 'dfsb_term_value' ) ) {
    function dfsb_term_value( string $role, string $field = 'name', ?array $context = null ) {
        return dfsb_value( 'term:' . sanitize_key( $role ) . ':' . sanitize_key( $field ), $context );
    }
}
if ( ! function_exists( 'dfsb_term_meta' ) ) {
    function dfsb_term_meta( string $role, string $metaKey, ?array $context = null ) {
        return dfsb_value( 'termmeta:' . sanitize_key( $role ) . ':' . sanitize_key( $metaKey ), $context );
    }
}

// Backwards-compatible delegates for already-saved Elementor templates and
// existing consumers. Do not use these prefixes in new implementations.
if ( ! function_exists( 'etg_dfsb_value' ) ) {
    function etg_dfsb_value( string $token, ?array $context = null ) { return dfsb_value( $token, $context ); }
}
if ( ! function_exists( 'etg_dfsb_slot' ) ) {
    function etg_dfsb_slot( string $slotId, ?array $context = null ): string { return dfsb_slot( $slotId, $context ); }
}
if ( ! function_exists( 'etg_dfsb_gallery_ids' ) ) {
    function etg_dfsb_gallery_ids( ?array $context = null ): array { return dfsb_gallery_ids( $context ); }
}
if ( ! function_exists( 'etg_dfsb_term_value' ) ) {
    function etg_dfsb_term_value( string $role, string $field = 'name', ?array $context = null ) { return dfsb_term_value( $role, $field, $context ); }
}
if ( ! function_exists( 'etg_dfsb_term_meta' ) ) {
    function etg_dfsb_term_meta( string $role, string $metaKey, ?array $context = null ) { return dfsb_term_meta( $role, $metaKey, $context ); }
}
