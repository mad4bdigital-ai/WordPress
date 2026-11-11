<?php
/**
 * Plugin Name: TripKlik WPML Cruise Runs Sync
 * Description: Safely syncs the cruise_runs repeater from the default-language post to translations and maps selected taxonomy IDs through WPML.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TK_WPML_CRUISE_RUNS_SYNC_ENABLED' ) || ! TK_WPML_CRUISE_RUNS_SYNC_ENABLED ) {
    return;
}

if ( ! function_exists( 'tk_wpml_cruise_runs_sync_post_types' ) ) {
    function tk_wpml_cruise_runs_sync_post_types(): array {
        $raw = defined( 'TK_WPML_CRUISE_RUNS_SYNC_POST_TYPES' ) ? (string) TK_WPML_CRUISE_RUNS_SYNC_POST_TYPES : 'tours-and-activities';
        $post_types = array_values( array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $raw ) ) ) ) );
        return apply_filters( 'tk_wpml_cruise_runs_sync_post_types', $post_types );
    }
}

if ( ! function_exists( 'tk_wpml_cruise_runs_sync_taxonomy_map' ) ) {
    function tk_wpml_cruise_runs_sync_taxonomy_map(): array {
        $map = [
            'day'     => 'schudle-days-of-weak',
            'origin'  => 'destinations',
            'program' => 'programs',
            'time'    => 'day-times',
        ];
        return apply_filters( 'tk_wpml_cruise_runs_sync_taxonomy_map', $map );
    }
}

if ( ! function_exists( 'tk_wpml_cruise_runs_sync_has_wpml' ) ) {
    function tk_wpml_cruise_runs_sync_has_wpml(): bool {
        return defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_object_id' ) || has_filter( 'wpml_default_language' );
    }
}

if ( ! function_exists( 'tk_wpml_cruise_runs_sync_map_value' ) ) {
    function tk_wpml_cruise_runs_sync_map_value( $value, string $taxonomy, string $lang ) {
        if ( is_array( $value ) ) {
            $mapped = [];
            foreach ( $value as $key => $item ) {
                if ( is_numeric( $item ) || ( is_string( $item ) && ctype_digit( trim( $item ) ) ) ) {
                    $id = absint( $item );
                    $translated = apply_filters( 'wpml_object_id', $id, $taxonomy, true, $lang );
                    $mapped[ $key ] = $translated ? (string) $translated : (string) $id;
                } else {
                    $mapped[ $key ] = $item;
                }
            }
            return $mapped;
        }

        if ( ! is_scalar( $value ) ) {
            return $value;
        }

        $string_value = (string) $value;
        $delimiter = false !== strpos( $string_value, '|' ) ? '|' : ',';
        $parts = false !== strpos( $string_value, $delimiter ) ? explode( $delimiter, $string_value ) : [ $string_value ];
        $translated_ids = [];

        foreach ( $parts as $part ) {
            $id = absint( trim( $part ) );
            if ( ! $id ) {
                continue;
            }

            $translated = apply_filters( 'wpml_object_id', $id, $taxonomy, true, $lang );
            $translated_ids[] = $translated ? (string) $translated : (string) $id;
        }

        return empty( $translated_ids ) ? $value : implode( $delimiter, $translated_ids );
    }
}

add_action( 'wpml_after_save_post', function ( $post_id, $trid, $lang ): void {
    static $running = false;

    if ( $running || ! tk_wpml_cruise_runs_sync_has_wpml() ) {
        return;
    }

    $post_id = absint( $post_id );
    if ( ! $post_id || ! $lang ) {
        return;
    }

    $post_type = get_post_type( $post_id );
    if ( ! $post_type || ! in_array( $post_type, tk_wpml_cruise_runs_sync_post_types(), true ) ) {
        return;
    }

    $default_lang = apply_filters( 'wpml_default_language', null );
    if ( ! $default_lang || $lang === $default_lang ) {
        return;
    }

    $source_id = apply_filters( 'wpml_object_id', $post_id, $post_type, true, $default_lang );
    $source_id = absint( $source_id );

    if ( ! $source_id || $source_id === $post_id ) {
        return;
    }

    $meta_key = defined( 'TK_WPML_CRUISE_RUNS_SYNC_META_KEY' ) ? (string) TK_WPML_CRUISE_RUNS_SYNC_META_KEY : 'cruise_runs';
    if ( '' === $meta_key ) {
        return;
    }

    if ( defined( 'TK_WPML_CRUISE_RUNS_SYNC_OVERWRITE' ) && ! TK_WPML_CRUISE_RUNS_SYNC_OVERWRITE ) {
        $existing = get_post_meta( $post_id, $meta_key, true );
        if ( ! empty( $existing ) ) {
            return;
        }
    }

    $runs = get_post_meta( $source_id, $meta_key, true );
    if ( ! is_array( $runs ) ) {
        return;
    }

    $taxonomy_map = tk_wpml_cruise_runs_sync_taxonomy_map();

    foreach ( $runs as &$row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }

        foreach ( $taxonomy_map as $field => $taxonomy ) {
            if ( ! array_key_exists( $field, $row ) || empty( $row[ $field ] ) || ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }

            $row[ $field ] = tk_wpml_cruise_runs_sync_map_value( $row[ $field ], (string) $taxonomy, (string) $lang );
        }
    }
    unset( $row );

    $running = true;
    update_post_meta( $post_id, $meta_key, wp_slash( $runs ) );
    $running = false;
}, 20, 3 );
