<?php
namespace Jet_Smart_Filters\Listing\Render;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Listing_CSS {

	protected $listing;

	public function __construct( $listing ) {
		$this->listing = $listing;
	}

	/**
	 * Get CSS for the listing
	 *
	 * @return string
	 */
	public function get_css() {

		$css = '';

		$css .= $this->get_columns_css();

		return $css;
	}

	/**
	 * Get class name for the listing
	 *
	 * @return string
	 */
	public function get_listing_class_name() {
		return sprintf(
			'.%1$s.%2$s',
			esc_attr( $this->listing->get_class_name() ),
			esc_attr( $this->listing->get_class_name( '--lid-' . $this->listing->get_id() ) )
		);
	}

	/**
	 * Get class name for the listing item
	 *
	 * @return string
	 */
	public function get_item_class_name() {
		return sprintf(
			'%1$s .%2$s',
			$this->get_listing_class_name(),
			esc_attr( $this->listing->get_class_name( '__item--lid-' . $this->listing->get_id() ) )
		);
	}

	/**
	 * Get CSS for the columns
	 *
	 * @return string
	 */
	public function get_columns_css() {

		$settings = $this->listing->get_settings();
		$css      = '';

		$sizing = isset( $settings['sizing'] ) ? $settings['sizing'] : [];

		foreach ( $sizing as $breakpoint ) {

			$width = isset( $breakpoint['width'] ) ? absint( $breakpoint['width'] ) : false;
			$columns = isset( $breakpoint['columns'] ) ? absint( $breakpoint['columns'] ) : 1;
			$spacing = isset( $breakpoint['spacing'] ) ? absint( $breakpoint['spacing'] ) : 0;

			$item_css = '';

			if ( $width ) {
				$item_css .= '@media (max-width: ' . $width . 'px) {';
			}

			$column_width = round( 100 / $columns, 4 ) . '%';
			$gap          = 0;

			if ( $spacing && 0 < $spacing ) {

				$spacing_compensation = ( $spacing * ( $columns - 1 ) ) / $columns;
				$column_width = 'calc(' . $column_width . ' - ' . $spacing_compensation . 'px)';

				$item_css .= $this->get_listing_class_name() . ' {';
				$item_css .= 'gap: ' . $spacing . 'px;';
				$item_css .= '}';
			}

			$item_css .= $this->get_item_class_name() . ' {';
			$item_css .= 'width: ' . $column_width . ';';
			$item_css .= 'flex: 0 0 ' . $column_width . ';';
			$item_css .= '}';

			if ( $width ) {
				$item_css .= '}';
			}

			$css .= $item_css;
		}

		return $css;
	}

	/**
	 * Print CSS for the listing
	 *
	 * This method outputs the CSS directly into the page.
	 */
	public function print_css() {

		$css = $this->get_css();

		if ( ! empty( $css ) ) {
			echo '<style type="text/css">' . wp_strip_all_tags( $css ) . '</style>'; // phpcs:ignore
		}
	}
}