<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$files=array(
    $root.'/includes/Runtime/BootGuard.php',
    $root.'/includes/Admin/DynamicContentPage.php',
    $root.'/includes/Admin/InventoryControlPage.php',
    $root.'/includes/Admin/MediaLabPage.php',
);
$legacy=0;$correct=0;
foreach($files as $file){$source=file_get_contents($file);$legacy+=substr_count($source,"wp_die('Forbidden', 403)")+substr_count($source,"wp_die('Forbidden',403)");$correct+=substr_count($source,"'response' => 403")+substr_count($source,"'response'=>403");}
if(0!==$legacy){fwrite(STDERR,"FAIL: legacy wp_die Forbidden HTTP semantics remain\n");exit(1);}
if(6>$correct){fwrite(STDERR,"FAIL: expected six corrected Forbidden response guards\n");exit(1);}
echo "Alpha13 admin Forbidden HTTP semantics smoke tests passed.\n";
