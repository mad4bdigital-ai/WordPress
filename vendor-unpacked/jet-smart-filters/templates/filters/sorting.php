<?php

if ( empty( $settings ) || empty( $sorting_options ) || empty( $container_data_atts ) ) {
	return;
}

$class               = 'jet-sorting';
$class_containter    = $class . ' ' . ( ! empty( $settings['label_block'] ) ? $class . '--flex-column' : $class . '--flex-row' );
$accessibility_label = ! empty( $settings['label'] ) ? $settings['label'] : __( 'Sort filter', 'jet-smart-filters' );

?>
<div
	class="<?php echo esc_attr( $class_containter ); ?>"
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo $container_data_atts;
	?>
>
	<?php if ( $label ) : ?>
		<div class="<?php echo esc_attr( $class ); ?>-label">
			<?php echo wp_kses_post( $label ); ?>
		</div>
	<?php endif; ?>
	<select
		class="<?php echo esc_attr( $class ); ?>-select"
		name="select-name"
		<?php
		// Tabindex attribute is generated internally and considered safe.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo jet_smart_filters()->data->get_tabindex_attr();
		?>
		aria-label="<?php echo esc_attr( $accessibility_label ); ?>"
	>
		<?php if ( $placeholder ) : ?>
			<option value="">
				<?php echo esc_html( $placeholder ); ?>
			</option>
		<?php endif; ?>
		<?php foreach ( $sorting_options as $option ) : ?>
			<option
				value="<?php echo esc_attr( $option['value'] ); ?>"
				<?php echo ! empty( $option['current'] ) ? ' selected="selected"' : ''; ?>
			>
				<?php echo esc_html( $option['title'] ); ?>
			</option>
		<?php endforeach; ?>
	</select>
</div>
