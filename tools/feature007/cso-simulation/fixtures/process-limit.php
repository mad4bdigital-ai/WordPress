<?php
set_error_handler(function($n,$m){echo "process-provider-unavailable\n";return true;});
$process=proc_open(array('fixture-no-host-process'),array(),$pipes);
echo false===$process?"process-rejected\n":"unexpected-process\n";
exit(false===$process?0:1);
