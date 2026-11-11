<?php
/** Plugin Name: Header Market Preferences — Compact Native Selectors */
if (!defined('ABSPATH')) exit;

/**
 * Visual composition only: existing country and traveller plugins continue to
 * own persistence, validation, WPML and WooCommerce market eligibility.
 */
add_shortcode('header_market_preferences', static function() {
 $countryLabel=apply_filters('wpml_current_language',null)==='ar'?'الدولة':'Country';
 $country=shortcode_exists('mad4b_country_switcher')?do_shortcode('[mad4b_country_switcher label="'.esc_attr($countryLabel).'"]'):'';
 $nationality=shortcode_exists('travel_booking_nationality_switcher')?do_shortcode('[travel_booking_nationality_switcher]'):'';
 if(!$country&&!$nationality)return '';
 $countryCode='';
 if(class_exists('MAD4B_Country_Switcher')){
  $state=MAD4B_Country_Switcher::context();
  $countryCode=(string)($state['country']??'');
 }
 if(!preg_match('/^[A-Z]{2}$/D',$countryCode))$countryCode='';
 $ar=apply_filters('wpml_current_language',null)==='ar';
 $summaryLabel=$ar?'الدولة والجنسية':'Country & passport';
 $countryFallback=$ar?'الدولة':'Country';
 $selectedLabel=$countryCode?:$countryFallback;
 $flag='';
 if($countryCode&&class_exists('MAD4B_SCP_Switcher_Sources'))
  $flag=MAD4B_SCP_Switcher_Sources::flag_html($countryCode);
 $arrow='<svg class="header-market-preferences__chevron" aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
 return '<details class="header-market-preferences" data-native-preference-switchers
  data-preference-api="'.esc_url(rest_url('visitor-preferences/v1/apply')).'"
  data-preference-state="'.esc_url(rest_url('visitor-preferences/v1/state')).'">'.
  '<summary aria-label="'.esc_attr($summaryLabel).'"><span class="header-market-preferences__icon" aria-hidden="true">'.($flag?:'🌐').'</span>'.
  '<span class="header-market-preferences__current">'.esc_html($selectedLabel).'</span>'.$arrow.'</summary>'.
  '<div class="header-market-preferences__panel" role="group" aria-label="'.esc_attr($summaryLabel).'">'.
  $country.$nationality.'<span class="header-market-preferences__message" role="status" aria-live="polite"></span>'.
  '</div></details>';
});

add_action('wp_enqueue_scripts', static function() {
 $base=content_url('mu-plugins/header-market-preferences/assets/');
 wp_enqueue_style('header-market-preferences-ui',$base.'preferences.css',[],'1.2.1');
 wp_enqueue_script('header-market-preferences-ui',$base.'preferences.js',['mad4b-browser-journey-v1'],'1.2.2',true);
});
