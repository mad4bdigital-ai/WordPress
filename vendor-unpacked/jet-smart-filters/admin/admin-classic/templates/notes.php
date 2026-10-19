<?php
/**
 * Filter notes template
 */
?>
<<p>
	<b>*Query Variable</b> – <?php esc_html_e( 'you need to add the meta field name by which you want to filter the data, into the field. The Query Variable is set automatically for taxonomies, search filters and filters via the post publication date.', 'jet-smart-filters' ); ?>
</p>

<h5><?php esc_html_e( 'Popular plugins fields', 'jet-smart-filters' ); ?></h5>
<h5><?php esc_html_e( 'WooCommerce:', 'jet-smart-filters' ); ?></h5>

<ul>
<?php
	printf(
		'<li><b>_price</b>: %s</li>',
		esc_html__( 'filter by product price;', 'jet-smart-filters' )
	);
	printf(
		'<li><b>_wc_average_rating</b>: %s</li>',
		esc_html__( 'filter by product rating;', 'jet-smart-filters' )
	);
	printf(
		'<li><b>total_sales</b>: %s</li>',
		esc_html__( 'filter by sales count;', 'jet-smart-filters' )
	);
	printf(
		'<li><b>_weight</b>: %s</li>',
		esc_html__( 'product weight;', 'jet-smart-filters' )
	);
	printf(
		'<li><b>_length</b>: %s</li>',
		esc_html__( 'product length;', 'jet-smart-filters' )
	);
	printf(
		'<li><b>_width</b>: %s</li>',
		esc_html__( 'product width;', 'jet-smart-filters' )
	);
	printf(
		'<li><b>_height</b>: %s</li>',
		esc_html__( 'product height;', 'jet-smart-filters' )
	);
	printf(
		'<li><b>_sale_price_dates_from</b>: %s</li>',
		esc_html__( 'filter by product sale start date;', 'jet-smart-filters' )
	);
	printf(
		'<li><b>_sale_price_dates_to</b>: %s</li>',
		esc_html__( 'filter by product sale end date;', 'jet-smart-filters' )
	);
?>
</ul>

<?php
do_action( 'jet-smart-filters/post-type/filter-notes-after' );
