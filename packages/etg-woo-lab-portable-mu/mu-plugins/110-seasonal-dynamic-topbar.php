<?php
/** ETG local: reviewed-season content rendering; no pricing or discount calculation. */
if(!defined('ABSPATH'))return;
/** Text is localized by current visible WPML language; never fallback from hidden languages. */
function shared_seasonal_dynamic_copy($language,$season){
 $dictionary=[
  'en'=>['winter'=>['name'=>'Winter escapes','active'=>'Winter travel season','upcoming'=>'Winter is on the way'], 'summer'=>['name'=>'Summer escapes','active'=>'Summer travel season','upcoming'=>'Summer is on the way'], 'current'=>'Explore trips and activities for the season','soon'=>'Get inspired before the season begins','countdown'=>'Starts in %d days','today'=>'Starts today','cta'=>'Explore tours','eyebrow_active'=>'Seasonal inspiration','eyebrow_upcoming'=>'Coming soon'],
  'ar'=>['winter'=>['name'=>'رحلات الشتاء','active'=>'موسم رحلات الشتاء','upcoming'=>'اقترب موسم الشتاء'], 'summer'=>['name'=>'رحلات الصيف','active'=>'موسم رحلات الصيف','upcoming'=>'اقترب موسم الصيف'], 'current'=>'اكتشف الرحلات والأنشطة المناسبة لهذا الموسم','soon'=>'خطّط لرحلتك قبل بداية الموسم','countdown'=>'يبدأ خلال %d يوم','today'=>'يبدأ اليوم','cta'=>'استكشف الرحلات','eyebrow_active'=>'موسم الرحلات','eyebrow_upcoming'=>'قريبًا'],
  'es'=>['winter'=>['name'=>'Escapadas de invierno','active'=>'Temporada de viajes de invierno','upcoming'=>'El invierno se acerca'], 'summer'=>['name'=>'Escapadas de verano','active'=>'Temporada de viajes de verano','upcoming'=>'El verano se acerca'], 'current'=>'Explora excursiones y actividades de temporada','soon'=>'Inspírate antes del inicio de la temporada','countdown'=>'Comienza en %d días','today'=>'Comienza hoy','cta'=>'Explorar excursiones','eyebrow_active'=>'Inspiración de temporada','eyebrow_upcoming'=>'Próximamente'],
  'de'=>['winter'=>['name'=>'Winterreisen','active'=>'Winterreisesaison','upcoming'=>'Der Winter kommt'], 'summer'=>['name'=>'Sommerreisen','active'=>'Sommerreisesaison','upcoming'=>'Der Sommer kommt'], 'current'=>'Entdecke saisonale Touren und Aktivitäten','soon'=>'Plane deine Reise vor Saisonbeginn','countdown'=>'Beginnt in %d Tagen','today'=>'Beginnt heute','cta'=>'Touren entdecken','eyebrow_active'=>'Saisonale Inspiration','eyebrow_upcoming'=>'Bald verfügbar'],
  'it'=>['winter'=>['name'=>'Viaggi invernali','active'=>'Stagione dei viaggi invernali','upcoming'=>'Sta arrivando l’inverno'], 'summer'=>['name'=>'Viaggi estivi','active'=>'Stagione dei viaggi estivi','upcoming'=>'Sta arrivando l’estate'], 'current'=>'Scopri tour e attività della stagione','soon'=>'Organizza il viaggio prima dell’inizio','countdown'=>'Inizia tra %d giorni','today'=>'Inizia oggi','cta'=>'Esplora i tour','eyebrow_active'=>'Ispirazione stagionale','eyebrow_upcoming'=>'Prossimamente'],
 ];
 return $dictionary[$language]??null;
}
function shared_seasonal_dynamic_topbar_shortcode($atts=[]){
 $a=shortcode_atts(['variant'=>'active','theme'=>'winter'],$atts,'shared_season_dynamic_bar');
 $variant=sanitize_key($a['variant']);$theme=sanitize_key($a['theme']);
 if(!in_array($variant,['active','upcoming'],true)||!in_array($theme,['winter','summer'],true))return '';
 if(!function_exists('shared_offer_seasonal_selection')||!function_exists('shared_offer_seasonal_rules')||!function_exists('shared_offer_current_visible_language'))return '';
 $options=get_option('global-offers',[]);if(empty($options['seasonal_templates_enabled']))return '';
 $language=shared_offer_current_visible_language();$copy=shared_seasonal_dynamic_copy($language,$theme);
 if(!$copy)return ''; // Hidden/unconfigured language is never translated from default.
 $sel=shared_offer_seasonal_selection();if(!$sel||($sel['state']??'')!==$variant)return '';
 $rules=shared_offer_seasonal_rules();$rule=$rules[$sel['rule_index']]??[];
 $expected=$theme==='winter'?2736:2731;
 if(absint($rule['season_term_id']??0)!==$expected)return '';
 $term=get_term($expected,'season');if(!$term||is_wp_error($term)||get_term_meta($expected,'season_enabled',true)==='0')return '';
 // Public copy contains no discount, limited-time price, market claim, or promise of eligibility.
 $title=$copy[$theme][$variant];$eyebrow=$copy['eyebrow_'.$variant];
 $summary=$variant==='upcoming'?$copy['soon']:$copy['current'];
 $days='';
 if($variant==='upcoming'){
  $today=apply_filters('shared_offer/seasonal_today',wp_date('Y-m-d'));$start=(string)($sel['starts_at']??'');
  if(!shared_offer_valid_date($start)||$start<=$today)return '';
  $duration=(new DateTimeImmutable($today,new DateTimeZone('UTC')))->diff(new DateTimeImmutable($start,new DateTimeZone('UTC')));
  $days=sprintf($copy['countdown'],(int)$duration->days);
 }
 $cta=get_post_type_archive_link('tours-and-activities');
 $certified_offer=null;
 if($variant==='active'&&!empty($rule['certified_campaign_id'])&&!empty($rule['brand_approval_required'])&&function_exists('global_offer_resolved')){
  // Core verifies prices, eligibility and the exact active campaign; the season never computes discounts.
  $offer=global_offer_resolved();
  if(is_array($offer)&&isset($offer['campaign'])&&absint($offer['campaign']->ID)===absint($rule['certified_campaign_id'])&&!empty($offer['message'])){
   $certified_offer=$offer;
   if(!empty($offer['url']))$cta=wp_validate_redirect($offer['url'],$cta?:home_url('/'));
  }
 }
 $is_rtl=$language==='ar';$class=$theme==='winter'?'seasonal-dynamic--winter':'seasonal-dynamic--summer';
 $html='<section class="seasonal-dynamic '.esc_attr($class).' seasonal-dynamic--'.esc_attr($variant).'" lang="'.esc_attr($language).'" dir="'.($is_rtl?'rtl':'ltr').'" role="region" aria-label="'.esc_attr($title).'" data-season-term="'.(int)$expected.'" data-seasonal-state="'.esc_attr($variant).'">';
 $html.='<span class="seasonal-dynamic__symbol" aria-hidden="true">'.($theme==='winter'?'&#10052;':'&#9728;').'</span>';
 $html.='<div class="seasonal-dynamic__content"><span class="seasonal-dynamic__eyebrow">'.esc_html($eyebrow).'</span><strong class="seasonal-dynamic__title">'.esc_html($title).'</strong><span class="seasonal-dynamic__description">'.esc_html($summary).'</span></div>';
 if($certified_offer)$html.='<span class="seasonal-dynamic__verified-offer" data-offer-campaign="'.(int)$certified_offer['campaign']->ID.'">'.esc_html($certified_offer['message']).'</span>';
 if($days)$html.='<span class="seasonal-dynamic__countdown">'.esc_html($days).'</span>';
 if($cta)$html.='<a class="seasonal-dynamic__cta" href="'.esc_url($cta).'">'.esc_html($copy['cta']).'<span aria-hidden="true"> &rsaquo;</span></a>';
 return $html.'</section>';
}
add_shortcode('shared_season_dynamic_bar','shared_seasonal_dynamic_topbar_shortcode');
add_action('wp_head',static function(){
 $options=get_option('global-offers',[]);
 if(empty($options['seasonal_templates_enabled'])||!function_exists('shared_offer_seasonal_visible')||!shared_offer_seasonal_visible())return;
 echo '<style id="etg-seasonal-dynamic-topbar">.seasonal-dynamic{--sd-accent:#bcf15a;box-sizing:border-box;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:clamp(12px,2vw,24px);min-height:76px;padding:14px clamp(14px,3vw,38px);color:#f6f9fd;background:linear-gradient(110deg,#092039,#173b53);border-bottom:2px solid var(--sd-accent);text-align:start}.seasonal-dynamic--summer{--sd-accent:#ffc963;background:linear-gradient(110deg,#113249,#235456)}.seasonal-dynamic__symbol{font-size:28px;color:var(--sd-accent);line-height:1}.seasonal-dynamic__content{display:flex;flex:1 1 290px;flex-direction:column;gap:2px}.seasonal-dynamic__eyebrow{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--sd-accent)}.seasonal-dynamic__title{font-size:clamp(16px,1.7vw,21px);font-weight:750;line-height:1.3;color:#fff}.seasonal-dynamic__description{font-size:13px;opacity:.88}.seasonal-dynamic__verified-offer{font-size:13px;font-weight:700;color:var(--sd-accent)}.seasonal-dynamic__countdown{color:var(--sd-accent);font-size:13px;font-weight:700;white-space:nowrap}.seasonal-dynamic__cta{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:100px;background:var(--sd-accent);color:#0c2038!important;font-size:13px;font-weight:750;padding:11px 17px;text-decoration:none!important;white-space:nowrap;transition:filter .15s}.seasonal-dynamic__cta:hover,.seasonal-dynamic__cta:focus-visible{filter:brightness(1.12);outline:2px solid #fff;outline-offset:2px}@media(max-width:720px){.seasonal-dynamic{gap:8px;padding:12px 14px;min-height:unset}.seasonal-dynamic__symbol{font-size:23px}.seasonal-dynamic__content{flex-basis:calc(100% - 40px)}.seasonal-dynamic__description{font-size:12px}.seasonal-dynamic__countdown{margin-inline-start:30px}.seasonal-dynamic__cta{margin-inline-start:auto;font-size:12px;padding:9px 13px}}@media(prefers-reduced-motion:reduce){.seasonal-dynamic__cta{transition:none}}.elementor-66131 .elementor-element-a0fbe10{display:block!important;width:100%!important;max-width:100%!important;min-width:0}.elementor-66131 .elementor-element-seasc76,.elementor-66131 .elementor-element-seas97d,.elementor-66131 .elementor-element-seas4cc{display:block!important;width:100%!important;max-width:100%!important;min-width:0}.elementor-66131 .elementor-widget-shortcode,.elementor-66131 .elementor-widget-shortcode>.elementor-widget-container,.elementor-66131 .elementor-shortcode{width:100%!important;max-width:100%!important;min-width:0}.elementor-66131 .elementor .e-con.e-parent{width:100%!important;max-width:100%!important;min-width:0}.elementor-66131 .elementor .e-con.e-parent{--flex-direction:column!important;flex-direction:column!important}.elementor-66131 .elementor-66365>.e-con,.elementor-66131 .elementor-66366>.e-con,.elementor-66131 .elementor-66367>.e-con,.elementor-66131 .elementor-66368>.e-con{--flex-direction:column!important;flex-direction:column!important}.seasonal-dynamic{width:100%;max-width:100%;min-width:0}.etg-season-messages,.etg-season-tours{width:100%;background:#0f293e;color:#fff;padding:0 16px;box-sizing:border-box}.etg-season-messages:has(.jet-listing-dynamic-repeater__items:empty){display:none}.etg-season-messages .jet-listing-dynamic-repeater__items{display:flex;flex-wrap:wrap;align-items:center;gap:8px}.etg-seasonal-message-item{display:inline-flex;align-items:center;gap:8px;color:#eef6f9;font-size:12px;line-height:1.5}.etg-seasonal-message-item strong{color:#bcf15a}.etg-season-message-cta{color:#bcf15a;text-underline-offset:2px}.etg-season-tours .jet-listing-grid__items{display:flex!important;gap:10px;flex-wrap:wrap;justify-content:center;padding:7px 0}.etg-season-tours .jet-listing-grid__item{flex:1 1 160px!important;max-width:320px;min-width:0}.etg-season-tour-item{border-radius:9px;background:#1a4054;padding:8px 10px;min-height:34px;display:flex;align-items:center}.etg-season-tour-title,.etg-season-tour-title .elementor-heading-title{font-size:12px!important;line-height:1.45!important;color:#f7fbfe!important;margin:0!important;font-weight:600}.etg-season-tour-title a{color:inherit!important;text-decoration:none}.etg-season-tour-title a:hover,.etg-season-tour-title a:focus-visible{text-decoration:underline;color:#bcf15a!important}.etg-season-tours .jet-listing-grid__item-content{min-width:0}@media(max-width:720px){.etg-season-tours .jet-listing-grid__items{flex-wrap:nowrap!important;overflow-x:auto!important;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}.etg-season-tours .jet-listing-grid__item{flex:0 0 min(78vw,270px)!important;max-width:min(78vw,270px)!important;min-width:0!important;scroll-snap-align:start}.etg-season-messages .jet-listing-dynamic-repeater__items{gap:5px}.etg-season-messages,.etg-season-tours{padding:0 10px}.etg-seasonal-message-item{font-size:11px}}</style>';
},50);
