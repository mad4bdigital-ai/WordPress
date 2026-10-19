<?php
/**
 * Checkbox list item template
 */

$collapsible  = isset( $args['collapsible'] ) ? (bool) $args['collapsible'] : false;
$checked_icon = apply_filters(
	'jet-smart-filters/templates/checkboxes-item/checked-icon',
	jet_smart_filters()->print_template( 'svg/check.svg' )
);

?>
<div class="jet-checkboxes-list__row jet-filter-row<?php echo esc_attr( $extra_classes ); ?>">
	<?php
	if ( $collapsible ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		include jet_smart_filters()->get_template( 'common/collapsible-toggle.php' );
	}
	?>
	<label
		class="jet-checkboxes-list__item"
		<?php
		// Tabindex attribute is generated internally and considered safe.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo jet_smart_filters()->data->get_tabindex_attr();
		?>
	>
		<input
			type="checkbox"
			class="jet-checkboxes-list__input"
			name="<?php echo esc_attr( $query_var ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			data-label="<?php echo esc_attr( $label ); ?>"
			<?php
			if ( ! empty( $data_attrs ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo jet_smart_filters()->utils->generate_data_attrs( $data_attrs );
			}
			?>
			aria-label="<?php echo esc_attr( $label ); ?>"
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $checked;
			?>
		>
		<div class="jet-checkboxes-list__button">
			<?php if ( $show_decorator ) : ?>
				<span class="jet-checkboxes-list__decorator">
					<i class="jet-checkboxes-list__checked-icon">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo $checked_icon;
						?>
					</i>
				</span>
			<?php endif; ?>
			<span class="jet-checkboxes-list__label">
				<?php echo esc_html( $label ); ?>
			</span>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			do_action( 'jet-smart-filter/templates/counter', $args );
			?>
		</div>
	</label>
</div>