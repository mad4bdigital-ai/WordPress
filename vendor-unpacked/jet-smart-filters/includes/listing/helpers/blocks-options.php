<?php

namespace Jet_Smart_Filters\Listing\Helpers;

use Jet_Smart_Filters\Listing\Controller as Listing_Controller;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class Blocks_Options {

	protected $listing_instance;

	public function __construct() {

		$this->listing_instance = Listing_Controller::instance();
	}

	/**
	 * Get listings options
	 *
	 * @return void
	 */
	public function get_listings( $args = [], $as_pairs = true ) {

		$untitled_item_name = apply_filters(
			'jet-smart-filters/listing/untitled_listing_name',
			__( 'Untitled Listing', 'jet-smart-filters' )
		);

		$listings_list = $this->listing_instance->storage->get_listings_list( $args, $as_pairs );

		if ( $as_pairs ) {
			foreach ( $listings_list as $id => $label ) {
				if ( empty( $label ) ) {
					$listings_list[ $id ] = $untitled_item_name . ' (Id: ' . $id . ')';
				}
			}
		} else {
			foreach ( $listings_list as &$listing ) {
				if ( empty( $listing['label'] ) ) {
					$listing['label'] = $untitled_item_name . ' (Id: ' . $listing['value'] . ')';
				}
			}
		}

    return $listings_list;
	}

	/**
	 * Get post types list for options
	 *
	 * @return Array
	 */
	public function get_post_types_options() {

		return apply_filters(
			'jet-smart-filters/listing/blocks-options/post-types',
			jet_smart_filters()->data->get_post_types_for_options()
		);
	}

	/**
	 * Get posts order by list for options
	 *
	 * @return Array
	 */
	public function get_posts_order_by_options() {

		return apply_filters(
			'jet-smart-filters/listing/blocks-options/post-order-by',
			jet_smart_filters()->data->get_posts_order_by_options()
		);
	}

	/**
	 * Get taxonomies list for options
	 */
	public function get_taxonomies_options() {

		return apply_filters(
			'jet-smart-filters/listing/blocks-options/taxonomies',
			jet_smart_filters()->data->get_taxonomies_for_options()
		);
	}

	/**
	 * Get grouped taxonomies list for option
	 */
	public function get_grouped_taxonomies_options() {

		return apply_filters(
			'jet-smart-filters/listing/blocks-options/grouped-taxonomies',
			jet_smart_filters()->data->get_grouped_taxonomies_options()
		);
	}

	/**
	 * Get taxonomy term field list for options
	 */
	public function get_taxonomy_term_field_options() {

		return apply_filters(
			'jet-smart-filters/listing/blocks-options/taxonomy-term-field',
			jet_smart_filters()->data->get_taxonomy_term_field_for_options()
		);
	}

	/**
	 * Get term compare operators list for options
	 */
	public function get_term_compare_operator_options() {

		return apply_filters(
			'jet-smart-filters/listing/blocks-options/term-compare-operator',
			jet_smart_filters()->data->get_term_compare_operators_for_options()
		);
	}

	/**
	 * Get meta compare operators list for options
	 */
	public function get_meta_compare_operator_options() {

		return apply_filters(
			'jet-smart-filters/listing/blocks-options/meta-compare-operator',
			jet_smart_filters()->data->get_meta_compare_operators_for_options()
		);
	}

	/**
	 * Get meta type list for options
	 */
	public function get_meta_type_options() {

		return apply_filters(
			'jet-smart-filters/listing/blocks-options/meta-type',
			jet_smart_filters()->data->get_meta_type_for_options()
		);
	}

	/**
	 * Get post stati list for options
	 */
	public function get_post_stati_options() {

		return apply_filters(
			'jet-smart-filters/listing/blocks-options/post-stati',
			jet_smart_filters()->data->get_post_stati_for_options()
		);
	}

	/**
	 * Returns available list sources
	 */
	public function get_field_sources() {

		return apply_filters( 'jet-smart-filters/listing/blocks-options/field-sources', array(
			'object' => __( 'Post/Term/User/Object Data', 'jet-smart-filters' ),
			'meta'   => __( 'Meta Data', 'jet-smart-filters' ),
			'option' => __( 'Option Value', 'jet-smart-filters' ),
		) );
	}

	/**
	 * Retuns current object fields array
	 */
	public function get_object_fields() {

		$groups = array(
			'post'    => array(
				'label'  => __( 'Post', 'jet-smart-filters' ),
				'options' => apply_filters( 'jet-smart-filters/listing/blocks-options/object-fields/post', array(
					'post_id'       => __( 'Post ID', 'jet-smart-filters' ),
					'post_title'    => __( 'Title', 'jet-smart-filters' ),
					'post_name'     => __( 'Post Slug', 'jet-smart-filters' ),
					'post_type'     => __( 'Post Type', 'jet-smart-filters' ),
					'post_date'     => __( 'Date', 'jet-smart-filters' ),
					'post_modified' => __( 'Date Modified', 'jet-smart-filters' ),
					'post_content'  => __( 'Content', 'jet-smart-filters' ),
					'post_excerpt'  => __( 'Excerpt', 'jet-smart-filters' ),
					'post_status'   => __( 'Post Status', 'jet-smart-filters' ),
				)
			) ),
			'term'    => array(
				'label'  => __( 'Term', 'jet-smart-filters' ),
				'options' => apply_filters( 'jet-smart-filters/listing/blocks-options/object-fields/term', array(
					'term_id'     => __( 'Term ID', 'jet-smart-filters' ),
					'name'        => __( 'Term name', 'jet-smart-filters' ),
					'slug'        => __( 'Term slug', 'jet-smart-filters' ),
					'description' => __( 'Term description', 'jet-smart-filters' ),
					'count'       => __( 'Posts count', 'jet-smart-filters' ),
					'parent'      => __( 'Parent term ID', 'jet-smart-filters' ),
				)
			) ),
			'user'    => array(
				'label'   => __( 'User', 'jet-smart-filters' ),
				'options' => apply_filters( 'jet-smart-filters/listing/blocks-options/object-fields/user', array(
					'ID'              => __( 'ID', 'jet-smart-filters' ),
					'user_login'      => __( 'Login', 'jet-smart-filters' ),
					'user_nicename'   => __( 'Nickname', 'jet-smart-filters' ),
					'user_email'      => __( 'E-mail', 'jet-smart-filters' ),
					'user_url'        => __( 'URL', 'jet-smart-filters' ),
					'user_registered' => __( 'Registration Date', 'jet-smart-filters' ),
					'display_name'    => __( 'Display Name', 'jet-smart-filters' ),
				)
			) ),
			'comment' => array(
				'label'   => __( 'Comment', 'jet-smart-filters' ),
				'options' => apply_filters( 'jet-smart-filters/listing/blocks-options/object-fields/comment', array(
					'comment_ID'           => __( 'ID', 'jet-smart-filters' ),
					'comment_post_ID'      => __( 'Post ID', 'jet-smart-filters' ),
					'comment_author'       => __( 'Author', 'jet-smart-filters' ),
					'comment_author_email' => __( 'Author E-mail', 'jet-smart-filters' ),
					'comment_author_url'   => __( 'Author URL', 'jet-smart-filters' ),
					'comment_author_IP'    => __( 'Author IP', 'jet-smart-filters' ),
					'comment_date'         => __( 'Date', 'jet-smart-filters' ),
					'comment_date_gmt'     => __( 'Date GMT', 'jet-smart-filters' ),
					'comment_content'      => __( 'Content', 'jet-smart-filters' ),
					'comment_karma'        => __( 'Karma', 'jet-smart-filters' ),
					'comment_approved'     => __( 'Approved', 'jet-smart-filters' ),
					'comment_agent'        => __( 'Agent', 'jet-smart-filters' ),
					'comment_type'         => __( 'Type', 'jet-smart-filters' ),
					'comment_parent'       => __( 'Parent', 'jet-smart-filters' ),
					'user_id'              => __( 'User ID', 'jet-smart-filters' ),
				)
			) )
		);

		return apply_filters( 'jet-smart-filters/listing/blocks-options/object-fields', $groups );
	}

	/**
	 * Retruns registered callbacks list to use in options
	 * 
	 * @return array
	 */
	public function get_filter_сallbacks() {

		return apply_filters( 'jet-smart-filters/listing/blocks-options/filter-сallbacks', array(
			'date'                    => __( 'Format date', 'jet-smart-filters' ),
			'date_i18n'               => __( 'Format date, localized', 'jet-smart-filters' ),
			'number_format'           => __( 'Format number', 'jet-smart-filters' ),
			'get_the_title'           => __( 'Get post/page title', 'jet-smart-filters' ),
			'get_permalink'           => __( 'Get post/page URL', 'jet-smart-filters' ),
			'get_term_link'           => __( 'Get term URL', 'jet-smart-filters' ),
			'wp_oembed_get'           => __( 'Embed URL', 'jet-smart-filters' ),
			'make_clickable'          => __( 'Make clickable', 'jet-smart-filters' ),
			'wp_get_attachment_image' => __( 'Get image by ID', 'jet-smart-filters' ),
			'do_shortcode'            => __( 'Do shortcodes', 'jet-smart-filters' ),
			'human_time_diff'         => __( 'Human readable time difference', 'jet-smart-filters' ),
			'wpautop'                 => __( 'Add paragraph tags (wpautop)', 'jet-smart-filters' ),
			'zeroise'                 => __( 'Zeroise (add leading zeros)', 'jet-smart-filters' ),
		) );
	}

	/**
	 * Retruns image source options
	 */
	public function get_media_sources() {

		$groups = array(
			array(
				'label'  => __( 'General', 'jet-smart-filters' ),
				'options' => apply_filters( 'jet-smart-filters/listing/blocks-options/media_sources/post', array(
					'post_thumbnail' => __( 'Post thumbnail', 'jet-smart-filters' ),
					//'user_avatar'  => __( 'User avatar (works only for user listing and pages)', 'jet-smart-filters' ),
					'meta'           => __( 'Image From Meta Field', 'jet-smart-filters' ),
					'options'        => __( 'Image From Options', 'jet-smart-filters' ),
				) )
			),
		);

		return apply_filters( 'jet-smart-filters/listing/blocks-options/media_sources', $groups );
	}

	/**
	 * Retruns link source options
	 */
	public function get_link_sources() {

		$groups = array(
			array(
				'label'  => __( 'General', 'jet-smart-filters' ),
				'options' => apply_filters( 'jet-smart-filters/listing/blocks-options/link_sources/post', array(
					'permalink'  => __( 'Permalink', 'jet-smart-filters' ),
					'attachment' => __( 'Attachment URL', 'jet-smart-filters' ),
					'meta'       => __( 'URL From Meta Field', 'jet-smart-filters' ),
					'options'    => __( 'URL From Options', 'jet-smart-filters' ),
				) )
			),
		);

		return apply_filters( 'jet-smart-filters/listing/blocks-options/link_sources', $groups );
	}

	/**
	 * Returns image size
	 */
	function get_image_sizes() {

		global $_wp_additional_image_sizes;

		$sizes  = get_intermediate_image_sizes();
		$result = array();

		foreach ( $sizes as $size ) {
			if ( in_array( $size, array( 'thumbnail', 'medium', 'medium_large', 'large' ) ) ) {
				$result[ $size ] = ucwords( trim( str_replace( array( '-', '_' ), array( ' ', ' ' ), $size ) ) );
			} else {
				$result[ $size ] = sprintf(
					'%1$s (%2$sx%3$s)',
					ucwords( trim( str_replace( array( '-', '_' ), array( ' ', ' ' ), $size ) ) ),
					$_wp_additional_image_sizes[ $size ]['width'],
					$_wp_additional_image_sizes[ $size ]['height']
				);
			}
		}

		return apply_filters(
			'jet-smart-filters/listing/blocks-options/image_sizes',
			array_merge( array( 'full' => esc_html__( 'Full', 'jet-smart-filters' ), ), $result )
		);
	}

	/**
	 * Returns label types
	 */
	function get_label_types() {

		return apply_filters( 'jet-smart-filters/listing/blocks-options/label_types', array(
			'static'  => __( 'Static Text', 'jet-smart-filters' ),
			'dynamic' => __( 'Generate Dynamically', 'jet-smart-filters' )
		) );
	}

	/**
	 * Returns label aria types
	 */
	function get_label_aria_types() {

		return apply_filters( 'jet-smart-filters/listing/blocks-options/label_aria_types', array(
			'inherit' => __( 'Inherit Main Label', 'jet-smart-filters' ),
			'custom'  => __( 'Custom Text', 'jet-smart-filters' )
		) );
	}

	/**
	 * Returns rel attribute types
	 */
	function get_rel_attribute_types() {

		return apply_filters( 'jet-smart-filters/listing/blocks-options/rel_attribute_types', array(
			''           => __( 'No', 'jet-smart-filters' ),
			'alternate'  => __( 'Alternate', 'jet-smart-filters' ),
			'author'     => __( 'Author', 'jet-smart-filters' ),
			'bookmark'   => __( 'Bookmark', 'jet-smart-filters' ),
			'external'   => __( 'External', 'jet-smart-filters' ),
			'help'       => __( 'Help', 'jet-smart-filters' ),
			'license'    => __( 'License', 'jet-smart-filters' ),
			'next'       => __( 'Next', 'jet-smart-filters' ),
			'nofollow'   => __( 'Nofollow', 'jet-smart-filters' ),
			'noreferrer' => __( 'Noreferrer', 'jet-smart-filters' ),
			'noopener'   => __( 'Noopener', 'jet-smart-filters' ),
			'prev'       => __( 'Prev', 'jet-smart-filters' ),
			'search'     => __( 'Search', 'jet-smart-filters' ),
			'tag'        => __( 'Tag', 'jet-smart-filters' )
		) );
	}

	/**
	 * Return term order by fields
	 */
	public function get_term_order_by_fields() {

		return apply_filters( 'jet-smart-filters/listing/blocks-options/term_order_by', array(
				'name'        => __( 'Name', 'jet-smart-filters' ),
				'slug'        => __( 'Slug', 'jet-smart-filters' ),
				'term_group'  => __( 'Term Group', 'jet-smart-filters' ),
				'term_id'     => __( 'Term ID', 'jet-smart-filters' ),
				'description' => __( 'Description', 'jet-smart-filters' ),
				'parent'      => __( 'Parent', 'jet-smart-filters' ),
				'term_order'  => __( 'Term Order', 'jet-smart-filters' ),
				'count'       => __( 'By the number of objects associated with the term', 'jet-smart-filters' ),
			)
		);
	}

	/**
	 * Return term order fields
	 */
	public function get_term_order_fields() {

		return apply_filters( 'jet-smart-filters/listing/blocks-options/term_order', array(
				'asc'  => __( 'ASC', 'jet-smart-filters' ),
				'desc' => __( 'DESC', 'jet-smart-filters' ),
			)
		);
	}
}