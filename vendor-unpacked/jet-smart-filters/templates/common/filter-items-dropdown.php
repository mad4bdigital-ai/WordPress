<?php

if ( empty( $args ) ) {
	return;
}

$dropdown_apply_button      = ! empty( $args['dropdown_apply_button'] );
$dropdown_apply_button_text = $args['dropdown_apply_button_text'] ?? '';

?>
<div
	class="jet-filter-items-dropdown"
	<?php if ( ! empty( $args['dropdown_n_selected_enabled'] ) ) : ?>
		data-dropdown-n-selected="<?php echo esc_attr( $args['dropdown_n_selected_number'] ); ?>"
		data-dropdown-n-selected-text="<?php echo esc_attr( $args['dropdown_n_selected_text'] ); ?>"
	<?php endif; ?>
>
	<div
		class="jet-filter-items-dropdown__label"
		<?php
		// Tabindex attribute is generated internally and considered safe.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo jet_smart_filters()->data->get_tabindex_attr();
		?>
	>
		<?php
		if ( isset( $args['dropdown_placeholder'] ) ) {
			echo esc_html( $args['dropdown_placeholder'] );
		}
		?>
	</div>

	<div class="jet-filter-items-dropdown__body">
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		include jet_smart_filters()->get_template( 'filters/' . $this->filter_type . '.php' );
		?>

		<?php if ( $dropdown_apply_button ) : ?>
			<div class="jet-filter-items-dropdown__footer">
				<button
					type="button"
					class="jet-filter-items-dropdown__apply-button"
					<?php
					// Tabindex attribute is generated internally and considered safe.
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo jet_smart_filters()->data->get_tabindex_attr();
					?>
				>
					<?php echo esc_html( $dropdown_apply_button_text ); ?>
				</button>
			</div>
		<?php endif; ?>
	</div>
</div>
