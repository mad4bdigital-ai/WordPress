<?php
/**
 * Filter items search template
 */

if ( empty( $args['search_enabled'] ) ) {
	return;
}

$search_placeholder = $args['search_placeholder'] ?? '';

?>
<div class="jet-filter-items-search">
	<input
		class="jet-filter-items-search__input"
		type="search"
		autocomplete="off"
		aria-label="<?php
			echo esc_attr(
				sprintf(
					/* translators: %s: accessibility label */
					__( 'Search in %s', 'jet-smart-filters' ),
					$accessibility_label
				)
			);
		?>"
		<?php if ( $search_placeholder ) : ?>
			placeholder="<?php echo esc_attr( $search_placeholder ); ?>"
		<?php endif; ?>
	>
	<div class="jet-filter-items-search__clear">
		<?php
		// SVG template output is generated internally and considered safe.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo jet_smart_filters()->print_template( 'svg/close.svg' );
		?>
	</div>
</div>