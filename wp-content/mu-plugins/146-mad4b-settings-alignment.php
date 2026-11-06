<?php
/**
 * Plugin Name: MAD4B Company Settings Alignment
 * Description: Read-only canonical-source map across JetEngine, WooCommerce, and MAD4B Company settings.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Company_Settings_Alignment_V1', false)) {
final class MAD4B_Company_Settings_Alignment_V1 {
 const PAGE='mad4b-settings-alignment';
 const AREAS=['commerce'=>'Commerce & Booking','presentation'=>'Presentation & Brand','communication'=>'Communication','operations'=>'Company Operations'];
 static function link(string $page,string $tab=''):string {
  $args=['page'=>$page];if($tab!=='')$args['tab']=$tab;
  return add_query_arg($args,admin_url('admin.php'));
 }
 static function bool_label($v):string {return in_array($v,['yes','1',1,true,'true','on'],true)?'Enabled':'Disabled';}
 static function row(string $area,string $label,string $owner,string $ownerPage,string $effective,string $status,string $explanation,string $consumer='',string $consumerPage='',string $ownerTab='',string $consumerTab=''):array {
  return compact('area','label','owner','ownerPage','effective','status','explanation','consumer','consumerPage','ownerTab','consumerTab');
 }
 /** Values are read from each domain's actual store. Never expose credentials, email addresses or recipients. */
 static function snapshot():array {
  $look=get_option('look-and-feel',[]);$look=is_array($look)?$look:[];
  $comm=get_option('company-communication-operations',[]);$comm=is_array($comm)?$comm:[];
  $global=get_option('global-offers',[]);$global=is_array($global)?$global:[];
  $wooCurrency=(string)get_option('woocommerce_currency','USD');
  $jetCurrency=trim((string)get_option('etg-dynamic-pricing-controls_default_currency',''));
  $jetTax=trim((string)get_option('etg-dynamic-pricing-controls_tax_percent',''));
  $taxes=get_option('woocommerce_calc_taxes','no')==='yes';
  $sender=trim((string)($comm['transactional_from_email']??''));
  $wooAddress=(string)get_option('woocommerce_email_from_address','');
  $companyRuntime=class_exists('MAD4B_SCP_Company_Communication',false) && class_exists('MAD4B_SCP_Company_Email_Routing',false);
  $route=(string)($comm['woo_routing_policy']??'disabled');
  $sending=(string)($comm['outbound_email_policy']??'disabled');
  $ownerActivated=$companyRuntime && $route==='enabled' && $sending==='enabled' && $sender!=='';
  $mode=(string)($look['mad4b_rate_view_precedence_v1']??'preserve');
  $modes=[];foreach(['tour','cruise_tour','activity','extra_trip'] as $type){$v=(string)($look['mad4b_rate_view_mode_'.$type]??'inherit');if(in_array($v,['grid','accordion'],true))$modes[]=$type.': '.$v;}
  $companyAdmin=class_exists('MAD4B_SCP_Company_Master_Admin',false);
  $companyOps=class_exists('MAD4B_SCP_Company_Operations_Policy',false);
  $coreBooking=(string)get_option('etg-dynamic-pricing-controls_booking_enabled','');
  $pricingSection=get_option('calculate-booking-price_display_tours_pricing_section',null);
  $hasBrand=trim((string)($look['mad4b_brand_name']??''))!=='';
  $result=[];
  $result[]=self::row('commerce','Store & display currency','WooCommerce · General','wc-settings',$wooCurrency.($jetCurrency!==''?' | Jet: '.$jetCurrency:' | Jet inherits'),$jetCurrency!=='' && $jetCurrency!==$wooCurrency?'review':'aligned','WooCommerce owns the store currency; Jet pricing may explicitly override a pricing context, not the store base. WCML owns market conversion.','JetEngine · Dynamic Pricing','etg-dynamic-pricing-controls','general');
  $result[]=self::row('commerce','Taxes & fees','WooCommerce · Tax','wc-settings',($taxes?'WC tax calculations ON':'WC tax calculations OFF').($jetTax!==''?' | Jet override: configured':''),!$taxes && is_numeric($jetTax) && (float)$jetTax>0?'review':'scoped','WooCommerce owns tax calculation. Jet pricing policies can hold service/mode-specific overrides; these are not automatic replacements for Woo tax setup.','JetEngine · Dynamic Pricing','etg-dynamic-pricing-controls','tax');
  $result[]=self::row('commerce','Tour pricing policy','JetEngine · Dynamic Pricing','etg-dynamic-pricing-controls','Pricing engine: '.self::bool_label(get_option('etg-dynamic-pricing-controls_pricing_engine_enabled','false')),'scoped','Tour pricing rules and rate sources are independent of WooCommerce catalog product prices. Rates remain authoritative in their existing data model.','WooCommerce · General','wc-settings','','general');
  $result[]=self::row('commerce','Booking & checkout availability','JetEngine · Dynamic Pricing','etg-dynamic-pricing-controls','Tour booking flag: '.self::bool_label($coreBooking).' | Woo guest checkout: '.self::bool_label(get_option('woocommerce_enable_guest_checkout','no')),'scoped','WooCommerce checkout availability never implies Tour Booking v2 is ready. The two switches govern different stages.','WooCommerce · Accounts','wc-settings','','account');
  $result[]=self::row('commerce','Pricing section visibility','JetEngine · Calculate Booking Price','calculate-booking-price',$pricingSection===null?'No explicit value':self::bool_label($pricingSection),'inherited','Controls whether the price section is displayed. Look & Feel decides its presentation; neither changes native rates or checkout rules.','JetEngine · Look & Feel','look-and-feel');
  $result[]=self::row('presentation','Rates: Accordion or Card Slider','JetEngine · Look & Feel','look-and-feel',($modes?implode(' · ',$modes):'All modes inherit').' | Existing styles: '.($mode==='apply_to_existing'?'policy-first':'preserved'),'aligned','Per-tour override is authoritative. WPML-linked Tours inherit the source preference unless an explicit translation override exists. Elementor only renders the selected native widget.','Tour editor · JetEngine Meta Box','edit.php?post_type=tours-and-activities');
  $result[]=self::row('presentation','Company brand & design','MAD4B brand / JetEngine · Look & Feel','look-and-feel',$hasBrand?'Brand tokens configured':'Brand tokens inherited / incomplete','inherited','Brand identity comes from the site-bound company authority; Look & Feel holds presentation tokens. Woo store sender name is not the brand identity source.','WordPress · General','options-general.php');
  $result[]=self::row('presentation','Global offers vs discounts','JetEngine · Global Offers','global-offers','Site offer bar: '.self::bool_label($global['offers_enabled']??'no').' | Woo coupons: '.self::bool_label(get_option('woocommerce_enable_coupons','no')),'scoped','Global Offers controls promotional display. WooCommerce coupons control cart discounts; do not sync unlike-for-like settings.','WooCommerce · General','wc-settings','','general');
  $result[]=self::row('presentation','Date and calendar formats','JetEngine · ABK Date Formats','abk-date-formats','Booking calendar and WordPress general date format are separate','scoped','The booking calendar owns input/display locale rules; WordPress controls site-wide date display.','WordPress · General','options-general.php');
  $result[]=self::row('communication','Sender identity & order email','JetEngine · Company Communication','company-communication-operations',(!$companyRuntime?'Company runtime adapter not loaded':($ownerActivated?'Company routing policy requested':'WooCommerce native sender fallback')).' | Company sender '.($sender!==''?'configured':'missing').' | Woo sender '.($wooAddress!==''?'configured':'not configured'),!$companyRuntime||$sender===''?'review':($ownerActivated?'scoped':'inherited'),'Company Communication is the company routing source. Woo email settings remain the native fallback until company sender, routing and verified transport are ready. This page does not send mail.','WooCommerce · Emails','wc-settings','','email');
  $result[]=self::row('communication','Public contact vs operational inbox','JetEngine · Contact & Help','contact-and-help-info','Public contact is separate from company booking, sales and support inboxes','scoped','Public website links and operational notification recipients have different purposes. Never copy private routing lists into public contact fields.','JetEngine · Company Communication','company-communication-operations');
  $result[]=self::row('operations','Company permissions & workflow','JetEngine · Company Operations Policies','company-operations-policies',($companyOps?'Native operations policy loaded':'Company Operations runtime not loaded').($companyAdmin?' | Company admin enabled':' | Company admin unavailable'),!$companyOps?'review':'scoped','Company workflow permissions do not replace WooCommerce roles and settings. Migration and staff-management capabilities remain independent and must not be silently enabled.','MAD4B Company','mad4b-company');
  $result[]=self::row('operations','Public service eligibility & discovery','JetEngine · Service Activation','service-activation','Service discovery is independent of WooCommerce publication','scoped','This page decides which service types are publicly discoverable. WooCommerce controls products and checkout; published services are not automatically bookable.','WooCommerce · Products','edit.php?post_type=product');
  return $result;
 }
 static function url(string $page,string $tab=''):string {
  if($page==='options-general.php')return admin_url($page);
  if(str_starts_with($page,'edit.php?'))return admin_url($page);
  return self::link($page,$tab);
 }
 static function menu():void { add_submenu_page('mad4b-control-plane','Company Settings Alignment','Settings Alignment','manage_options',self::PAGE,[__CLASS__,'page']); }
 static function page():void {
  if(!current_user_can('manage_options'))wp_die(esc_html__('Insufficient permissions.'));
  $area=isset($_GET['area'])?sanitize_key(wp_unslash($_GET['area'])):'';
  if($area!=='' && !isset(self::AREAS[$area]))$area='';
  $rows=self::snapshot();$statusCounts=[];foreach($rows as $r)$statusCounts[$r['status']]=($statusCounts[$r['status']]??0)+1;
  echo '<div class="wrap"><h1>Company Settings Alignment</h1><p>One canonical editor per decision. Related settings are shown as consumers or scoped overrides. Read-only: no prices, emails, roles, or site settings are changed here.</p>';
  echo '<p><strong>Contracts:</strong> JetEngine '.esc_html((string)count(self::jet_pages())).' Options Pages · WooCommerce native settings · MAD4B Company Options. <strong>Observations:</strong> '.esc_html(wp_json_encode($statusCounts)).'</p>';
  // Skills are provider/site-scoped instructions, not write authority. Surface the
  // real runtime readiness instead of treating a bundled SKILL.md as activated.
  $skills_ready = false;
  $skills_eligible = class_exists('MAD4B_SCP_Site_Profile', false)
    && MAD4B_SCP_Site_Profile::skills_enabled();
  if (class_exists('MAD4B_SCP_Skill_Registry', false)) {
    $skill_rows = MAD4B_SCP_Skill_Registry::list_skills(['level'=>'workflow','target'=>'settings-lifecycle']);
    foreach ((array)$skill_rows as $skill_row) {
      if (($skill_row['name']??'')==='wordpress-settings-lifecycle' && !empty($skill_row['enabled'])) {
        $skills_ready = true;break;
      }
    }
  }
  $skills_caption=$skills_ready?'Runtime Skill registered and enabled'
    :($skills_eligible?'Runtime registration pending':'Packaged Skill only — company Skills feature not enabled');
  echo '<p><strong>Settings Lifecycle Skill:</strong> '.esc_html($skills_caption)
    .'. <a href="'.esc_url(self::link('mad4b-control-plane-skills')).'">Open MAD4B Skills</a></p>';
  echo '<nav class="nav-tab-wrapper">';$nav=[''=>'All']+self::AREAS;foreach($nav as $key=>$title){$href=$key===''?self::link(self::PAGE):add_query_arg('area',$key,self::link(self::PAGE));echo '<a class="nav-tab'.($key===$area?' nav-tab-active':'').'" href="'.esc_url($href).'">'.esc_html($title).'</a>'; }echo '</nav>';
  echo '<table class="widefat striped" style="margin-top:15px"><thead><tr><th>Setting / scope</th><th>Canonical editor</th><th>Effective state</th><th>Related screen</th><th>Notes</th></tr></thead><tbody>';
  foreach($rows as $r){if($area!==''&&$r['area']!==$area)continue;
   $owner=self::url($r['ownerPage'],$r['ownerTab']);$other=$r['consumerPage']!==''?self::url($r['consumerPage'],$r['consumerTab']):'';
   echo '<tr><td><strong>'.esc_html($r['label']).'</strong><p class="description">'.esc_html(self::AREAS[$r['area']]).'</p></td>';
   echo '<td><a href="'.esc_url($owner).'">'.esc_html($r['owner']).'</a></td>';
   echo '<td><strong>'.esc_html(ucfirst($r['status'])).'</strong><p>'.esc_html($r['effective']).'</p></td>';
   echo '<td>'.($other!==''?'<a href="'.esc_url($other).'">'.esc_html($r['consumer']).'</a>':esc_html($r['consumer'])).'</td>';
   echo '<td>'.esc_html($r['explanation']).'</td></tr>';
  }echo '</tbody></table>';
  echo '<h2>Discovered JetEngine Options Pages</h2><p>Discovered dynamically from the registered JetEngine catalogue. Counts are native saved field definitions, not replicated settings.</p><table class="widefat striped"><thead><tr><th>Options Page</th><th>Saved fields</th><th>Storage</th></tr></thead><tbody>';
  foreach(self::jet_pages() as $p){echo '<tr><td><a href="'.esc_url(self::link($p['slug'])).'">'.esc_html($p['slug']).'</a></td><td>'.esc_html((string)$p['fields']).'</td><td>'.esc_html($p['storage']).'</td></tr>';}
  echo '</tbody></table></div>';
 }
 static function jet_pages():array {
  if(!function_exists('jet_engine')||!isset(jet_engine()->options_pages->data))return [];
  $rows=[];foreach((array)jet_engine()->options_pages->data->get_items() as $p){$args=maybe_unserialize($p['args']??[]);$fs=maybe_unserialize($p['meta_fields']??[]);$rows[]=['slug'=>(string)($p['slug']??''),'fields'=>count((array)$fs),'storage'=>(string)($args['storage_type']??'default')];}return $rows;
 }
 static function field_hints($fields,$page) {
  if(!is_array($fields)||!is_object($page))return $fields;
  $hints=[
   'etg-dynamic-pricing-controls'=>[
    'default_currency'=>'WooCommerce General owns the store currency. Leave blank to inherit; use an explicit pricing-context currency only when the pricing policy requires it.',
    'tax_percent'=>'WooCommerce owns tax calculation. This is a pricing-policy override, not a switch to enable WC tax calculations.',
    'booking_enabled'=>'Tour Booking v2 eligibility is separate from the WooCommerce checkout/guest checkout settings.',
    'pricing_engine_enabled'=>'Enables the Tour pricing policy layer, not native WooCommerce product pricing.',
    'shared_mode_policy_rows'=>'Applies Tour Mode pricing rules; Rate presentation is controlled independently under Look & Feel.'
   ],
   'look-and-feel'=>[
    'mad4b_rate_view_default_v1'=>'Owner of Tour Rate presentation defaults. Each Tour can explicitly override this preference.',
    'mad4b_rate_view_precedence_v1'=>'Preserve means existing Tour styles remain until overridden. Policy-first applies Tour Mode preferences without rewriting each Tour record.',
    'mad4b_brand_name'=>'Brand identity is site/company-bound; this Look & Feel field is a presentation token, not a WooCommerce sender name.',
   ],
   'company-communication-operations'=>[
    'company_display_name'=>'Company email display identity; this does not replace the canonical MAD4B company brand identity.',
    'transactional_from_email'=>'Company email routing source; sending still depends on the saved policy and verified transport. WooCommerce keeps its native fallback.',
    'woo_routing_policy'=>'When enabled and verified, company routing replaces the native WooCommerce email sender/recipient selection.',
    'bookings_inbox'=>'Operational recipient, not the public contact address shown on the site.'
   ],
   'calculate-booking-price'=>['display_tours_pricing_section'=>'Controls the Tour pricing section visibility only. Rate view mode is selected under Look & Feel.'],
   'service-activation'=>['discovery_presentation_mode'=>'Controls discovery cards, not the Tour Rates Accordion/Card Slider inside a Tour.'],
   'global-offers'=>['offers_enabled'=>'Controls the public promotional offer bar. WooCommerce coupons are managed separately.'],
   'abk-date-formats'=>['display_date_format'=>'Booking calendar presentation only. WordPress General date format is a separate global setting.'],
   'company-operations-policies'=>['price_edit_policy'=>'Company editing policy; does not change native WooCommerce tax, product or checkout configuration.'],
   'contact-and-help-info'=>['site_email'=>'Public contact email only. Company booking and notification inboxes are managed in Company Communication.']
  ];
  $map=$hints[(string)($page->slug??'')]??[];if(!$map)return $fields;
  foreach($fields as &$f){if(!is_array($f)||($f['object_type']??'field')==='tab')continue;$name=(string)($f['name']??'');if(!isset($map[$name]))continue;
   $description=(string)($f['description']??'');if(str_contains($description,'Settings Alignment:'))continue;
   $f['description']=trim($description.' Settings Alignment: '.$map[$name]);
  }unset($f);
  return $fields;
 }
 static function notice():void {
  if(!current_user_can('manage_options')||!isset($_GET['page']))return;
  $page=sanitize_key(wp_unslash($_GET['page']));if($page===self::PAGE)return;
  $context=[
   'look-and-feel'=>'Look & Feel owns visual preferences. Per-Tour overrides are managed in the Tour editor.',
   'company-communication-operations'=>'Company Communication owns company routing. WooCommerce Emails is the native fallback.',
   'company-operations-policies'=>'Company workflow policy is separate from WooCommerce roles and checkout settings.',
   'etg-dynamic-pricing-controls'=>'JetEngine owns Tour pricing policy; WooCommerce owns store currency and tax calculation.',
   'calculate-booking-price'=>'This page controls visibility/calculation context, not Rate View design.',
   'service-activation'=>'Service visibility is not the same as WooCommerce checkout availability.',
   'global-offers'=>'Global Offers is a display surface; WooCommerce coupons are separate commercial rules.',
   'contact-and-help-info'=>'Public contacts are separate from company notification inboxes.',
   'abk-date-formats'=>'Booking calendar formats are separate from WordPress site-wide date formats.',
   'wc-settings'=>'WooCommerce is authoritative for store currency and tax. MAD4B and JetEngine use separate tour and company policies.',
   'mad4b-company'=>'MAD4B Company owns company workflows, independent of WooCommerce checkout.',
  ];
  if(!isset($context[$page]))return;
  echo '<div class="notice notice-info"><p><strong>MAD4B Settings Alignment:</strong> '.esc_html($context[$page]).' <a href="'.esc_url(self::link(self::PAGE)).'">View sources and related editors</a>.</p></div>';
 }
 static function boot():void {if(!is_admin())return;add_filter('jet-engine/options-pages/raw-fields',[__CLASS__,'field_hints'],220,2);add_action('admin_menu',[__CLASS__,'menu'],120);add_action('admin_notices',[__CLASS__,'notice'],40);}
}
MAD4B_Company_Settings_Alignment_V1::boot();
}
