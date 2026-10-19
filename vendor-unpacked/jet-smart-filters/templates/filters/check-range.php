<?php

if ( empty( $args ) ) {
	return;
}

$options             = $args['options'];
$query_var           = $args['query_var'];
$show_decorator      = isset( $args['display_options']['show_decorator'] ) ? filter_var( $args['display_options']['show_decorator'], FILTER_VALIDATE_BOOLEAN ) : false;
$accessibility_label = $args['accessibility_label'];
$scroll_height       = ! empty( $args['scroll_height'] )
	? absint( $args['scroll_height'] )
	: false;
// SVG template output is generated internally and considered safe.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
$checked_icon        = apply_filters( 'jet-smart-filters/templates/check-range/checked-icon', jet_smart_filters()->print_template( 'svg/check.svg' ) );

if ( ! $options ) {
	return;
}

$current = $this->get_current_filter_value( $args );

?>
<div class="jet-checkboxes-list" <?php $this->filter_data_atts( $args ); ?>>
	<?php
	include jet_smart_filters()->get_template( 'common/filter-items-search.php' );

	if ( $scroll_height ) {
		echo '<div class="jet-filter-items-scroll" style="max-height:' . esc_attr( $scroll_height ) . 'px"><div class="jet-filter-items-scroll-container">';
	}

	echo '<fieldset class="jet-checkboxes-list-wrapper">';
	echo '<legend style="display:none;">' . esc_html( $accessibility_label ) . '</legend>';

	foreach ( $options as $value => $label ) {

		$checked = '';

		if ( $current ) {
			if ( is_array( $current ) && in_array( $value, $current ) ) {
				$checked = 'checked';
			}

			if ( ! is_array( $current ) && $value == $current ) {
				$checked = 'checked';
			}
		}
		?>
		<div class="jet-checkboxes-list__row jet-filter-row">
			<label class="jet-checkboxes-list__item" <?php
				// Tabindex attribute is generated internally and considered safe.
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo jet_smart_filters()->data->get_tabindex_attr();
			?>>
				<input
					type="checkbox"
					class="jet-checkboxes-list__input"
					name="<?php echo esc_attr( $query_var ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					data-label="<?php echo esc_attr( $label ); ?>"
					aria-label="<?php echo esc_attr( $label ); ?>"
					<?php echo esc_attr( $checked ); ?>
				>
				<div class="jet-checkboxes-list__button">
					<?php if ( $show_decorator ) : ?>
						<span class="jet-checkboxes-list__decorator">
							<i class="jet-checkboxes-list__checked-icon"><?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo $checked_icon;
							?></i>
						</span>
					<?php endif; ?>
					<span class="jet-checkboxes-list__label"><?php echo esc_html( $label ); ?></span>
					<?php do_action( 'jet-smart-filter/templates/counter', $args ); ?>
				</div>
			</label>
		</div>
		<?php
	}

	echo '</fieldset>';

	if ( $scroll_height ) {
		echo '</div></div>';
	}

	include jet_smart_filters()->get_template( 'common/filter-items-moreless.php' );
	?>
</div>
