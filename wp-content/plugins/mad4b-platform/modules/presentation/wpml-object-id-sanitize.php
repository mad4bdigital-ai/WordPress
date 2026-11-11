<?php
/**
 * Plugin Name: TK - WPML Object ID Sanitizer
 * Description: Emergency guard for calls that pass arrays/objects into wpml_object_id. Keeps WPML from fatalling while logging the source when debug is enabled.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TK_WPML_OBJECT_ID_SANITIZE_ENABLED' ) ) {
    define( 'TK_WPML_OBJECT_ID_SANITIZE_ENABLED', true );
}

if ( ! defined( 'TK_WPML_OBJECT_ID_SANITIZE_LOG' ) ) {
    define( 'TK_WPML_OBJECT_ID_SANITIZE_LOG', false );
}

if ( ! function_exists( 'tk_wpml_object_id_pick_first_id' ) ) {
    /**
     * Extract the first usable numeric ID from a mixed value.
     * This is intentionally conservative because wpml_object_id expects one ID, not a list.
     *
     * @param mixed $value
     * @return int
     */
    function tk_wpml_object_id_pick_first_id( $value ) {
        if ( is_numeric( $value ) ) {
            return absint( $value );
        }

        if ( $value instanceof WP_Post || $value instanceof WP_Term || $value instanceof WP_User ) {
            return ! empty( $value->ID ) ? absint( $value->ID ) : absint( $value->term_id ?? 0 );
        }

        if ( is_object( $value ) ) {
            if ( isset( $value->ID ) ) {
                return absint( $value->ID );
            }
            if ( isset( $value->id ) ) {
                return absint( $value->id );
            }
            if ( isset( $value->term_id ) ) {
                return absint( $value->term_id );
            }
            return 0;
        }

        if ( is_array( $value ) ) {
            foreach ( array( 'ID', 'id', 'term_id', 'attachment_id', 'value' ) as $key ) {
                if ( isset( $value[ $key ] ) ) {
                    $picked = tk_wpml_object_id_pick_first_id( $value[ $key ] );
                    if ( $picked ) {
                        return $picked;
                    }
                }
            }

            foreach ( $value as $item ) {
                $picked = tk_wpml_object_id_pick_first_id( $item );
                if ( $picked ) {
                    return $picked;
                }
            }
        }

        return 0;
    }
}

if ( ! function_exists( 'tk_wpml_object_id_sanitize_log' ) ) {
    /**
     * Optional debug log. Disabled by default to avoid noisy production logs.
     *
     * @param mixed  $original
     * @param int    $picked
     * @param string $type
     * @return void
     */
    function tk_wpml_object_id_sanitize_log( $original, $picked, $type ) {
        if ( ! TK_WPML_OBJECT_ID_SANITIZE_LOG || ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        $summary = is_array( $original ) ? 'array(' . count( $original ) . ')' : gettype( $original );
        error_log( sprintf(
            '[TK WPML Object ID Sanitizer] wpml_object_id received %s for type "%s". Picked ID: %d',
            $summary,
            is_scalar( $type ) ? (string) $type : '',
            $picked
        ) );
    }
}

add_filter( 'wpml_object_id', function ( $object_id, $type = null, $return_original = false, $lang = null ) {
    if ( ! TK_WPML_OBJECT_ID_SANITIZE_ENABLED ) {
        return $object_id;
    }

    if ( is_scalar( $object_id ) || null === $object_id ) {
        return $object_id;
    }

    $picked = tk_wpml_object_id_pick_first_id( $object_id );
    tk_wpml_object_id_sanitize_log( $object_id, $picked, is_scalar( $type ) ? (string) $type : '' );

    return $picked;
}, 0, 4 );
