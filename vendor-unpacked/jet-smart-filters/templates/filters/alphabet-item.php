<?php
/**
 * Alphabet list item template
 */

$label = strtoupper( $value );
?>
<div class="jet-alphabet-list__row jet-filter-row">
	<label
		class="jet-alphabet-list__item"
		<?php
		// Tabindex attribute is generated internally and considered safe.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo jet_smart_filters()->data->get_tabindex_attr();
		?>
	>
		<input
			type="<?php echo esc_attr( $filter_type ); ?>"
			class="jet-alphabet-list__input"
			name="<?php echo esc_attr( $query_var ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			data-label="<?php echo esc_attr( $label ); ?>"
			aria-label="<?php echo esc_attr( $label ); ?>"
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $checked;
			?>
		>
		<span class="jet-alphabet-list__button">
			<?php echo esc_html( $label ); ?>
		</span>
	</label>
</div>