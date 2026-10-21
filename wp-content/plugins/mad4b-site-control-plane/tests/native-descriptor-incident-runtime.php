<?php
/** New stages retain the existing staging gate, redaction and request bound. */
define('ABSPATH',__DIR__);define('DAY_IN_SECONDS',86400);
define('AUTH_KEY',str_repeat('K',64));define('AUTH_SALT',str_repeat('S',64));
$GLOBALS['options']=[];
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,$autoload=false){$GLOBALS['options'][$k]=$v;return true;}
function is_admin(){return false;}
require __DIR__.'/../bootstrap/mad4b-native-incident-mu.php';
if(false!==MAD4B_Native_Incident_MU::record_throwable(new RuntimeException('PRIVATE'),'ability_inspection'))throw new RuntimeException('Inactive capture enabled');
MAD4B_Native_Incident_MU::boot(true);
foreach(['ability_inspection','descriptor_binding'] as $stage){
 MAD4B_Native_Incident_MU::record_throwable(new RuntimeException('PRIVATE '.$stage),$stage,'mad4b/site-info',MAD4B_Native_Incident_MU::correlation_id());
 $events=MAD4B_Native_Incident_MU::status()['events'];$last=end($events);
 if($last['stage']!==$stage||$last['ability_name']!=='mad4b/site-info'||strlen($last['correlation_id'])!==32)throw new RuntimeException('Stage metadata lost');
}
if(strpos(json_encode($GLOBALS['options']),'PRIVATE')!==false)throw new RuntimeException('Raw error leaked');
echo "NATIVE_DESCRIPTOR_INCIDENT_PASS\n";
