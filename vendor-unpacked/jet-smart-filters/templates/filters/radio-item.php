<?php
/**
 * Radio item template
 */

$checked_icon = apply_filters( 'jet-smart-filters/templates/radio/checked-icon', jet_smart_filters()->print_template( 'svg/check.svg' ) );
$collapsible  = isset( $args['collapsible'] ) ? $args['collapsible'] : false;

?>
<div class="jet-radio-list__row jet-filter-row<?php echo esc_attr( $extra_classes ); ?>">
	<?php
	if ( $collapsible ) {
		include jet_smart_filters()->get_template( 'common/collapsible-toggle.php' );
	}
	?>
	<label class="jet-radio-list__item" <?php
		// Tabindex attribute is generated internally and considered safe.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo jet_smart_filters()->data->get_tabindex_attr();
	?>>
		<input
			type="radio"
			class="jet-radio-list__input"
			name="<?php echo esc_attr( $query_var ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			data-label="<?php echo esc_attr( $label ); ?>"
			<?php if ( ! empty( $data_attrs ) ) {
				echo wp_kses_post( jet_smart_filters()->utils->generate_data_attrs( $data_attrs ) );
			} ?>
			aria-label="<?php echo esc_attr( $label ); ?>"
			<?php echo esc_attr( $checked ); ?>
		>
		<div class="jet-radio-list__button">
			<?php if ( $show_decorator ) : ?>
				<span class="jet-radio-list__decorator">
					<i class="jet-radio-list__checked-icon"><?php
						// SVG output is considered safe
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo $checked_icon;
					?></i>
				</span>
			<?php endif; ?>
			<span class="jet-radio-list__label"><?php echo esc_html( $label ); ?></span>
			<?php
				// print counter if not all option
				if ( $value !== 'all' ) {
					do_action('jet-smart-filter/templates/counter', $args );
				}
			?>
		</div>
	</label>
</div>
