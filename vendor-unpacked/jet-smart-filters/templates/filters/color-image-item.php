<?php
/**
 * Color / Image list item template
 */

$image_src  = wp_get_attachment_image_src( $image, $display_options['filter_image_size'] );
$image_alt  = get_post_meta( $image, '_wp_attachment_image_alt', true );
$show_label = ! empty( $display_options['show_items_label'] );

if ( ! empty( $image_src[0] ) ) {
	$image_src = $image_src[0];
} else {
	$image_src = jet_smart_filters()->plugin_url( 'assets/images/placeholder.png' );
}

if ( ! $image_alt ) {
	$image_alt = $label;
}

?>
<div class="jet-color-image-list__row jet-filter-row<?php echo esc_attr( $extra_classes ); ?>">
	<label
		class="jet-color-image-list__item"
		<?php
		// Tabindex attribute is generated internally and considered safe.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo jet_smart_filters()->data->get_tabindex_attr();
		?>
	>
		<input
			type="<?php echo esc_attr( $filter_type ); ?>"
			class="jet-color-image-list__input"
			name="<?php echo esc_attr( $query_var ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			data-label="<?php echo esc_attr( $label ); ?>"
			<?php
			if ( ! empty( $data_attrs ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo jet_smart_filters()->utils->generate_data_attrs( $data_attrs );
			}
			?>
			aria-label="<?php echo esc_attr( $label ); ?>"
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $checked;
			?>
		>
		<div class="jet-color-image-list__button">
			<span class="jet-color-image-list__decorator">
				<?php if ( 'color' === $type ) : ?>
					<span
						class="jet-color-image-list__color"
						style="background-color: <?php echo esc_attr( $color ); ?>"
					></span>
				<?php endif; ?>

				<?php if ( 'image' === $type ) : ?>
					<span class="jet-color-image-list__image">
						<img src="<?php echo esc_url( $image_src ); ?>" alt="<?php echo esc_attr( $image_alt ); ?>">
					</span>
				<?php endif; ?>
			</span>
			<?php if ( $show_label ) : ?>
				<span class="jet-color-image-list__label">
					<?php echo wp_kses_post( $label ); ?>
				</span>
			<?php endif; ?>
			<?php
			if ( 'all' !== $value ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				do_action( 'jet-smart-filter/templates/counter', $args );
			}
			?>
		</div>
	</label>
</div>
