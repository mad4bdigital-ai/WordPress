
<?php

if ( empty( $args ) ) {
	return;
}

$query_var           = $args['query_var'];
$placeholder         = $args['placeholder'];
$hide_apply_button   = $args['hide_apply_button'];
$current             = $this->get_current_filter_value( $args );
$accessibility_label = $args['accessibility_label'];
$classes = array(
	'jet-search-filter'
);

if ( '' !== $args['button_icon'] ) {
	$classes[] = 'button-icon-position-' . $args['button_icon_position'];
}
?>
<div
	class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
	<?php
		$this->filter_data_atts( $args );

		if ( ! empty( $args['min_letters_count'] ) ) :
			echo ' data-min-letters-count="' . esc_attr( $args['min_letters_count'] ) . '"';
		endif;
	?>
>
	<div class="jet-search-filter__input-wrapper">
		<input
			class="jet-search-filter__input"
			type="search"
			autocomplete="off"
			name="<?php echo esc_attr( $query_var ); ?>"
			value="<?php echo esc_attr( $current ); ?>"
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
			aria-label="<?php echo esc_attr( $accessibility_label ); ?>"
			<?php
			// Tabindex attribute is generated internally and considered safe.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo jet_smart_filters()->data->get_tabindex_attr();
			?>
		>
		<?php if ( 'ajax-ontyping' === $args['apply_type'] ) : ?>
			<div class="jet-search-filter__input-clear">
				<?php
				// SVG template output is generated internally and considered safe.
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo jet_smart_filters()->print_template( 'svg/close.svg' );
				?>
			</div>
			<div class="jet-search-filter__input-loading"></div>
		<?php endif; ?>
	</div>

	<?php if ( ! $hide_apply_button && 'ajax-ontyping' !== $args['apply_type'] ) : ?>
		<button
			type="button"
			class="jet-search-filter__submit apply-filters__button"
			<?php
			// Tabindex attribute is generated internally and considered safe.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo jet_smart_filters()->data->get_tabindex_attr();
			?>
		>
			<?php
			if ( 'left' === $args['button_icon_position'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $args['button_icon'];
			}
			?>
			<span class="jet-search-filter__submit-text">
				<?php echo esc_html( $args['button_text'] ); ?>
			</span>
			<?php
			if ( 'right' === $args['button_icon_position'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $args['button_icon'];
			}
			?>
		</button>
	<?php endif; ?>
</div>
