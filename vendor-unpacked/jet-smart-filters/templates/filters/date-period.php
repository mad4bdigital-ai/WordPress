<?php

if ( empty( $args ) ) {
	return;
}

$query_var = $args['query_var'];
$current   = $this->get_current_filter_value( $args );

$date_format            = isset( $args['date_format'] ) ? $args['date_format'] : '';
$period_type            = isset( $args['period_type'] ) ? $args['period_type'] : 'week';
$datepicker_button_text = isset( $args['datepicker_button_text'] )
	? $args['datepicker_button_text']
	: __( 'Select Date', 'jet-smart-filters' );
$period_duration        = isset( $args['period_duration'] ) ? $args['period_duration'] : '1';
$min_date_attr          = isset( $args['min_date'] ) ? 'data-mindate="' . esc_attr( $args['min_date'] ) . '"' : '';
$max_date_attr          = isset( $args['max_date'] ) ? 'data-maxdate="' . esc_attr( $args['max_date'] ) . '"' : '';
$accessibility_label    = $args['accessibility_label'];

$classes = array(
	'jet-date-period'
);

if ( '' !== $args['button_icon'] ) {
	$classes[] = 'button-icon-position-' . $args['button_icon_position'];
}

?>
<div
	class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
	<?php $this->filter_data_atts( $args ); ?>
>
	<div class="jet-date-period__wrapper">
		<div
			class="jet-date-period__prev"
			<?php
			// Tabindex attribute is generated internally and considered safe.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo jet_smart_filters()->data->get_tabindex_attr();
			?>
		>
			<?php
			// SVG template output is generated internally and considered safe.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo jet_smart_filters()->print_template( 'svg/chevron-left.svg' );
			?>
		</div>
		<div class="jet-date-period__datepicker date">
			<div
				class="jet-date-period__datepicker-button input-group-addon"
				<?php
				// Tabindex attribute is generated internally and considered safe.
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo jet_smart_filters()->data->get_tabindex_attr();
				?>
			>
				<?php echo esc_html( $datepicker_button_text ); ?>
				<?php
				// SVG template output is generated internally and considered safe.
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo jet_smart_filters()->print_template( 'svg/angle-down.svg' );
				?>
			</div>
			<input
				class="jet-date-period__datepicker-input"
				name="<?php echo esc_attr( $query_var ); ?>"
				value="<?php echo esc_attr( $current ); ?>"
				aria-label="<?php echo esc_attr( $accessibility_label ); ?>"
				type="hidden"
				tabindex="-1"
				data-format="<?php echo esc_attr( $date_format ); ?>"
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $min_date_attr;
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $max_date_attr;
				?>
				readonly
			>
		</div>
		<div
			class="jet-date-period__next"
			<?php
			// Tabindex attribute is generated internally and considered safe.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo jet_smart_filters()->data->get_tabindex_attr();
			?>
		>
			<?php
			// SVG template output is generated internally and considered safe.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo jet_smart_filters()->print_template( 'svg/chevron-right.svg' );
			?>
		</div>
	</div>
</div>
