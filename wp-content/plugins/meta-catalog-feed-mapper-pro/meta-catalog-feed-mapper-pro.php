<?php
/*
Plugin Name: MAD4B Distribution - Catalog Feed Studio
Description: Company-ready catalog mapping for tours, destinations, CPTs, JetEngine, WPML and MAD4B distribution channels.
Version: 2.6.1
Author: MAD4B Digital AI
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MCFM_OPTION', 'mcfm_feeds_v2' );
define( 'MCFM_MENU_SLUG', 'mcfm_feeds_v2' );
define( 'MCFM_BATCH_SIZE', 250 );

register_activation_hook( __FILE__, 'mcfm_activate' );
register_deactivation_hook( __FILE__, 'mcfm_deactivate' );

function mcfm_activate() {
	mcfm_register_rewrites();
	flush_rewrite_rules( false );
}

function mcfm_deactivate() {
	flush_rewrite_rules( false );
}

add_action( 'init', 'mcfm_register_rewrites' );
function mcfm_register_rewrites() {
	add_rewrite_tag( '%mcfm_feed_slug%', '([^&]+)' );
	add_rewrite_tag( '%mcfm_feed_format%', '(csv|tsv|xml|rss|atom|xlsx)' );
	add_rewrite_tag( '%mcfm_feed_lang%', '([A-Za-z_-]+)' );

	add_rewrite_rule(
		'^meta-feed/([^/]+)\.(csv|tsv|xml|rss|atom|xlsx)$',
		'index.php?mcfm_feed_slug=$matches[1]&mcfm_feed_format=$matches[2]',
		'top'
	);

	add_rewrite_rule(
		'^meta-feed/([^/]+)/([A-Za-z_-]+)\.(csv|tsv|xml|rss|atom|xlsx)$',
		'index.php?mcfm_feed_slug=$matches[1]&mcfm_feed_lang=$matches[2]&mcfm_feed_format=$matches[3]',
		'top'
	);
}

add_filter( 'query_vars', function( $vars ) {
	$vars[] = 'mcfm_feed_slug';
	$vars[] = 'mcfm_feed_format';
	$vars[] = 'mcfm_feed_lang';
	return $vars;
} );

function mcfm_get_feeds() {
	$feeds = get_option( MCFM_OPTION, array() );
	return is_array( $feeds ) ? $feeds : array();
}

/** Shared revision across Studio and Advanced Mapping editor submissions. */
function mcfm_feed_list_revision( $feeds = null ) {
    if ( null === $feeds ) $feeds = mcfm_get_feeds();
    return hash( 'sha256', wp_json_encode( array_values( (array) $feeds ), JSON_UNESCAPED_SLASHES ) );
}
function mcfm_feed_editor_lock_name() {
    global $wpdb;
    return 'mad4b_mcfm_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 32 );
}
function mcfm_feed_editor_lock() {
    global $wpdb;
    $result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', mcfm_feed_editor_lock_name() ) );
    return (int) $result === 1 ? true : new WP_Error( 'feed_edit_busy', 'Another catalog editor is saving. Please retry.' );
}
function mcfm_feed_editor_unlock() {
    global $wpdb;
    $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', mcfm_feed_editor_lock_name() ) );
}

function mcfm_update_feeds( $feeds ) {
	update_option( MCFM_OPTION, array_values( $feeds ), false );
}

function mcfm_get_public_post_types() {
	$post_types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $post_types['attachment'] );
	return $post_types;
}

function mcfm_get_public_taxonomies() {
	$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
	unset( $taxonomies['post_format'] );
	return $taxonomies;
}

function mcfm_default_feed() {
	return array(
		'label'            => '',
		'slug'             => '',
		'object_type'      => 'post_type',
		'object_key'       => 'post',
		'default_format'   => 'csv',
		'xml_variant'      => 'rss',
		'language_mode'    => 'all',
		'status'           => 'publish',
		'hide_empty_terms' => 0,
		'delimiter'        => ',',
		'mapping'          => array(),
	);
}

function mcfm_normalize_feed( $feed ) {
	$defaults = mcfm_default_feed();
	$feed     = wp_parse_args( is_array( $feed ) ? $feed : array(), $defaults );

	if ( empty( $feed['mapping'] ) || ! is_array( $feed['mapping'] ) ) {
		$feed['mapping'] = array();
	} else {
		$feed['mapping'] = array_map( 'mcfm_sanitize_mapping_row', $feed['mapping'] );
	}

	return $feed;
}

function mcfm_sanitize_mapping_row( $row ) {
	return array(
		'column'             => sanitize_text_field( $row['column'] ?? '' ),
		'source_type'        => sanitize_text_field( $row['source_type'] ?? 'meta' ),
		'source'             => sanitize_text_field( $row['source'] ?? '' ),
		'extra'              => sanitize_text_field( $row['extra'] ?? '' ),
		'required_non_empty' => empty( $row['required_non_empty'] ) ? 0 : 1,
	);
}

function mcfm_is_empty_feed_value( $value ) {
	if ( is_array( $value ) ) {
		$value = implode( '', array_map( 'strval', $value ) );
	}

	if ( is_object( $value ) ) {
		$value = wp_json_encode( $value );
	}

	$value = trim( wp_strip_all_tags( (string) $value ) );
	return '' === $value;
}

function mcfm_row_passes_required_mapping( $feed, $row ) {
	foreach ( $feed['mapping'] as $map ) {
		if ( empty( $map['required_non_empty'] ) || empty( $map['column'] ) ) {
			continue;
		}

		$column = $map['column'];
		$value  = array_key_exists( $column, $row ) ? $row[ $column ] : '';
		if ( mcfm_is_empty_feed_value( $value ) ) {
			return false;
		}
	}

	return true;
}

function mcfm_get_feed_by_slug( $slug ) {
	foreach ( mcfm_get_feeds() as $index => $feed ) {
		if ( ! empty( $feed['slug'] ) && $feed['slug'] === $slug ) {
			return array( 'index' => $index, 'feed' => mcfm_normalize_feed( $feed ) );
		}
	}
	return null;
}

function mcfm_is_wpml_active() {
	return has_filter( 'wpml_active_languages' ) || defined( 'ICL_SITEPRESS_VERSION' );
}

function mcfm_get_default_language() {
	if ( mcfm_is_wpml_active() ) {
		$default = apply_filters( 'wpml_default_language', null );
		if ( ! empty( $default ) ) {
			return $default;
		}
	}
	$locale = get_locale();
	return $locale ? strtolower( str_replace( '_', '-', $locale ) ) : 'default';
}

function mcfm_get_active_languages() {
	if ( ! mcfm_is_wpml_active() ) {
		return array(
			mcfm_get_default_language() => array(
				'code'    => mcfm_get_default_language(),
				'native_name' => mcfm_get_default_language(),
				'default_locale' => get_locale(),
				'active'  => true,
			),
		);
	}

	$languages = apply_filters(
		'wpml_active_languages',
		null,
		array(
			'skip_missing' => 0,
			'orderby'      => 'code',
			'order'        => 'ASC',
		)
	);

	return is_array( $languages ) && ! empty( $languages ) ? $languages : array();
}

function mcfm_switch_language( $lang = null ) {
	if ( ! mcfm_is_wpml_active() ) {
		return;
	}
	do_action( 'wpml_switch_language', $lang );
}

function mcfm_resolve_requested_language( $feed, $requested_lang ) {
	$languages = mcfm_get_active_languages();

	if ( empty( $requested_lang ) ) {
		if ( 'specific' === $feed['language_mode'] && ! empty( $feed['language_code'] ) ) {
			return $feed['language_code'];
		}
		if ( 'default' === $feed['language_mode'] ) {
			return mcfm_get_default_language();
		}
		if ( 'all' === $feed['language_mode'] && mcfm_is_wpml_active() ) {
			return mcfm_get_default_language();
		}
		return '';
	}

	return sanitize_text_field( $requested_lang );
}

function mcfm_get_supported_formats() {
	return array(
		'csv'  => 'CSV',
		'tsv'  => 'TSV',
		'xml'  => 'XML',
		'rss'  => 'RSS XML',
		'atom' => 'Atom XML',
		'xlsx' => 'XLSX',
	);
}

function mcfm_get_mapping_types( $feed ) {
	$types = array(
		'object_field'   => 'Object field',
		'meta'           => 'Meta key',
		'static'         => 'Static value',
		'language_code'  => 'Language code',
		'computed'       => 'Computed value',
	);

	if ( 'post_type' === $feed['object_type'] ) {
		$types['featured_image'] = 'Featured image';
		$types['taxonomy_terms'] = 'Taxonomy terms';
	}

	return $types;
}

function mcfm_get_object_fields_help( $feed ) {
	if ( 'taxonomy' === $feed['object_type'] ) {
		return 'name, slug, description, term_id, taxonomy, parent, parent_name, count, link';
	}
	return 'title, excerpt, content, permalink, id, slug, date, modified, status, author_id';
}

function mcfm_get_computed_help( $feed ) {
	if ( 'taxonomy' === $feed['object_type'] ) {
		return 'full_slug, smart_destination_id, smart_destination_image_url, smart_destination_image_json, smart_destination_address_json, smart_destination_address_addr1, smart_destination_address_city, smart_destination_address_region, smart_destination_address_region_safe, smart_destination_address_country, smart_destination_address_postal_code, smart_destination_type, smart_google_destination_type, smart_meta_destination_type_json, smart_google_place_types, smart_destination_latitude, smart_destination_longitude';
	}
	return 'post_type';
}

function mcfm_get_object_field_options( $feed ) {
	if ( 'taxonomy' === $feed['object_type'] ) {
		return array(
			'name'        => 'Name',
			'slug'        => 'Slug',
			'description' => 'Description',
			'term_id'     => 'Term ID',
			'taxonomy'    => 'Taxonomy',
			'parent'      => 'Parent term ID',
			'parent_name' => 'Parent term name',
			'count'       => 'Count',
			'link'        => 'Term link',
		);
	}

	return array(
		'title'     => 'Title',
		'excerpt'   => 'Excerpt',
		'content'   => 'Content',
		'permalink' => 'Permalink',
		'id'        => 'ID',
		'slug'      => 'Slug',
		'date'      => 'Date',
		'modified'  => 'Modified',
		'status'    => 'Status',
		'author_id' => 'Author ID',
	);
}

function mcfm_get_computed_options( $feed ) {
	if ( 'taxonomy' === $feed['object_type'] ) {
		$options = array(
			'full_slug'                 => 'Full slug path',
			'smart_destination_id'      => 'Smart Destination ID: place ID or stable term ID',
			'smart_destination_image'           => 'Deprecated alias: image URL',
			'smart_destination_image_url'       => 'Smart Destination Image URL: use with image[0].url',
			'smart_destination_image_json'      => 'Smart Destination Image JSON: use with image column',
			'smart_destination_address'         => 'Deprecated alias: address text. Do not use with Meta address column.',
			'smart_destination_address_json'    => 'Smart Destination Address JSON: use only with address column if you do not use address.* flat fields',
			'smart_destination_address_city'    => 'Smart Destination Address City: use with address.city',
			'smart_destination_address_region'  => 'Smart Destination Address Region: safe region/governorate; never continent',
			'smart_destination_address_region_safe' => 'Smart Destination Address Region Safe: alias for address.region',
			'smart_destination_address_country' => 'Smart Destination Address Country: use with address.country',
			'smart_destination_address_addr1'   => 'Smart Destination Address Addr1: use with address.addr1',
			'smart_destination_address_street'  => 'Deprecated alias for addr1; do not use address.street_address for Meta',
			'smart_destination_address_postal_code' => 'Smart Destination Postal Code: use with address.postal_code',
			'smart_destination_type'            => 'Smart Destination Type JSON: Meta-safe JSON array for type column, e.g. [\"city\"]',
			'smart_google_destination_type'     => 'Smart Google Destination Type JSON: maps Google place types to Meta-safe JSON array for type column',
			'smart_meta_destination_type_json'  => 'Smart Meta Destination Type JSON: explicit alias for type column, e.g. [\"beach\"]',
			'smart_google_place_types'          => 'Smart Google Place Types: raw normalized Google place type list for diagnostics only',
			'smart_destination_latitude'        => 'Smart Destination Latitude: normalized decimal number',
			'smart_destination_longitude'       => 'Smart Destination Longitude: normalized decimal number',
		);
		return apply_filters( 'mad4b/mcfm/computed_options', $options, $feed );
	}

	$options = array(
		'post_type' => 'Post type',
	);
	return apply_filters( 'mad4b/mcfm/computed_options', $options, $feed );
}

function mcfm_get_taxonomy_term_extra_options() {
	return array(
		''      => 'Names',
		'slugs' => 'Slugs',
		'ids'   => 'IDs',
		'first' => 'First term name',
		'links' => 'Links',
	);
}

function mcfm_get_featured_image_extra_options() {
	return array(
		'full'      => 'Full',
		'large'     => 'Large',
		'medium'    => 'Medium',
		'thumbnail' => 'Thumbnail',
	);
}

function mcfm_get_post_taxonomy_options( $feed ) {
	if ( 'post_type' !== $feed['object_type'] ) {
		return array();
	}

	$taxes = get_object_taxonomies( $feed['object_key'], 'objects' );
	$options = array();
	foreach ( $taxes as $tax ) {
		$options[ $tax->name ] = $tax->labels->singular_name . ' (' . $tax->name . ')';
	}
	return $options;
}

