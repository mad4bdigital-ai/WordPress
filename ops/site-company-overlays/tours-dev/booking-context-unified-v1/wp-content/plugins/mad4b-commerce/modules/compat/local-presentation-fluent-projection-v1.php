<?php
/**
 * Lab-only Form #6 presentation projection.
 * Never edits the form schema, submissions, orders, invoices or Woo cart.
 */
defined('ABSPATH') || exit;
// Read-only source-date endpoint is valid with Presentation runtime alone.
$contextFile=WPMU_PLUGIN_DIR.'/shared-product-core/adapters/presentation-form6-source-context-v1.php';
if(is_readable($contextFile))require_once $contextFile;

// Source-linked Form #6 assets must be updated together with their actual
// filemtime. Never serve stale optimized bundles across a Rate/date change.
add_filter('litespeed_optimize_js_excludes',static function($patterns){
 $patterns=is_array($patterns)?$patterns:[];
 return array_values(array_unique(array_merge($patterns,[
  'presentation-selection-state-v1.js',
  'presentation-fluent-projection-v1.js',
  'presentation-fluent-quote-v1.js'
 ])));
},90);
add_filter('litespeed_optm_js_defer_exc',static function($patterns){
 $patterns=is_array($patterns)?$patterns:[];
 return array_values(array_unique(array_merge($patterns,[
  'presentation-selection-state-v1.js',
  'presentation-fluent-projection-v1.js',
  'presentation-fluent-quote-v1.js'
 ])));
},90);
add_filter('script_loader_tag',static function($tag,$handle){
 if(in_array($handle,['shared-presentation-selection-v1',
    'shared-presentation-fluent-projection-v1',
    'shared-presentation-fluent-quote-v1'],true)&&
    is_string($tag)&&str_starts_with($tag,'<script'))
   return preg_replace('/^<script\b/','<script data-no-optimize="1" data-no-defer="1"',$tag,1);
 return $tag;
},90,2);

$commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED===true;
$presentation=defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED===true;
if($commerce || ($presentation && class_exists('MAD4B_Form6_Readonly_Price_Preview_V1',false)
   && !empty(MAD4B_Form6_Readonly_Price_Preview_V1::status()['enabled'])
   && !empty(MAD4B_Form6_Readonly_Price_Preview_V1::status()['all_readonly_dependencies'])))
 require_once WPMU_PLUGIN_DIR.'/shared-product-core/adapters/presentation-form6-stay-options-v1.php';
