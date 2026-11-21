<?php
/** Hermetic no-WordPress, no DB, no network unit test for original 993 source defaults. */
define('ABSPATH','/');
function add_action($hook,$callback,$priority=10,$accepted_args=1) {
    return true; // Suppress boot hooks; only test pure defaults.
}
require __DIR__.'/993-mad4b-scraping-source-profiles-v1.php';
$defaults=MAD4B_Scraping_Source_Profiles_V1::defaults();
$official=[
  'sofitel.accor.com','triumphhotel.com','tolipgroup.com','sonesta.com',
  'jazhotels.com','baronhotels.com','marinasharm.com','pyramisahotels.com'
];
foreach($official as $host) {
    if(!isset($defaults[$host])||!is_array($defaults[$host]))throw new RuntimeException('MISSING_HOST_'.$host);
    $p=$defaults[$host];
    if($p['source_host']!==$host||
       $p['price_policy']!=='hold'||
       $p['content_policy']!=='manual_review'||
       $p['auto_publish_authorized']!==false||
       $p['pricing_evidence']!=='none'||
       $p['field_trust']['properties']!=='B'||
       $p['field_trust']['units']!=='B'||
       !in_array('prices',$p['exclude_fields'],true)||
       !in_array('gallery',$p['exclude_fields'],true)||
       !in_array('source_operator',$p['exclude_fields'],true))
       throw new RuntimeException('OFFICIAL_WEB_SOURCE_POLICY_REGRESSION_'.$host);
}
if(($defaults['memphistours.com']['id']??'')!=='memphis_tours')
 throw new RuntimeException('PREEXISTING_MEMPHIS_DEFAULT_REGRESSION');
echo "ORIGINAL_CP4_SOURCE_PROFILE_POLICY_PASS official_hosts=".count($official)." price_policy=hold image_rights=unverified auto_publish=false\n";
