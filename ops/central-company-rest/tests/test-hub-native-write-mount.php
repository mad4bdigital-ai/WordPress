<?php
/**
 * Isolated SCP catalog regression. The native Write Ability must be mounted
 * without making its source sync callback a Read tool or a bypassed host command.
 */
define('ABSPATH', '/test/');
require dirname(__DIR__, 3) .
    '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-server-write-catalog.php';

final class MAD4B_Hub_Source_Write_Mount_Test {
    use MAD4B_SCP_Server_Write_Catalog;
    public static function core_candidates_for_test(): array {
        return self::core_write_candidates();
    }
}
$names=MAD4B_Hub_Source_Write_Mount_Test::core_candidates_for_test();
if(!in_array('mad4b/hub-source-sync-request',$names,true)) {
    fwrite(STDERR,"FAIL: Native source sync not admitted to existing SCP Write catalog\n");
    exit(1);
}
if(count($names)!==count(array_unique($names))) {
    fwrite(STDERR,"FAIL: Duplicate SCP Write catalog entries\n");
    exit(1);
}
if(!in_array('mad4b/content-job-create',$names,true) ||
   !in_array('mad4b/provider-connection-refresh',$names,true)) {
    fwrite(STDERR,"FAIL: Pre-existing governed Write tools were removed\n");
    exit(1);
}
$sync=file_get_contents(dirname(__DIR__).'/central/276-mad4b-hub-source-sync-jobs.php');
if(!is_string($sync) ||
   strpos($sync,"'category'=>$spec['write']?'mad4b-write':'mad4b-read'")===false ||
   strpos($sync,"'surface'=>$spec['write']?'write':'read'")===false ||
   strpos($sync,"'queued_execution_automatic'=>false")===false ||
   strpos($sync,"'requires_vps_runner'=>true")===false ||
   strpos($sync,"'auto_merge_enabled'=>false")===false) {
    fwrite(STDERR,"FAIL: Native Write classification or VPS-only execution boundary drift\n");
    exit(1);
}
echo "PASS 6 SCP native Write mount, retained tools and safety assertions\n";
