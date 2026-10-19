<?php

if ( empty( $args ) ) {
	return;
}

$options             = $args['options'];
$widget_id           = $args['__widget_id'];
$query_var           = $args['query_var'];
$accessibility_label = $args['accessibility_label'];

if ( ! $options ) {
	return;
}

$current = $this->get_current_filter_value( $args );
$has_current = jet_smart_filters()->utils->is_truthy_or_zero( $current );

?>
<div class="jet-rating" <?php $this->filter_data_atts( $args ); ?>>
	<div class="jet-rating__control">
		<div class="jet-rating-stars">
			<fieldset class="jet-rating-stars__fields">
				<legend style="display:none;">
					<?php echo esc_html( $accessibility_label ); ?>
				</legend>
				<?php

				$options = array_reverse( $options );

				foreach ( $options as $key => $value ) :

					$is_checked = false;

					if ( $has_current ) {
						if ( is_array( $current ) && in_array( $value, $current ) ) {
							$is_checked = true;
						}

						if ( ! is_array( $current ) && (string) $value === (string) $current ) {
							$is_checked = true;
						}
					}

					$input_id = 'jet-rating-' . $widget_id . '-' . $value;

					?>
					<input
						class="jet-rating-star__input<?php echo $is_checked ? ' is-checked' : ''; ?>"
						type="radio"
						id="<?php echo esc_attr( $input_id ); ?>"
						name="<?php echo esc_attr( $query_var ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						aria-label="<?php echo esc_attr( $value ); ?>"
						<?php checked( $is_checked ); ?>
					/>
					<label
						class="jet-rating-star__label"
						for="<?php echo esc_attr( $input_id ); ?>"
						<?php
						// tabindex attribute is already escaped inside JetSmartFilters
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo jet_smart_filters()->data->get_tabindex_attr();
						?>
					>
						<span class="jet-rating-star__icon">
							<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo jet_smart_filters()->utils->sanitize_icon_html( $args['rating_icon'] );
							?>
						</span>
					</label>
				<?php endforeach; ?>
			</fieldset>
		</div>
	</div>
</div>
