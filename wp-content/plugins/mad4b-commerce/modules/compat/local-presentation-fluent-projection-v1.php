<?php
/**
 * Lab-only Form #6 presentation projection.
 * Never edits the form schema, submissions, orders, invoices or Woo cart.
 */
defined('ABSPATH') || exit;
$commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED===true;
$presentation=defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED===true;
if($commerce) require_once WPMU_PLUGIN_DIR.'/shared-product-core/adapters/presentation-form6-stay-options-v1.php';
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
 if($tour<1||get_post_type($tour)!=='tours-and-activities'
  ||get_post_status($tour)!=='publish')return;
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
   'preview_price'=>$indicative,
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
   foreach($plan['groupsByRate']??[] as $groups){
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
    'extraNames'=>$extraNames,'extraDetails'=>$extraDetails,'transportDetails'=>$transportDetails,
    'accommodationPolicy'=>$accommodationPolicy,
    'rateChoices'=>$rateChoices,
    'pricingEntryEffectiveMode'=>class_exists('Shared_Tour_Pricing_Entry_v1')
      ?(Shared_Tour_Pricing_Entry_v1::inspect($rateSourceTour)['effective_mode']??'inquiry')
      :($rateChoices?'existing_rates':'inquiry'),
    'rateSelectionPrompt'=>([
      'en'=>'Select a tour rate for a date-specific estimate',
      'ar'=>'اختر سعر الرحلة لعرض تقدير يناسب تاريخ السفر',
      'fr'=>'Choisissez un tarif pour une estimation à cette date',
      'de'=>'Wählen Sie einen Tarif für eine datumsbezogene Schätzung',
      'es'=>'Elige una tarifa para calcular el precio según la fecha',
      'it'=>'Scegli una tariffa per stimare il prezzo nella data scelta'
    ][(string)apply_filters('wpml_current_language','en')]??'Select a tour rate for an estimate'),
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
},30);
