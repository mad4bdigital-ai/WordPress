<?php

/**
 * Plugin Name: Restricted REST Meta Exposure for JetEngine + Elementor + WPML
 * Description: Restricted REST exposure for selected public post meta, taxonomy meta, WPML import fields, and explicit Elementor page fields.
 * Version: 1.2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensure public post types and taxonomies are REST-enabled.
 */
add_action( 'init', function () {
	global $wp_post_types, $wp_taxonomies;

	foreach ( $wp_post_types as $post_type => $obj ) {
		if ( empty( $obj->public ) ) {
			continue;
		}

		$wp_post_types[ $post_type ]->show_in_rest = true;

		if ( post_type_exists( $post_type ) && ! post_type_supports( $post_type, 'custom-fields' ) ) {
			add_post_type_support( $post_type, 'custom-fields' );
		}
	}

	foreach ( $wp_taxonomies as $taxonomy => $obj ) {
		if ( empty( $obj->public ) ) {
			continue;
		}

		$wp_taxonomies[ $taxonomy ]->show_in_rest = true;
	}
}, 20 );

/**
 * Shared capability callback.
 */
function areg_rest_meta_can_edit() {
	return current_user_can( 'edit_posts' );
}

/**
 * Build a safe REST schema for a meta type.
 *
 * WordPress requires array meta exposed in REST to define schema.items.
 */
function areg_build_rest_schema( $meta_type ) {
	switch ( $meta_type ) {
		case 'array':
			return array(
				'type'  => 'array',
				'items' => array(
					'type' => 'string',
				),
			);

		case 'object':
			return array(
				'type'                 => 'object',
				'additionalProperties' => true,
			);

		default:
			return array(
				'type' => $meta_type,
			);
	}
}

/**
 * Explicit Elementor exposure.
 *
 * Keep this narrow and stable.
 * Do not rely on type inference for these fields.
 */
add_action( 'rest_api_init', function () {
	$elementor_meta = array(
		'_elementor_edit_mode' => array(
			'type'   => 'string',
			'single' => true,
			'schema' => array(
				'type' => 'string',
			),
		),
		'_elementor_template_type' => array(
			'type'   => 'string',
			'single' => true,
			'schema' => array(
				'type' => 'string',
			),
		),
		'_elementor_data' => array(
			// Stored as JSON string in many Elementor installs.
			'type'   => 'string',
			'single' => true,
			'schema' => array(
				'type' => 'string',
			),
		),
		'_elementor_page_settings' => array(
			'type'   => 'object',
			'single' => true,
			'schema' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
			),
		),
		'_elementor_conditions' => array(
			'type'   => 'array',
			'single' => true,
			'schema' => array(
				'type'  => 'array',
				'items' => array(
					'type' => 'object',
				),
			),
		),
		'_elementor_version' => array(
			'type'   => 'string',
			'single' => true,
			'schema' => array(
				'type' => 'string',
			),
		),
	);

	foreach ( $elementor_meta as $meta_key => $config ) {
		register_post_meta(
			'page',
			$meta_key,
			array(
				'single'        => $config['single'],
				'type'          => $config['type'],
				'show_in_rest'  => array(
					'schema' => $config['schema'],
				),
				'auth_callback' => 'areg_rest_meta_can_edit',
			)
		);
	}
}, 20 );

/**
 * Restricted generic meta exposure.
 *
 * Only expose:
 * - approved private keys
 * - non-private keys that are explicitly allowed or match safe prefixes
 *
 * This is intentionally much narrower than "expose everything discoverable".
 */
