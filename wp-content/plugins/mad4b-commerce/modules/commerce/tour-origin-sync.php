<?php
/**
 * Plugin Name: TripKlik Tour Origin Sync
 * Description: Syncs tour origin address/place/lat/lng post meta from selected destinations term meta. WPML-aware and child-theme independent.
 * Version: 1.0.0
 * Author: OpenAI for Nagy
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( defined( 'TK_TOUR_ORIGIN_SYNC_ENABLED' ) && ! TK_TOUR_ORIGIN_SYNC_ENABLED ) {
    return;
}

if ( ! function_exists( 'tk_tour_origin_sync_post_type' ) ) {
    function tk_tour_origin_sync_post_type(): string {
        return defined( 'TK_TOUR_ORIGIN_SYNC_POST_TYPE' ) ? (string) TK_TOUR_ORIGIN_SYNC_POST_TYPE : 'tours-and-activities';
    }
}

if ( ! function_exists( 'tk_tour_origin_sync_taxonomy' ) ) {
    function tk_tour_origin_sync_taxonomy(): string {
        return defined( 'TK_TOUR_ORIGIN_SYNC_TAXONOMY' ) ? (string) TK_TOUR_ORIGIN_SYNC_TAXONOMY : 'destinations';
    }
}

if ( ! function_exists( 'tk_tour_origin_sync_reference_lang' ) ) {
    function tk_tour_origin_sync_reference_lang(): string {
        if ( defined( 'TK_TOUR_ORIGIN_SYNC_REFERENCE_LANG' ) ) return sanitize_key( (string) TK_TOUR_ORIGIN_SYNC_REFERENCE_LANG );
        // Each enrolled company owns its active WPML language registry.
        $settings = get_option( 'icl_sitepress_settings', [] );
        $default = is_array( $settings ) ? sanitize_key( (string) ( $settings['default_language'] ?? '' ) ) : '';
        return $default !== '' ? $default : 'en';
    }
}

if ( ! function_exists( 'tk_tour_origin_sync_debug' ) ) {
    function tk_tour_origin_sync_debug( string $message, array $context = [] ): void {
        if ( ! defined( 'TK_TOUR_ORIGIN_SYNC_DEBUG' ) || ! TK_TOUR_ORIGIN_SYNC_DEBUG ) {
            return;
        }
        error_log( 'TK TOUR ORIGIN SYNC: ' . $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ) );
    }
}

if ( ! function_exists( 'tk_tour_origin_sync_extract_id' ) ) {
    function tk_tour_origin_sync_extract_id( $value ): int {
        if ( is_numeric( $value ) ) {
            return absint( $value );
        }

        if ( is_array( $value ) ) {
            foreach ( [ 'term_id', 'id', 'ID', 'value' ] as $key ) {
                if ( isset( $value[ $key ] ) && is_numeric( $value[ $key ] ) ) {
                    return absint( $value[ $key ] );
                }
            }

            foreach ( $value as $item ) {
                $id = tk_tour_origin_sync_extract_id( $item );
                if ( $id ) {
                    return $id;
                }
            }
        }

        if ( is_object( $value ) ) {
            foreach ( [ 'term_id', 'id', 'ID' ] as $prop ) {
                if ( isset( $value->{$prop} ) && is_numeric( $value->{$prop} ) ) {
                    return absint( $value->{$prop} );
                }
            }
        }

        return 0;
    }
}

if ( ! function_exists( 'tk_tour_origin_sync_map_term_to_reference_lang' ) ) {
    function tk_tour_origin_sync_map_term_to_reference_lang( int $term_id, string $taxonomy ): int {
        if ( ! $term_id ) {
            return 0;
        }

        $reference_lang = tk_tour_origin_sync_reference_lang();

        if ( $reference_lang && has_filter( 'wpml_object_id' ) ) {
            $mapped = apply_filters( 'wpml_object_id', $term_id, $taxonomy, false, $reference_lang );
            if ( $mapped ) {
                return absint( $mapped );
            }
        }

        if ( $reference_lang && function_exists( 'icl_object_id' ) ) {
            $mapped = icl_object_id( $term_id, $taxonomy, false, $reference_lang );
            if ( $mapped ) {
                return absint( $mapped );
            }
        }

        return $term_id;
    }
}

if ( ! function_exists( 'tk_tour_origin_sync_read_location_term_meta' ) ) {
    function tk_tour_origin_sync_read_location_term_meta( int $term_id, string $taxonomy ): array {
        $address  = get_term_meta( $term_id, 'google_address', true );
        $place_id = get_term_meta( $term_id, 'google_place_id', true );
        $location = get_term_meta( $term_id, 'google_location', true );

        if ( $address === '' || $address === null ) {
            $term = get_term( $term_id, $taxonomy );
            if ( $term instanceof WP_Term && ! is_wp_error( $term ) ) {
                $address = $term->name;
            }
        }

        return [
            'address'  => is_scalar( $address ) ? (string) $address : '',
            'place_id' => is_scalar( $place_id ) ? (string) $place_id : '',
            'location' => $location,
        ];
    }
}

if ( ! function_exists( 'tk_tour_origin_sync_parse_lat_lng' ) ) {
    function tk_tour_origin_sync_parse_lat_lng( $location ): array {
        $lat = '';
        $lng = '';

        if ( is_string( $location ) && $location !== '' ) {
            $trimmed = trim( $location );
            $decoded = json_decode( $trimmed, true );

            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                $location = $decoded;
            } elseif ( preg_match( '/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $trimmed, $matches ) ) {
                $lat = $matches[1];
                $lng = $matches[2];
            }
        }

        if ( is_array( $location ) ) {
            $lat = $lat ?: ( $location['lat'] ?? $location['latitude'] ?? '' );
            $lng = $lng ?: ( $location['lng'] ?? $location['longitude'] ?? '' );

            if ( ( ! $lat || ! $lng ) && isset( $location['location'] ) && is_array( $location['location'] ) ) {
                $lat = $lat ?: ( $location['location']['lat'] ?? $location['location']['latitude'] ?? '' );
                $lng = $lng ?: ( $location['location']['lng'] ?? $location['location']['longitude'] ?? '' );
            }

            if ( ( ! $lat || ! $lng ) && isset( $location[0], $location[1] ) ) {
                $lat = $lat ?: $location[0];
                $lng = $lng ?: $location[1];
            }
        }

        $lat = is_scalar( $lat ) ? trim( (string) $lat ) : '';
        $lng = is_scalar( $lng ) ? trim( (string) $lng ) : '';

        if ( $lat !== '' && ! is_numeric( $lat ) ) {
            $lat = '';
        }
        if ( $lng !== '' && ! is_numeric( $lng ) ) {
            $lng = '';
        }

        return [ $lat, $lng ];
    }
}

if ( ! function_exists( 'tk_tour_origin_sync_clear_post_meta' ) ) {
    function tk_tour_origin_sync_clear_post_meta( int $post_id ): void {
        delete_post_meta( $post_id, 'tour_origin_address' );
        delete_post_meta( $post_id, 'tour_origin_address_place_id' );
        delete_post_meta( $post_id, 'tour_origin_address_lat' );
        delete_post_meta( $post_id, 'tour_origin_address_lng' );
    }
}

if ( ! function_exists( 'tk_tour_origin_sync_save' ) ) {
    function tk_tour_origin_sync_save( int $post_id, WP_Post $post, bool $update ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $post_type = tk_tour_origin_sync_post_type();
        $taxonomy  = tk_tour_origin_sync_taxonomy();

        if ( $post->post_type !== $post_type || ! taxonomy_exists( $taxonomy ) ) {
            return;
        }

        $selected_raw     = get_post_meta( $post_id, 'tour_origin_location_term', true );
        $selected_term_id = tk_tour_origin_sync_extract_id( $selected_raw );

        // When one translated family has the same canonical source, inherit it.
        // Keep conflicting/missing source families untouched for Readiness review.
        if ( ! $selected_term_id && has_filter( 'wpml_element_trid' ) ) {
            $trid = apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . $post_type );
            $siblings = $trid ? apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . $post_type ) : array();
            $sources = array();
            foreach ( (array) $siblings as $sibling ) {
                $peer = (int) ( $sibling->element_id ?? 0 );
                if ( ! $peer || $peer === $post_id || get_post_status( $peer ) !== 'publish' ) continue;
                $term = tk_tour_origin_sync_extract_id( get_post_meta( $peer, 'tour_origin_location_term', true ) );
                if ( ! $term ) continue;
                $ref = tk_tour_origin_sync_map_term_to_reference_lang( $term, $taxonomy );
                if ( get_term( $ref, $taxonomy ) instanceof WP_Term ) $sources[$ref] = true;
            }
            if ( count( $sources ) === 1 ) {
                $ref = (int) array_key_first( $sources );
                $source = tk_tour_origin_sync_read_location_term_meta( $ref, $taxonomy );
                [ $lat, $lng ] = tk_tour_origin_sync_parse_lat_lng( $source['location'] );
                $valid = sanitize_key( (string ) get_term_meta( $ref, 'destination_type', true ) ) !== 'continent'
                    && trim( (string) $source['address'] ) !== ''
                    && trim( (string) $source['place_id'] ) !== ''
                    && is_numeric( $lat ) && is_numeric( $lng )
                    && abs( (float) $lat ) <= 90 && abs( (float) $lng ) <= 180;
                $details = apply_filters( 'wpml_post_language_details', null, $post_id );
                $lang = is_array( $details ) ? (string) ( $details['language_code'] ?? '' ) : '';
                $mapped = $lang ? apply_filters( 'wpml_object_id', $ref, $taxonomy, false, $lang ) : 0;
                $selected_term_id = $valid && $mapped ? (int) $mapped : 0;
                if ( $selected_term_id ) update_post_meta( $post_id, 'tour_origin_location_term', $selected_term_id );
            }
        }

        if ( ! $selected_term_id ) {
            tk_tour_origin_sync_clear_post_meta( $post_id );
            tk_tour_origin_sync_debug( 'cleared empty origin', [ 'post_id' => $post_id ] );
            return;
        }

        $reference_term_id = tk_tour_origin_sync_map_term_to_reference_lang( $selected_term_id, $taxonomy );
        // A continent is a discovery level, never a usable point of pickup.
        // Use WPML reference term metadata, not a localized term label, to
        // avoid reintroducing placeholder addresses such as "Africa".
        $origin_level = sanitize_key( (string) get_term_meta( $reference_term_id, 'destination_type', true ) );
        if ( 'continent' === $origin_level ) {
            tk_tour_origin_sync_clear_post_meta( $post_id );
            tk_tour_origin_sync_debug( 'invalid broad origin requires content review', array(
                'post_id' => $post_id, 'reference_term_id' => $reference_term_id,
                'source_level' => $origin_level,
            ) );
            return;
        }
        $meta              = tk_tour_origin_sync_read_location_term_meta( $reference_term_id, $taxonomy );

        if ( empty( $meta['address'] ) && empty( $meta['place_id'] ) && empty( $meta['location'] ) && $reference_term_id !== $selected_term_id ) {
            $meta = tk_tour_origin_sync_read_location_term_meta( $selected_term_id, $taxonomy );
        }

        [ $lat, $lng ] = tk_tour_origin_sync_parse_lat_lng( $meta['location'] );

        update_post_meta( $post_id, 'tour_origin_address', sanitize_text_field( $meta['address'] ) );
        update_post_meta( $post_id, 'tour_origin_address_place_id', sanitize_text_field( $meta['place_id'] ) );
        update_post_meta( $post_id, 'tour_origin_address_lat', sanitize_text_field( $lat ) );
        update_post_meta( $post_id, 'tour_origin_address_lng', sanitize_text_field( $lng ) );

        tk_tour_origin_sync_debug( 'updated origin', [
            'post_id'          => $post_id,
            'selected_term_id' => $selected_term_id,
            'reference_term_id'=> $reference_term_id,
            'address'          => $meta['address'],
            'place_id'         => $meta['place_id'],
            'lat'              => $lat,
            'lng'              => $lng,
        ] );
    }
}

add_action( 'save_post_' . tk_tour_origin_sync_post_type(), 'tk_tour_origin_sync_save', 20, 3 );
