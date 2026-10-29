<?php
/** Neutral seasonal announcement content, no offer, price or implied discount. */
if(!defined('ABSPATH'))return;
function shared_seasonal_announcement_shortcode($atts=[]){
 $a=shortcode_atts(['type'=>'announcement'],$atts,'shared_season_announcement');
 if(!function_exists('shared_offer_seasonal_selection')||!function_exists('shared_offer_seasonal_rules'))return '';
 $selection=shared_offer_seasonal_selection();if(!$selection)return '';
 $rules=shared_offer_seasonal_rules();$rule=$rules[$selection['rule_index']]??[];
 $season=get_term(absint($rule['season_term_id']??0),'season');
 if(!$season||is_wp_error($season))return '';
 $lang=function_exists('shared_offer_current_visible_language')?shared_offer_current_visible_language():'';
 if(!$lang)return '';
 $message=$selection['state']==='upcoming'?'Upcoming season':'Season highlights';
 $name=esc_html($season->name);
 return '<div class="seasonal-announcement" role="status" aria-live="off" data-season="'.esc_attr($season->slug).'" data-language="'.esc_attr($lang).'"><span class="seasonal-announcement__label">'.esc_html($message).'</span><strong class="seasonal-announcement__name">'.$name.'</strong></div>';
}
add_shortcode('shared_season_announcement','shared_seasonal_announcement_shortcode');
add_action('wp_head',static function(){
 if(!function_exists('shared_offer_seasonal_visible')||!shared_offer_seasonal_visible())return;
 echo '<style>.seasonal-announcement{display:flex;gap:.65rem;align-items:center;justify-content:center;flex-wrap:wrap;padding:.55rem 1rem;background:#0c2038;color:#f8fdf0;font-size:.95rem}.seasonal-announcement__label{opacity:.82}.seasonal-announcement__name{color:#b6ef52}</style>';
});
