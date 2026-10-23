<?php
/** Guided UI regression checks. These do not execute WordPress or Google APIs. */
$dir=dirname(__DIR__);
$ui=file_get_contents($dir.'/includes/class-mad4b-google-measurement-admin.php');
$reports=file_get_contents($dir.'/includes/class-mad4b-google-measurement-reports.php');
$projects=file_get_contents($dir.'/includes/class-mad4b-keyword-projects.php');
$profile=file_get_contents(dirname($dir).'/mad4b-site-control-plane/includes/search/class-mad4b-scp-search-profile-admin.php');
$checks=array(
 array($ui,'Quick report from template'),
 array($ui,'MAD4B_Google_Measurement_Reports::presets()'),
 array($ui,'Advanced GA4 report definition'),
 array($ui,"wp_nonce_field('mad4b_measurement_report_save')"),
 array($reports,"array_key_exists(\$preset,self::presets())"),
 array($reports,"$"."def=self::normalize(\$input)"),
 array($profile,"'add_market' === \$input['operation']"),
 array($profile,'Add target market to this profile'),
 array($profile,'profile_targeting_requires_pause_and_spend_freeze'),
 array($profile,'guided Add target market form'),
 array($projects,'public static function read_ability'),
 array($projects,'public static function save_ability'),
 array($projects,"'execute_callback'=>array(__CLASS__,\$read?'read_ability':'save_ability')")
);
foreach($checks as $check)if(strpos($check[0],$check[1])===false){
 fwrite(STDERR,"Missing guided admin invariant: ".$check[1]."\n");exit(1);
}
if(strpos($reports,"update_option(self::OPTIONS")===false)exit(1);
echo "PASS: guided GA4 preset and market UI reuse governed WordPress save paths\n";
