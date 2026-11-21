<?php
if (!defined('ABSPATH') || !defined('WP_CLI')) exit(1);
if (!class_exists('MAD4B_Guide_Daily_Base_Cost_V1',false) ||
    !class_exists('MAD4B_Form6_Guide_Locale_Default_V1',false)) {
    WP_CLI::error('Guide pricing or form locale component absent');
}
$expected=['en'=>['english',2500],'fr'=>['french',3000],
           'es'=>['spanish',3000],'ar'=>['arabic',2000],
           'de'=>['german',3000],'it'=>[null,null]];
$checks=0;
foreach ($expected as $requested=>[$label,$cost]) {
    do_action('wpml_switch_language', $requested);
    $effective=(string)apply_filters('wpml_current_language',null);
    $real=$expected[$effective]??[null,null];
    $html=do_shortcode('[fluentform id="6"]');
    if (!preg_match('/<select[^>]*name="guide_language"[^>]*>(.*?)<\/select>/s',$html,$match)) {
        WP_CLI::error('Guide language select not rendered');
    }
    $selected=null;
    if (preg_match('/<option[^>]*value="([^"]+)"[^>]*selected(?:=|\s|>)/s',$match[1],$item)) {
        $selected=$item[1];
    }
    if ($selected!==$real[0]) WP_CLI::error("Bad default requested=$requested effective=$effective");
    if ($selected!==null) {
        $quoted=MAD4B_Guide_Daily_Base_Cost_V1::preview($selected,1);
        if (is_wp_error($quoted) || ($quoted['base_cost']??null)!==$real[1] ||
            ($quoted['apply_to_commercial_total']??true)!==false) {
            WP_CLI::error("Locale price mismatch requested=$requested effective=$effective");
        }
    }
    $checks++;
    WP_CLI::log("PASS requested=$requested effective=$effective guide=".($selected??'(none)')." base_cost=".($real[1]??'(none)'));
}
WP_CLI::success('GUIDE_FORM_TO_COST_PREVIEW_VERIFIED cases='.$checks);
