<?php
namespace Jet_Smart_Filters\Listing\Render;

use Jet_Smart_Filters\Listing\Controller as Listing_Controller;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Listing base class
 */
class Listing_Base {

	protected $listing_instance;

	protected $listing_id   = null;
	protected $listing_data = [
		'name'     => '',
		'query'    => null,
		'settings' => null,
		'card_id'  => null,
	];
	protected $card_data    = [];
	protected $base_class   = 'jsf-listing';
	protected $query        = null;

	public static $assets_enqueued = false;

	public function __construct( $listing_id ) {

		$this->listing_instance = Listing_Controller::instance();
		$this->init_listing( $listing_id );
	}

	/**
	 * Listing initialization
	 */
	private function init_listing( $listing_id ) {

		// Listing ID
		$this->listing_id = $listing_id;

		// If listing ID is not set, we have nothing more to do here
		if ( ! $this->listing_id ) {
			return;
		}

		$listing_response_data = $this->listing_instance->storage->get_listing( $listing_id );

		$this->listing_data['name'] = isset( $listing_response_data['name'] )
			? $listing_response_data['name']
			: '';

		$this->listing_data['query'] = isset( $listing_response_data['query'] )
			? $listing_response_data['query']
			: [];

		$this->listing_data['settings'] = isset( $listing_response_data['settings'] )
			? $listing_response_data['settings']
			: [];

		$this->listing_data['card_id'] = isset( $listing_response_data['item_id'] )
			? $listing_response_data['item_id']
			: null;

		// Card Data
		if ( $this->get_card_id() ) {
			$card_response_data = $this->listing_instance->storage->get_listing_item( $this->listing_data['card_id'] );

			$this->card_data['name'] = isset( $card_response_data['name'] )
				? $card_response_data['name']
				: '';

			$this->card_data['content'] = isset( $card_response_data['content'] )
				? $card_response_data['content']
				: '';

			$this->card_data['settings'] = isset( $card_response_data['settings'] )
				? $card_response_data['settings']
				: [];

			$this->card_data['styles'] = isset( $card_response_data['styles'] )
				? $card_response_data['styles']
				: [];
		}

		do_action( 'jet-smart-filters/listing/render/init-listing', $this );
	}

	/**
	 * Get listing ID
	 */
	public function get_id() {
		return $this->listing_id;
	}

	/**
	 * Get listing name
	 */
	public function get_name() {
		return apply_filters( 'jet-smart-filters/listing/render/get/name', $this->listing_data['name'], $this );
	}

	/**
	 * Get base class name for the whole listing CSS classes
	 *
	 * @return string
	 */
	public function get_base_class_name() {
		return apply_filters( 'jet-smart-filters/listing/render/get/base-class-name', $this->base_class, $this );
	}

	/**
	 * Get listing class with suffix
	 *
	 * @param string $suffix
	 * @return string
	 */
	public function get_class_name( $suffix = '' ) {
		return apply_filters( 'jet-smart-filters/listing/render/get/class-name', $this->get_base_class_name() . $suffix, $this );
	}

	/**
	 * Get listing query args
	 */
	public function get_query_args() {
		return apply_filters( 'jet-smart-filters/listing/render/raw-query-args', $this->listing_data['query'], $this );
	}

	/**
	 * Get listing settings
	 */
	public function get_settings() {
		return apply_filters( 'jet-smart-filters/listing/render/get/query-settings', $this->listing_data['settings'], $this );
	}

	/**
	 * Get card id
	 */
	public function get_card_id() {
		return apply_filters( 'jet-smart-filters/listing/render/get/card-id', $this->listing_data['card_id'], $this );
	}

	/**
	 * Get card name
	 */
	public function get_card_name() {
		return apply_filters( 'jet-smart-filters/listing/render/get/card-name', $this->card_data['name'], $this );
	}

	/**
	 * Get card content
	 */
	public function get_card_content() {
		return apply_filters( 'jet-smart-filters/listing/render/get/card-content', $this->card_data['content'], $this );
	}

	/**
	 * Get card settings
	 */
	public function get_card_settings() {
		return apply_filters( 'jet-smart-filters/listing/render/get/card-settings', $this->card_data['settings'], $this );
	}

	/**
	 * Get card styles
	 */
	public function get_card_styles() {
		return apply_filters( 'jet-smart-filters/listing/render/get/card-styles', $this->card_data['styles'], $this );
	}

	/**
	 * Get query
	 */
	public function get_query() {

		if ( null === $this->query ) {
			$this->query = Query_Factory::create( $this->get_query_args() );
		}

		return apply_filters( 'jet-smart-filters/listing/render/query', $this->query, $this );
	}

	/**
	 * Assets registration and printing
	 */
	public function assets() {

		if ( self::$assets_enqueued ) {
			return;
		}

		$style_render_assets = include jet_smart_filters()->plugin_path( 'includes/listing/render/assets/css/jsf-listing-render.asset.php' );

		wp_enqueue_style(
			'jsf-listing-render',
			jet_smart_filters()->plugin_url( is_rtl()
				? 'includes/listing/render/assets/css/style-jsf-listing-render-rtl.css'
				: 'includes/listing/render/assets/css/style-jsf-listing-render.css'
			),
			$style_render_assets['dependencies'],
			$style_render_assets['version'],
		);

		wp_print_styles( 'jsf-listing-render' );

		$render_script_assets = include jet_smart_filters()->plugin_path( 'includes/listing/render/assets/js/jsf-listing-render-frontend.asset.php' );

		wp_enqueue_script(
			'jsf-listing-item-link',
			jet_smart_filters()->plugin_url( 'includes/listing/render/assets/js/jsf-listing-render-frontend.js' ),
			$render_script_assets['dependencies'],
			$render_script_assets['version'],
			true
		);

		self::$assets_enqueued = true;
	}

