<?php
/** Boundary regression: an aligned Inventory Profile Plan must not reset publication evidence. */
$source=file_get_contents(dirname(__DIR__).'/includes/Admin/InventoryControlPage.php');
if(!is_string($source)){throw new RuntimeException('Missing Inventory Control source');}
$find=array('$originalRaw=$raw;', 'if($raw===$originalRaw)', '$publication=is_array(', 'get_option(Configuration::OPTION_NAME,array())!==$target');
$offsets=array();
foreach($find as$token){$pos=strpos($source,$token);if(false===$pos){throw new RuntimeException('Missing no-op or readback guard: '.$token);}$offsets[]=$pos;}
if(!($offsets[0]<$offsets[1]&&$offsets[1]<$offsets[2]&&$offsets[2]<$offsets[3])){throw new RuntimeException('No-op check must precede publication evidence reset and persistence');}
echo "PASS Inventory Profile Plan structural no-op boundary (static contract)\n";
