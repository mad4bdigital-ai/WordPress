<?php

if ( empty( $args ) ) {
	return;
}

$options             = $args['options'];
$query_var           = $args['query_var'];
$is_hierarchical     = $args['is_hierarchical'];
$classes             = array( 'jet-select__control' );
$current             = $this->get_current_filter_value( $args );
$has_current         = jet_smart_filters()->utils->is_truthy_or_zero( $current );
$display_options     = ! empty( $args['display_options'] ) ? $args['display_options'] : false;
$counter_prefix      = ! empty( $display_options['counter_prefix'] ) ? $display_options['counter_prefix'] : '';
$counter_suffix      = ! empty( $display_options['counter_suffix'] ) ? $display_options['counter_suffix'] : '';
$accessibility_label = $args['accessibility_label'];

?>
<div class="jet-select" <?php $this->filter_data_atts( $args ); ?>>
	<?php
	// Hierarchical logic
	if ( $is_hierarchical ) {
		$current = false;

		if ( isset( $args['current_value'] ) && jet_smart_filters()->utils->is_truthy_or_zero( $args['current_value'] ) ) {
			$current = $args['current_value'];
			$has_current = true;
		}

		$classes[] = 'depth-' . absint( $args['depth'] );

		$filter_label = $args['filter_label'];
		if ( $filter_label ) {
			$accessibility_label = $filter_label;
			include jet_smart_filters()->get_template( 'common/filter-label.php' );
		}
	}
	?>
	<?php if ( ! empty( $options ) || $is_hierarchical ) : ?>
		<select
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			name="<?php echo esc_attr( $query_var ); ?>"
			<?php
			// Tabindex attribute is generated internally and considered safe.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo jet_smart_filters()->data->get_tabindex_attr();
			?>
			aria-label="<?php echo esc_attr( $accessibility_label ); ?>"
		>
			<?php if ( ! empty( $args['placeholder'] ) ) : ?>
				<option value="">
					<?php echo esc_html( $args['placeholder'] ); ?>
				</option>
			<?php endif; ?>
			<?php
			foreach ( $options as $option_key => $option_data ) {

				$selected = false;

				extract( jet_smart_filters()->utils->сreate_option_data( $option_key, $option_data ), EXTR_OVERWRITE );

				if ( $has_current ) {
					if ( is_array( $current ) && in_array( $value, $current ) ) {
						$selected = true;
					}

					if ( ! is_array( $current ) && (string) $value === (string) $current ) {
						$selected = true;
					}
				}
				?>
				<option
					value="<?php echo esc_attr( $value ); ?>"
					data-label="<?php echo esc_attr( $label ); ?>"
					data-counter-prefix="<?php echo esc_attr( $counter_prefix ); ?>"
					data-counter-suffix="<?php echo esc_attr( $counter_suffix ); ?>"
					<?php
					if ( ! empty( $data_attrs ) ) {
						// Data attribute is generated internally and considered safe.
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo jet_smart_filters()->utils->generate_data_attrs( $data_attrs );
					}
					?>
					<?php selected( $selected ); ?>
				>
					<?php echo esc_html( $label ); ?>
				</option>
				<?php
			}
		?></select>
	<?php endif; ?>
</div>
