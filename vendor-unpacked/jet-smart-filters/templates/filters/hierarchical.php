<?php

if ( empty( $levels ) ) {
	return;
}

?>
<div class="jet-filters-group">
<?php
foreach ( $levels as $level ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo $level;
}
?>
</div>