add_action( 'rest_api_init', function () {
	global $wpdb, $wp_post_types, $wp_taxonomies;

	/**
	 * Explicit private keys allowed in REST.
	 */
	$allowed_private_keys = array(
		'_description',

		// WPML import fields
		'_wpml_import_translation_group',
		'_wpml_import_language_code',
		'_wpml_import_source_language_code',
		'_wpml_import_after_process_post_status',
	);

	/**
	 * Explicitly handled elsewhere or always excluded.
	 */
	$excluded_keys = array(
		'_edit_lock',
		'_edit_last',
		'_thumbnail_id',
		'_wp_old_slug',
		'_wp_page_template',
		'_elementor_css',
		'_elementor_page_assets',
		'_elementor_controls_usage',

		// Explicit Elementor keys are registered separately above.
		'_elementor_edit_mode',
		'_elementor_template_type',
		'_elementor_data',
		'_elementor_page_settings',
		'_elementor_conditions',
		'_elementor_version',
	);

	/**
	 * Optional: allow selected non-private prefixes.
	 * Adjust to your environment.
	 */
	$allowed_public_prefixes = array(
		'jet_',
		'rs_',
		'rank_math_',
		'custom_',
		'ast-',
		'ast_',
	);

	/**
	 * Optional: explicit non-private keys.
	 */
	$allowed_public_keys = array(
		'copied_media_ids',
		'referenced_media_ids',
		'litespeed_vpi_list',
		'litespeed_vpi_list_mobile',
		'rate_review',
	);

	$infer_meta_type = function( $raw_value ) {
		if ( is_bool( $raw_value ) ) {
			return 'boolean';
		}

		if ( is_int( $raw_value ) ) {
			return 'integer';
		}

		if ( is_float( $raw_value ) ) {
			return 'number';
		}

		if ( is_array( $raw_value ) ) {
			return 'array';
		}

		if ( is_object( $raw_value ) ) {
			return 'object';
		}

		if ( is_numeric( $raw_value ) && is_string( $raw_value ) ) {
			return ( strpos( $raw_value, '.' ) !== false ) ? 'number' : 'integer';
		}

		return 'string';
	};

	$is_allowed_public_key = function( $meta_key ) use ( $allowed_public_keys, $allowed_public_prefixes ) {
		if ( in_array( $meta_key, $allowed_public_keys, true ) ) {
			return true;
		}

		foreach ( $allowed_public_prefixes as $prefix ) {
			if ( strpos( $meta_key, $prefix ) === 0 ) {
				return true;
			}
		}

		return false;
	};

	/**
	 * Register post meta.
	 */
	foreach ( $wp_post_types as $post_type => $obj ) {
		if ( empty( $obj->public ) ) {
			continue;
		}


		// Staging inventory evidence: attachment has 38 discovered meta
		// keys but zero permitted by the strict exposure policy. Avoid
		// the otherwise wasted DISTINCT postmeta/attachment join on
		// every REST bootstrap. Core /wp/v2/media remains untouched.
		if ( 'attachment' === $post_type ) {
			continue;
		}

		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"
				SELECT DISTINCT pm.meta_key
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s
				",
				$post_type
			)
		);

		if ( empty( $keys ) ) {
			continue;
		}

		foreach ( $keys as $meta_key ) {
			$is_private = strpos( $meta_key, '_' ) === 0;

			if ( in_array( $meta_key, $excluded_keys, true ) ) {
				continue;
			}

			if ( $is_private ) {
				if ( ! in_array( $meta_key, $allowed_private_keys, true ) ) {
					continue;
				}
			} else {
				if ( ! $is_allowed_public_key( $meta_key ) ) {
					continue;
				}
			}

			$sample_post_id = $wpdb->get_var(
				$wpdb->prepare(
					"
					SELECT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
					WHERE p.post_type = %s
					  AND pm.meta_key = %s
					LIMIT 1
					",
					$post_type,
					$meta_key
				)
			);

			$sample_value = $sample_post_id ? get_post_meta( (int) $sample_post_id, $meta_key, true ) : '';
			$meta_type    = $infer_meta_type( $sample_value );

			register_post_meta(
				$post_type,
				$meta_key,
				array(
					'single'        => true,
					'type'          => $meta_type,
					'show_in_rest'  => array(
						'schema' => areg_build_rest_schema( $meta_type ),
					),
					'auth_callback' => 'areg_rest_meta_can_edit',
				)
			);
		}
	}

	/**
	 * Register term meta.
	 */
	foreach ( $wp_taxonomies as $taxonomy => $obj ) {
		if ( empty( $obj->public ) ) {
			continue;
		}

		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"
				SELECT DISTINCT tm.meta_key
				FROM {$wpdb->termmeta} tm
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
				WHERE tt.taxonomy = %s
				",
				$taxonomy
			)
		);

		if ( empty( $keys ) ) {
			continue;
		}

		foreach ( $keys as $meta_key ) {
			$is_private = strpos( $meta_key, '_' ) === 0;

			if ( in_array( $meta_key, $excluded_keys, true ) ) {
				continue;
			}

			if ( $is_private ) {
				if ( ! in_array( $meta_key, $allowed_private_keys, true ) ) {
					continue;
				}
			} else {
				if ( ! $is_allowed_public_key( $meta_key ) ) {
					continue;
				}
			}

			$sample_term_id = $wpdb->get_var(
				$wpdb->prepare(
					"
					SELECT tt.term_id
					FROM {$wpdb->term_taxonomy} tt
					INNER JOIN {$wpdb->termmeta} tm ON tm.term_id = tt.term_id
					WHERE tt.taxonomy = %s
					  AND tm.meta_key = %s
					LIMIT 1
					",
					$taxonomy,
					$meta_key
				)
			);

			$sample_value = $sample_term_id ? get_term_meta( (int) $sample_term_id, $meta_key, true ) : '';
			$meta_type    = $infer_meta_type( $sample_value );

			register_term_meta(
				$taxonomy,
				$meta_key,
				array(
					'single'        => true,
					'type'          => $meta_type,
					'show_in_rest'  => array(
						'schema' => areg_build_rest_schema( $meta_type ),
					),
					'auth_callback' => 'areg_rest_meta_can_edit',
				)
			);
		}
	}
}, 30 );