// The company Site Profile binding is established after plugin-file load.
// Admit the original read-only stay catalog at init, before REST registration,
// but do not activate any Commerce-owned payment or order adapter.
add_action('init',static function():void {
 $presentation=defined('MAD4B_PRESENTATION_RUNTIME_ENABLED')
   && MAD4B_PRESENTATION_RUNTIME_ENABLED===true;
 $commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED')
   && MAD4B_COMMERCE_RUNTIME_ENABLED===true;
 if(!$presentation || $commerce ||
    !class_exists('MAD4B_Form6_Readonly_Price_Preview_V1',false))return;
 $status=MAD4B_Form6_Readonly_Price_Preview_V1::status();
 if(empty($status['enabled'])||empty($status['all_readonly_dependencies']))return;
 require_once WPMU_PLUGIN_DIR.'/shared-product-core/adapters/presentation-form6-stay-options-v1.php';
},12);
add_action('wp_enqueue_scripts',static function():void {
 global $wp_the_query;
 $commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED===true;
 $presentation=defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED===true;
 if ((!$commerce && !$presentation) ||
   !$wp_the_query instanceof WP_Query ||
   !$wp_the_query->is_singular('tours-and-activities')) return;
 // Tenant presentation may only project the existing Form 6 when its
 // original site profile and provider dependencies are readback-ready.
 if (!$commerce) {
  if (!class_exists('MAD4B_Form6_Readonly_Price_Preview_V1',false) ||
      !MAD4B_Form6_Readonly_Price_Preview_V1::status()['enabled']) return;
  // Public quote widgets must not appear before the source-owned JetEngine
  // pricing strategy and quote dependencies have passed live readback.
  $policies=function_exists('shared_product_mode_policies')?shared_product_mode_policies():null;
  if (!is_array($policies) || empty($policies['tour']['enabled']) ||
      !in_array($policies['tour']['strategy']??null,
       ['rate_occupancy','group_size_tiered','seasonal_occupancy','accommodation_matrix'],true) ||
      !MAD4B_Form6_Readonly_Price_Preview_V1::status()['all_readonly_dependencies']) return;
 }
 $tour=(int)$wp_the_query->get_queried_object_id();
 $editorPreview=function_exists('mad4b_native_extra_editor_preview')
  &&mad4b_native_extra_editor_preview($tour);
 if($tour<1||get_post_type($tour)!=='tours-and-activities'
  ||(get_post_status($tour)!=='publish'&&!$editorPreview))return;
 if(!wp_script_is('shared-presentation-selection-v1','enqueued'))return;
 $path=WPMU_PLUGIN_DIR.'/shared-product-core/assets/presentation-fluent-projection-v1.js';
 if(!is_readable($path))return;
 $extraNames=[];$extraDetails=[];
 // Use the exact same native selection-plan IDs that back the Elementor
 // optional-activity listing, rather than a second raw-meta availability rule.
 $allowed=[];
 if(function_exists('native_component_selection_plan')){
  $plan=native_component_selection_plan($tour,'extras');
  foreach($plan['groups']??[] as $group)
   if(($group['type']??'')==='extra')
    $allowed=array_merge($allowed,(array)($group['ids']??[]));
 }
 $allowed=array_slice(array_unique(array_map('absint',$allowed)),0,30);
 foreach($allowed as $extraId){
  $post=get_post($extraId);
  if(!$post||$post->post_type!=='tours-and-activities'||
     $post->post_status!=='publish')continue;
  $text=wp_strip_all_tags(html_entity_decode($post->post_title,ENT_QUOTES,'UTF-8'));
  if(str_starts_with((string)get_post_meta($post->ID,'_booking_experience_qa_pricing_fixture',true),'cart-lab-'))
   $text=preg_replace('/^QA ONLY\s*[·:-]\s*/iu','',$text);
  $thumb=get_the_post_thumbnail_url($post->ID,'thumbnail');
  $image='';
  if(is_string($thumb)&&preg_match('~^https?://~i',$thumb)){
   $image=esc_url_raw($thumb);
   // Local WordPress may emit http://localhost image URLs even when reached
   // through tours.dev. Keep same-site thumbnails relative for the browser.
   $imgHost=wp_parse_url($thumb,PHP_URL_HOST);
   $siteHost=wp_parse_url(home_url('/'),PHP_URL_HOST);
   if(is_string($imgHost)&&$imgHost!==''&&strcasecmp($imgHost,(string)$siteHost)===0){
    $path=wp_parse_url($thumb,PHP_URL_PATH);
    $query=wp_parse_url($thumb,PHP_URL_QUERY);
    if(is_string($path)&&str_starts_with($path,'/'))
     $image=esc_url_raw($path.(is_string($query)&&$query!==''?'?'.$query:''));
   }
  }
  $extraNames[(string)$post->ID]=$text;
  $indicative=null;
  if(function_exists('tour_component_price_evidence')){
   $source=tour_component_price_evidence((int)$post->ID,'general','upcoming');
   if(!is_wp_error($source)&&is_numeric($source['amount']??null)&&
      preg_match('/^[A-Z]{3}$/D',(string)($source['currency']??'')))
    $indicative=['amount'=>(float)$source['amount'],
      'currency'=>(string)$source['currency'],
      'unit'=>(string)($source['unit']??'per_person')];
  }
  $extraDetails[(string)$post->ID]=[
   'preview_price'=>$editorPreview?null:$indicative,
   'name'=>$text,
   'image'=>$image,
   'price_unit'=>in_array((string)get_post_meta($post->ID,'tour_price_unit',true),
      ['per_person','per_trip','per_day','per_room','per_vehicle','per_cabin'],true)
      ?(string)get_post_meta($post->ID,'tour_price_unit',true):null
  ];
 }
 // Transport options follow the same source and rate eligibility manifested
 // in the original JetEngine transport listings; no separate form catalog.
 $transportDetails=[];
 if(function_exists('native_component_selection_plan')){
  foreach(['vehicles','train','air'] as $mode){
   $plan=native_component_selection_plan($tour,$mode);
   $allGroups=(array)($plan['groups']??[]);
   foreach($plan['groupsByRate']??[] as $groups)
    $allGroups=array_merge($allGroups,(array)$groups);
   foreach([$allGroups] as $groups){
    foreach($groups as $group){
     if(($group['type']??'')!=='transport')continue;
     foreach((array)($group['ids']??[]) as $id){
      $id=absint($id);
      if(!$id||isset($transportDetails[(string)$id]))continue;
      $post=get_post($id);
      if(!$post||$post->post_type!=='transportations'||
         $post->post_status!=='publish')continue;
      $transportDetails[(string)$id]=[
       'name'=>wp_strip_all_tags($post->post_title),
       'mode'=>$mode];
     }
    }
   }
  }
 }
 require_once WPMU_PLUGIN_DIR.'/shared-product-core/adapters/presentation-form6-occupancy-policy-v1.php';
 $accommodationPolicy=shared_form6_accommodation_policy_v1($tour);
 // Reuse the BookingExperience plan already verified by original Form #6.
 // Property titles are presentation-only labels, not a price/availability source.
 foreach($accommodationPolicy['plans_by_rate'] as &$rateStayPlan){
  foreach($rateStayPlan['stages'] as &$stage){
   $stage['options']=[];
   foreach($stage['properties'] as $property){
    $name=wp_strip_all_tags(get_the_title((int)$property));
    if($name!=='')$stage['options'][]=['id'=>(int)$property,'name'=>$name];
   }
  }
  unset($stage);
 }
 unset($rateStayPlan);
 $rateSourceTour=$tour;
 if(class_exists('MAD4B_Tour_Rate_Language_Policy_V1',false)){
  $resolved=MAD4B_Tour_Rate_Language_Policy_V1::resolve_context($tour);
  $candidate=absint($resolved['query_tour_id']??0);
  if($candidate>0 && get_post_type($candidate)==='tours-and-activities'
     && get_post_status($candidate)==='publish')$rateSourceTour=$candidate;
 }
 $rateChoices=[];
 if(!class_exists('Shared_Tour_Pricing_Entry_v1')) {
  // Portable presentation runtimes do not load the original editor adapter
  // because it registers admin save hooks; discover read-only Rates instead.
  $ids=get_posts(['post_type'=>'tour-rates','post_status'=>'publish',
    'posts_per_page'=>151,'fields'=>'ids','meta_key'=>'related_tour_id',
    'meta_value'=>(string)$rateSourceTour,'suppress_filters'=>$rateSourceTour!==$tour]);
  if(count($ids)<151){
   foreach($ids as $rid){
    $rid=(int)$rid;
    if($rateSourceTour!==$tour &&
     (!class_exists('MAD4B_Tour_Rate_Language_Policy_V1',false) ||
      !MAD4B_Tour_Rate_Language_Policy_V1::eligible_rate($tour,$rid)))continue;
    if((string)get_post_meta($rid,'stop_sale',true)==='1') continue;
    if(function_exists('shared_product_rate_locale_decision') &&
       empty(shared_product_rate_locale_decision($rid,$rateSourceTour)['allowed'])) continue;
    $until=(int)get_post_meta($rid,'tour_rate_end_date',true);
    if(!$until || $until<time()) continue;
    $title=wp_strip_all_tags(get_the_title($rid));
    $rateChoices[]=['id'=>$rid,'label'=>class_exists('MAD4B_Rate_Naming_And_Note_V1',false)?MAD4B_Rate_Naming_And_Note_V1::name($rid,$tour): (preg_match('/^\d+$/D',$title)?'Rate #'.$rid:$title)];
   }
  }
 }
 if(class_exists('Shared_Tour_Pricing_Entry_v1')){
  $entry=Shared_Tour_Pricing_Entry_v1::inspect($rateSourceTour);
  $today=(int)strtotime(gmdate('Y-m-d').' 00:00:00 UTC');
  foreach((array)($entry['rate_ids']??[]) as $rid){
   if($rateSourceTour!==$tour &&
    (!class_exists('MAD4B_Tour_Rate_Language_Policy_V1',false) ||
     !MAD4B_Tour_Rate_Language_Policy_V1::eligible_rate($tour,(int)$rid)))continue;
   $until=get_post_meta($rid,'tour_rate_end_date',true);
   if(!is_numeric($until)||(int)$until<$today)continue;
   if(function_exists('shared_product_rate_locale_decision')&&
      empty(shared_product_rate_locale_decision($rid,$rateSourceTour)['allowed']))continue;
   $package=absint(get_post_meta($rid,'related_package_term_id',true));
   $term=$package?get_term($package):null;
   $level=$term instanceof WP_Term?wp_strip_all_tags($term->name):'';
   $name=wp_strip_all_tags(get_the_title($rid));
   if(preg_match('/^\d+$/D',$name))$name='Rate #'.(int)$rid;
   $from=get_post_meta($rid,'tour_rate_start_date',true);
   $window=is_numeric($from)?gmdate('Y-m-d',(int)$from).' → '.gmdate('Y-m-d',(int)$until):'';
   $rateChoices[]=['id'=>(int)$rid,'label'=>class_exists('MAD4B_Rate_Naming_And_Note_V1',false)?MAD4B_Rate_Naming_And_Note_V1::name((int)$rid,$tour):trim(($level?$level.' · ':'').$name.($window?' · '.$window:''))];
  }
 }

 // Authorized editor-only draft Rates can be selected in the original
 // Form #6 QA preview without making them sellable or quoteable.
 if($editorPreview){
  $drafts=get_posts(['post_type'=>'tour-rates','post_status'=>'draft',
   'posts_per_page'=>81,'fields'=>'ids','meta_key'=>'related_tour_id',
   'meta_value'=>(string)$tour,'orderby'=>'ID','order'=>'ASC']);
  if(count($drafts)<=80)foreach($drafts as $rid){
   $rid=(int)$rid;
   if(!current_user_can('edit_post',$rid))continue;
   $rateChoices[]=['id'=>$rid,'label'=>'Draft · '.wp_strip_all_tags(get_the_title($rid))];
  }
 }
 // Rate pricing basis is not proof of a hotel or allocated room.
 // List ONLY priced tiers returned by the approved Rate pricing presentation.
 // The browser uses this for choices; server quote still recomputes the amount.
 $ratePricingBasisChoices=[];
 if(function_exists('pricing_rate_presentation')){
  foreach($rateChoices as $choice){
   $rid=absint($choice['id']??0);
   if(!$rid || get_post_type($rid)!=='tour-rates' ||
      get_post_status($rid)!=='publish')continue;
   $presented=pricing_rate_presentation($rid);
   if(!is_array($presented)||($presented['status']??'')!=='available')continue;
   $available=[];
   foreach((array)($presented['options']??[]) as $opt){
    $tier=(string)($opt['occupancy']??'');
    $amount=$opt['final']??null;
    if(in_array($tier,['single','double','triple'],true)
       && is_numeric($amount) && is_finite((float)$amount)
       && (float)$amount>0)$available[$tier]=true;
   }
   if($available)$ratePricingBasisChoices[(string)$rid]=array_values(
      array_filter(['single','double','triple'],static fn($tier)=>isset($available[$tier])));
  }
 }

 /**
  * Frontend hint follows the ORIGINAL governed source weights (Rate/Direct).
  * The editor adapter isn't available on anonymous pages; never fall back to
  * "inquiry" merely because its administrative class is not loaded.
  * This is a display hint only: signed Fluent/Woo price proof is recomputed
  * independently and may still refuse unpriced components or invalid dates.
  */
 $pricingMode='inquiry';
 $configured=(string)get_post_meta($rateSourceTour,'_shared_tour_price_entry_mode_v1',true);
 $explicitInquiry=$configured==='inquiry';
 $policy=function_exists('shared_product_price_source_policy')
   ?shared_product_price_source_policy((string)get_post_meta($rateSourceTour,'tour_mode',true))
   :new \WP_Error('source_policy_unavailable');
 $directPrice=get_post_meta($rateSourceTour,'start_price',true);
 $directCurrency=(string)get_post_meta($rateSourceTour,'tour_base_currency',true);
 $directUnit=(string)get_post_meta($rateSourceTour,'tour_price_unit',true);
 $hasDirect=is_numeric($directPrice)&&is_finite((float)$directPrice)
    &&(float)$directPrice>0&&$directUnit==='per_person'
    &&preg_match('/^[A-Z]{3}$/D',$directCurrency);
 if(!$explicitInquiry&&!is_wp_error($policy)) {
   $ratePreferred=$policy['rate_weight']>$policy['direct_weight'] ||
       ($policy['rate_weight']===$policy['direct_weight']&&$policy['tie_break']==='rate');
   $rateAvailable=count($rateChoices)>0;
   if($ratePreferred && $rateAvailable)
     $pricingMode='existing_rates';
   elseif($hasDirect && (!$ratePreferred||$policy['fallback']==='next_valid'))
     $pricingMode='direct_per_person';
   elseif($rateAvailable && (!$ratePreferred||$policy['fallback']==='next_valid'))
     $pricingMode='existing_rates';
 }
 // Only the known Tour Content templates own this booking projection.
 // A Form #6 instance inside a popup, property, transport or a generic
 // Elementor template is NOT automatically a Tour booking form.
 $tourTemplateIds=[24784,35119,35121,35123,40121,42179];
 $scopeSelector=implode(',',array_map(
   static fn($id)=>'.elementor-'.$id.' form.fluent_form_6',$tourTemplateIds));
 $name='shared-presentation-fluent-projection-v1';
 wp_register_script($name,content_url('mu-plugins/shared-product-core/assets/presentation-fluent-projection-v1.js'),
  ['shared-presentation-selection-v1'],(string)filemtime($path),true);
 wp_add_inline_script($name,'window.SharedPresentationFluentConfigV1='.
  wp_json_encode(['tourId'=>$tour,'schemaVersion'=>'1.0.0','formId'=>6,
    'scope'=>'tour_single_booking','formScopeSelector'=>$scopeSelector,
    'previewOnly'=>$editorPreview,
    'extraNames'=>$extraNames,'extraDetails'=>$extraDetails,'transportDetails'=>$transportDetails,
    'accommodationPolicy'=>$accommodationPolicy,
    'rateChoices'=>$rateChoices,
    'ratePricingBasisChoices'=>$ratePricingBasisChoices,
    'pricingEntryEffectiveMode'=>$pricingMode,
    'rateSelectionPrompt'=>([
      'en'=>'Select a tour rate for a date-specific estimate',
      'ar'=>'اختر سعر الرحلة لعرض تقدير يناسب تاريخ السفر',
      'fr'=>'Choisissez un tarif pour une estimation à cette date',
      'de'=>'Wählen Sie einen Tarif für eine datumsbezogene Schätzung',
      'es'=>'Elige una tarifa para calcular el precio según la fecha',
      'it'=>'Scegli una tariffa per stimare il prezzo nella data scelta'
    ][(string)apply_filters('wpml_current_language','en')]??'Select a tour rate for an estimate'),
    'sourceContextEndpoint'=>rest_url('shared-product/v1/tour-form-source-context'),
    'quoteEndpoint'=>rest_url('shared-product/v1/tour-form-price-preview'),
    'indicativeEndpoint'=>str_starts_with((string)get_post_meta($tour,
       '_booking_experience_qa_pricing_fixture',true),'cart-lab-')
         ?rest_url('shared-product/v1/tour-form-indicative-qa-preview'):null,
    'stayOptionsEndpoint'=>rest_url('shared-product/v1/tour-form-stay-options')],JSON_UNESCAPED_SLASHES).';','before');
 wp_enqueue_script($name);
 $style=WPMU_PLUGIN_DIR.'/shared-product-core/assets/presentation-tour-price-first-qa.css';
 if(is_readable($style)){
  wp_enqueue_style('shared-presentation-price-first-qa',
   content_url('mu-plugins/shared-product-core/assets/presentation-tour-price-first-qa.css'),
   [],(string)filemtime($style));
 }

 $quotePath=WPMU_PLUGIN_DIR.'/shared-product-core/assets/presentation-fluent-quote-v1.js';
 if(is_readable($quotePath)){
  $handle='shared-presentation-fluent-quote-v1';
  wp_register_script($handle,content_url('mu-plugins/shared-product-core/assets/presentation-fluent-quote-v1.js'),
   [$name],(string)filemtime($quotePath),true);
  wp_enqueue_script($handle);
 }
 $pricingCss=WPMU_PLUGIN_DIR.'/shared-product-core/assets/presentation-pricing-basis-v1.css';
 if(is_readable($pricingCss)){
  wp_enqueue_style('shared-presentation-pricing-basis-v1',
    content_url('mu-plugins/shared-product-core/assets/presentation-pricing-basis-v1.css'),
    [],(string)filemtime($pricingCss));
 }
},30);
