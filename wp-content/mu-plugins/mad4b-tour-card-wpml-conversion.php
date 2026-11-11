<?php
/**
 * Plugin Name: MAD4B Tour Card WPML Conversion Reuse
 * Description: Reuses repeated Elementor template conversions within an anonymous homepage request.
 */
defined( 'ABSPATH' ) || exit;

final class MAD4B_Tour_Card_WPML_Conversion {
    private $converter;
    private $results = [];

    public function __construct( $converter ) {
        $this->converter = $converter;
    }

    public function convert( $data, $document_id ) {
        if ( ! in_array( (int) $document_id, [ 33747, 33753 ], true )
            || has_filter( 'wpml_pb_elementor_widget_dynamic_id_converters' ) ) {
            return $this->converter->convert( $data );
        }
        $language = apply_filters( 'wpml_current_language', null );
        if ( ! in_array( $language, [ 'en', 'es' ], true ) ) {
            return $this->converter->convert( $data );
        }
        $key = $document_id . ':' . $language . ':' . hash( 'sha256', serialize( $data ) );
        if ( isset( $this->results[ $key ] ) ) {
            return unserialize( $this->results[ $key ], [ 'allowed_classes' => false ] );
        }
        $converted = $this->converter->convert( $data );
        if ( count( $this->results ) < 4 ) {
            // Store a snapshot so downstream filters cannot modify a later card's input.
            $this->results[ $key ] = serialize( $converted );
        }
        return $converted;
    }
}

add_action( 'wp', static function () {
    if ( ! is_front_page() || ! is_singular() || is_user_logged_in() || is_preview()
        || is_customize_preview() || wp_doing_ajax() || is_admin()
        || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET'
        || ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['HTTP_COOKIE'] )
        || ! empty( $_SERVER['QUERY_STRING'] ) ) {
        return;
    }
    global $wp_filter;
    $hook = $wp_filter['elementor/frontend/builder_content_data'] ?? null;
    if ( ! $hook || empty( $hook->callbacks[10] ) ) {
        return;
    }
    foreach ( $hook->callbacks[10] as $id => $entry ) {
        $callback = $entry['function'];
        if ( is_array( $callback ) && $callback[0] instanceof \WPML\PB\Elementor\Hooks\DynamicElements
            && 'convert' === $callback[1] ) {
            // Preserve WPML's position relative to other priority-10 converters.
            $wrapper = new MAD4B_Tour_Card_WPML_Conversion( $callback[0] );
            $hook->callbacks[10][ $id ]['function'] = [ $wrapper, 'convert' ];
            $hook->callbacks[10][ $id ]['accepted_args'] = 2;
            break;
        }
    }
}, 100 );
