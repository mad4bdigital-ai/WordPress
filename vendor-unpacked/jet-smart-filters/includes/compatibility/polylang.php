<?php
/**
 * Polylang compatibility filters and actions
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Define Jet_Smart_Filters_Compatibility_Polylang class
 */
class Jet_Smart_Filters_Compatibility_Polylang {

	/**
	 * Constructor for the class
	 */
	public function __construct() {

		add_filter( 'rewrite_rules_array', array( $this, 'register_rewrites' ) );
		add_filter( 'jet-smart-filters/render/set-query-var', array( $this, 'translate_permalink_tax_query' ), 10, 3 );
		add_filter( 'jet-smart-filters/widgets/apply-button/redirect-path', array( $this, 'translate_redirect_path' ), 10, 2 );
	}

	/**
	 * Register language-prefixed copies of JetSmartFilters rewrite rules.
	 */
	public function register_rewrites( $rules ) {

		if ( empty( $rules ) || ! is_array( $rules ) ) {
			return $rules;
		}

		$language_prefixes = $this->get_language_prefixes();

		if ( empty( $language_prefixes ) ) {
			return $rules;
		}

		$translated_rules = array();

		foreach ( $rules as $regex => $query ) {
			if ( false === strpos( $regex, 'jsf/' ) ) {
				continue;
			}

			foreach ( $language_prefixes as $language_code => $language_prefix ) {
				if ( 0 === strpos( $regex, $language_prefix . '/' ) ) {
					continue;
				}

				$translated_rules[ $language_prefix . '/' . $regex ] = $this->add_language_to_rewrite_query( $query, $language_code );
			}
		}

		return $translated_rules + $rules;
	}

	/**
	 * Translate Apply Button redirect path to the current language.
	 */
	public function translate_redirect_path( $redirect_path, $context = array() ) {

		if ( empty( $redirect_path ) || ! is_string( $redirect_path ) ) {
			return $redirect_path;
		}

		$language_code = $this->get_current_language();

		if ( ! $language_code ) {
			return $redirect_path;
		}

		$redirect_url = jet_smart_filters()->utils->normalize_redirect_path_to_url( $redirect_path );

		if ( ! $redirect_url ) {
			return $redirect_path;
		}

		$post_id = url_to_postid( strtok( $redirect_url, '?#' ) );

		if ( ! $post_id ) {
			return $redirect_path;
		}

		$translated_post_id = pll_get_post( $post_id, $language_code );

		if ( ! $translated_post_id ) {
			return jet_smart_filters()->utils->get_relative_redirect_path( $redirect_url );
		}

		$translated_url = get_permalink( $translated_post_id );

		if ( ! $translated_url ) {
			return $redirect_path;
		}

		$translated_url = jet_smart_filters()->utils->append_url_query_and_fragment( $translated_url, $redirect_url );

		if ( $this->is_reload_redirect_with_current_language_query( $context, $language_code ) ) {
			$translated_url = $this->remove_language_query_arg( $translated_url, $language_code );
		}

		return jet_smart_filters()->utils->get_relative_redirect_path( $translated_url );
	}

	/**
	 * Translate taxonomy values from permalink URL to the current language.
	 */
	public function translate_permalink_tax_query( $query_var_value, $query_var ) {

		if ( 'tax' !== $query_var || ! is_string( $query_var_value ) ) {
			return $query_var_value;
		}

		$language_code = $this->get_current_language();

		if ( ! $language_code ) {
			return $query_var_value;
		}

		$url_symbols = jet_smart_filters()->data->url_symbol;
		$items       = explode( $url_symbols['items_separator'], $query_var_value );

		foreach ( $items as $index => $item ) {
			$items[ $index ] = $this->translate_tax_query_item( $item, $language_code, $url_symbols );
		}

		return implode( $url_symbols['items_separator'], $items );
	}