function mcfm_get_discovery_cache_key( $feed ) {
	return 'mcfm_keys_' . md5( $feed['object_type'] . '|' . $feed['object_key'] );
}

function mcfm_discover_meta_keys( $feed, $force = false ) {
	global $wpdb;

	$cache_key = mcfm_get_discovery_cache_key( $feed );
	if ( ! $force ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$items = array();

	if ( 'taxonomy' === $feed['object_type'] ) {
		$term_ids = get_terms(
			array(
				'taxonomy'   => $feed['object_key'],
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 500,
				'orderby'    => 'term_id',
				'order'      => 'DESC',
			)
		);

		if ( ! is_wp_error( $term_ids ) && ! empty( $term_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
			$sql = $wpdb->prepare(
				"SELECT meta_key, COUNT(*) AS usage_count, MIN(CAST(meta_value AS CHAR(255))) AS sample_value
				FROM {$wpdb->termmeta}
				WHERE term_id IN ($placeholders)
				GROUP BY meta_key
				ORDER BY usage_count DESC, meta_key ASC
				LIMIT 300",
				$term_ids
			);
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$key = (string) $row['meta_key'];
				if ( '' === $key ) {
					continue;
				}
				$items[] = array(
					'key'    => $key,
					'count'  => (int) $row['usage_count'],
					'sample' => mb_substr( wp_strip_all_tags( (string) $row['sample_value'] ), 0, 80 ),
				);
			}
		}
	} else {
		$post_ids = get_posts(
			array(
				'post_type'              => $feed['object_key'],
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => 500,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);

		if ( ! empty( $post_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			$sql = $wpdb->prepare(
				"SELECT meta_key, COUNT(*) AS usage_count, MIN(CAST(meta_value AS CHAR(255))) AS sample_value
				FROM {$wpdb->postmeta}
				WHERE post_id IN ($placeholders)
				GROUP BY meta_key
				ORDER BY usage_count DESC, meta_key ASC
				LIMIT 300",
				$post_ids
			);
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$key = (string) $row['meta_key'];
				if ( '' === $key ) {
					continue;
				}
				$items[] = array(
					'key'    => $key,
					'count'  => (int) $row['usage_count'],
					'sample' => mb_substr( wp_strip_all_tags( (string) $row['sample_value'] ), 0, 80 ),
				);
			}
		}
	}

	set_transient( $cache_key, $items, 6 * HOUR_IN_SECONDS );
	return $items;
}

function mcfm_clear_meta_discovery_cache( $feed ) {
	delete_transient( mcfm_get_discovery_cache_key( $feed ) );
}

function mcfm_render_source_select_options( $options, $selected, $placeholder = '— Select —' ) {
	$html = '<option value="">' . esc_html( $placeholder ) . '</option>';
	foreach ( $options as $value => $label ) {
		$html .= '<option value="' . esc_attr( $value ) . '" ' . selected( (string) $selected, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	return $html;
}

function mcfm_get_headers( $feed ) {
	$headers = array();
	foreach ( $feed['mapping'] as $map ) {
		if ( ! empty( $map['column'] ) ) {
			$headers[] = $map['column'];
		}
	}
	return $headers;
}

function mcfm_csv_delimiter_for_format( $feed, $format ) {
	if ( 'tsv' === $format ) {
		return "\t";
	}
	return isset( $feed['delimiter'] ) && "\t" === $feed['delimiter'] ? "\t" : ',';
}

function mcfm_stream_headers_for_download( $filename, $content_type, $length = 0 ) {
	if ( headers_sent() ) {
		return;
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );
	header( 'Content-Type: ' . $content_type );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Transfer-Encoding: binary' );
	if ( $length > 0 ) {
		header( 'Content-Length: ' . (int) $length );
	}
}

function mcfm_disable_output_buffering() {
	while ( ob_get_level() > 0 ) {
		@ob_end_clean();
	}
	if ( function_exists( 'apache_setenv' ) ) {
		@apache_setenv( 'no-gzip', '1' );
	}
	@ini_set( 'zlib.output_compression', 'Off' );
	@ini_set( 'output_buffering', 'Off' );
	@ini_set( 'memory_limit', '-1' );
	@set_time_limit( 0 );
}

function mcfm_feed_url( $feed, $format = '', $lang = '' ) {
	$format = $format ? $format : $feed['default_format'];
	$slug   = $feed['slug'];
	$path   = '/meta-feed/' . rawurlencode( $slug );

	if ( ! empty( $lang ) ) {
		$path .= '/' . rawurlencode( $lang );
	}

	$path .= '.' . rawurlencode( $format );

	return home_url( $path );
}

function mcfm_term_link_safe( $term ) {
	$link = get_term_link( $term );
	return is_wp_error( $link ) ? '' : $link;
}

function mcfm_resolve_meta_value( $object_type, $object, $meta_key ) {
	if ( ! $meta_key ) {
		return '';
	}

	if ( 'taxonomy' === $object_type ) {
		$value = get_term_meta( $object->term_id, $meta_key, true );
	} else {
		$value = get_post_meta( $object->ID, $meta_key, true );
	}

	if ( is_array( $value ) ) {
		if ( isset( $value['url'] ) ) {
			return $value['url'];
		}
		if ( isset( $value[0] ) && ! is_array( $value[0] ) ) {
			return implode( '|', array_map( 'strval', $value ) );
		}
		return wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	return $value;
}

function mcfm_resolve_object_field( $feed, $object, $source ) {
	if ( 'taxonomy' === $feed['object_type'] ) {
		switch ( $source ) {
			case 'name':
				return $object->name;
			case 'slug':
				return $object->slug;
			case 'description':
				return $object->description;
			case 'term_id':
			case 'id':
				return $object->term_id;
			case 'taxonomy':
				return $object->taxonomy;
			case 'parent':
				return $object->parent;
			case 'parent_name':
				if ( ! empty( $object->parent ) ) {
					$parent = get_term( $object->parent, $object->taxonomy );
					return $parent && ! is_wp_error( $parent ) ? $parent->name : '';
				}
				return '';
			case 'count':
				return $object->count;
			case 'link':
			case 'permalink':
				return mcfm_term_link_safe( $object );
			default:
				return isset( $object->{$source} ) ? $object->{$source} : '';
		}
	}

	switch ( $source ) {
		case 'title':
			return get_the_title( $object->ID );
		case 'excerpt':
			return get_the_excerpt( $object->ID );
		case 'content':
			return wp_strip_all_tags( $object->post_content );
		case 'permalink':
		case 'link':
			return get_permalink( $object->ID );
		case 'id':
			return $object->ID;
		case 'slug':
			return $object->post_name;
		case 'date':
			return mysql2date( 'c', $object->post_date_gmt ? $object->post_date_gmt : $object->post_date, false );
		case 'modified':
			return mysql2date( 'c', $object->post_modified_gmt ? $object->post_modified_gmt : $object->post_modified, false );
		case 'status':
			return $object->post_status;
		case 'author_id':
			return $object->post_author;
		default:
			return get_post_field( $source, $object->ID );
	}
}

function mcfm_resolve_taxonomy_terms( $object, $taxonomy, $extra ) {
	if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
		return '';
	}
	$terms = wp_get_post_terms( $object->ID, $taxonomy );
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return '';
	}

	$mode = $extra ? $extra : 'names';
	if ( 'first' === $mode ) {
		return $terms[0]->name;
	}
	if ( 'slugs' === $mode ) {
		return implode( '|', wp_list_pluck( $terms, 'slug' ) );
	}
	if ( 'ids' === $mode ) {
		return implode( '|', wp_list_pluck( $terms, 'term_id' ) );
	}
	if ( 'links' === $mode ) {
		$vals = array();
		foreach ( $terms as $term ) {
			$vals[] = mcfm_term_link_safe( $term );
		}
		return implode( '|', $vals );
	}
	return implode( '|', wp_list_pluck( $terms, 'name' ) );
}

function mcfm_resolve_featured_image( $object, $source, $extra ) {
	$size = $extra ? $extra : 'full';
	if ( has_post_thumbnail( $object->ID ) ) {
		return get_the_post_thumbnail_url( $object->ID, $size );
	}

	if ( $source ) {
		return mcfm_resolve_meta_value( 'post_type', $object, $source );
	}

	return '';
}


function mcfm_get_term_meta_first_value( $term_id, $keys ) {
	foreach ( (array) $keys as $key ) {
		$value = get_term_meta( $term_id, $key, true );
		if ( is_array( $value ) ) {
			if ( isset( $value['url'] ) && '' !== trim( (string) $value['url'] ) ) {
				return $value['url'];
			}
			if ( isset( $value['address'] ) && '' !== trim( (string) $value['address'] ) ) {
				return $value['address'];
			}
			if ( isset( $value['formatted_address'] ) && '' !== trim( (string) $value['formatted_address'] ) ) {
				return $value['formatted_address'];
			}
		}

		if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
			return trim( (string) $value );
		}
	}

	return '';
}

function mcfm_decode_json_field_value( $value, $keys ) {
	$value = trim( (string) $value );
	if ( '' === $value || '{' !== substr( $value, 0, 1 ) ) {
		return '';
	}

	$decoded = json_decode( $value, true );
	if ( ! is_array( $decoded ) ) {
		return '';
	}

	foreach ( (array) $keys as $key ) {
		if ( isset( $decoded[ $key ] ) && '' !== trim( (string) $decoded[ $key ] ) ) {
			return trim( (string) $decoded[ $key ] );
		}
	}

	return '';
}

function mcfm_attachment_or_url_to_url( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}

	$json_url = mcfm_decode_json_field_value( $value, array( 'url', 'src' ) );
	if ( '' !== $json_url ) {
		return esc_url_raw( $json_url );
	}

	if ( ctype_digit( $value ) ) {
		$url = wp_get_attachment_image_url( (int) $value, 'full' );
		return $url ? esc_url_raw( $url ) : '';
	}

	if ( preg_match( '~^https?://~i', $value ) ) {
		return esc_url_raw( $value );
	}

	return '';
}

/** Request-local WPML term mapping. Never persist cross-request translations. */
function mcfm_default_wpml_term_id( $term ) {
    static $memo = array();
    $id = (int) $term->term_id;
    if ( ! mcfm_is_wpml_active() ) return $id;
    $key = $term->taxonomy . ':' . $id . ':' . mcfm_get_default_language();
    if ( array_key_exists( $key, $memo ) ) return $memo[$key];
    $mapped = apply_filters( 'wpml_object_id', $id, $term->taxonomy, true, mcfm_get_default_language() );
    return $memo[$key] = ! empty( $mapped ) ? (int) $mapped : $id;
}

/** One bounded WPML translation-cache warmup per taxonomy/request. */
function mcfm_prefetch_wpml_taxonomy_ids( $taxonomy ) {
    static $done = array();
    $taxonomy = sanitize_key( (string) $taxonomy );
    if ( ! $taxonomy || ! mcfm_is_wpml_active() || ! class_exists( 'WPML_Term_Translation' ) ) return false;
    if ( isset( $done[ $taxonomy ] ) ) return $done[ $taxonomy ];
    if ( ! method_exists( 'WPML_Term_Translation', 'getGlobalInstance' ) ) return false;
    global $wpdb;
    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy=%s ORDER BY term_id ASC LIMIT %d",
        $taxonomy, 512
    ) );
    if ( ! is_array( $ids ) || ! $ids ) return $done[ $taxonomy ] = false;
    try {
        $instance = WPML_Term_Translation::getGlobalInstance();
        if ( ! $instance || ! method_exists( $instance, 'prefetch_ids' ) ) return false;
        $instance->prefetch_ids( array_map( 'intval', $ids ) );
        return $done[ $taxonomy ] = true;
    } catch ( Throwable $error ) {
        // Prefetch must never block a normal or translated feed export.
        return $done[ $taxonomy ] = false;
    }
}

function mcfm_term_meta_candidates( $term, $keys ) {
	$term_ids = array( (int) $term->term_id );

	if ( mcfm_is_wpml_active() ) {
		$default_id = mcfm_default_wpml_term_id( $term );
		if ( ! empty( $default_id ) ) {
			$term_ids[] = (int) $default_id;
		}
	}

	$term_ids = array_values( array_unique( array_filter( $term_ids ) ) );
	$values   = array();

	foreach ( $term_ids as $term_id ) {
		foreach ( (array) $keys as $key ) {
			$value = get_term_meta( $term_id, $key, true );
			if ( '' !== $value && null !== $value && false !== $value ) {
				$values[] = $value;
			}
		}
	}

	return $values;
}

function mcfm_flatten_meta_value( $value ) {
	$out = array();

	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
				$out[ (string) $k ] = trim( (string) $v );
			}
		}
		return $out;
	}

	$value = trim( (string) $value );
	if ( '' === $value ) {
		return $out;
	}

	if ( '{' === substr( $value, 0, 1 ) || '[' === substr( $value, 0, 1 ) ) {
		$decoded = json_decode( $value, true );
		if ( is_array( $decoded ) ) {
			return mcfm_flatten_meta_value( $decoded );
		}
	}

	$out['value'] = $value;
	return $out;
}

