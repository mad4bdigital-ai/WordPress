<?php
/**
 * MAD4B Native WP UI Screen Coverage read-only scanner.
 * wp eval-file wp-content/plugins/mad4b-jetengine/modules/functional-modes/qa/wp-native-screen-coverage-scan.php --skip-themes --user=1
 * This inventories registered admin routes, NOT actual screen widgets.
 * No callbacks/render functions for third-party admin screens invoked.
 */
if(!class_exists('MAD4B_Native_WP_Screen_Coverage_V1')){
    echo wp_json_encode(['pass'=>false,'error'=>'Registry class not loaded']).PHP_EOL;
    return;
}
if(!did_action('admin_menu'))do_action('admin_menu');
$scan=MAD4B_Native_WP_Screen_Coverage_V1::scan();
$bad=[];
foreach($scan['screens'] as $item){
    if(empty($item['slug'])||empty($item['parent'])||empty($item['family']))$bad[]=$item['slug']??'unknown';
}
echo wp_json_encode([
 'pass'=>!$bad && $scan['total']>=1,
 'scope'=>'Registered MAD4B-related admin menu routes. No render/Save or cross-screen modernization is claimed.',
 'registered_routes'=>$scan['total'],
 'groups'=>$scan['groups'],
 'needs_per_screen_acceptance'=>$scan['total'],
 'invalid_records'=>$bad,
 'screen_catalog'=>$scan['screens'],
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
