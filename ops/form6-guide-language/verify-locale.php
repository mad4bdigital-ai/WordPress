<?php
if (!defined('ABSPATH') || !defined('WP_CLI')) exit(1);
if (!class_exists('MAD4B_Form6_Guide_Locale_Default_V1', false)) WP_CLI::error('Locale resolver MU plugin not loaded');
global $wpdb;
$row=$wpdb->get_row($wpdb->prepare("SELECT form_fields FROM {$wpdb->prefix}fluentform_forms WHERE id=%d",6),ARRAY_A);
$form=json_decode($row['form_fields']??'',true);
$names=array_map(static fn($item)=>(string)($item['attributes']['name']??''),$form['fields']??[]);
if (count(array_keys($names,'guide_language',true))!==1) WP_CLI::error('Guide field count mismatch');
$guideIdx=array_search('guide_language',$names,true);
$adultIdx=array_search('no_of_adults',$names,true);
if ($guideIdx!==$adultIdx-1) WP_CLI::error('Guide field does not immediately precede adults');
$expect=['en'=>'english','fr'=>'french','es'=>'spanish','ar'=>'arabic','de'=>'german','it'=>null];
$passes=0;
foreach(array_keys($expect) as $requested) {
    do_action('wpml_switch_language',$requested);
    $effective=(string)apply_filters('wpml_current_language',null);
    $want=$expect[$effective]??null;
    $html=do_shortcode('[fluentform id="6"]');
    if (!preg_match('/<select[^>]*name="guide_language"[^>]*>(.*?)<\/select>/s',$html,$match)) {
        WP_CLI::error("Guide dropdown not rendered for locale=$effective");
    }
    $selected=null;
    if (preg_match('/<option[^>]*value="([^"]+)"[^>]*selected(?:=|\s|>)/s',$match[1],$option)) {
        $selected=$option[1];
    }
    if ($selected!==$want) WP_CLI::error("Bad locale mapping requested=$requested effective=$effective actual=".($selected??'-')." want=".($want??'-'));
    WP_CLI::log("PASS requested=$requested effective=$effective default=".($selected??'(none)'));
    $passes++;
}
do_action('wpml_switch_language','en');
$manual=['element'=>'select','attributes'=>['name'=>'guide_language','value'=>'spanish'],'settings'=>[
    'advanced_options'=>[['label'=>'Spanish','value'=>'spanish']], 'dynamic_default_value'=>''
]];
$unchanged=MAD4B_Form6_Guide_Locale_Default_V1::render($manual,(object)['id'=>6]);
if (($unchanged['attributes']['value']??'')!=='spanish')WP_CLI::error('Manual guide preference overwritten');
WP_CLI::success("FORM6_GUIDE_LOCALE_VERIFIED cases=$passes manual_override=preserved guide_before_adults=yes");
