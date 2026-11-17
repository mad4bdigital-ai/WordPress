<?php
/**
 * Plugin Name: Flight Scope Helper
 * Description: Auto detect Domestic / International flights (JetEngine Dynamic Tag).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tk_flight_scope_normalize_manual_value' ) ) {
	/**
	 * Normalize manual flight_scope values.
	 */
	function tk_flight_scope_normalize_manual_value( $scope ) {
		$scope = strtolower( trim( (string) $scope ) );

		if ( '' === $scope || 'auto' === $scope ) {
			return '';
		}

		if ( in_array( $scope, array( 'domestic', 'international' ), true ) ) {
			return ucfirst( $scope );
		}

		return '';
	}
}

if ( ! function_exists( 'tk_flight_scope_maybe_translate_term_id' ) ) {
	/**
	 * Keep country comparison stable across WPML languages when possible.
	 */
	function tk_flight_scope_maybe_translate_term_id( $term_id, $taxonomy = 'destinations' ) {
		$term_id = (int) $term_id;

		if ( ! $term_id ) {
			return 0;
		}

		if ( has_filter( 'wpml_object_id' ) ) {
			$default_lang = apply_filters( 'wpml_default_language', null );

			if ( $default_lang ) {
				$translated_id = apply_filters( 'wpml_object_id', $term_id, $taxonomy, true, $default_lang );

				if ( $translated_id ) {
					return (int) $translated_id;
				}
			}
		}

		return $term_id;
	}
}

if ( ! function_exists( 'tk_flight_scope_term_is_country' ) ) {
	/**
	 * Check whether a location term is marked as country.
	 */
	function tk_flight_scope_term_is_country( $term_id ) {
		$type = get_term_meta( (int) $term_id, 'destination_type', true );

		return ( 'country' === strtolower( trim( (string) $type ) ) );
	}
}

if ( ! function_exists( 'tk_flight_scope_find_country_from_term' ) ) {
	/**
	 * Resolve country ID from a term or one of its ancestors.
	 */
	function tk_flight_scope_find_country_from_term( $term_id, $taxonomy = 'destinations' ) {
		$term_id = (int) $term_id;

		if ( ! $term_id ) {
			return 0;
		}

		$chain_ids = array( $term_id );
		$ancestors = get_ancestors( $term_id, $taxonomy, 'taxonomy' );

		if ( ! empty( $ancestors ) && is_array( $ancestors ) ) {
			$chain_ids = array_merge( $chain_ids, array_map( 'intval', $ancestors ) );
		}

		foreach ( $chain_ids as $id ) {
			if ( tk_flight_scope_term_is_country( $id ) ) {
				return tk_flight_scope_maybe_translate_term_id( $id, $taxonomy );
			}
		}

		return 0;
	}
}

if ( ! function_exists( 'tk_flight_scope_find_station_country' ) ) {
	/**
	 * Resolve station country from directly assigned location terms, then their ancestors.
	 */
	function tk_flight_scope_find_station_country( $station_id, $taxonomy = 'destinations' ) {
		$station_id = (int) $station_id;

		if ( ! $station_id ) {
			return 0;
		}

		$terms = wp_get_post_terms( $station_id, $taxonomy );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 0;
		}

		foreach ( $terms as $term ) {
			$country_id = tk_flight_scope_find_country_from_term( $term->term_id, $taxonomy );

			if ( $country_id ) {
				return $country_id;
			}
		}

		return 0;
	}
}

if ( ! function_exists( 'tk_get_flight_scope' ) ) {

	function tk_get_flight_scope( $post_id ) {

		$post_id = (int) $post_id;

		if ( ! $post_id ) {
			return '';
		}

		$scope = tk_flight_scope_normalize_manual_value( get_post_meta( $post_id, 'flight_scope', true ) );

		// manual override
		if ( $scope ) {
			return $scope;
		}

		$from_id = (int) get_post_meta( $post_id, 'trans_from_station_id', true );
		$to_id   = (int) get_post_meta( $post_id, 'trans_to_station_id', true );

		if ( ! $from_id || ! $to_id ) {
			return '';
		}

		$from_country_id = tk_flight_scope_find_station_country( $from_id );
		$to_country_id   = tk_flight_scope_find_station_country( $to_id );

		if ( ! $from_country_id || ! $to_country_id ) {
			return '';
		}

		return ( $from_country_id === $to_country_id ) ? 'Domestic' : 'International';
	}
}

/**
 * Register JetEngine Dynamic Tag (Elementor)
 * Correct hook from your JetEngine: jet-engine/elementor-views/dynamic-tags/register
 */
add_action( 'jet-engine/elementor-views/dynamic-tags/register', function( $dynamic_tags, $tags_module ) {

	// Elementor not loaded
	if ( ! class_exists( '\Elementor\Core\DynamicTags\Tag' ) ) {
		return;
	}

	// JetEngine Dynamic Tags module not available
	if ( ! class_exists( 'Jet_Engine_Dynamic_Tags_Module' ) ) {
		return;
	}

	if ( ! class_exists( 'Jet_Engine_Flight_Scope_Auto_Tag' ) ) {

		class Jet_Engine_Flight_Scope_Auto_Tag extends \Elementor\Core\DynamicTags\Tag {

			public function get_name() {
				return 'flight-scope-auto';
			}

			public function get_title() {
				return 'Flight Scope (Auto)';
			}

			public function get_group() {
				return \Jet_Engine_Dynamic_Tags_Module::JET_GROUP;
			}

			public function get_categories() {
				return array(
					\Jet_Engine_Dynamic_Tags_Module::TEXT_CATEGORY,
				);
			}

			public function render() {
				echo esc_html( tk_get_flight_scope( get_the_ID() ) );
			}
		}
	}

	// Register with JetEngine module helper (handles old/new Elementor API)
	if ( is_object( $tags_module ) && method_exists( $tags_module, 'register_tag' ) ) {
		$tags_module->register_tag( $dynamic_tags, new \Jet_Engine_Flight_Scope_Auto_Tag() );
	} elseif ( is_object( $dynamic_tags ) && method_exists( $dynamic_tags, 'register' ) ) {
		$dynamic_tags->register( new \Jet_Engine_Flight_Scope_Auto_Tag() );
	} elseif ( is_object( $dynamic_tags ) && method_exists( $dynamic_tags, 'register_tag' ) ) {
		$dynamic_tags->register_tag( new \Jet_Engine_Flight_Scope_Auto_Tag() );
	}

}, 20, 2 );


// Cache calculated scope on save
add_action( 'save_post', function( $post_id ) {

    // avoid autosave / revisions
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;

    // IMPORTANT: غيّر اسم post type لو عندك مختلف
    if ( get_post_type( $post_id ) !== 'transportations' ) return;

    // احسب النتيجة (ترجع Domestic/International أو manual)
    $result = tk_get_flight_scope( $post_id );

    // خزّنها للكويريز والفلاتر
    if ( $result ) {
        update_post_meta( $post_id, 'trans_flight_scope_calc', $result );
    } else {
        delete_post_meta( $post_id, 'trans_flight_scope_calc' );
    }

}, 20 );