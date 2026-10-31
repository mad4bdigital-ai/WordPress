<?php
/**
 * Plugin Name: Tour Rates Dynamic Function
 * Description: Adds Tour Rates label builder to JetEngine Dynamic Functions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'jet-engine/dynamic-functions/functions-list', function( $functions ) {

	$functions['tk_tour_rates_label'] = array(
		'label' => __( 'Tour Rates Label', 'textdomain' ),
		'type'  => 'raw',
		'cb'    => 'tk_tour_rates_dynamic_function_cb',
		'custom_settings' => array(
			'separator' => array(
				'label'   => __( 'Separator', 'textdomain' ),
				'type'    => 'text',
				'default' => ' | ',
			),
			'date_format' => array(
				'label'   => __( 'Date Format', 'textdomain' ),
				'type'    => 'text',
				'default' => 'd M Y',
			),
			'show_program' => array(
				'label'   => __( 'Show Program', 'textdomain' ),
				'type'    => 'switcher',
				'default' => true,
			),
			'show_package' => array(
				'label'   => __( 'Show Package', 'textdomain' ),
				'type'    => 'switcher',
				'default' => true,
			),
			'show_route' => array(
				'label'   => __( 'Show Route', 'textdomain' ),
				'type'    => 'switcher',
				'default' => true,
			),
			'show_day' => array(
				'label'   => __( 'Show Day of Week', 'textdomain' ),
				'type'    => 'switcher',
				'default' => false,
			),
			'show_time' => array(
				'label'   => __( 'Show Day Time', 'textdomain' ),
				'type'    => 'switcher',
				'default' => false,
			),
			'show_dates' => array(
				'label'   => __( 'Show Date Range', 'textdomain' ),
				'type'    => 'switcher',
				'default' => true,
			),
			'show_price' => array(
				'label'   => __( 'Show Lowest Price', 'textdomain' ),
				'type'    => 'switcher',
				'default' => true,
			),
			'price_prefix' => array(
				'label'   => __( 'Price Prefix', 'textdomain' ),
				'type'    => 'text',
				'default' => 'From ',
			),
		),
	);

	return $functions;
} );

if ( ! function_exists( 'tk_trdf_get_term_name' ) ) {
function tk_trdf_get_term_name( $term_id ) {
	$term_id = (int) $term_id;

	if ( ! $term_id ) {
		return '';
	}

	$term = get_term( $term_id );

	if ( ! $term || is_wp_error( $term ) ) {
		return '';
	}

	// Display the translated label when WPML is active, without changing stored IDs.
	if ( has_filter( 'wpml_object_id' ) && ! empty( $term->taxonomy ) ) {
		$lang = apply_filters( 'wpml_current_language', null );

		if ( $lang ) {
			$translated_id = apply_filters( 'wpml_object_id', $term_id, $term->taxonomy, true, $lang );

			if ( $translated_id && (int) $translated_id !== $term_id ) {
				$translated_term = get_term( (int) $translated_id, $term->taxonomy );

				if ( $translated_term && ! is_wp_error( $translated_term ) && ! empty( $translated_term->name ) ) {
					return $translated_term->name;
				}
			}
		}
	}

	return $term->name;
}
}

if ( ! function_exists( 'tk_trdf_format_date' ) ) {
function tk_trdf_format_date( $timestamp, $format = 'd M Y' ) {
	$timestamp = (int) $timestamp;

	if ( ! $timestamp ) {
		return '';
	}

	return wp_date( $format, $timestamp );
}
}

if ( ! function_exists( 'tk_trdf_get_lowest_price' ) ) {
function tk_trdf_get_lowest_price( $post_id ) {
	$sets = array(
		array(
			'regular' => get_post_meta( $post_id, 'single_price', true ),
			'sale'    => get_post_meta( $post_id, 'single_sale_price', true ),
		),
		array(
			'regular' => get_post_meta( $post_id, 'double_price', true ),
			'sale'    => get_post_meta( $post_id, 'double_sale_price', true ),
		),
		array(
			'regular' => get_post_meta( $post_id, 'triple_price', true ),
			'sale'    => get_post_meta( $post_id, 'triple_sale_price', true ),
		),
	);

	$prices = array();

	foreach ( $sets as $set ) {
		$regular = is_numeric( $set['regular'] ) ? (float) $set['regular'] : 0;
		$sale    = is_numeric( $set['sale'] ) ? (float) $set['sale'] : 0;

		if ( $sale > 0 ) {
			$prices[] = $sale;
		} elseif ( $regular > 0 ) {
			$prices[] = $regular;
		}
	}

	if ( empty( $prices ) ) {
		return '';
	}

	$lowest   = min( $prices );
	$currency = get_post_meta( $post_id, 'base_currency', true );
	$amount   = rtrim( rtrim( number_format( $lowest, 2, '.', '' ), '0' ), '.' );

	return trim( $amount . ' ' . $currency );
}
}

/**
 * أهم جزء: التقاط current query object
 */
