<?php
/**
 * Plugin Name: Location Term Tools (WPML friendly)
 * Description: Shortcodes for destinations hierarchy tools: siblings, descendants, and term picking by JetEngine term meta destination_type.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'LOC_TAX' ) ) {
	define( 'LOC_TAX', 'destinations' );
}

if ( ! defined( 'TYPE_KEY' ) ) {
	define( 'TYPE_KEY', 'destination_type' );
}

if ( ! function_exists( 'current_lang' ) ) {
	/**
	 * Get current WPML language if available.
	 */
	function current_lang(): ?string {
		if ( defined( 'ICL_LANGUAGE_CODE' ) && ICL_LANGUAGE_CODE ) {
			return ICL_LANGUAGE_CODE;
		}

		if ( function_exists( 'apply_filters' ) ) {
			$lang = apply_filters( 'wpml_current_language', null );
			if ( is_string( $lang ) && $lang ) {
				return $lang;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'queried_term' ) ) {
	/**
	 * Get queried taxonomy term.
	 */
	function queried_term(): ?WP_Term {
		$obj = get_queried_object();
		return ( $obj instanceof WP_Term ) ? $obj : null;
	}
}

if ( ! function_exists( 'term_parent_id' ) ) {
	/**
	 * Get direct parent ID for a term.
	 */
	function term_parent_id( int $term_id, string $tax ): int {
		$t = get_term( $term_id, $tax );
		if ( ! $t || is_wp_error( $t ) ) {
			return 0;
		}

		return (int) $t->parent;
	}
}

if ( ! function_exists( 'terms_args_base' ) ) {
	/**
	 * Base get_terms args with WPML support.
	 */
	function terms_args_base( string $tax ): array {
		$args = [
			'taxonomy'         => $tax,
			'hide_empty'       => false,
			'suppress_filters' => false,
		];

		$lang = current_lang();
		if ( $lang ) {
			$args['lang'] = $lang;
		}

		return $args;
	}
}

if ( ! function_exists( 'get_siblings_by_type' ) ) {
	/**
	 * Get sibling terms by destination_type.
	 */
	function get_siblings_by_type( int $term_id, string $type_value, string $tax = LOC_TAX ): array {
		$parent_id = term_parent_id( $term_id, $tax );
		if ( ! $parent_id ) {
			return [];
		}

		$args               = terms_args_base( $tax );
		$args['parent']     = $parent_id;
		$args['meta_query'] = [
			[
				'key'     => TYPE_KEY,
				'value'   => $type_value,
				'compare' => '=',
			],
		];
		$args['orderby']    = 'name';
		$args['order']      = 'ASC';

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		return array_values(
			array_filter(
				$terms,
				function ( $t ) use ( $term_id ) {
					return (int) $t->term_id !== (int) $term_id;
				}
			)
		);
	}
}

if ( ! function_exists( 'get_descendants_by_type' ) ) {
	/**
	 * Get all descendants by destination_type at any depth.
	 */
	function get_descendants_by_type( int $root_term_id, string $type_value, string $tax = LOC_TAX ): array {
		$args               = terms_args_base( $tax );
		$args['child_of']   = $root_term_id;
		$args['meta_query'] = [
			[
				'key'     => TYPE_KEY,
				'value'   => $type_value,
				'compare' => '=',
			],
		];
		$args['orderby']    = 'name';
		$args['order']      = 'ASC';

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		return $terms;
	}
}

if ( ! function_exists( 'render_terms_ul' ) ) {
	/**
	 * Render terms as an unordered list.
	 */
	function render_terms_ul( array $terms ): string {
		if ( empty( $terms ) ) {
			return '';
		}

		$out = '<ul class="location-term-list">';

		foreach ( $terms as $t ) {
			if ( ! $t instanceof WP_Term ) {
				continue;
			}

			$link = get_term_link( $t );
			if ( is_wp_error( $link ) ) {
				continue;
			}

			$out .= '<li><a href="' . esc_url( $link ) . '">' . esc_html( $t->name ) . '</a></li>';
		}

		$out .= '</ul>';

		return $out;
	}
}

if ( ! function_exists( 'term_chain_ids' ) ) {
	/**
	 * Return current term + ancestors, nearest parent first.
	 */
	function term_chain_ids( WP_Term $term ): array {
		$chain_ids = [ (int) $term->term_id ];
		$ancestors = get_ancestors( (int) $term->term_id, $term->taxonomy );

		if ( ! empty( $ancestors ) && is_array( $ancestors ) ) {
			$chain_ids = array_merge( $chain_ids, array_map( 'intval', $ancestors ) );
		}

		return array_values( array_unique( array_filter( $chain_ids ) ) );
	}
}

if ( ! function_exists( 'pick_term_from_chain_by_type' ) ) {
	/**
	 * Pick one term from current term hierarchy using destination_type priority order.
	 */
	function pick_term_from_chain_by_type( WP_Term $term, array $wanted, string $meta_key = TYPE_KEY ): ?WP_Term {
		$chain_ids = term_chain_ids( $term );

		foreach ( $wanted as $target ) {
			$target = trim( (string) $target );
			if ( '' === $target ) {
				continue;
			}

			foreach ( $chain_ids as $id ) {
				$type = get_term_meta( (int) $id, $meta_key, true );

				if ( (string) $type === $target ) {
					$t = get_term( (int) $id, $term->taxonomy );
					if ( $t && ! is_wp_error( $t ) && $t instanceof WP_Term ) {
						return $t;
					}
				}
			}
		}

		return null;
	}
}

/**
 * Shortcode: [location_siblings type="city" tax="destinations"]
 */
add_shortcode(
	'location_siblings',
	function ( $atts ) {
		$atts = shortcode_atts(
			[
				'type' => '',
				'tax'  => LOC_TAX,
			],
			$atts,
			'location_siblings'
		);

		$term = queried_term();
		$type = trim( (string) $atts['type'] );
		$tax  = sanitize_key( (string) $atts['tax'] );

		if ( ! $term || '' === $type || '' === $tax ) {
			return '';
		}

		$terms = get_siblings_by_type( (int) $term->term_id, $type, $tax );
		return render_terms_ul( $terms );
	}
);

/**
 * Shortcode: [location_descendants type="attraction" tax="destinations"]
 */
add_shortcode(
	'location_descendants',
	function ( $atts ) {
		$atts = shortcode_atts(
			[
				'type' => '',
				'tax'  => LOC_TAX,
			],
			$atts,
			'location_descendants'
		);

		$term = queried_term();
		$type = trim( (string) $atts['type'] );
		$tax  = sanitize_key( (string) $atts['tax'] );

		if ( ! $term || '' === $type || '' === $tax ) {
			return '';
		}

		$terms = get_descendants_by_type( (int) $term->term_id, $type, $tax );
		return render_terms_ul( $terms );
	}
);

/**
 * Shortcode: [term_pick show="country,continent" meta_key="destination_type" output="name|link" tax="destinations"]
 */
add_shortcode(
	'term_pick',
	function ( $atts ) {
		$atts = shortcode_atts(
			[
				'show'     => 'country',
				'meta_key' => TYPE_KEY,
				'output'   => 'name',
				'tax'      => LOC_TAX,
			],
			$atts,
			'term_pick'
		);

		// Keep behavior focused on taxonomy archives, but allow any taxonomy by queried object.
		if ( ! is_tax() && ! is_category() && ! is_tag() ) {
			return '';
		}

		$term = queried_term();
		if ( ! $term || empty( $term->term_id ) ) {
			return '';
		}

		$tax = sanitize_key( (string) $atts['tax'] );
		if ( $tax && $term->taxonomy !== $tax ) {
			return '';
		}

		$wanted = array_values( array_filter( array_map( 'trim', explode( ',', (string) $atts['show'] ) ) ) );
		if ( empty( $wanted ) ) {
			return '';
		}

		$match = pick_term_from_chain_by_type( $term, $wanted, sanitize_key( (string) $atts['meta_key'] ) );
		if ( ! $match ) {
			return '';
		}

		if ( 'link' === strtolower( (string) $atts['output'] ) ) {
			$link = get_term_link( $match );
			if ( is_wp_error( $link ) ) {
				return esc_html( $match->name );
			}

			return '<a href="' . esc_url( $link ) . '">' . esc_html( $match->name ) . '</a>';
		}

		return esc_html( $match->name );
	}
);
