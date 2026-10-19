<?php

if ( empty( $args ) ) {
	return;
}

$container_class = 'jet-range';
if ( wp_is_mobile() ) {
	$container_class .= ' jet-range--mobile';
}

$query_var           = $args['query_var'];
$inputs_enabled      = $args['inputs_enabled'];
$inputs_type         = $args['inputs_separators_enabled'] ? 'text' : 'number';
$prefix              = $args['prefix'];
$suffix              = $args['suffix'];
$accessibility_label = $args['accessibility_label'];
$current             = $this->get_current_filter_value( $args );

if ( $current ) {
	$slider_val = explode( '_', $current );
} else {
	$slider_val = array( $args['min'], $args['max'] );
}

$min_value = $slider_val[0];
$max_value = $slider_val[1];
$slider_min_attr = (float) $args['min'];
$slider_max_attr = (float) $args['max'];

if ( $current ) {
	$current_min = isset( $slider_val[0] ) ? (float) $slider_val[0] : $slider_min_attr;
	$current_max = isset( $slider_val[1] ) ? (float) $slider_val[1] : $slider_max_attr;

	$slider_min_attr = min( $slider_min_attr, $current_min, $current_max );
	$slider_max_attr = max( $slider_max_attr, $current_min, $current_max );
}

?>
<div class="<?php echo esc_attr( $container_class ); ?>" <?php $this->filter_data_atts( $args ); ?>>
	<fieldset class="jet-range__slider">
		<legend style="display:none;">
			<?php
			printf(
				esc_html__( '%s - slider', 'jet-smart-filters' ),
				esc_html( $accessibility_label )
			);
			?>
		</legend>
		<div class="jet-range__slider__track">
			<div class="jet-range__slider__track__range"></div>
		</div>
		<input
			type="range"
			class="jet-range__slider__input jet-range__slider__input--min"
			step="<?php echo esc_attr( $args['step'] ); ?>"
			min="<?php echo esc_attr( $slider_min_attr ); ?>"
			max="<?php echo esc_attr( $slider_max_attr ); ?>"
			value="<?php echo esc_attr( $min_value ); ?>"
			aria-label="<?php esc_attr_e( 'Minimal value', 'jet-smart-filters' ); ?>"
			<?php
			// Tabindex attribute is generated internally and considered safe.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo jet_smart_filters()->data->get_tabindex_attr();
			?>
		/>

		<input
			type="range"
			class="jet-range__slider__input jet-range__slider__input--max"
			step="<?php echo esc_attr( $args['step'] ); ?>"
			min="<?php echo esc_attr( $slider_min_attr ); ?>"
			max="<?php echo esc_attr( $slider_max_attr ); ?>"
			value="<?php echo esc_attr( $max_value ); ?>"
			aria-label="<?php esc_attr_e( 'Maximum value', 'jet-smart-filters' ); ?>"
			<?php
			// Tabindex attribute is generated internally and considered safe.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo jet_smart_filters()->data->get_tabindex_attr();
			?>
		/>
	</fieldset>
	<?php if ( $inputs_enabled ) : ?>
		<div class="jet-range__inputs">
			<fieldset class="jet-range__inputs__container">
				<legend style="display:none;">
					<?php
					printf(
						esc_html__( '%s - inputs', 'jet-smart-filters' ),
						esc_html( $accessibility_label )
					);
					?>
				</legend>
				<div class="jet-range__inputs__group">
					<?php if ( $prefix ) : ?>
						<span class="jet-range__inputs__group__text">
							<?php echo esc_html( $prefix ); ?>
						</span>
					<?php endif; ?>
					<input
						type="<?php echo esc_attr( $inputs_type ); ?>"
						class="jet-range__inputs__min"
						min-range
						step="<?php echo esc_attr( $args['step'] ); ?>"
						min="<?php echo esc_attr( $slider_min_attr ); ?>"
						max="<?php echo esc_attr( $slider_max_attr ); ?>"
						value="<?php echo esc_attr( $min_value ); ?>"
						aria-label="<?php esc_attr_e( 'Minimal value', 'jet-smart-filters' ); ?>"
						<?php
						// Tabindex attribute is generated internally and considered safe.
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo jet_smart_filters()->data->get_tabindex_attr();
						?>
					/>
					<?php if ( $suffix ) : ?>
						<span class="jet-range__inputs__group__text">
							<?php echo esc_html( $suffix ); ?>
						</span>
					<?php endif; ?>
				</div>
				<div class="jet-range__inputs__group">
					<?php if ( $prefix ) : ?>
						<span class="jet-range__inputs__group__text">
							<?php echo esc_html( $prefix ); ?>
						</span>
					<?php endif; ?>
					<input
						type="<?php echo esc_attr( $inputs_type ); ?>"
						class="jet-range__inputs__max"
						max-range
						step="<?php echo esc_attr( $args['step'] ); ?>"
						min="<?php echo esc_attr( $slider_min_attr ); ?>"
						max="<?php echo esc_attr( $slider_max_attr ); ?>"
						value="<?php echo esc_attr( $max_value ); ?>"
						aria-label="<?php esc_attr_e( 'Maximum value', 'jet-smart-filters' ); ?>"
						<?php
						// Tabindex attribute is generated internally and considered safe.
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo jet_smart_filters()->data->get_tabindex_attr();
						?>
					/>
					<?php if ( $suffix ) : ?>
						<span class="jet-range__inputs__group__text">
							<?php echo esc_html( $suffix ); ?>
						</span>
					<?php endif; ?>
				</div>
			</fieldset>
		</div>
	<?php else : ?>
		<div class="jet-range__values">
			<span class="jet-range__values-prefix"><?php echo esc_html( $prefix ); ?></span>
			<span class="jet-range__values-min">
				<?php
				echo esc_html(
					number_format(
						$min_value,
						$args['format']['decimal_num'],
						$args['format']['decimal_sep'],
						$args['format']['thousands_sep']
					)
				);
				?>
			</span>
			<span class="jet-range__values-suffix"><?php echo esc_html( $suffix ); ?></span>
			—
			<span class="jet-range__values-prefix"><?php echo esc_html( $prefix ); ?></span>
			<span class="jet-range__values-max">
				<?php
				echo esc_html(
					number_format(
						$max_value,
						$args['format']['decimal_num'],
						$args['format']['decimal_sep'],
						$args['format']['thousands_sep']
					)
				);
				?>
			</span>
			<span class="jet-range__values-suffix"><?php echo esc_html( $suffix ); ?></span>
		</div>
	<?php endif; ?>
</div>
