<?php
/**
 * Filter dates formats template
 */
?>
<ul class="date-formats">
<?php
	printf( '<li><b>d</b> - %s</li>', esc_html__( 'day of month;', 'jet-smart-filters' ) );
	printf( '<li><b>dd</b> - %s</li>', esc_html__( 'day of month with leading zero;', 'jet-smart-filters' ) );
	printf( '<li><b>D</b> - %s</li>', esc_html__( 'short day name;', 'jet-smart-filters' ) );
	printf( '<li><b>DD</b> - %s</li>', esc_html__( 'full day name;', 'jet-smart-filters' ) );
	printf( '<li><b>m</b> - %s</li>', esc_html__( 'month of year;', 'jet-smart-filters' ) );
	printf( '<li><b>mm</b> - %s</li>', esc_html__( 'month of year with leading zero;', 'jet-smart-filters' ) );
	printf( '<li><b>M</b> - %s</li>', esc_html__( 'short month name;', 'jet-smart-filters' ) );
	printf( '<li><b>MM</b> - %s</li>', esc_html__( 'full month name;', 'jet-smart-filters' ) );
	printf( '<li><b>y</b> - %s</li>', esc_html__( 'year (two digit);', 'jet-smart-filters' ) );
	printf( '<li><b>yy</b> - %s</li>', esc_html__( 'year (four digit);', 'jet-smart-filters' ) );
?>
</ul>
<?php
	do_action( 'jet-smart-filters/admin/filter-date-formats-after' );
?>