<?php
/**
 * Apply filters button
 */

$show_apply_button = ( ! empty( $settings['apply_on'] ) && 'submit' === $settings['apply_on'] ) && ! empty( $settings['apply_button'] )
	? filter_var( $settings['apply_button'], FILTER_VALIDATE_BOOLEAN )
	: false;

if ( ! $show_apply_button ) {
	return;
}

$apply_button_text = $settings['apply_button_text'] ?? '';

if ( empty( $apply_button_text ) ) {
	return;
}

$btn_classes = 'apply-filters__button';

$active_state = ! empty( $settings['active_state'] ) && 'always' !== $settings['active_state']
	? $settings['active_state']
	: false;

$if_inactive = ! empty( $settings['if_inactive'] ) ? $settings['if_inactive'] : 'disable';

if ( $active_state ) {
	if ( 'hide' === $if_inactive ) {
		$btn_classes .= ' jsf_hidden';
	} else {
		$btn_classes .= ' jsf_disabled';
	}
}

?>
<div class="apply-filters"
	<?php
	if ( ! empty( $data_atts ) ) {
		// Data attributes are generated internally and considered safe.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo ' ' . $data_atts;
	}
	?>
>
	<button
		type="button"
		class="<?php echo esc_attr( $btn_classes ); ?>"
		<?php if ( $active_state ) : ?>
			data-active-state="<?php echo esc_attr( $active_state ); ?>"
			data-if-inactive="<?php echo esc_attr( $if_inactive ); ?>"
			<?php if ( 'disable' === $if_inactive ) : ?>
				disabled
			<?php endif; ?>
		<?php endif; ?>
		<?php
		// Tabindex attribute is generated internally and considered safe.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo jet_smart_filters()->data->get_tabindex_attr();
		?>
	>
		<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo jet_smart_filters()->utils->sanitize_icon_html( $apply_button_text );
		?>
	</button>
</div>