function mcfm_first_from_meta_arrays( $term, $keys, $value_keys ) {
	foreach ( mcfm_term_meta_candidates( $term, $keys ) as $raw ) {
		$flat = mcfm_flatten_meta_value( $raw );
		foreach ( (array) $value_keys as $key ) {
			if ( isset( $flat[ $key ] ) && '' !== trim( (string) $flat[ $key ] ) ) {
				return trim( (string) $flat[ $key ] );
			}
		}
		if ( isset( $flat['value'] ) && '' !== trim( (string) $flat['value'] ) ) {
			return trim( (string) $flat['value'] );
		}
	}
	return '';
}

function mcfm_normalize_decimal( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}
	$value = preg_replace( '~[^0-9,\.\-]+~', '', $value );
	if ( false !== strpos( $value, ',' ) && false === strpos( $value, '.' ) ) {
		$value = str_replace( ',', '.', $value );
	}
	if ( ! is_numeric( $value ) ) {
		return '';
	}
	return rtrim( rtrim( number_format( (float) $value, 7, '.', '' ), '0' ), '.' );
}

function mcfm_resolve_term_level_type( $term ) {
	$raw = mcfm_get_term_meta_first_value(
		$term->term_id,
		array( 'destination_type', 'type', 'location_type', 'place_type', 'term_type' )
	);
	$raw = strtolower( trim( (string) $raw ) );
	$raw = str_replace( array( ' ', '-' ), '_', $raw );

	$map = array(
		'country' => 'country',
		'continent' => 'continent',
		'region' => 'region',
		'governorate' => 'region',
		'state' => 'region',
		'province' => 'region',
		'city' => 'city',
		'town' => 'city',
		'zone' => 'neighborhood',
		'district' => 'neighborhood',
		'area' => 'neighborhood',
		'neighborhood' => 'neighborhood',
		'attraction' => 'landmark',
		'landmark' => 'landmark',
		'poi' => 'landmark',
		'point_of_interest' => 'landmark',
	);

	if ( isset( $map[ $raw ] ) ) {
		return $map[ $raw ];
	}

	if ( empty( $term->parent ) ) {
		return 'country';
	}

	$depth = 0;
	$parent_id = (int) $term->parent;
	while ( $parent_id > 0 && $depth < 10 ) {
		$parent = get_term( $parent_id, $term->taxonomy );
		if ( ! $parent || is_wp_error( $parent ) ) {
			break;
		}
		$depth++;
		$parent_id = (int) $parent->parent;
	}

	if ( 0 === $depth ) {
		return 'city';
	}
	if ( 1 === $depth ) {
		return 'region';
	}
	if ( 2 === $depth ) {
		return 'city';
	}
	return 'landmark';
}

function mcfm_is_continent_label( $value ) {
	$value = strtolower( remove_accents( trim( (string) $value ) ) );
	$value = preg_replace( '~[^a-z]+~', ' ', $value );
	$value = trim( preg_replace( '~\s+~', ' ', $value ) );
	return in_array( $value, array( 'africa', 'asia', 'europe', 'north america', 'south america', 'antarctica', 'australia', 'oceania' ), true );
}

function mcfm_safe_meta_destination_region( $parts ) {
	$region  = isset( $parts['region'] ) ? trim( (string) $parts['region'] ) : '';
	$city    = isset( $parts['city'] ) ? trim( (string) $parts['city'] ) : '';
	$country = isset( $parts['country'] ) ? trim( (string) $parts['country'] ) : '';

	if ( '' === $region || mcfm_is_continent_label( $region ) ) {
		$region = '' !== $city ? $city : $country;
	}
	return $region;
}

function mcfm_normalize_google_type_token( $value ) {
	$value = strtolower( remove_accents( trim( (string) $value ) ) );
	$value = preg_replace( '~[^a-z0-9_ -]+~', '', $value );
	$value = str_replace( array( ' ', '-' ), '_', $value );
	return trim( $value, '_' );
}

function mcfm_extract_google_place_types( $term ) {
	$keys = array(
		'google_types',
		'google_place_types',
		'place_types',
		'place_type',
		'types',
		'google_type',
		'google_place_type',
		'google_address',
		'formatted_address',
		'place_json',
		'google_place_json',
		'google_place_data',
		'google_details',
	);

	$types = array();

	foreach ( mcfm_term_meta_candidates( $term, $keys ) as $raw ) {
		$stack = array( $raw );

		while ( $stack ) {
			$item = array_shift( $stack );

			if ( is_array( $item ) ) {
				foreach ( $item as $key => $value ) {
					$key_norm = mcfm_normalize_google_type_token( $key );
					if ( in_array( $key_norm, array( 'types', 'type', 'place_types', 'google_types' ), true ) ) {
						$stack[] = $value;
					} elseif ( is_array( $value ) ) {
						$stack[] = $value;
					} elseif ( is_scalar( $value ) ) {
						$token = mcfm_normalize_google_type_token( $value );
						if ( preg_match( '~^(administrative_area_level_[0-9]+|locality|postal_town|sublocality|sublocality_level_[0-9]+|neighborhood|country|tourist_attraction|point_of_interest|natural_feature|museum|amusement_park|airport|lodging|establishment|park|zoo|political)$~', $token ) ) {
							$types[] = $token;
						}
					}
				}
				continue;
			}

			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$item = trim( (string) $item );
			if ( '' === $item ) {
				continue;
			}

			if ( '{' === substr( $item, 0, 1 ) || '[' === substr( $item, 0, 1 ) ) {
				$decoded = json_decode( $item, true );
				if ( is_array( $decoded ) ) {
					$stack[] = $decoded;
					continue;
				}
			}

			foreach ( preg_split( '~[,|;\n\r]+~', $item ) as $token ) {
				$token = mcfm_normalize_google_type_token( $token );
				if ( preg_match( '~^(administrative_area_level_[0-9]+|locality|postal_town|sublocality|sublocality_level_[0-9]+|neighborhood|country|tourist_attraction|point_of_interest|natural_feature|museum|amusement_park|airport|lodging|establishment|park|zoo|political)$~', $token ) ) {
					$types[] = $token;
				}
			}
		}
	}

	return array_values( array_unique( array_filter( $types ) ) );
}

function mcfm_google_place_types_to_meta_destination_type( $term ) {
	$types = mcfm_extract_google_place_types( $term );
	$text  = strtolower( remove_accents( $term->name . ' ' . mcfm_term_hierarchy_names( $term ) ) );

	if ( preg_match( '~red sea|hurghada|marsa alam|sharm|beach|sea|reef|island|snorkel|diving|dive|resort~', $text ) ) {
		return 'beach';
	}

	if ( in_array( 'country', $types, true ) ) {
		return 'country';
	}
	if ( in_array( 'locality', $types, true ) || in_array( 'postal_town', $types, true ) ) {
		return 'city';
	}
	if ( array_intersect( $types, array( 'administrative_area_level_1', 'administrative_area_level_2', 'administrative_area_level_3' ) ) ) {
		return 'region';
	}
	if ( array_intersect( $types, array( 'sublocality', 'sublocality_level_1', 'sublocality_level_2', 'neighborhood' ) ) ) {
		return 'neighborhood';
	}
	if ( array_intersect( $types, array( 'tourist_attraction', 'point_of_interest', 'museum', 'amusement_park', 'zoo', 'establishment' ) ) ) {
		return 'landmark';
	}
	if ( array_intersect( $types, array( 'natural_feature', 'park' ) ) ) {
		return 'landmark';
	}
	if ( in_array( 'airport', $types, true ) ) {
		return 'airport';
	}
	if ( in_array( 'lodging', $types, true ) ) {
		return 'hotel';
	}

	return '';
}

function mcfm_resolve_smart_google_destination_type( $term ) {
	$type = mcfm_google_place_types_to_meta_destination_type( $term );
	return '' !== $type ? $type : mcfm_resolve_smart_destination_type_value( $term );
}

