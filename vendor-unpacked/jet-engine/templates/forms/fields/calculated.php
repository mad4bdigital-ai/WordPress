<?php
/**
 * Calculated field template
 */
$calc_data = $this->get_calculated_data( $args );

if ( empty( $calc_data['formula'] ) ){
	return;
}

$name          = $this->get_field_name( $args['name'] );
$default_value = ! empty( $args['default'] ) ? $args['default'] : '';
$prefix        = ! empty( $args['calc_prefix'] ) ? $args['calc_prefix'] : false;
$suffix        = ! empty( $args['calc_suffix'] ) ? $args['calc_suffix'] : false;
$precision     = isset( $args['precision'] ) ? $args['precision'] : 0;
$is_hidden     = isset( $args['calc_hidden'] ) ? filter_var( $args['calc_hidden'], FILTER_VALIDATE_BOOLEAN ) : false;

$this->add_attribute( 'data-formula', esc_attr( $calc_data['formula'] ) );
$this->add_attribute( 'data-name', esc_attr( $args['name'] ) );
$this->add_attribute( 'data-listen_to', esc_attr( wp_json_encode( $calc_data['listen_fields'] ) ) );
$this->add_attribute( 'data-precision', $precision );

if ( ! empty( $this->current_repeater ) ) {
	$class_name = 'jet-form__calculated-field--child';
} else {
	$class_name = 'jet-form__calculated-field';
}

if ( $is_hidden ) {
	$class_name .= ' jet-form__calculated-field--hidden';
}

?>
<div class="<?php echo esc_attr( $class_name ); ?>"<?php $this->render_attributes_string(); ?>>
	<?php if ( false !== $prefix && ! $is_hidden ) { ?>
		<div class="jet-form__calculated-field-prefix">
			<?php echo wp_kses_post( $prefix ); ?>
		</div>
	<?php } ?>
	<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( is_scalar( $default_value ) ? (string) $default_value : '' ); ?>" class="jet-form__calculated-field-input jet-form__field" data-field-name="<?php echo esc_attr( $args['name'] ); ?>">
	<?php if ( ! $is_hidden ) { ?>
		<div class="jet-form__calculated-field-val"></div>
	<?php } ?>
	<?php if ( false !== $suffix && ! $is_hidden ) { ?>
		<div class="jet-form__calculated-field-suffix">
			<?php echo wp_kses_post( $suffix ); ?>
		</div>
	<?php } ?>
</div>
