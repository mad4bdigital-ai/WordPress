<?php
/**
 * Plugin Name: Shared Product Program Date Filter
 * Description: Loads tour date filter data, Flatpickr locale files, and cruise itinerary frontend flag on tour pages only.
 * Version: 1.0.0
 * Author: Shared Product Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( defined( 'SHARED_PRODUCT_PROGRAM_ASSETS_ENABLED' ) && ! SHARED_PRODUCT_PROGRAM_ASSETS_ENABLED ) {
    return;
}

if ( ! function_exists( 'shared_product_program_post_type' ) ) {
    function shared_product_program_post_type(): string {
        return defined( 'SHARED_PRODUCT_PROGRAM_POST_TYPE' ) ? (string) SHARED_PRODUCT_PROGRAM_POST_TYPE : 'tours-and-activities';
    }
}

if ( ! function_exists( 'shared_product_program_weekday_taxonomy' ) ) {
    function shared_product_program_weekday_taxonomy(): string {
        return class_exists('Shared_Product_Schedule_Policy')
          ? Shared_Product_Schedule_Policy::weekdays_taxonomy()
          : (defined('SHARED_PRODUCT_PROGRAM_WEEKDAY_TAXONOMY') ? (string) SHARED_PRODUCT_PROGRAM_WEEKDAY_TAXONOMY : 'schudle-days-of-weak');
    }
}

if ( ! function_exists( 'shared_product_program_reference_language' ) ) {
    function shared_product_program_reference_language(): string {
        return defined( 'SHARED_PRODUCT_PROGRAM_REFERENCE_LANGUAGE' ) ? (string) SHARED_PRODUCT_PROGRAM_REFERENCE_LANGUAGE : 'en';
    }
}

if ( ! function_exists( 'shared_product_program_weekday_term_slug' ) ) {
    function shared_product_program_weekday_term_slug( WP_Term $term, string $taxonomy ): string {
        $reference_lang = shared_product_program_reference_language();

        if ( $reference_lang && has_filter( 'wpml_object_id' ) ) {
            $mapped_id = apply_filters( 'wpml_object_id', (int) $term->term_id, $taxonomy, false, $reference_lang );
            if ( $mapped_id ) {
                $mapped = get_term( (int) $mapped_id, $taxonomy );
                if ( $mapped instanceof WP_Term && ! is_wp_error( $mapped ) && $mapped->slug ) {
                    return strtolower( (string) $mapped->slug );
                }
            }
        }

        if ( $reference_lang && function_exists( 'icl_object_id' ) ) {
            $mapped_id = icl_object_id( (int) $term->term_id, $taxonomy, false, $reference_lang );
            if ( $mapped_id ) {
                $mapped = get_term( (int) $mapped_id, $taxonomy );
                if ( $mapped instanceof WP_Term && ! is_wp_error( $mapped ) && $mapped->slug ) {
                    return strtolower( (string) $mapped->slug );
                }
            }
        }

        return strtolower( (string) $term->slug );
    }
}

if ( ! function_exists( 'shared_product_program_weekdays' ) ) {
    function shared_product_program_weekdays( int $post_id ): array {
        if(class_exists('Shared_Product_Schedule_Policy'))return Shared_Product_Schedule_Policy::weekdays($post_id);
        $taxonomy = shared_product_program_weekday_taxonomy();
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return [];
        }

        $terms = get_the_terms( $post_id, $taxonomy );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return [];
        }

        $day_map = [
            'sunday'    => 0,
            'monday'    => 1,
            'tuesday'   => 2,
            'wednesday' => 3,
            'thursday'  => 4,
            'friday'    => 5,
            'saturday'  => 6,
        ];

        $allowed = [];
        foreach ( $terms as $term ) {
            if ( ! $term instanceof WP_Term ) {
                continue;
            }

            $slug = shared_product_program_weekday_term_slug( $term, $taxonomy );
            if ( isset( $day_map[ $slug ] ) ) {
                $allowed[] = $day_map[ $slug ];
            }
        }

        $allowed = array_values( array_unique( array_map( 'intval', $allowed ) ) );
        sort( $allowed );

        return $allowed;
    }
}

if ( ! function_exists( 'shared_product_program_enqueue_calendar_locales' ) ) {
    function shared_product_program_enqueue_calendar_locales(): void {
        if ( defined( 'SHARED_PRODUCT_PROGRAM_LOAD_CALENDAR_LOCALES' ) && ! SHARED_PRODUCT_PROGRAM_LOAD_CALENDAR_LOCALES ) {
            return;
        }

        if ( ! wp_script_is( 'flatpickr', 'registered' ) && ! wp_script_is( 'flatpickr', 'enqueued' ) && ! wp_script_is( 'flatpickr', 'done' ) ) {
            return;
        }

        $base    = 'https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/';
        $locales = [ 'ar', 'es', 'it', 'fr', 'de' ];

        foreach ( $locales as $locale ) {
            wp_enqueue_script(
                'flatpickr-locale-' . $locale,
                $base . $locale . '.js',
                [ 'flatpickr' ],
                null,
                true
            );
        }
    }
}

if ( ! function_exists( 'shared_product_program_enqueue_calendar_assets' ) ) {
    function shared_product_program_enqueue_calendar_assets(): void {
        $post_type = shared_product_program_post_type();

        if ( ! is_singular( $post_type ) ) {
            return;
        }

        $post_id = get_queried_object_id();
        if ( ! $post_id ) {
            return;
        }

        $script_file = defined( 'SHARED_PRODUCT_PROGRAM_LEGACY_CALENDAR_SCRIPT' ) ? (string) SHARED_PRODUCT_PROGRAM_LEGACY_CALENDAR_SCRIPT : 'trip-date-filter.js';
        $script_file = ltrim( $script_file, '/' );
        $script_path = trailingslashit( get_stylesheet_directory() ) . $script_file;
        $script_url  = trailingslashit( get_stylesheet_directory_uri() ) . $script_file;
        $handle      = 'trip-date-filter';

        if ( file_exists( $script_path ) ) {
            wp_enqueue_script(
                $handle,
                $script_url,
                [ 'jquery' ],
                (string) filemtime( $script_path ),
                true
            );
        } else {
            wp_register_script( $handle, false, [ 'jquery' ], null, true );
            wp_enqueue_script( $handle );
        }

        $allowed_days = shared_product_program_weekdays( $post_id );
        wp_localize_script( $handle, 'tripDaysData', [
            // Preserve the original Fluent Forms weekday contract unchanged.
            'allowedDays' => $allowed_days,
            // Additive, versioned schedule contract for progressive migration.
            'programScheduleVersion' => 1,
            'programScheduleEndpoint' => rest_url( 'shared-product/v1/program-schedule' ),
            'programScheduleTourId' => (int) $post_id,
            'programScheduleLeadDays' => class_exists('Shared_Product_Program_Schedule')
                ? Shared_Product_Program_Schedule::lead_days((int)$post_id) : 5,
            'programScheduleSemantics' => 'program_schedule_not_booking_availability',
        ] );

        $is_cruise = get_post_meta( $post_id, 'cruise_trip_itinerary', true );
        $is_cruise = ( $is_cruise === '1' || $is_cruise === 1 || $is_cruise === true || $is_cruise === 'true' );
        wp_add_inline_script( $handle, 'window.isCruiseTrip = ' . ( $is_cruise ? 'true' : 'false' ) . ';', 'before' );

        shared_product_program_enqueue_calendar_locales();
    }
}

add_action( 'wp_enqueue_scripts', 'shared_product_program_enqueue_calendar_assets', 30 );