function mcfm_meta_destination_type_to_json_array( $type ) {
	$type = strtolower( remove_accents( trim( (string) $type ) ) );
	$type = preg_replace( '~[^a-z0-9_ -]+~', '', $type );
	$type = str_replace( array( ' ', '-' ), '_', $type );
	if ( '' === $type ) { $type = 'city'; }
	$aliases = array( 'point_of_interest' => 'landmark', 'poi' => 'landmark', 'natural_feature' => 'nature', 'locality' => 'city', 'administrative_area_level_1' => 'region', 'administrative_area_level_2' => 'region', 'administrative_area_level_3' => 'region' );
	if ( isset( $aliases[ $type ] ) ) { $type = $aliases[ $type ]; }
	$allowed = array( 'beach', 'city', 'country', 'region', 'neighborhood', 'landmark', 'nature', 'park', 'museum', 'airport', 'hotel', 'resort' );
	if ( ! in_array( $type, $allowed, true ) ) { $type = 'city'; }
	return wp_json_encode( array( $type ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

function mcfm_resolve_smart_meta_destination_type_json( $term ) {
	$type = mcfm_resolve_smart_google_destination_type( $term );
	if ( '' === $type ) { $type = mcfm_resolve_smart_destination_type_value( $term ); }
	return mcfm_meta_destination_type_to_json_array( $type );
}
function mcfm_resolve_smart_google_place_types( $term ) {
	return implode( ',', mcfm_extract_google_place_types( $term ) );
}

function mcfm_resolve_smart_destination_types( $term ) {
	return mcfm_resolve_smart_destination_type_value( $term );
}

function mcfm_resolve_smart_destination_type_value( $term ) {
	$google_type = mcfm_google_place_types_to_meta_destination_type( $term );
	if ( '' !== $google_type ) {
		return $google_type;
	}

	$explicit = mcfm_get_term_meta_first_value(
		$term->term_id,
		array( 'type', 'destination_type', 'location_type', 'place_type', 'term_type', 'meta_type' )
	);

	$allowed = array(
		'beach',
		'city',
		'country',
		'region',
		'neighborhood',
		'landmark',
		'point_of_interest',
		'nature',
		'park',
		'museum',
		'airport',
		'hotel',
		'resort',
	);

	if ( '' !== trim( (string) $explicit ) ) {
		$explicit = strtolower( remove_accents( trim( (string) $explicit ) ) );
		$explicit = preg_replace( '~[^a-z0-9_ -]+~', '', $explicit );
		$explicit = str_replace( array( ' ', '-' ), '_', $explicit );
		if ( in_array( $explicit, $allowed, true ) ) {
			return $explicit;
		}
	}

	$name      = strtolower( remove_accents( (string) $term->name ) );
	$level     = mcfm_resolve_term_level_type( $term );
	$hierarchy = strtolower( remove_accents( mcfm_term_hierarchy_names( $term ) ) );
	$text      = $name . ' ' . $hierarchy;

	if ( preg_match( '~red sea|hurghada|marsa alam|sharm|beach|sea|reef|island|snorkel|diving|dive|resort~', $text ) ) {
		return 'beach';
	}
	if ( preg_match( '~museum~', $text ) ) {
		return 'museum';
	}
	if ( preg_match( '~park|protectorate|national park|garden~', $text ) ) {
		return 'park';
	}
	if ( preg_match( '~temple|pyramid|tomb|philae|karnak|valley|monastery|mosque|church|citadel|attraction|landmark|poi|point of interest~', $text ) ) {
		return 'landmark';
	}
	if ( preg_match( '~airport~', $text ) ) {
		return 'airport';
	}

	if ( in_array( $level, array( 'country', 'region', 'city', 'neighborhood', 'landmark' ), true ) ) {
		return $level;
	}

	return 'city';
}

function mcfm_term_hierarchy_parts( $term ) {
	$parts = array();
	$current = $term;
	$guard = 0;

	while ( $current && ! is_wp_error( $current ) && $guard < 12 ) {
		$parts[] = $current;
		if ( empty( $current->parent ) ) {
			break;
		}
		$current = get_term( (int) $current->parent, $current->taxonomy );
		$guard++;
	}

	return array_reverse( $parts );
}

function mcfm_guess_destination_address_parts( $term ) {
	static $memo = array();
	$key = $term->taxonomy . ':' . (int) $term->term_id . ':' .
		( mcfm_is_wpml_active() ? apply_filters( 'wpml_current_language', null ) : '' );
	if ( isset( $memo[ $key ] ) ) return $memo[ $key ];
	$parts = array(
		'city' => '',
		'region' => '',
		'country' => '',
		'street_address' => '',
		'postal_code' => '',
	);

	foreach ( mcfm_term_meta_candidates( $term, array( 'address', 'google_address', 'formatted_address', 'location_address', 'destination_address', 'full_address', 'tour_origin_address' ) ) as $raw ) {
		$flat = mcfm_flatten_meta_value( $raw );
		foreach ( array( 'city', 'region', 'country', 'street_address', 'addr1', 'postal_code' ) as $key ) {
			if ( empty( $parts[ $key ] ) && ! empty( $flat[ $key ] ) ) {
				$parts[ $key ] = trim( (string) $flat[ $key ] );
			}
		}
		if ( empty( $parts['street_address'] ) ) {
			foreach ( array( 'formatted_address', 'address', 'name', 'value' ) as $key ) {
				if ( ! empty( $flat[ $key ] ) ) {
					$parts['street_address'] = trim( (string) $flat[ $key ] );
					break;
				}
			}
		}
	}

	foreach ( mcfm_term_hierarchy_parts( $term ) as $node ) {
		$type = mcfm_resolve_term_level_type( $node );
		if ( 'country' === $type && empty( $parts['country'] ) ) {
			$parts['country'] = $node->name;
		} elseif ( 'region' === $type && empty( $parts['region'] ) && ! mcfm_is_continent_label( $node->name ) ) {
			$parts['region'] = $node->name;
		} elseif ( 'city' === $type && empty( $parts['city'] ) ) {
			$parts['city'] = $node->name;
		}
	}

	if ( empty( $parts['country'] ) ) {
		$parts['country'] = 'Egypt';
	}
	if ( empty( $parts['city'] ) ) {
		$parts['city'] = $term->name;
	}
	if ( empty( $parts['region'] ) || mcfm_is_continent_label( $parts['region'] ) ) {
		$parts['region'] = $parts['city'];
	}
	if ( ! empty( $parts['addr1'] ) && empty( $parts['street_address'] ) ) {
		$parts['street_address'] = $parts['addr1'];
	}
	if ( empty( $parts['street_address'] ) ) {
		$parts['street_address'] = implode( ', ', array_filter( array( $term->name, $parts['city'], $parts['region'], $parts['country'] ) ) );
	}
	$parts['addr1'] = $parts['street_address'];

	return $memo[ $key ] = $parts;
}

function mcfm_resolve_smart_destination_image_json( $term ) {
	$url = mcfm_resolve_smart_destination_image( $term );
	if ( '' === $url ) {
		return '';
	}
	return wp_json_encode( array( array( 'url' => $url ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

function mcfm_resolve_smart_destination_address_json( $term ) {
	$parts = mcfm_guess_destination_address_parts( $term );
	return wp_json_encode( array_filter( $parts ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

function mcfm_resolve_smart_destination_latitude( $term ) {
	return mcfm_normalize_decimal(
		mcfm_first_from_meta_arrays(
			$term,
			array( 'latitude', 'lat', 'google_lat', 'google_latitude', 'location_lat', 'map_lat', 'google_address' ),
			array( 'latitude', 'lat' )
		)
	);
}

function mcfm_resolve_smart_destination_longitude( $term ) {
	return mcfm_normalize_decimal(
		mcfm_first_from_meta_arrays(
			$term,
			array( 'longitude', 'lng', 'lon', 'google_lng', 'google_longitude', 'location_lng', 'map_lng', 'google_address' ),
			array( 'longitude', 'lng', 'lon' )
		)
	);
}

function mcfm_term_hierarchy_names( $term ) {
	$names = array();
	$current = $term;
	$guard = 0;

	while ( $current && ! is_wp_error( $current ) && $guard < 12 ) {
		if ( ! empty( $current->name ) ) {
			array_unshift( $names, $current->name );
		}
		if ( empty( $current->parent ) ) {
			break;
		}
		$current = get_term( (int) $current->parent, $current->taxonomy );
		$guard++;
	}

	$names = array_values( array_unique( array_filter( array_map( 'trim', $names ) ) ) );
	return implode( ', ', array_reverse( $names ) );
}

function mcfm_resolve_smart_destination_id( $term ) {
	$place_id = mcfm_get_term_meta_first_value(
		$term->term_id,
		array( 'place_id', 'google_place_id', 'google_placeid', 'google_map_place_id', 'location_place_id' )
	);

	if ( '' !== $place_id ) {
		return $place_id;
	}

	$default_term_id = mcfm_default_wpml_term_id( $term );

	return 'dest_' . $default_term_id;
}

function mcfm_resolve_smart_destination_image( $term ) {
	$value = mcfm_get_term_meta_first_value(
		$term->term_id,
		array( 'image', 'thumbnail_id', '_thumbnail_id', 'term_image', 'destination_image', 'location_image', 'jet_engine_image', 'grid_image_1', 'grid_image', 'main_image', 'image_url', 'image_link', 'photo', 'photo_url', 'google_photo_url' )
	);

	$url = mcfm_attachment_or_url_to_url( $value );
	if ( '' !== $url ) {
		return $url;
	}

	return '';
}

function mcfm_resolve_smart_destination_address( $term ) {
	$value = mcfm_get_term_meta_first_value(
		$term->term_id,
		array( 'address', 'google_address', 'formatted_address', 'location_address', 'destination_address', 'full_address', 'tour_origin_address' )
	);

	$json_address = mcfm_decode_json_field_value( $value, array( 'formatted_address', 'address', 'name' ) );
	if ( '' !== $json_address ) {
		return $json_address;
	}

	if ( '' !== trim( (string) $value ) ) {
		return trim( (string) $value );
	}

	$hierarchy = mcfm_term_hierarchy_names( $term );
	if ( '' !== $hierarchy ) {
		return $hierarchy;
	}

	return $term->name;
}

function mcfm_resolve_computed( $feed, $object, $source ) {
	switch ( $source ) {
		case 'smart_destination_id':
			return 'taxonomy' === $feed['object_type'] ? mcfm_resolve_smart_destination_id( $object ) : '';
		case 'smart_destination_image':
		case 'smart_destination_image_url':
			return 'taxonomy' === $feed['object_type'] ? mcfm_resolve_smart_destination_image( $object ) : '';
		case 'smart_destination_image_json':
			return 'taxonomy' === $feed['object_type'] ? mcfm_resolve_smart_destination_image_json( $object ) : '';
		case 'smart_destination_address':
			return 'taxonomy' === $feed['object_type'] ? mcfm_resolve_smart_destination_address( $object ) : '';
		case 'smart_destination_address_json':
			return 'taxonomy' === $feed['object_type'] ? mcfm_resolve_smart_destination_address_json( $object ) : '';
		case 'smart_destination_address_city':
			return 'taxonomy' === $feed['object_type'] ? mcfm_guess_destination_address_parts( $object )['city'] : '';
		case 'smart_destination_address_region':
		case 'smart_destination_address_region_safe':
			return 'taxonomy' === $feed['object_type'] ? mcfm_safe_meta_destination_region( mcfm_guess_destination_address_parts( $object ) ) : '';
		case 'smart_destination_address_country':
			return 'taxonomy' === $feed['object_type'] ? mcfm_guess_destination_address_parts( $object )['country'] : '';
		case 'smart_destination_address_addr1':
		case 'smart_destination_address_street':
			return 'taxonomy' === $feed['object_type'] ? mcfm_guess_destination_address_parts( $object )['addr1'] : '';
		case 'smart_destination_address_postal_code':
			return 'taxonomy' === $feed['object_type'] ? mcfm_guess_destination_address_parts( $object )['postal_code'] : '';
		case 'smart_destination_latitude':
			return 'taxonomy' === $feed['object_type'] ? mcfm_resolve_smart_destination_latitude( $object ) : '';
		case 'smart_destination_longitude':
			return 'taxonomy' === $feed['object_type'] ? mcfm_resolve_smart_destination_longitude( $object ) : '';
		case 'smart_destination_type':
			return 'taxonomy' === $feed['object_type'] ? mcfm_resolve_smart_meta_destination_type_json( $object ) : '';
		case 'smart_google_destination_type':
		case 'smart_meta_destination_type_json':
			return 'taxonomy' === $feed['object_type'] ? mcfm_resolve_smart_meta_destination_type_json( $object ) : '';
		case 'smart_google_place_types':
			return 'taxonomy' === $feed['object_type'] ? mcfm_resolve_smart_google_place_types( $object ) : '';
		case 'post_type':
			return 'post_type' === $feed['object_type'] ? $feed['object_key'] : '';
		case 'full_slug':
			if ( 'taxonomy' !== $feed['object_type'] ) {
				return '';
			}
			$parts = array( $object->slug );
			$parent_id = (int) $object->parent;
			while ( $parent_id > 0 ) {
				$parent = get_term( $parent_id, $object->taxonomy );
				if ( ! $parent || is_wp_error( $parent ) ) {
					break;
				}
				array_unshift( $parts, $parent->slug );
				$parent_id = (int) $parent->parent;
			}
			return implode( '/', $parts );
		default:
			return apply_filters( 'mad4b/mcfm/computed_value', '', $feed, $object, $source );
	}
}

function mcfm_resolve_value( $feed, $object, $map, $lang ) {
	$source_type = $map['source_type'] ?? 'meta';
	$source      = $map['source'] ?? '';
	$extra       = $map['extra'] ?? '';

	switch ( $source_type ) {
		case 'object_field':
			$value = mcfm_resolve_object_field( $feed, $object, $source );
			break;
		case 'featured_image':
			$value = 'post_type' === $feed['object_type'] ? mcfm_resolve_featured_image( $object, $source, $extra ) : '';
			break;
		case 'taxonomy_terms':
			$value = 'post_type' === $feed['object_type'] ? mcfm_resolve_taxonomy_terms( $object, $source, $extra ) : '';
			break;
		case 'static':
			$value = $source;
			break;
		case 'language_code':
			$value = $lang;
			break;
		case 'computed':
			$value = mcfm_resolve_computed( $feed, $object, $source );
			break;
		case 'meta':
		default:
			$value = mcfm_resolve_meta_value( $feed['object_type'], $object, $source );
			break;
	}

	if ( is_bool( $value ) ) {
		return $value ? '1' : '0';
	}

	if ( is_array( $value ) ) {
		return implode( '|', array_map( 'strval', $value ) );
	}

	if ( is_object( $value ) ) {
		return wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	return (string) $value;
}

function mcfm_iterate_feed_rows( $feed, $lang, $callback ) {
	$feed = mcfm_normalize_feed( $feed );

	if ( 'taxonomy' === $feed['object_type'] ) {
		mcfm_prefetch_wpml_taxonomy_ids( $feed['object_key'] );
		$offset = 0;
		$number = MCFM_BATCH_SIZE;
		do {
			$args = array(
				'taxonomy'   => $feed['object_key'],
				'hide_empty' => ! empty( $feed['hide_empty_terms'] ),
				'number'     => $number,
				'offset'     => $offset,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			);

			$terms = get_terms( $args );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				break;
			}

			foreach ( $terms as $term ) {
				$row = array();
				foreach ( $feed['mapping'] as $map ) {
					if ( empty( $map['column'] ) ) {
						continue;
					}
					$row[ $map['column'] ] = mcfm_resolve_value( $feed, $term, $map, $lang );
				}
				if ( ! mcfm_row_passes_required_mapping( $feed, $row ) ) {
					continue;
				}
				if ( ! apply_filters( 'mad4b/mcfm/include_row', true, $feed, $term, $row, $lang ) ) {
					continue;
				}
				$continue = call_user_func( $callback, $row, $term );
				if ( false === $continue ) {
					return;
				}
			}

			$count = count( $terms );
			$offset += $count;
		} while ( $count === $number );

		return;
	}

	$paged = 1;
	do {
		$query = new WP_Query(
			array(
				'post_type'              => $feed['object_key'],
				'post_status'            => $feed['status'] ? array_map( 'trim', explode( ',', $feed['status'] ) ) : array( 'publish' ),
				'posts_per_page'         => MCFM_BATCH_SIZE,
				'paged'                  => $paged,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);

		if ( ! $query->have_posts() ) {
			break;
		}

		foreach ( $query->posts as $post ) {
			$row = array();
			foreach ( $feed['mapping'] as $map ) {
				if ( empty( $map['column'] ) ) {
					continue;
				}
				$row[ $map['column'] ] = mcfm_resolve_value( $feed, $post, $map, $lang );
			}
			if ( ! mcfm_row_passes_required_mapping( $feed, $row ) ) {
				continue;
			}
			if ( ! apply_filters( 'mad4b/mcfm/include_row', true, $feed, $post, $row, $lang ) ) {
				continue;
			}
			$continue = call_user_func( $callback, $row, $post );
			if ( false === $continue ) {
				wp_reset_postdata();
				return;
			}
		}

		$count = count( $query->posts );
		$paged++;
		wp_reset_postdata();
	} while ( $count === MCFM_BATCH_SIZE );
}

function mcfm_xml_tag_name( $name ) {
	$name = preg_replace( '/[^A-Za-z0-9_\-:.]+/', '_', (string) $name );
	if ( preg_match( '/^[^A-Za-z_]+/', $name ) ) {
		$name = 'field_' . $name;
	}
	return $name ? $name : 'field';
}

function mcfm_output_csv_like( $feed, $format, $lang ) {
	mcfm_disable_output_buffering();

	$headers   = mcfm_get_headers( $feed );
	$delimiter = mcfm_csv_delimiter_for_format( $feed, $format );
	$filename  = $feed['slug'] . ( $lang ? '-' . $lang : '' ) . '.' . $format;
	$content   = 'csv' === $format ? 'text/csv; charset=utf-8' : 'text/tab-separated-values; charset=utf-8';

	mcfm_stream_headers_for_download( $filename, $content );

	$out = fopen( 'php://output', 'w' );
	if ( ! $out ) {
		status_header( 500 );
		exit;
	}

	fputcsv( $out, $headers, $delimiter );

	mcfm_iterate_feed_rows(
		$feed,
		$lang,
		function( $row ) use ( $out, $headers, $delimiter ) {
			$line = array();
			foreach ( $headers as $header ) {
				$line[] = isset( $row[ $header ] ) ? $row[ $header ] : '';
			}
			fputcsv( $out, $line, $delimiter );
			return true;
		}
	);

	fclose( $out );
	exit;
}

function mcfm_output_rss( $feed, $lang ) {
	mcfm_disable_output_buffering();
	$headers  = mcfm_get_headers( $feed );
	$filename = $feed['slug'] . ( $lang ? '-' . $lang : '' ) . '.xml';
	mcfm_stream_headers_for_download( $filename, 'application/rss+xml; charset=utf-8' );

	$xml = new XMLWriter();
	$xml->openURI( 'php://output' );
	$xml->startDocument( '1.0', 'UTF-8' );
	$xml->startElement( 'rss' );
	$xml->writeAttribute( 'version', '2.0' );
	$xml->startElement( 'channel' );
	$xml->writeElement( 'title', get_bloginfo( 'name' ) . ' - ' . $feed['label'] );
	$xml->writeElement( 'link', home_url( '/' ) );
	$xml->writeElement( 'description', 'Catalog feed for ' . $feed['label'] . ( $lang ? ' [' . $lang . ']' : '' ) );

	mcfm_iterate_feed_rows(
		$feed,
		$lang,
		function( $row ) use ( $xml, $headers ) {
			$xml->startElement( 'item' );
			foreach ( $headers as $header ) {
				$value = isset( $row[ $header ] ) ? $row[ $header ] : '';
				if ( '' === $value ) {
					continue;
				}
				$xml->writeElement( mcfm_xml_tag_name( $header ), $value );
			}
			$xml->endElement();
			return true;
		}
	);

	$xml->endElement();
	$xml->endElement();
	$xml->endDocument();
	$xml->flush();
	exit;
}

function mcfm_output_atom( $feed, $lang ) {
	mcfm_disable_output_buffering();
	$headers  = mcfm_get_headers( $feed );
	$filename = $feed['slug'] . ( $lang ? '-' . $lang : '' ) . '.atom';
	mcfm_stream_headers_for_download( $filename, 'application/atom+xml; charset=utf-8' );

	$xml = new XMLWriter();
	$xml->openURI( 'php://output' );
	$xml->startDocument( '1.0', 'UTF-8' );
	$xml->startElementNS( null, 'feed', 'http://www.w3.org/2005/Atom' );
	$xml->writeElement( 'title', get_bloginfo( 'name' ) . ' - ' . $feed['label'] );
	$xml->writeElement( 'id', home_url( '/meta-feed/' . $feed['slug'] ) );
	$xml->startElement( 'link' );
	$xml->writeAttribute( 'href', home_url( '/' ) );
	$xml->endElement();
	$xml->writeElement( 'updated', gmdate( 'c' ) );

	mcfm_iterate_feed_rows(
		$feed,
		$lang,
		function( $row ) use ( $xml, $headers, $feed, $lang ) {
			$xml->startElement( 'entry' );
			$entry_id = ! empty( $row['id'] ) ? $row['id'] : md5( wp_json_encode( $row ) );
			$xml->writeElement( 'id', $feed['slug'] . ':' . $entry_id . ( $lang ? ':' . $lang : '' ) );
			$title = '';
			foreach ( array( 'title', 'name', 'slug', 'id' ) as $candidate ) {
				if ( ! empty( $row[ $candidate ] ) ) {
					$title = $row[ $candidate ];
					break;
				}
			}
			$xml->writeElement( 'title', $title ? $title : 'Entry ' . $entry_id );
			$xml->writeElement( 'updated', gmdate( 'c' ) );

			$xml->startElement( 'content' );
			$xml->writeAttribute( 'type', 'xhtml' );
			$xml->startElementNS( 'xhtml', 'div', 'http://www.w3.org/1999/xhtml' );
			$xml->startElementNS( 'xhtml', 'table', null );
			foreach ( $headers as $header ) {
				$value = isset( $row[ $header ] ) ? $row[ $header ] : '';
				if ( '' === $value ) {
					continue;
				}
				$xml->startElementNS( 'xhtml', 'tr', null );
				$xml->writeElementNS( 'xhtml', 'th', null, $header );
				$xml->writeElementNS( 'xhtml', 'td', null, $value );
				$xml->endElement();
			}
			$xml->endElement();
			$xml->endElement();
			$xml->endElement();
			$xml->endElement();
			return true;
		}
	);

	$xml->endElement();
	$xml->endDocument();
	$xml->flush();
	exit;
}

function mcfm_xlsx_col_letter( $index ) {
	$index += 1;
	$letters = '';
	while ( $index > 0 ) {
		$mod = ( $index - 1 ) % 26;
		$letters = chr( 65 + $mod ) . $letters;
		$index = (int) floor( ( $index - 1 ) / 26 );
	}
	return $letters;
}

function mcfm_xlsx_escape( $value ) {
	$value = (string) $value;
	$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value );
	return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

function mcfm_output_xlsx( $feed, $lang ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		status_header( 500 );
		wp_die( esc_html__( 'ZipArchive is required for XLSX export.', 'mcfm' ) );
	}

	mcfm_disable_output_buffering();

	$temp_base = wp_tempnam( 'mcfm-xlsx' );
	if ( ! $temp_base ) {
		status_header( 500 );
		exit;
	}
	@unlink( $temp_base );
	$temp_dir = $temp_base . '-dir';
	wp_mkdir_p( $temp_dir );
	wp_mkdir_p( $temp_dir . '/_rels' );
	wp_mkdir_p( $temp_dir . '/xl/_rels' );
	wp_mkdir_p( $temp_dir . '/xl/worksheets' );

	$headers = mcfm_get_headers( $feed );
	$sheet   = fopen( $temp_dir . '/xl/worksheets/sheet1.xml', 'wb' );

	if ( ! $sheet ) {
		status_header( 500 );
		exit;
	}

	fwrite( $sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' );
	fwrite( $sheet, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' );

	$row_index = 1;
	$write_row = function( $values ) use ( $sheet, &$row_index ) {
		fwrite( $sheet, '<row r="' . $row_index . '">' );
		foreach ( array_values( $values ) as $col_index => $value ) {
			$cell = mcfm_xlsx_col_letter( $col_index ) . $row_index;
			fwrite( $sheet, '<c r="' . $cell . '" t="inlineStr"><is><t xml:space="preserve">' . mcfm_xlsx_escape( $value ) . '</t></is></c>' );
		}
		fwrite( $sheet, '</row>' );
		$row_index++;
	};

	$write_row( $headers );

	mcfm_iterate_feed_rows(
		$feed,
		$lang,
		function( $row ) use ( $headers, $write_row ) {
			$line = array();
			foreach ( $headers as $header ) {
				$line[] = isset( $row[ $header ] ) ? $row[ $header ] : '';
			}
			$write_row( $line );
			return true;
		}
	);

	fwrite( $sheet, '</sheetData></worksheet>' );
	fclose( $sheet );

	file_put_contents(
		$temp_dir . '/[Content_Types].xml',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
		'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
		'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
		'<Default Extension="xml" ContentType="application/xml"/>' .
		'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
		'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
		'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' .
		'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' .
		'</Types>'
	);

	wp_mkdir_p( $temp_dir . '/docProps' );

	file_put_contents(
		$temp_dir . '/_rels/.rels',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
		'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
		'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
		'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' .
		'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' .
		'</Relationships>'
	);

	file_put_contents(
		$temp_dir . '/xl/workbook.xml',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
		'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
		'<sheets><sheet name="Feed" sheetId="1" r:id="rId1"/></sheets>' .
		'</workbook>'
	);

	file_put_contents(
		$temp_dir . '/xl/_rels/workbook.xml.rels',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
		'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
		'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
		'</Relationships>'
	);

	file_put_contents(
		$temp_dir . '/docProps/app.xml',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
		'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">' .
		'<Application>WordPress</Application><TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>Feed</vt:lpstr></vt:vector></TitlesOfParts>' .
		'</Properties>'
	);

	file_put_contents(
		$temp_dir . '/docProps/core.xml',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
		'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
		'<dc:title>' . mcfm_xlsx_escape( $feed['label'] ) . '</dc:title>' .
		'<dc:creator>WordPress</dc:creator>' .
		'<cp:lastModifiedBy>WordPress</cp:lastModifiedBy>' .
		'<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate( 'c' ) . '</dcterms:created>' .
		'<dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate( 'c' ) . '</dcterms:modified>' .
		'</cp:coreProperties>'
	);

	$temp_xlsx = $temp_base . '.xlsx';
	$zip = new ZipArchive();
	if ( true !== $zip->open( $temp_xlsx, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		status_header( 500 );
		exit;
	}

	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $temp_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $it as $file ) {
		if ( $file->isDir() ) {
			continue;
		}
		$abs = $file->getRealPath();
		$rel = ltrim( str_replace( $temp_dir, '', $abs ), DIRECTORY_SEPARATOR );
		$zip->addFile( $abs, str_replace( DIRECTORY_SEPARATOR, '/', $rel ) );
	}
	$zip->close();

	$filename = $feed['slug'] . ( $lang ? '-' . $lang : '' ) . '.xlsx';
	mcfm_stream_headers_for_download(
		$filename,
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		filesize( $temp_xlsx )
	);
	readfile( $temp_xlsx );

	@unlink( $temp_xlsx );
	mcfm_rrmdir( $temp_dir );
	exit;
}

function mcfm_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = scandir( $dir );
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . DIRECTORY_SEPARATOR . $item;
		if ( is_dir( $path ) ) {
			mcfm_rrmdir( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}

add_action( 'template_redirect', 'mcfm_template_redirect' );
function mcfm_template_redirect() {
	$slug   = get_query_var( 'mcfm_feed_slug' );
	$format = get_query_var( 'mcfm_feed_format' );
	$lang   = get_query_var( 'mcfm_feed_lang' );

	if ( ! $slug || ! $format ) {
		return;
	}

	$found = mcfm_get_feed_by_slug( sanitize_title( $slug ) );
	if ( ! $found ) {
		status_header( 404 );
		echo 'Feed not found.';
		exit;
	}

	$feed = $found['feed'];

	// Company-controlled feeds are private, independent of any external adapter.
	if ( str_starts_with( (string) ( $feed['slug'] ?? '' ), 'mad4b-managed-' )
		&& ! current_user_can( 'manage_options' ) ) {
		status_header( 403 );
		nocache_headers();
		exit;
	}
	if ( ! apply_filters( 'mad4b/mcfm/can_export_feed', true, $feed, $lang ) ) {
		status_header( 403 );
		nocache_headers();
		exit;
	}

	if ( str_starts_with( (string) ( $feed['slug'] ?? '' ), 'mad4b-managed-' ) ) {
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
	}

	if ( empty( $feed['mapping'] ) ) {
		status_header( 400 );
		echo 'Feed has no mapping.';
		exit;
	}

	$lang = mcfm_resolve_requested_language( $feed, $lang );
	$original_lang = mcfm_is_wpml_active() ? apply_filters( 'wpml_current_language', null ) : null;

	if ( $lang ) {
		mcfm_switch_language( $lang );
	}

	if ( 'xml' === $format ) {
		$format = ! empty( $feed['xml_variant'] ) ? $feed['xml_variant'] : 'rss';
	}

	switch ( $format ) {
		case 'csv':
		case 'tsv':
			mcfm_output_csv_like( $feed, $format, $lang );
			break;
		case 'rss':
			mcfm_output_rss( $feed, $lang );
			break;
		case 'atom':
			mcfm_output_atom( $feed, $lang );
			break;
		case 'xlsx':
			mcfm_output_xlsx( $feed, $lang );
			break;
		default:
			if ( null !== $original_lang ) {
				mcfm_switch_language( $original_lang );
			}
			status_header( 400 );
			echo 'Unsupported format.';
			exit;
	}
}

add_action( 'admin_menu', function() {
	add_options_page(
		'MAD4B Distribution - Catalog Feed Studio',
		'Catalog Feed Studio',
		'manage_options',
		MCFM_MENU_SLUG,
		'mcfm_admin_page'
	);
} );

add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( $hook !== 'settings_page_' . MCFM_MENU_SLUG ) return;
    $asset = plugin_dir_path( __FILE__ ) . 'assets/catalog-studio-admin.css';
    if ( is_file( $asset ) ) {
        wp_enqueue_style( 'mad4b-catalog-studio-admin',
            plugins_url( 'assets/catalog-studio-admin.css', __FILE__ ),
            array(), (string) filemtime( $asset ) );
    }
} );
add_action( 'admin_post_mcfm_export_template', 'mcfm_export_template_action' );


function mcfm_extract_template_payload_from_text( $raw_text ) {
	$raw_text = is_string( $raw_text ) ? trim( $raw_text ) : '';
	if ( '' === $raw_text ) {
		return new WP_Error( 'empty_template', 'Template content is empty.' );
	}

	$decoded = json_decode( $raw_text, true );
	if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
		if ( ! isset( $decoded['mapping'] ) || ! is_array( $decoded['mapping'] ) ) {
			return new WP_Error( 'invalid_json_template', 'JSON template is missing a valid mapping array.' );
		}
		return array(
			'type'    => 'json',
			'payload' => mcfm_normalize_feed( $decoded ),
		);
	}

	$headers = array();
	$lines   = preg_split( "/\r\n|\n|\r/", $raw_text );
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$headers = str_getcsv( $line );
		break;
	}

	if ( empty( $headers ) ) {
		return new WP_Error( 'no_headers', 'No headers detected.' );
	}

	$mapping = array();
	foreach ( $headers as $header ) {
		$header = trim( (string) $header );
		if ( '' === $header ) {
			continue;
		}
		$mapping[] = array(
			'column'             => $header,
			'source_type'        => 'meta',
			'source'             => $header,
			'extra'              => '',
			'required_non_empty' => 0,
		);
	}

	if ( empty( $mapping ) ) {
		return new WP_Error( 'no_headers', 'No usable headers detected.' );
	}

	return array(
		'type'    => 'headers',
		'payload' => array(
			'mapping' => $mapping,
		),
	);
}

function mcfm_extract_template_payload_from_upload( $file ) {
	if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'missing_upload', 'No uploaded file detected.' );
	}

	$raw_text = file_get_contents( $file['tmp_name'] );
	if ( false === $raw_text ) {
		return new WP_Error( 'unreadable_upload', 'Unable to read the uploaded template file.' );
	}

	return mcfm_extract_template_payload_from_text( $raw_text );
}


function mcfm_export_template_action() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Access denied.' );
	}
	check_admin_referer( 'mcfm_export_template' );
	$index = isset( $_GET['feed'] ) ? intval( $_GET['feed'] ) : -1;
	$feeds = mcfm_get_feeds();

	if ( ! isset( $feeds[ $index ] ) ) {
		wp_die( 'Feed not found.' );
	}

	$feed = mcfm_normalize_feed( $feeds[ $index ] );
	$filename = sanitize_file_name( ( ! empty( $feed['slug'] ) ? $feed['slug'] : 'feed' ) . '-template.json' );

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );
	echo wp_json_encode( $feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	exit;
}

function mcfm_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$feeds      = mcfm_get_feeds();
	$post_types = mcfm_get_public_post_types();
	$taxonomies = mcfm_get_public_taxonomies();
	$edit_index = isset( $_GET['edit'] ) ? max( 0, intval( $_GET['edit'] ) ) : null;

	if ( isset( $_POST['mcfm_action'] ) ) {
		check_admin_referer( 'mcfm_admin_action', 'mcfm_nonce' );
		$edit_lock = mcfm_feed_editor_lock();
		if ( is_wp_error( $edit_lock ) ) {
			add_settings_error( MCFM_MENU_SLUG, 'feed_busy', $edit_lock->get_error_message(), 'error' );
		} else {
		try {
			$feeds = mcfm_get_feeds();
			$submitted_revision = sanitize_text_field( wp_unslash( (string) ( $_POST['mcfm_revision'] ?? '' ) ) );
			if ( ! $submitted_revision || ! hash_equals( mcfm_feed_list_revision( $feeds ), $submitted_revision ) ) {
				add_settings_error( MCFM_MENU_SLUG, 'feed_revision_conflict', 'Another editor changed this feed. Reload before saving.', 'error' );
			} else {
		$action = sanitize_text_field( wp_unslash( $_POST['mcfm_action'] ) );

		if ( 'add_feed' === $action ) {
			$label       = sanitize_text_field( wp_unslash( $_POST['mcfm_label'] ?? '' ) );
			$slug        = sanitize_title( wp_unslash( $_POST['mcfm_slug'] ?? '' ) );
			$object_type = sanitize_text_field( wp_unslash( $_POST['mcfm_object_type'] ?? 'post_type' ) );
			$object_key  = sanitize_text_field( wp_unslash( $_POST['mcfm_object_key'] ?? '' ) );

			$valid = false;
			if ( 'taxonomy' === $object_type && isset( $taxonomies[ $object_key ] ) ) {
				$valid = true;
			}
			if ( 'post_type' === $object_type && isset( $post_types[ $object_key ] ) ) {
				$valid = true;
			}

			if ( ! $slug || ! $valid ) {
				add_settings_error( MCFM_MENU_SLUG, 'invalid_feed', 'Invalid feed settings.', 'error' );
			} else {
				foreach ( $feeds as $existing ) {
					if ( ! empty( $existing['slug'] ) && $existing['slug'] === $slug ) {
						add_settings_error( MCFM_MENU_SLUG, 'duplicate_slug', 'Slug already exists.', 'error' );
						$valid = false;
						break;
					}
				}
			}

			if ( $valid ) {
				$feed = mcfm_default_feed();
				$feed['label']       = $label ? $label : $slug;
				$feed['slug']        = $slug;
				$feed['object_type'] = $object_type;
				$feed['object_key']  = $object_key;
				$feeds[]             = $feed;
				mcfm_update_feeds( $feeds );
				flush_rewrite_rules( false );
				add_settings_error( MCFM_MENU_SLUG, 'feed_added', 'Feed added.', 'updated' );
			}
		}

		if ( 'delete_feed' === $action ) {
			$index = intval( $_POST['feed_index'] ?? -1 );
			if ( isset( $feeds[ $index ] ) ) {
				unset( $feeds[ $index ] );
				mcfm_update_feeds( $feeds );
				flush_rewrite_rules( false );
				add_settings_error( MCFM_MENU_SLUG, 'feed_deleted', 'Feed deleted.', 'updated' );
				$edit_index = null;
			}
		}

		if ( 'save_feed' === $action ) {
			$index = intval( $_POST['feed_index'] ?? -1 );
			if ( isset( $feeds[ $index ] ) ) {
				$feed = mcfm_normalize_feed( $feeds[ $index ] );
				$feed['label']            = sanitize_text_field( wp_unslash( $_POST['mcfm_label'] ?? '' ) );
				$feed['slug']             = sanitize_title( wp_unslash( $_POST['mcfm_slug'] ?? '' ) );
				$feed['default_format']   = sanitize_text_field( wp_unslash( $_POST['mcfm_default_format'] ?? 'csv' ) );
				$feed['xml_variant']      = sanitize_text_field( wp_unslash( $_POST['mcfm_xml_variant'] ?? 'rss' ) );
				$feed['language_mode']    = sanitize_text_field( wp_unslash( $_POST['mcfm_language_mode'] ?? 'all' ) );
				$feed['language_code']    = sanitize_text_field( wp_unslash( $_POST['mcfm_language_code'] ?? '' ) );
				$feed['status']           = sanitize_text_field( wp_unslash( $_POST['mcfm_status'] ?? 'publish' ) );
				$feed['hide_empty_terms'] = empty( $_POST['mcfm_hide_empty_terms'] ) ? 0 : 1;
				$feed['delimiter']        = 'tsv' === $feed['default_format'] ? "\t" : ',';

				$columns = $_POST['column'] ?? array();
				$types   = $_POST['source_type'] ?? array();
				$sources = $_POST['source'] ?? array();
				$extras    = $_POST['extra'] ?? array();
				$requireds = $_POST['required_non_empty'] ?? array();

				$mapping = array();
				$seen_columns = array();
				$had_errors = false;
				$count = max( count( $columns ), count( $types ), count( $sources ), count( $extras ) );
				for ( $i = 0; $i < $count; $i++ ) {
					$row = mcfm_sanitize_mapping_row(
						array(
							'column'      => $columns[ $i ] ?? '',
							'source_type' => $types[ $i ] ?? '',
							'source'      => $sources[ $i ] ?? '',
							'extra'              => $extras[ $i ] ?? '',
							'required_non_empty' => ! empty( $requireds[ $i ] ) ? 1 : 0,
						)
					);

					if ( '' === $row['column'] && '' === $row['source'] ) {
						continue;
					}

					if ( '' === $row['column'] ) {
						$had_errors = true;
						add_settings_error( MCFM_MENU_SLUG, 'mapping_missing_column_' . $i, 'Mapping row ' . ( $i + 1 ) . ' is missing an output column name.', 'error' );
						continue;
					}

					if ( in_array( $row['source_type'], array( 'object_field', 'meta', 'taxonomy_terms', 'computed' ), true ) && '' === $row['source'] ) {
						$had_errors = true;
						add_settings_error( MCFM_MENU_SLUG, 'mapping_missing_source_' . $i, 'Mapping row "' . esc_html( $row['column'] ) . '" is missing a source field.', 'error' );
						continue;
					}

					if ( isset( $seen_columns[ $row['column'] ] ) ) {
						$had_errors = true;
						add_settings_error( MCFM_MENU_SLUG, 'mapping_duplicate_column_' . $i, 'Duplicate output column "' . esc_html( $row['column'] ) . '" detected.', 'error' );
						continue;
					}

					$seen_columns[ $row['column'] ] = true;
					$mapping[] = $row;
				}

				$feed['mapping'] = $mapping;
				$feeds[ $index ] = $feed;
				mcfm_update_feeds( $feeds );
				mcfm_clear_meta_discovery_cache( $feed );
				flush_rewrite_rules( false );
				if ( ! $had_errors ) {
					add_settings_error( MCFM_MENU_SLUG, 'feed_saved', 'Feed saved.', 'updated' );
				} else {
					add_settings_error( MCFM_MENU_SLUG, 'feed_saved_with_warnings', 'Feed saved with warnings. Review the mapping errors above.', 'warning' );
				}
				$edit_index = $index;
			}
		}


		if ( 'refresh_meta_keys' === $action ) {
			$index = intval( $_POST['feed_index'] ?? -1 );
			if ( isset( $feeds[ $index ] ) ) {
				$feed = mcfm_normalize_feed( $feeds[ $index ] );
				mcfm_clear_meta_discovery_cache( $feed );
				mcfm_discover_meta_keys( $feed, true );
				add_settings_error( MCFM_MENU_SLUG, 'meta_keys_refreshed', 'Discovered meta keys refreshed.', 'updated' );
				$edit_index = $index;
			}
		}
		if ( 'import_template' === $action ) {
			$index = intval( $_POST['feed_index'] ?? -1 );
			if ( isset( $feeds[ $index ] ) ) {
				$result = null;

				if ( ! empty( $_FILES['template_file']['tmp_name'] ) ) {
					$result = mcfm_extract_template_payload_from_upload( $_FILES['template_file'] );
				} else {
					$paste  = wp_unslash( $_POST['template_paste'] ?? '' );
					$result = mcfm_extract_template_payload_from_text( $paste );
				}

				if ( is_wp_error( $result ) ) {
					add_settings_error( MCFM_MENU_SLUG, 'template_empty', $result->get_error_message(), 'error' );
				} else {
					$current_feed = mcfm_normalize_feed( $feeds[ $index ] );
					$payload      = $result['payload'];
					$type         = $result['type'];

					if ( 'json' === $type ) {
						$imported_feed = mcfm_normalize_feed( $payload );

						$imported_feed['label'] = ! empty( $current_feed['label'] ) ? $current_feed['label'] : $imported_feed['label'];
						$imported_feed['slug']  = ! empty( $current_feed['slug'] ) ? $current_feed['slug'] : $imported_feed['slug'];

						$feeds[ $index ] = $imported_feed;
						mcfm_update_feeds( $feeds );
						mcfm_clear_meta_discovery_cache( $imported_feed );
						add_settings_error( MCFM_MENU_SLUG, 'template_imported', 'JSON template imported successfully. Mapping and feed settings were updated; current label and slug were preserved.', 'updated' );
					} else {
						$current_feed['mapping'] = isset( $payload['mapping'] ) ? $payload['mapping'] : array();
						$feeds[ $index ]         = $current_feed;
						mcfm_update_feeds( $feeds );
						add_settings_error( MCFM_MENU_SLUG, 'template_imported', 'Header template imported into mapping.', 'updated' );
					}

					$edit_index = $index;
				}
			}
		}

		}
		} finally { mcfm_feed_editor_unlock(); }
		}
		$feeds = mcfm_get_feeds();
	}

	settings_errors( MCFM_MENU_SLUG );

	echo '<div class="wrap mcfm-admin">';
	$managed_count = 0;
	$destination_count = 0;
	foreach ( $feeds as $item ) {
		if ( str_starts_with( (string) ( $item['slug'] ?? '' ), 'mad4b-managed-' ) ) $managed_count++;
		if ( ( $item['object_type'] ?? '' ) === 'taxonomy' && ( $item['object_key'] ?? '' ) === 'destinations' ) $destination_count++;
	}
	echo '<section class="mcfm-studio-hero">';
	echo '<div class="mcfm-studio-hero-main"><span class="mcfm-studio-eyebrow">MAD4B / DISTRIBUTION SUITE</span>';
	echo '<h1>MAD4B Catalog Feed Studio</h1>';
	echo '<p>Manage WordPress, JetEngine and WPML data mappings for tours, destinations and marketplaces. MAD4B pricing and channel readiness are resolved by their original engines.</p>';
	echo '<div class="mcfm-studio-tags"><span>CSV / TSV</span><span>RSS / Atom</span><span>XLSX</span><span>WPML</span></div></div>';
	echo '<div class="mcfm-studio-side"><a class="button" href="' . esc_url( admin_url( 'tools.php?page=mad4b-distribution-channels' ) ) . '">Channels &amp; Marketplaces</a>';
	echo '<small>Channel connections and delivery are configured separately.</small></div></section>';
	echo '<div class="mcfm-studio-stats" aria-label="Catalog overview">';
	echo '<div><span>Configured feeds</span><strong>' . esc_html( (string) count( $feeds ) ) . '</strong></div>';
	echo '<div><span>Private MAD4B feeds</span><strong>' . esc_html( (string) $managed_count ) . '</strong></div>';
	echo '<div><span>Destination feeds</span><strong>' . esc_html( (string) $destination_count ) . '</strong></div>';
	echo '<div><span>Delivery status</span><strong class="mcfm-studio-state">Managed per channel</strong></div>';
	echo '</div>';

	if ( null !== $edit_index && isset( $feeds[ $edit_index ] ) ) {
		$feed = mcfm_normalize_feed( $feeds[ $edit_index ] );

		$mapping = $feed['mapping'];
		if ( count( $mapping ) < 5 ) {
			for ( $i = count( $mapping ); $i < 5; $i++ ) {
				$mapping[] = array(
					'column'             => '',
					'source_type'        => 'meta',
					'source'             => '',
					'extra'              => '',
					'required_non_empty' => 0,
				);
			}
		}

		$languages             = mcfm_get_active_languages();
		$mapping_types         = mcfm_get_mapping_types( $feed );
		$object_options        = mcfm_get_object_field_options( $feed );
		$computed_options      = mcfm_get_computed_options( $feed );
		$taxonomy_options      = mcfm_get_post_taxonomy_options( $feed );
		$taxonomy_extra        = mcfm_get_taxonomy_term_extra_options();
		$featured_image_extra  = mcfm_get_featured_image_extra_options();
		$discovered_meta_keys  = mcfm_discover_meta_keys( $feed );
		$meta_options          = array();

		foreach ( $discovered_meta_keys as $meta_row ) {
			$label = $meta_row['key'] . ' (' . intval( $meta_row['count'] ) . ')';
			if ( ! empty( $meta_row['sample'] ) ) {
				$label .= ' — ' . $meta_row['sample'];
			}
			$meta_options[ $meta_row['key'] ] = $label;
		}

		echo '<style>
			.mcfm-mapping-table th,.mcfm-mapping-table td{vertical-align:top}
			.mcfm-row-actions{white-space:nowrap}
			.mcfm-source-wrap,.mcfm-extra-wrap{display:flex;flex-direction:column;gap:6px}
			.mcfm-source-select,.mcfm-extra-select{max-width:100%}
			.mcfm-source-custom,.mcfm-extra-custom{max-width:100%}
			.mcfm-inline-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0 14px}
			.mcfm-help-grid{background:#fff;border:1px solid #dcdcde;padding:12px 14px;margin:12px 0}
			.mcfm-help-grid ul{margin:4px 0 0 18px}
			.mcfm-preview-note{color:#50575e}
		</style>';

		echo '<h2>Edit Feed: ' . esc_html( $feed['label'] ) . '</h2>';
		echo '<p><a href="' . esc_url( admin_url( 'options-general.php?page=' . MCFM_MENU_SLUG ) ) . '">&laquo; Back to feeds list</a></p>';

		echo '<form method="post" action="" id="mcfm-feed-form">';
		wp_nonce_field( 'mcfm_admin_action', 'mcfm_nonce' );
		echo '<input type="hidden" name="mcfm_revision" value="' . esc_attr( mcfm_feed_list_revision( $feeds ) ) . '" />';
		echo '<input type="hidden" name="mcfm_action" value="save_feed" />';
		echo '<input type="hidden" name="feed_index" value="' . intval( $edit_index ) . '" />';

		echo '<table class="form-table">';
		echo '<tr><th><label>Label</label></th><td><input type="text" name="mcfm_label" class="regular-text" value="' . esc_attr( $feed['label'] ) . '" /></td></tr>';
		echo '<tr><th><label>Slug</label></th><td><input type="text" name="mcfm_slug" class="regular-text" value="' . esc_attr( $feed['slug'] ) . '" /></td></tr>';
		echo '<tr><th>Source</th><td><strong>' . esc_html( $feed['object_type'] ) . '</strong>: <code>' . esc_html( $feed['object_key'] ) . '</code></td></tr>';
		echo '<tr><th>Default format</th><td><select name="mcfm_default_format">';
		foreach ( mcfm_get_supported_formats() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $feed['default_format'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th>XML variant for .xml</th><td><select name="mcfm_xml_variant">';
		echo '<option value="rss" ' . selected( $feed['xml_variant'], 'rss', false ) . '>RSS</option>';
		echo '<option value="atom" ' . selected( $feed['xml_variant'], 'atom', false ) . '>Atom</option>';
		echo '</select></td></tr>';
		echo '<tr><th>Language mode</th><td><select name="mcfm_language_mode">';
		echo '<option value="all" ' . selected( $feed['language_mode'], 'all', false ) . '>All active languages / default when no lang in URL</option>';
		echo '<option value="default" ' . selected( $feed['language_mode'], 'default', false ) . '>Default language only</option>';
		echo '<option value="specific" ' . selected( $feed['language_mode'], 'specific', false ) . '>Specific language only</option>';
		echo '</select></td></tr>';
		echo '<tr><th>Specific language</th><td><select name="mcfm_language_code"><option value="">— Select —</option>';
		foreach ( $languages as $code => $lang_obj ) {
			$name = is_array( $lang_obj ) ? ( $lang_obj['native_name'] ?? $code ) : $code;
			echo '<option value="' . esc_attr( $code ) . '" ' . selected( $feed['language_code'] ?? '', $code, false ) . '>' . esc_html( $name . ' (' . $code . ')' ) . '</option>';
		}
		echo '</select></td></tr>';

		if ( 'post_type' === $feed['object_type'] ) {
			echo '<tr><th>Post status</th><td><input type="text" name="mcfm_status" class="regular-text" value="' . esc_attr( $feed['status'] ) . '" /><p class="description">Comma-separated. Example: publish or publish,future</p></td></tr>';
		} else {
			echo '<tr><th>Terms</th><td><label><input type="checkbox" name="mcfm_hide_empty_terms" value="1" ' . checked( ! empty( $feed['hide_empty_terms'] ), true, false ) . ' /> Hide empty terms</label></td></tr>';
		}
		echo '</table>';

		echo '<h2>Column Mapping</h2>';
		echo '<div class="mcfm-help-grid">';
		echo '<p><strong>What changed:</strong> Source field selection is now assisted with discovered meta keys, object fields, taxonomy selectors, add/remove rows, duplicate row, and save-time validation.</p>';
		echo '<ul>';
		echo '<li><strong>Object field help:</strong> ' . esc_html( mcfm_get_object_fields_help( $feed ) ) . '</li>';
		echo '<li><strong>Computed help:</strong> ' . esc_html( mcfm_get_computed_help( $feed ) ) . '</li>';
		if ( 'post_type' === $feed['object_type'] ) {
			echo '<li><strong>Taxonomy terms extra:</strong> names (blank), slugs, ids, first, links</li>';
			echo '<li><strong>Featured image extra:</strong> full, large, medium, thumbnail. No source field required.</li>';
		} else {
			echo '<li><strong>Taxonomy meta:</strong> choose <em>Meta key</em> and select a discovered JetEngine term meta key.</li>';
		}
		echo '<li><strong>Meta key discovery:</strong> built from a recent sample of up to 500 objects, with usage count and sample value preview.</li>';
		echo '<li><strong>Required value checkbox:</strong> when checked, the whole item/term is skipped if this mapped value is empty. Use it for destination_id, url, image[0].url, address.city/country, latitude/longitude, or any Meta-required field.</li>';
		echo '</ul>';
		echo '</div>';

		echo '<div class="mcfm-inline-actions">';
		echo '<button type="button" class="button" id="mcfm-add-row">Add row</button>';
		echo '<span class="mcfm-preview-note">Use Duplicate to clone a configured row. Use Remove to delete one.</span>';
		echo '</div>';

		echo '<table class="widefat striped mcfm-mapping-table">';
		echo '<thead><tr><th style="width:17%;">Output column name</th><th style="width:15%;">Value source</th><th style="width:31%;">Source field / key</th><th style="width:18%;">Options</th><th style="width:9%;">Required value</th><th style="width:10%;">Actions</th></tr></thead><tbody id="mcfm-mapping-body">';
		foreach ( $mapping as $row ) {
			echo '<tr class="mcfm-map-row" data-source="' . esc_attr( $row['source'] ) . '" data-extra="' . esc_attr( $row['extra'] ) . '">';
			echo '<td><input type="text" name="column[]" class="regular-text" value="' . esc_attr( $row['column'] ) . '" placeholder="id, title, price, image_link" /></td>';
			echo '<td><select name="source_type[]" class="mcfm-source-type">';
			foreach ( $mapping_types as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '" ' . selected( $row['source_type'], $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td>';
			echo '<td><div class="mcfm-source-wrap">';
			echo '<select class="mcfm-source-select regular-text"></select>';
			echo '<input type="text" class="regular-text mcfm-source-custom" style="display:none;" placeholder="Enter custom key or value" />';
			echo '</div></td>';
			echo '<td><div class="mcfm-extra-wrap">';
			echo '<select class="mcfm-extra-select regular-text" style="display:none;"></select>';
			echo '<input type="text" name="extra[]" class="regular-text mcfm-extra-custom" value="' . esc_attr( $row['extra'] ) . '" placeholder="Optional" />';
			echo '</div></td>';
			echo '<td><input type="hidden" name="required_non_empty[]" class="mcfm-required-hidden" value="' . ( ! empty( $row['required_non_empty'] ) ? '1' : '0' ) . '" /><label><input type="checkbox" class="mcfm-required-checkbox" value="1" ' . checked( ! empty( $row['required_non_empty'] ), true, false ) . ' /> Hide row if empty</label></td>';
			echo '<td class="mcfm-row-actions"><button type="button" class="button button-small mcfm-duplicate-row">Duplicate</button> <button type="button" class="button button-small mcfm-remove-row">Remove</button></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<p class="submit"><input type="submit" class="button button-primary" value="Save Feed" /></p>';
		echo '</form>';

		echo '<form method="post" action="" style="margin:8px 0 20px;">';
		wp_nonce_field( 'mcfm_admin_action', 'mcfm_nonce' );
		echo '<input type="hidden" name="mcfm_revision" value="' . esc_attr( mcfm_feed_list_revision( $feeds ) ) . '" />';
		echo '<input type="hidden" name="mcfm_action" value="refresh_meta_keys" />';
		echo '<input type="hidden" name="feed_index" value="' . intval( $edit_index ) . '" />';
		echo '<input type="submit" class="button" value="Refresh discovered meta keys" />';
		echo '</form>';

		$mcfm_ui_data = array(
			'mappingTypes'        => $mapping_types,
			'objectOptions'       => $object_options,
			'computedOptions'     => $computed_options,
			'metaOptions'         => $meta_options,
			'taxonomyOptions'     => $taxonomy_options,
			'taxonomyExtra'       => $taxonomy_extra,
			'featuredImageExtra'  => $featured_image_extra,
			'isPostType'          => 'post_type' === $feed['object_type'],
		);

		echo '<script>';
		echo 'window.MCFM_UI = ' . wp_json_encode( $mcfm_ui_data ) . ';';
		echo '(function(){';
		echo 'const data = window.MCFM_UI || {};';
		echo 'const tbody = document.getElementById("mcfm-mapping-body");';
		echo 'const addBtn = document.getElementById("mcfm-add-row");';
		echo 'if(!tbody){return;}';
		echo 'function esc(s){return String(s===undefined?"":s);}';
		echo 'function selectOptions(options, selected, includeCustom, placeholder){ var html = "<option value=\"\">" + esc(placeholder || "— Select —") + "</option>"; Object.keys(options || {}).forEach(function(key){ html += "<option value=\"" + key.replace(/&/g,"&amp;").replace(/\"/g,"&quot;").replace(/</g,"&lt;") + "\"" + (String(selected)===String(key)?" selected":"") + ">" + String(options[key]).replace(/&/g,"&amp;").replace(/</g,"&lt;") + "</option>"; }); if(includeCustom){ html += "<option value=\"__custom__\"" + ((selected && !options[selected])?" selected":"") + ">Custom…</option>"; } return html; }';
		echo 'function configureRow(row){';
		echo 'const typeEl = row.querySelector(".mcfm-source-type");';
		echo 'const sourceSelect = row.querySelector(".mcfm-source-select");';
		echo 'const sourceCustom = row.querySelector(".mcfm-source-custom");';
		echo 'const extraSelect = row.querySelector(".mcfm-extra-select");';
		echo 'const extraCustom = row.querySelector(".mcfm-extra-custom");';
		echo 'const selectedSource = row.getAttribute("data-source") || sourceCustom.value || "";';
		echo 'const selectedExtra = row.getAttribute("data-extra") || extraCustom.value || "";';
		echo 'let opts = {}, showSourceSelect = false, showSourceCustom = false, sourcePlaceholder = "— Select —";';
		echo 'let extraOpts = null, showExtraSelect = false, extraPlaceholder = "Optional";';
		echo 'switch(typeEl.value){';
		echo 'case "object_field": opts = data.objectOptions || {}; showSourceSelect = true; sourcePlaceholder = "Select object field"; break;';
		echo 'case "meta": opts = data.metaOptions || {}; showSourceSelect = true; sourcePlaceholder = "Select discovered meta key"; break;';
		echo 'case "computed": opts = data.computedOptions || {}; showSourceSelect = true; sourcePlaceholder = "Select computed field"; break;';
		echo 'case "taxonomy_terms": opts = data.taxonomyOptions || {}; showSourceSelect = true; sourcePlaceholder = "Select taxonomy"; extraOpts = data.taxonomyExtra || {}; showExtraSelect = true; extraPlaceholder = "Output mode"; break;';
		echo 'case "featured_image": extraOpts = data.featuredImageExtra || {}; showExtraSelect = true; extraPlaceholder = "Image size"; sourcePlaceholder = "No source field required"; break;';
		echo 'case "static": showSourceCustom = true; sourcePlaceholder = "Static value"; break;';
		echo 'case "language_code": sourcePlaceholder = "No source needed"; break;';
		echo 'default: showSourceCustom = true; break;';
		echo '}';
		echo 'if(showSourceSelect){ sourceSelect.innerHTML = selectOptions(opts, selectedSource, true, sourcePlaceholder); sourceSelect.style.display = ""; sourceSelect.name = "source[]"; sourceCustom.style.display = "none"; sourceCustom.name = ""; if(sourceSelect.value === "__custom__"){ sourceSelect.style.display = ""; sourceCustom.style.display = ""; sourceCustom.name = "source[]"; sourceSelect.name = ""; sourceCustom.placeholder = sourcePlaceholder; if(!sourceCustom.value){ sourceCustom.value = selectedSource && !opts[selectedSource] ? selectedSource : ""; } } } else if(showSourceCustom){ sourceSelect.style.display = "none"; sourceSelect.name = ""; sourceCustom.style.display = ""; sourceCustom.name = "source[]"; sourceCustom.placeholder = sourcePlaceholder; if(!sourceCustom.value){ sourceCustom.value = selectedSource; } } else { sourceSelect.style.display = "none"; sourceSelect.name = ""; sourceCustom.style.display = ""; sourceCustom.name = "source[]"; sourceCustom.placeholder = sourcePlaceholder; sourceCustom.value = ""; }';
		echo 'if(showExtraSelect){ extraSelect.innerHTML = selectOptions(extraOpts, selectedExtra, false, extraPlaceholder); extraSelect.style.display = ""; extraSelect.name = "extra[]"; extraCustom.style.display = "none"; extraCustom.name = ""; } else { extraSelect.style.display = "none"; extraSelect.name = ""; extraCustom.style.display = ""; extraCustom.name = "extra[]"; if(!extraCustom.value){ extraCustom.value = selectedExtra; } }';
		echo 'row.setAttribute("data-source",""); row.setAttribute("data-extra","");';
		echo '}';
		echo 'function syncRequired(row){ const cb = row.querySelector(".mcfm-required-checkbox"); const hidden = row.querySelector(".mcfm-required-hidden"); if(cb && hidden){ hidden.value = cb.checked ? "1" : "0"; } } function bindRow(row){ configureRow(row); syncRequired(row); const req = row.querySelector(".mcfm-required-checkbox"); if(req){ req.addEventListener("change", function(){ syncRequired(row); }); } row.querySelector(".mcfm-source-type").addEventListener("change", function(){ row.setAttribute("data-source",""); row.setAttribute("data-extra",""); row.querySelector(".mcfm-source-custom").value = ""; row.querySelector(".mcfm-extra-custom").value = ""; configureRow(row); }); row.querySelector(".mcfm-source-select").addEventListener("change", function(){ if(this.value === "__custom__"){ row.setAttribute("data-source",""); configureRow(row); } }); row.querySelector(".mcfm-duplicate-row").addEventListener("click", function(){ const clone = row.cloneNode(true); tbody.insertBefore(clone, row.nextSibling); bindRow(clone); }); row.querySelector(".mcfm-remove-row").addEventListener("click", function(){ if(tbody.querySelectorAll(".mcfm-map-row").length === 1){ row.querySelectorAll("input").forEach(function(el){ el.value=""; }); const requiredCheckbox = row.querySelector(".mcfm-required-checkbox"); if(requiredCheckbox){ requiredCheckbox.checked = false; } syncRequired(row); row.querySelector(".mcfm-source-type").value = "meta"; configureRow(row); return; } row.remove(); }); }';
		echo 'function addRow(){ const row = document.createElement("tr"); row.className = "mcfm-map-row"; row.setAttribute("data-source",""); row.setAttribute("data-extra",""); row.innerHTML = `<td><input type="text" name="column[]" class="regular-text" placeholder="id, title, price, image_link" /></td><td><select name="source_type[]" class="mcfm-source-type">` + selectOptions(data.mappingTypes || {}, "meta", false, "— Select —") + `</select></td><td><div class="mcfm-source-wrap"><select class="mcfm-source-select regular-text"></select><input type="text" class="regular-text mcfm-source-custom" style="display:none;" placeholder="Enter custom key or value" /></div></td><td><div class="mcfm-extra-wrap"><select class="mcfm-extra-select regular-text" style="display:none;"></select><input type="text" name="extra[]" class="regular-text mcfm-extra-custom" placeholder="Optional" /></div></td><td><input type="hidden" name="required_non_empty[]" class="mcfm-required-hidden" value="0" /><label><input type="checkbox" class="mcfm-required-checkbox" value="1" /> Hide row if empty</label></td><td class="mcfm-row-actions"><button type="button" class="button button-small mcfm-duplicate-row">Duplicate</button> <button type="button" class="button button-small mcfm-remove-row">Remove</button></td>`; tbody.appendChild(row); bindRow(row); }';
		echo 'tbody.querySelectorAll(".mcfm-map-row").forEach(bindRow);';
		echo 'if(addBtn){ addBtn.addEventListener("click", addRow); }';
		echo 'document.getElementById("mcfm-feed-form").addEventListener("submit", function(e){ const seen = {}; let errors = []; tbody.querySelectorAll(".mcfm-map-row").forEach(function(row){ syncRequired(row); }); tbody.querySelectorAll(".mcfm-map-row").forEach(function(row, idx){ const col = row.querySelector(\'input[name="column[]"]\').value.trim(); let sourceEl = row.querySelector(\'[name="source[]"]\'); const source = sourceEl ? sourceEl.value.trim() : ""; const type = row.querySelector(".mcfm-source-type").value; if(!col && !source){ return; } if(!col){ errors.push("Row " + (idx+1) + " is missing an output column name."); return; } if(["object_field","meta","taxonomy_terms","computed"].indexOf(type) !== -1 && !source){ errors.push("Row " + (idx+1) + " is missing a source field."); return; } if(seen[col]){ errors.push("Duplicate output column: " + col); } seen[col] = true; }); if(errors.length){ alert(errors.join("\\n")); e.preventDefault(); } });';
		echo '})();';
		echo '</script>';
		echo '<hr />';
		echo '<h2>Import Template or Headers</h2>';
		echo '<p class="description">JSON imports restore the full mapping template. CSV or pasted header lines create meta-key mapping rows from the header names.</p>';
		echo '<form method="post" enctype="multipart/form-data" action="">';
		wp_nonce_field( 'mcfm_admin_action', 'mcfm_nonce' );
		echo '<input type="hidden" name="mcfm_revision" value="' . esc_attr( mcfm_feed_list_revision( $feeds ) ) . '" />';
		echo '<input type="hidden" name="mcfm_action" value="import_template" />';
		echo '<input type="hidden" name="feed_index" value="' . intval( $edit_index ) . '" />';
		echo '<p><input type="file" name="template_file" accept=".json,.csv,.txt" /></p>';
		echo '<p>Upload a full exported JSON template, or paste JSON / a single CSV header line:</p>';
		echo '<p><textarea name="template_paste" rows="3" style="width:100%;"></textarea></p>';
		echo '<p class="submit"><input type="submit" class="button" value="Import Template" /></p>';
		echo '</form>';

		echo '<hr />';
		echo '<h2>Feed URLs</h2>';
		echo '<ul>';
		foreach ( mcfm_get_supported_formats() as $format_key => $label ) {
			echo '<li><strong>' . esc_html( $label ) . ':</strong> <code>' . esc_html( mcfm_feed_url( $feed, $format_key ) ) . '</code></li>';
		}
		echo '</ul>';

		if ( ! empty( $languages ) ) {
			echo '<h3>Per-language URLs</h3><ul>';
			foreach ( $languages as $code => $lang_obj ) {
				$name = is_array( $lang_obj ) ? ( $lang_obj['native_name'] ?? $code ) : $code;
				echo '<li><strong>' . esc_html( $name . ' (' . $code . ')' ) . ':</strong> <code>' . esc_html( mcfm_feed_url( $feed, $feed['default_format'], $code ) ) . '</code></li>';
			}
			echo '</ul>';
		}

		echo '<p><strong>Large-file note:</strong> CSV, TSV, RSS, and Atom are streamed directly. XLSX is generated in temporary files on disk before download, which is more scalable than in-memory generation.</p>';

		echo '</div>';
		return;
	}

	echo '<h2>Add Feed</h2>';
	echo '<form method="post" action="">';
	wp_nonce_field( 'mcfm_admin_action', 'mcfm_nonce' );
		echo '<input type="hidden" name="mcfm_revision" value="' . esc_attr( mcfm_feed_list_revision( $feeds ) ) . '" />';
	echo '<input type="hidden" name="mcfm_action" value="add_feed" />';

	echo '<table class="form-table">';
	echo '<tr><th><label>Label</label></th><td><input type="text" name="mcfm_label" class="regular-text" /></td></tr>';
	echo '<tr><th><label>Slug</label></th><td><input type="text" name="mcfm_slug" class="regular-text" placeholder="example-feed" /></td></tr>';
	echo '<tr><th><label>Source type</label></th><td><select name="mcfm_object_type" id="mcfm_object_type_select">';
	echo '<option value="post_type">Post type</option>';
	echo '<option value="taxonomy">Taxonomy</option>';
	echo '</select></td></tr>';
	echo '<tr><th><label>Post type</label></th><td><select name="mcfm_object_key" id="mcfm_post_type_select">';
	foreach ( $post_types as $key => $obj ) {
		echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $obj->labels->name . ' (' . $key . ')' ) . '</option>';
	}
	echo '</select>';
	echo '<select name="mcfm_object_key_tax" id="mcfm_taxonomy_select" style="display:none;">';
	foreach ( $taxonomies as $key => $obj ) {
		echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $obj->labels->name . ' (' . $key . ')' ) . '</option>';
	}
	echo '</select>';
	echo '<p class="description">Choose the post type or taxonomy that this feed will export.</p></td></tr>';
	echo '</table>';

	echo '<p class="submit"><input type="submit" class="button button-primary" value="Add Feed" /></p>';
	echo '</form>';

	echo '<script>
	(function(){
		var typeSelect = document.getElementById("mcfm_object_type_select");
		var postSelect = document.getElementById("mcfm_post_type_select");
		var taxSelect  = document.getElementById("mcfm_taxonomy_select");
		if (!typeSelect || !postSelect || !taxSelect) return;
		function sync(){
			if (typeSelect.value === "taxonomy") {
				postSelect.style.display = "none";
				postSelect.disabled = true;
				taxSelect.style.display = "";
				taxSelect.disabled = false;
				postSelect.removeAttribute("name");
				taxSelect.setAttribute("name", "mcfm_object_key");
			} else {
				postSelect.style.display = "";
				postSelect.disabled = false;
				taxSelect.style.display = "none";
				taxSelect.disabled = true;
				taxSelect.removeAttribute("name");
				postSelect.setAttribute("name", "mcfm_object_key");
			}
		}
		typeSelect.addEventListener("change", sync);
		sync();
	})();
	</script>';

	echo '<hr />';
	echo '<h2>Configured Feeds</h2>';

	if ( empty( $feeds ) ) {
		echo '<p><em>No feeds configured yet.</em></p>';
	} else {
		echo '<table class="widefat striped">';
		echo '<thead><tr><th>#</th><th>Label</th><th>Slug</th><th>Source</th><th>Default</th><th>Main URL</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $feeds as $index => $feed ) {
			$feed = mcfm_normalize_feed( $feed );
			$edit_url = add_query_arg(
				array(
					'page' => MCFM_MENU_SLUG,
					'edit' => $index,
				),
				admin_url( 'options-general.php' )
			);
			$export_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'mcfm_export_template',
						'feed'   => $index,
					),
					admin_url( 'admin-post.php' )
				),
				'mcfm_export_template'
			);
			echo '<tr>';
			echo '<td>' . ( $index + 1 ) . '</td>';
			$is_private = str_starts_with( (string) $feed['slug'], 'mad4b-managed-' );
			echo '<td><strong>' . esc_html( $feed['label'] ) . '</strong>' .
				( $is_private ? ' <span class="mcfm-studio-private">Private / MAD4B</span>' : '' ) . '</td>';
			echo '<td><code>' . esc_html( $feed['slug'] ) . '</code></td>';
			echo '<td>' . esc_html( $feed['object_type'] . ': ' . $feed['object_key'] ) . '</td>';
			echo '<td>' . esc_html( strtoupper( $feed['default_format'] ) ) . '</td>';
			echo '<td><code>' . esc_html( mcfm_feed_url( $feed, $feed['default_format'] ) ) . '</code>' .
				( $is_private ? '<div class="mcfm-studio-note">Admin-only preview, not a partner delivery URL</div>' : '' ) . '</td>';
			echo '<td>';
			echo '<a class="button" href="' . esc_url( $edit_url ) . '">Edit</a> ';
			echo '<a class="button" href="' . esc_url( $export_url ) . '">Export Template</a> ';
			echo '<form method="post" action="" style="display:inline">';
			wp_nonce_field( 'mcfm_admin_action', 'mcfm_nonce' );
		echo '<input type="hidden" name="mcfm_revision" value="' . esc_attr( mcfm_feed_list_revision( $feeds ) ) . '" />';
			echo '<input type="hidden" name="mcfm_action" value="delete_feed" />';
			echo '<input type="hidden" name="feed_index" value="' . intval( $index ) . '" />';
			echo '<input type="submit" class="button button-link-delete" value="Delete" onclick="return confirm(\'Delete this feed?\');" />';
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	echo '<p><strong>After activation or major changes, visit Settings → Permalinks and click Save once if your feed URLs do not resolve immediately.</strong></p>';
	echo '</div>';
}
