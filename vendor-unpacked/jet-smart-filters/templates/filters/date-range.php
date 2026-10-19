<?php

if ( empty( $args ) ) {
	return;
}

$query_var   = $args['query_var'];
$current     = $this->get_current_filter_value( $args );
$from        = '';
$to          = '';

$date_format         = isset( $args['date_format'] ) ? $args['date_format'] : '';
$from_placeholder    = isset( $args['from_placeholder'] ) ? $args['from_placeholder'] : '';
$to_placeholder      = isset( $args['to_placeholder'] ) ? $args['to_placeholder'] : '';
$min_date_attr       = isset( $args['min_date'] ) ? 'data-mindate="' . esc_attr( $args['min_date'] ) . '"' : '';
$max_date_attr       = isset( $args['max_date'] ) ? 'data-maxdate="' . esc_attr( $args['max_date'] ) . '"' : '';
$accessibility_label = $args['accessibility_label'];

$classes = array(
	'jet-date-range'
);

if ( '' !== $args['button_icon'] ) {
	$classes[] = 'button-icon-position-' . $args['button_icon_position'];
}

/* if ( $current ) {
	$formated = explode( '-', $current );

	$from_placeholder = $formated[0];
	$to_placeholder   = $formated[1];
} */

$hide_button = isset( $args['hide_button'] ) ? $args['hide_button'] : false;

?>


<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" <?php $this->filter_data_atts( $args ); ?>>
	<fieldset class="jet-date-range__inputs">
		<legend style="display:none;"><?php echo esc_html( $accessibility_label ); ?></legend>
		<input
			class="jet-date-range__from jet-date-range__control"
			type="text"
			autocomplete="off"
			placeholder="<?php echo esc_attr( $from_placeholder ); ?>"
			name="<?php echo esc_attr( $query_var ); ?>_from"
			value="<?php echo esc_attr( $from ); ?>"
			aria-label="<?php esc_html_e( 'Date range from', 'jet-smart-filters' ); ?>"
			<?php
			// Tabindex attribute is generated internally and considered safe.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo jet_smart_filters()->data->get_tabindex_attr();
			?>
		>
		<input
			class="jet-date-range__to jet-date-range__control"
			type="text"
			autocomplete="off"
			placeholder="<?php echo esc_attr( $to_placeholder ); ?>"
			name="<?php echo esc_attr( $query_var ); ?>_to"
			value="<?php echo esc_attr( $to ); ?>"
			aria-label="<?php esc_html_e( 'Date range to', 'jet-smart-filters' ); ?>"
			<?php
			// Tabindex attribute is generated internally and considered safe.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo jet_smart_filters()->data->get_tabindex_attr();
			?>
		>
	</fieldset>
	<input
		class="jet-date-range__input"
		type="hidden"
		name="<?php echo esc_attr( $query_var ); ?>"
		value="<?php echo esc_attr( $current ); ?>"
		aria-label="<?php esc_html_e( 'Date range value', 'jet-smart-filters' ); ?>"
		data-date-format="<?php echo esc_attr( $date_format ); ?>"
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $min_date_attr;
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $max_date_attr;
		?>
	>
	<?php if ( ! $hide_button ) : ?>
	<button
		type="button"
		class="jet-date-range__submit apply-filters__button"
		<?php
		// Tabindex attribute is generated internally and considered safe.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo jet_smart_filters()->data->get_tabindex_attr();
		?>
	>
		<?php
			echo 'left' === $args['button_icon_position']
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				? jet_smart_filters()->utils->sanitize_icon_html( $args['button_icon'] )
				: '';
		?>
		<span class="jet-date-range__submit-text"><?php echo esc_html( $args['button_text'] ); ?></span>
		<?php
			echo 'right' === $args['button_icon_position']
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				? jet_smart_filters()->utils->sanitize_icon_html( $args['button_icon'] )
				: '';
		?>
	</button>
	<?php endif; ?>
</div>
