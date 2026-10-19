<?php
/**
 * input[type="hidden"] template
 */
?>
<input
	class="jet-form__field hidden-field"
	type="hidden"
	name="<?php echo esc_attr( $this->get_field_name( $args['name'] ) ); ?>"
	value="<?php echo esc_attr( is_scalar( $args['default'] ) ? (string) $args['default'] : '' ); ?>"
	data-field-name="<?php echo esc_attr( $args['name'] ); ?>"
>
