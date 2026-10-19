<?php

if ( empty( $args ) ) {
	return;
}

$options             = $args['options'];
$query_var           = $args['query_var'];
$by_parents          = $args['by_parents'];
$collapsible         = isset( $args['collapsible'] ) ? $args['collapsible'] : false;
$scroll_height       = ! empty( $args['scroll_height'] )
	? absint( $args['scroll_height'] )
	: false;
$show_decorator      = isset( $args['display_options']['show_decorator'] )
	? filter_var( $args['display_options']['show_decorator'], FILTER_VALIDATE_BOOLEAN )
	: false;
$extra_classes       = '';
$accessibility_label = $args['accessibility_label'];

if ( ! $options ) {
	return;
}

$current = $this->get_current_filter_value( $args );
$has_current = jet_smart_filters()->utils->is_truthy_or_zero( $current );

?>
<div class="jet-checkboxes-list" <?php $this->filter_data_atts( $args ); ?>>
	<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		include jet_smart_filters()->get_template( 'common/filter-items-search.php' );

		if ( $scroll_height ) {
			echo '<div class="jet-filter-items-scroll" style="max-height:' . esc_attr( $scroll_height ) . 'px"><div class="jet-filter-items-scroll-container">';
		}

		echo '<fieldset class="jet-checkboxes-list-wrapper">';
		echo '<legend style="display:none;">' . esc_html( $accessibility_label ) . '</legend>';

		if ( $by_parents ) {

			if ( ! class_exists( 'Jet_Smart_Filters_Terms_Walker' ) ) {
				require_once jet_smart_filters()->plugin_path( 'includes/walkers/terms-walker.php' );
			}

			$walker            = new Jet_Smart_Filters_Terms_Walker();
			$walker->tree_type = $query_var;

			$args['item_template'] = jet_smart_filters()->get_template( 'filters/checkboxes-item.php' );
			$args['current']       = $current;
			$args['decorator']     = $show_decorator;

			$container_classes = 'jet-list-tree';

			if ( $collapsible ) {
				$container_classes .= ' jet-list-collapsible';
			}

			echo '<div class="' . esc_attr( $container_classes ) . '">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $walker->walk( $options, 0, $args );
			echo '</div>';

		} else {

			foreach ( $options as $optionKey => $optionData ) {

				$checked = '';

				extract(
					jet_smart_filters()->utils->сreate_option_data( $optionKey, $optionData ),
					EXTR_OVERWRITE
				);

				if ( $has_current ) {
					if ( is_array( $current ) && in_array( $value, $current ) ) {
						$checked = 'checked';
					}

					if ( ! is_array( $current ) && $value == $current ) {
						$checked = 'checked';
					}
				}

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				include jet_smart_filters()->get_template( 'filters/checkboxes-item.php' );
			}
		}

		echo '</fieldset>';

		if ( $scroll_height ) {
			echo '</div></div>';
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		include jet_smart_filters()->get_template( 'common/filter-items-moreless.php' );
	?>
</div>
