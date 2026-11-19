<?php
/** Read-only: company Split default, original per-tour overrides and stable cards. */
defined('ABSPATH')||exit;
if(!class_exists('MAD4B_Tour_Highlights_V1',false) ||
   !class_exists('MAD4B_Tour_Highlights_Presentation_V1',false))
    WP_CLI::error('Native Journey engine unavailable');

$company=get_option('look-and-feel',[]);
if(!is_array($company) ||
   ($company['tour_highlights_default_pattern']??'')!=='split')
    WP_CLI::error('Company default pattern must be Split');
$id=60623;
if(get_post_type($id)!=='tours-and-activities')
    WP_CLI::error('Canonical 8-day regression Tour missing');
if(MAD4B_Tour_Highlights_V1::display_pattern($id)!=='split')
    WP_CLI::error('Native Journey pattern did not inherit Split');

$overrides=[];
$virtual='inherit';
$filter=static function($pre,$objectId,$key,$single)use(&$virtual,$id){
    if((int)$objectId===$id && $key==='tour_highlights_display_pattern')
        return $single?$virtual:[$virtual];
    return $pre;
};
add_filter('get_post_metadata',$filter,999,4);
foreach(['explorer'=>'explorer','story'=>'story','split'=>'split',
          'inherit'=>'split','not-a-pattern'=>'split'] as $input=>$expected){
    $virtual=$input;
    $actual=MAD4B_Tour_Highlights_V1::display_pattern($id);
    $overrides[$input]=$actual;
    if($actual!==$expected)
        WP_CLI::error("Per-Tour override contract failed: $input -> $actual");
}
remove_filter('get_post_metadata',$filter,999);
$cards=MAD4B_Tour_Highlights_Presentation_V1::cards(
    (string)get_post_meta($id,'tour_highlights_content',true));
$keys=MAD4B_Tour_Highlights_Presentation_V1::card_keys($cards);
if(count($cards)!==12 || count($keys)!==12 || count(array_unique($keys))!==12)
    WP_CLI::error('8-day Tour Highlight count or stable-key contract failed');
WP_CLI::success(wp_json_encode([
    'site'=>home_url(),'company_default'=>'split','tour_id'=>$id,
    'cards'=>count($cards),'unique_keys'=>count(array_unique($keys)),
    'override_cases'=>$overrides,'read_only'=>true,
],JSON_UNESCAPED_SLASHES));