	protected function add_listing_link_to_content( $content, $settings = [] ) {

		if ( empty( $settings ) || empty( $settings['listing_link'] ) ) {
			return $content;
		}

		$object = $this->listing_instance->render->get_query_object();
		$url    = $this->listing_instance->helpers->utils->get_listing_item_link_url( $settings, $object );

		if ( ! $url ) {
			return $content;
		}

		$overlay_attrs = [
			'class'    => 'jsf-listing-overlay-wrap',
			'data-url' => $url,
		];

		$link_attrs = [
			'href'  => $url,
			'class' => 'jsf-listing-overlay-link',
			'tabindex' => '-1',
			'aria-hidden' => 'true',
		];

		if ( ! empty( $settings['listing_link_open_in_new'] ) ) {
			$overlay_attrs['data-target'] = '_blank';
			$link_attrs['target']         = '_blank';
		}

		if ( ! empty( $settings['listing_link_rel_attr'] ) ) {
			$link_attrs['rel'] = $settings['listing_link_rel_attr'];
		}

		$overlay_attrs = apply_filters(
			'jet-smart-filters/listing/item-link/overlay-attrs',
			$overlay_attrs,
			$settings,
			$object,
			$this
		);

		$link_attrs = apply_filters(
			'jet-smart-filters/listing/item-link/link-attrs',
			$link_attrs,
			$settings,
			$object,
			$this
		);

		$overlay_attr_string = '';
		$link_attr_string    = '';

		foreach ( $overlay_attrs as $attr_name => $attr_value ) {
			if ( '' === $attr_value || null === $attr_value ) {
				continue;
			}

			$overlay_attr_string .= sprintf( ' %1$s="%2$s"', esc_attr( $attr_name ), esc_attr( $attr_value ) );
		}

		foreach ( $link_attrs as $attr_name => $attr_value ) {
			if ( '' === $attr_value || null === $attr_value ) {
				continue;
			}

			$link_attr_string .= sprintf( ' %1$s="%2$s"', esc_attr( $attr_name ), esc_attr( $attr_value ) );
		}

		return sprintf(
			'<div%2$s>%1$s<a%3$s></a></div>',
			$content,
			$overlay_attr_string,
			$link_attr_string
		);
	}

	/**
	 * Render listing
	 */
	public function render() {

		// Dynamic assets unique for each listing
		$listing_css = new Listing_CSS( $this );
		$listing_css->print_css();

		// Static assets
		$this->assets();

		$classes = [
			$this->get_class_name(),
			$this->get_class_name( '--lid-' . $this->get_id() ),
			$this->get_class_name( '--iid-' . $this->get_card_id() ),
		];

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		if ( ! $this->get_id() ) {
			echo esc_html(
				apply_filters(
					'jet-smart-filters/listing/render/no-listing-id-text',
					__( 'No listing selected', 'jet-smart-filters' )
				)
			);

			return;
		}

		if ( ! $this->get_card_id() ) {
			echo esc_html(
				apply_filters(
					'jet-smart-filters/listing/render/no-card-text',
					__( 'No item selected', 'jet-smart-filters' )
				)
			);

			return;
		}

		$query = $this->get_query();
		$items = [];

		if ( $query && is_object( $query ) && is_callable( [ $query, 'get_items' ] ) ) {
			$items = apply_filters( 'jet-smart-filters/listing/render/items', $query->get_items(), $this );
		}

		if ( empty( $items ) ) {
			echo esc_html(
				apply_filters(
					'jet-smart-filters/listing/render/no-posts-text',
					__( 'No items found.', 'jet-smart-filters' )
				)
			);
			return;
		}

		$styles_collection = 'jsf-listing-item-' . $this->get_card_id();

		foreach( $items as $item ) {

			// Setup object early
			$this->listing_instance->render->setup_query_object( $item );

			$item_classes = [
				$this->get_class_name( '__item' ),
				$this->get_class_name( '__item--uid-' . ( is_callable( [ $query, 'get_item_id' ] ) ? $query->get_item_id( $item ) : '' ) ),
				$this->get_class_name( '__item--lid-' . $this->get_id() ),
				$this->get_class_name( '__item--iid-' . $this->get_card_id() ),
			];

			if ( $styles_collection ) {
				Listing_Controller::instance()->style_manager->start_collection( $styles_collection );
			}

			$raw_content = $this->get_card_content();
			$rendered_blocks = do_blocks( $raw_content );
			$rendered_blocks = $this->add_listing_link_to_content( $rendered_blocks, $this->get_card_settings() );

			if ( $styles_collection ) {
				Listing_Controller::instance()->style_manager->stop_current_collection();
				$styles = Listing_Controller::instance()->style_manager->get_styles_collection(
					$styles_collection
				);

				if ( $styles ) {
					echo $styles; // phpcs:ignore
				}
			}

			echo '<div class="' . esc_attr( implode( ' ', $item_classes ) ) . '">';
			echo $rendered_blocks; // phpcs:ignore
			echo '</div>';

			$styles_collection = false;
		}

		$this->listing_instance->render->reset_query_object();

		echo '</div>';
	}
}
