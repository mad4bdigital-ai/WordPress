<?php
/**
 * Pagination filter class
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Smart_Filters_Pagination_Filter' ) ) {
	/**
	 * Define Jet_Smart_Filters_Pagination_Filter class
	 */
	class Jet_Smart_Filters_Pagination_Filter {

		/**
		 * Get provider ID
		 */
		public function get_id() {

			return 'pagination';
		}

		/**
		 * Render pagination sample
		 */
		public function render_pagination_sample( $controls ) {

			$pages             = 10;
			$page              = 4;
			$items_enabled     = filter_var( $controls['items_enabled'], FILTER_VALIDATE_BOOLEAN );
			$pages_mid_size    = ! empty( $controls['pages_mid_size'] ) ? absint( $controls['pages_mid_size'] ) : 0;
			$pages_end_size    = ! empty( $controls['pages_end_size'] ) ? absint( $controls['pages_end_size'] ) : 0;
			$nav_enabled       = filter_var( $controls['nav_enabled'], FILTER_VALIDATE_BOOLEAN );
			$load_more_enabled = filter_var( $controls['load_more_enabled'], FILTER_VALIDATE_BOOLEAN );
			$pages_show_all    = ( 0 === $pages_mid_size ) ? true : false;
			$dots              = true;

			echo '<nav class="jet-filters-pagination">';
				if ( $nav_enabled ) {
					echo '<a class="jet-filters-pagination__item prev-next prev">';
						echo wp_kses_post(
							jet_smart_filters()->utils->template_replace_with_value(
								'for-js/pagination/item.php',
								$controls['prev']
							)
						);
					echo '</a>';
				}
				if ( $items_enabled ) {
					for ( $i = 1; $i <= $pages ; $i++ ) {
						$show_dots =  ( $pages_end_size < $i && $i < $page - $pages_mid_size ) || ( $pages_end_size <= ( $pages - $i ) && $i > $page + $pages_mid_size );

						if ( !$show_dots || $pages_show_all ) {
							$dots = true;
							if ( $page === $i ) {
								echo '<div class="jet-filters-pagination__item jet-filters-pagination__current">';
									echo wp_kses_post(
										jet_smart_filters()->utils->template_replace_with_value(
											'for-js/pagination/item.php',
											$i
										)
									);
								echo '</div>';
							} else {
								echo '<a class="jet-filters-pagination__item">';
									echo wp_kses_post(
										jet_smart_filters()->utils->template_replace_with_value(
											'for-js/pagination/item.php',
											$i
										)
									);
								echo '</a>';
							}
						} elseif ( $dots ) {
							$dots = false;
							echo '<a class="jet-filters-pagination__item">';
								echo wp_kses_post(
									jet_smart_filters()->utils->get_template_html(
										'for-js/pagination/dots.php'
									)
								);
							echo '</a>';
						}
					}
				}
				if ( $nav_enabled ) {
					echo '<a class="jet-filters-pagination__item prev-next next">';
						echo wp_kses_post(
							jet_smart_filters()->utils->template_replace_with_value(
								'for-js/pagination/item.php',
								$controls['next']
							)
						);
					echo '</a>';
				}
				if ( $load_more_enabled ) {
					echo '<div class="jet-filters-pagination__load-more">';
						echo wp_kses_post(
							jet_smart_filters()->utils->template_replace_with_value(
								'for-js/pagination/load-more.php',
								$controls['load_more_text']
							)
						);
					echo '</div>';
				}
			echo '</nav>';
		}
	}
}
