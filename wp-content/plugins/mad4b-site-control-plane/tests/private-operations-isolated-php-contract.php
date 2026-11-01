<?php
/* Ensure early privacy guard is loadable by pure PHP test harnesses. */
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-mad4b-scp-private-operations-boundary.php';
if ( ! class_exists( 'MAD4B_SCP_Private_Operations_Boundary', false ) ) {
    throw new RuntimeException( 'Privacy guard declaration unavailable' );
}
if ( function_exists( 'add_filter' ) || function_exists( 'add_action' ) ) {
    throw new RuntimeException( 'Unexpected WordPress hook functions in isolated test' );
}
echo "PASS: private operations guard loads without WordPress hooks\n";