if ( ! function_exists( 'tk_trdf_get_current_tour_rate_id' ) ) {
function tk_trdf_get_current_tour_rate_id() {

	// 1) JetEngine current object ID
	if (
		function_exists( 'jet_engine' ) &&
		isset( jet_engine()->listings ) &&
		isset( jet_engine()->listings->data ) &&
		method_exists( jet_engine()->listings->data, 'get_current_object_id' )
	) {
		$current_id = (int) jet_engine()->listings->data->get_current_object_id();

		if ( $current_id && 'tour-rates' === get_post_type( $current_id ) ) {
			return $current_id;
		}
	}

	// 2) JetEngine current object
	if (
		function_exists( 'jet_engine' ) &&
		isset( jet_engine()->listings ) &&
		isset( jet_engine()->listings->data ) &&
		method_exists( jet_engine()->listings->data, 'get_current_object' )
	) {
		$current_object = jet_engine()->listings->data->get_current_object();

		if ( $current_object instanceof WP_Post ) {
			if ( 'tour-rates' === $current_object->post_type ) {
				return (int) $current_object->ID;
			}
		}

		if ( is_object( $current_object ) && ! empty( $current_object->ID ) ) {
			$current_id = (int) $current_object->ID;

			if ( $current_id && 'tour-rates' === get_post_type( $current_id ) ) {
				return $current_id;
			}
		}

		if ( is_array( $current_object ) && ! empty( $current_object['ID'] ) ) {
			$current_id = (int) $current_object['ID'];

			if ( $current_id && 'tour-rates' === get_post_type( $current_id ) ) {
				return $current_id;
			}
		}
	}

	// 3) Fallback
	$fallback_id = get_the_ID();

	if ( $fallback_id && 'tour-rates' === get_post_type( $fallback_id ) ) {
		return (int) $fallback_id;
	}

	return 0;
}
}

if ( ! function_exists( 'tk_tour_rates_dynamic_function_cb' ) ) {
function tk_tour_rates_dynamic_function_cb( $func_data ) {

	$post_id = tk_trdf_get_current_tour_rate_id();

	if ( ! $post_id ) {
		return '';
	}

	$settings = isset( $func_data['custom_settings'] ) ? $func_data['custom_settings'] : array();

	$separator    = isset( $settings['separator'] ) ? $settings['separator'] : ' | ';
	$date_format  = isset( $settings['date_format'] ) ? $settings['date_format'] : 'd M Y';
	$price_prefix = isset( $settings['price_prefix'] ) ? $settings['price_prefix'] : 'From ';

	$program = tk_trdf_get_term_name( get_post_meta( $post_id, 'related_program_term_id', true ) );
	$package = tk_trdf_get_term_name( get_post_meta( $post_id, 'related_package_term_id', true ) );
	// Departure details must never be inferred from preselected taxonomy values.
	// The shared governance source verifies opt-in and resolves Tour origin.
	$trusted = class_exists('MAD4B_Rate_Departure_Details_V1')
		&& MAD4B_Rate_Departure_Details_V1::is_enabled( $post_id );
	$route = $trusted ? MAD4B_Rate_Departure_Details_V1::origin( $post_id ) : '';
	$day   = $trusted ? MAD4B_Rate_Departure_Details_V1::actual_day_time( $post_id, 'day' ) : '';
	$time  = $trusted ? MAD4B_Rate_Departure_Details_V1::actual_day_time( $post_id, 'time' ) : '';

	$start = tk_trdf_format_date( get_post_meta( $post_id, 'tour_rate_start_date', true ), $date_format );
	$end   = tk_trdf_format_date( get_post_meta( $post_id, 'tour_rate_end_date', true ), $date_format );

	$date_range = '';
	if ( $start && $end ) {
		$date_range = $start . ' → ' . $end;
	} elseif ( $start ) {
		$date_range = $start;
	} elseif ( $end ) {
		$date_range = $end;
	}

	$lowest_price = tk_trdf_get_lowest_price( $post_id );

	$parts = array();

	if ( ! empty( $settings['show_program'] ) && $program ) {
		$parts[] = $program;
	}

	if ( ! empty( $settings['show_package'] ) && $package ) {
		$parts[] = $package;
	}

	if ( ! empty( $settings['show_route'] ) && $route ) {
		$parts[] = $route;
	}

	if ( ! empty( $settings['show_day'] ) && $day ) {
		$parts[] = $day;
	}

	if ( ! empty( $settings['show_time'] ) && $time ) {
		$parts[] = $time;
	}

	if ( ! empty( $settings['show_dates'] ) && $date_range ) {
		$parts[] = $date_range;
	}

	if ( ! empty( $settings['show_price'] ) && $lowest_price ) {
		$parts[] = $price_prefix . $lowest_price;
	}

	$label = implode( $separator, array_filter( $parts ) );

	if ( '' === $label ) {
		return '';
	}

	return '<span class="tk-tour-rate-label">' . esc_html( $label ) . '</span>';
}
}