	/**
	 * Get language URL prefixes used by Polylang rewrite rules.
	 */
	public function get_language_prefixes() {

		$language_prefixes = array();
		$languages         = pll_languages_list( array( 'fields' => 'slug' ) );

		if ( empty( $languages ) || ! is_array( $languages ) ) {
			return $language_prefixes;
		}

		foreach ( $languages as $language_code ) {
			$home_url = pll_home_url( $language_code );
			$path     = wp_parse_url( $home_url, PHP_URL_PATH );
			$path     = $path ? trim( $path, '/' ) : '';
			$path     = jet_smart_filters()->utils->strip_home_path_from_redirect_path( $path );

			if ( '' === $path ) {
				continue;
			}

			$language_prefixes[ $language_code ] = preg_quote( $path );
		}

		return $language_prefixes;
	}

	/**
	 * Add Polylang language query var to a rewrite query.
	 */
	public function add_language_to_rewrite_query( $query, $language_code ) {

		if ( false === strpos( $query, '?' ) || false !== strpos( $query, 'lang=' ) ) {
			return $query;
		}

		list( $base, $query_args ) = explode( '?', $query, 2 );

		return $base . '?lang=' . rawurlencode( $language_code ) . '&' . $query_args;
	}

	/**
	 * Translate a single taxonomy query URL item.
	 */
	public function translate_tax_query_item( $item, $language_code, $url_symbols ) {

		$key_value_position = strpos( $item, $url_symbols['key_value'] );

		if ( false === $key_value_position ) {
			return $item;
		}

		$query_var = substr( $item, 0, $key_value_position );
		$values    = substr( $item, $key_value_position + strlen( $url_symbols['key_value'] ) );
		$taxonomy  = explode( $url_symbols['var_suffix'], $query_var, 2 );
		$taxonomy  = $taxonomy[0];

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $item;
		}

		$values = explode( $url_symbols['value_separator'], $values );

		foreach ( $values as $index => $value ) {
			$values[ $index ] = $this->translate_term_value( $value, $taxonomy, $language_code );
		}

		return $query_var . $url_symbols['key_value'] . implode( $url_symbols['value_separator'], $values );
	}

	/**
	 * Translate term ID or slug to the current language equivalent.
	 */
	public function translate_term_value( $value, $taxonomy, $language_code ) {

		if ( '' === $value ) {
			return $value;
		}

		if ( 'slug' === jet_smart_filters()->settings->url_taxonomy_term_name ) {
			$term = get_term_by( 'slug', $value, $taxonomy );

			if ( ! $term || is_wp_error( $term ) ) {
				return $value;
			}

			$translated_term_id = pll_get_term( $term->term_id, $language_code );

			if ( ! $translated_term_id ) {
				return $value;
			}

			$translated_term = get_term( $translated_term_id, $taxonomy );

			if ( ! $translated_term || is_wp_error( $translated_term ) ) {
				return $value;
			}

			return $translated_term->slug;
		}

		if ( ! is_numeric( $value ) ) {
			return $value;
		}

		$translated_term_id = pll_get_term( absint( $value ), $language_code );

		return $translated_term_id ? $translated_term_id : $value;
	}

	/**
	 * Get current Polylang language code.
	 */
	public function get_current_language() {

		if ( ! function_exists( 'pll_current_language' ) ) {
			return false;
		}

		return pll_current_language( 'slug' );
	}

	/**
	 * Remove current Polylang language query argument from URL.
	 */
	public function remove_language_query_arg( $url, $language_code ) {

		$query = wp_parse_url( $url, PHP_URL_QUERY );

		if ( ! $query ) {
			return $url;
		}

		wp_parse_str( $query, $query_args );

		if ( empty( $query_args['lang'] ) || $language_code !== $query_args['lang'] ) {
			return $url;
		}

		return remove_query_arg( 'lang', $url );
	}

	/**
	 * Check if reload redirect already carries current Polylang language in page URL.
	 */
	public function is_reload_redirect_with_current_language_query( $context, $language_code ) {

		if ( empty( $context['apply_type'] ) || 'reload' !== $context['apply_type'] ) {
			return false;
		}

		$request_language_code = jet_smart_filters()->data->get_request_var( 'lang' );

		return $request_language_code && $language_code === $request_language_code;
	}
}
