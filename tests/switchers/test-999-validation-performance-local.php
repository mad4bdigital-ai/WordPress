<?php
/** Local only: CPU cost of validating the largest native booking-unit count. */
require '/var/www/html/wp-load.php';
if(wp_get_environment_type()!=='local')exit(41);
$n='Travel_Booking_Traveller_Nationality_Switcher';
$input=array_fill(0,999,'EG');
$start=microtime(true);$before=memory_get_usage(true);
for($i=0;$i<20;$i++){
 $validated=$n::validate_list($input);
 if(is_wp_error($validated)||count($validated)!==999){echo "INVALID_RESULT\n";exit(1);}
}
$duration=1000*(microtime(true)-$start);
$delta=max(0,memory_get_peak_usage(true)-$before);
echo "VALIDATION_999_20_ITERATIONS_MS=".round($duration,2)."\n";
echo "VALIDATION_MEMORY_DELTA_BYTES=$delta\n";
$ok=$duration<2000&&$delta<=33554432;
echo "VALIDATION_999_PERFORMANCE=".($ok?'PASS':'FAIL').PHP_EOL;
exit($ok?0:1);
