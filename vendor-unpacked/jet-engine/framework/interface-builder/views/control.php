<?php
/**
 * Control template.
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$required_class = ! empty( $args['required'] ) ? ' cx-control-required' : '';
$field_layout = ! empty( $args['field_layout'] ) ? $args['field_layout'] : 'inline';
?>
<div class="cx-ui-kit cx-control cx-control-<?php echo esc_attr( $args['type'] ); ?> cx-control-<?php echo esc_attr( $field_layout ); ?><?php echo $required_class; ?>" data-control-name="<?php echo esc_attr( $args['id'] ); ?>">
	<?php if ( ! empty( $args['title'] ) ) { ?>
		<div class="cx-control__info">
			<?php if ( ! empty( $args['title'] ) ) { ?>
				<div class="h4-style cx-ui-kit__title cx-control__title" role="banner" >
					<?php echo wp_kses_post( $args['title'] ); ?>
					<?php echo ! empty( $args['required'] ) ? ' <span class="cx-control__required">*</span>' : '' ?>
				</div>
			<?php } ?>
			<?php if ( ! empty( $args['name'] ) && empty( $args['hide_field_name'] ) ) { ?>
				<div class="cx-ui-kit__name cx-control__name">
					<span><?php echo esc_html__( 'Name: ', 'jet-engine' ); ?></span>
					<span class="je-field-name"><?php echo esc_html( $args['name'] ); ?></span>
				</div>
			<?php } ?>
		</div>
	<?php } ?>
	<?php if ( ! empty( $args['children'] ) || ! empty( $args['description'] ) ) { ?>
		<div class="cx-ui-kit__content cx-control__content" role="group" >
			<?php if ( ! empty( $args['children'] ) ) { ?>
				<?php echo $args['children']; ?>
			<?php } ?>
			<?php if ( ! empty( $args['description'] ) ) { ?>
				<div class="cx-ui-kit__description cx-control__description" role="note" >
					<?php echo wp_kses_post( $args['description'] ); ?>
				</div>
			<?php } ?>
		</div>
	<?php } ?>
</div>
