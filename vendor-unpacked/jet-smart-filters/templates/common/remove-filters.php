<?php
/**
 * Remove filters button
 */

$extra_classes = '';

if ( !$edit_mode ) {
	$extra_classes = 'hide';
}

?>
<div class="jet-remove-all-filters <?php echo esc_attr( $extra_classes ); ?>">
	<button
		type="button"
		class="jet-remove-all-filters__button"
		data-content-provider="<?php echo esc_attr( $provider ); ?>"
		data-additional-providers="<?php echo esc_attr( $additional_providers ); ?>"
		data-apply-type="<?php echo esc_attr( $settings['apply_type'] ); ?>"
		data-query-id="<?php echo esc_attr( $query_id ); ?>"
		<?php
		// Tabindex attribute is generated internally and considered safe
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo jet_smart_filters()->data->get_tabindex_attr();
		?>
	>
		<?php echo esc_html( $settings['remove_filters_text'] ); ?>
	</button>
</div>