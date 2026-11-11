<?php
/**
 * MAD4B Import Property & Rates — company-scoped wp-admin review proposals.
 * No network scraping, CPT publication, Rate writes, or checkout activation.
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Property_Intake_Admin_V1')) {
final class MAD4B_Property_Intake_Admin_V1 {
 const SLUG='mad4b-property-intake';
 const ACTION='mad4b_property_intake_submit_v1';
 const PREFIX='mad4b_property_intake_proposals_v1_';
 private static function err($code) {return new WP_Error('property_intake_ui_'.$code);}
 public static function scope() {
  $site=class_exists('MAD4B_SCP_Site_Profile')?(string)MAD4B_SCP_Site_Profile::site_uuid():'';
  if($site===''||!preg_match('/^[a-zA-Z0-9-]{8,128}$/D',$site))return self::err('site_scope_unavailable');
  return self::PREFIX.substr(hash('sha256',$site.'|'.home_url('/')),0,24);
 }
 public static function records() {
  $key=self::scope();if(is_wp_error($key))return $key;
  $rows=get_option($key,[]);
  return is_array($rows)?$rows:[];
 }
 public static function submit(array $request) {
  if(!is_user_logged_in()||!current_user_can('manage_options'))return self::err('permission_denied');
  $scope=self::scope();if(is_wp_error($scope))return $scope;
  if(!class_exists('MAD4B_Property_Source_Intake_V1'))return self::err('intake_contract_missing');
  $family=(string)($request['product_family']??'properties');
  if(!in_array($family,['properties','units'],true))return self::err('product_family_invalid');
  $mode=(string)($request['mode']??'');
  $platform=(string)($request['platform']??'');
  $unitsRaw=(string)($request['units_json']??'[]');
  $ratesRaw=(string)($request['rates_json']??'[]');
  if(strlen($unitsRaw)>40000||strlen($ratesRaw)>40000)return self::err('payload_too_large');
  $units=json_decode($unitsRaw,true);$rates=json_decode($ratesRaw,true);
  if(!is_array($units)||!is_array($rates)||!array_is_list($units)||!array_is_list($rates))
   return self::err('json_invalid');
  $quickKey=trim((string)($request['quick_unit_key']??''));
  if($quickKey!=='') {
   $quickName=trim((string)($request['quick_unit_name']??''));
   $quickKind=trim((string)($request['quick_unit_kind']??'room'));
   $quickCapacity=$request['quick_capacity']??2;
   $units[]=['key'=>$quickKey,'name'=>$quickName,'kind'=>$quickKind,
       'capacity'=>$quickCapacity,'amenities'=>[]];
   $quickPrice=trim((string)($request['quick_price']??''));
   if($quickPrice!=='') {
     $rates[]=['unit_key'=>$quickKey,'date_from'=>trim((string)($request['quick_from']??'')),
       'date_to'=>trim((string)($request['quick_to']??'')),
       'amount'=>$quickPrice,'currency'=>trim((string)($request['quick_currency']??'USD')),
       'basis'=>trim((string)($request['quick_basis']??'per_room_night')),
       'occupancy'=>$quickCapacity,'fees_included'=>false];
   }
  }
  $input=['mode'=>$mode,'platform'=>$platform,
    'company_property_key'=>sanitize_text_field((string)($request['company_property_key']??'')),
    'title'=>sanitize_text_field((string)($request['title']??'')),
    'address'=>sanitize_textarea_field((string)($request['address']??'')),
    'proof_type'=>(string)($request['proof_type']??''),
    'proof_reference'=>sanitize_text_field((string)($request['proof_reference']??'')),
    'units'=>$units,'rate_observations'=>$rates];
  foreach(['source_url','connection_id'] as $field) {
    $v=trim((string)($request[$field]??''));
    if($v!=='')$input[$field]=$v;
  }
  $plan=MAD4B_Property_Source_Intake_V1::plan($input);
  if(is_wp_error($plan))return $plan;
  // Binding to this site's option is implicit and verified on every read.
  // Save only the validated plan and public source metadata, not credentials or files.
  $rows=self::records();if(is_wp_error($rows))return $rows;
  $id=substr(hash('sha256',$input['company_property_key'].'|'.$mode.'|'.$platform),0,20);
  $previous=$rows[$id]??null;
  if($previous && ($previous['plan']['candidate_digest']??null)===$plan['candidate_digest'])
    return ['state'=>'unchanged','id'=>$id,'plan'=>$previous['plan'],'new_record'=>false];
  if(!$previous&&count($rows)>=30)return self::err('capacity_reached');
  $rows[$id]=['id'=>$id,'plan'=>$plan,'product_family'=>$family,'state'=>'WAITING_REVIEW',
    'source_mode'=>$mode,'platform'=>$platform,'created_by'=>get_current_user_id(),
    'updated_at'=>gmdate('c'),'revision'=>(int)($previous['revision']??0)+1,
    'content_job_id'=>null,'commercial_authority'=>false];
  // Avoid unsafe automatic retry on an uncertain write.
  update_option($scope,$rows,false);
  $saved=get_option($scope,[]);
  if(!is_array($saved)||($saved[$id]['plan']['candidate_digest']??'')!==$plan['candidate_digest'])
   return self::err('readback_failed');
  return ['state'=>'WAITING_REVIEW','id'=>$id,'plan'=>$plan,
    'new_record'=>!$previous,'revision'=>$rows[$id]['revision']];
 }
 public static function register() {
  add_action('admin_menu',[__CLASS__,'menu'],70);
  add_action('admin_enqueue_scripts',[__CLASS__,'assets'],15);
  add_action('admin_post_'.self::ACTION,[__CLASS__,'handle']);
 }
 public static function menu() {
  add_submenu_page('mad4b-control-plane','Import Workspace','Import Workspace',
    'manage_options',self::SLUG,[__CLASS__,'screen']);
 }
 public static function handle() {
  if(!current_user_can('manage_options'))wp_die('Not authorized',403);
  check_admin_referer(self::ACTION,'property_intake_nonce');
  $raw=wp_unslash($_POST);
  $result=self::submit(is_array($raw)?$raw:[]);
  $args=['page'=>self::SLUG,'tab'=>in_array((string)($raw['product_family']??''),['properties','units'],true)?$raw['product_family']:'review'];
  if(is_wp_error($result))$args['error']=$result->get_error_code();
  else {$args['saved']=$result['state'];$args['record']=$result['id'];}
  wp_safe_redirect(add_query_arg($args,admin_url('admin.php')));exit;
 }
 public static function assets($hook) {
  if(sanitize_key((string)($_GET['page']??''))!==self::SLUG)return;
  $file=__DIR__.'/assets/property-import-workspace.css';
  if(is_readable($file))wp_enqueue_style('mad4b-import-workspace',set_url_scheme(plugins_url('assets/property-import-workspace.css',__FILE__),parse_url(home_url(),PHP_URL_SCHEME)),[],(string)filemtime($file));
  $js=__DIR__.'/assets/property-import-workspace.js';
  if(is_readable($js))wp_enqueue_script('mad4b-import-workspace',set_url_scheme(plugins_url('assets/property-import-workspace.js',__FILE__),parse_url(home_url(),PHP_URL_SCHEME)),[],(string)filemtime($js),true);
 }
 private static function tabs() {
  return [
   'overview'=>['Overview','dashicons-dashboard'],
   'tours'=>['Tours & Cruises','dashicons-palmtree'],
   'properties'=>['Properties','dashicons-building'],
   'units'=>['Units & Rates','dashicons-admin-multisite'],
   'transport'=>['Transportation','dashicons-car'],
   'extras'=>['Experiences & Services','dashicons-tickets-alt'],
   'review'=>['Review Queue','dashicons-clipboard'],
  ];
 }
 private static function selected_tab() {
  $tab=sanitize_key((string)($_GET['tab']??'overview'));
  return isset(self::tabs()[$tab])?$tab:'overview';
 }
 private static function tab_url($tab) {
  return admin_url('admin.php?page='.self::SLUG.'&tab='.rawurlencode($tab));
 }
 private static function stats($records) {
  $status=['all'=>count($records),'properties'=>0,'units'=>0,'waiting'=>0];
  foreach($records as $row) {
   $family=$row['product_family']??'properties';
   if(isset($status[$family]))$status[$family]++;
   if(($row['state']??'')==='WAITING_REVIEW')$status['waiting']++;
  }
  return $status;
 }
 public static function screen() {
  if(!current_user_can('manage_options'))wp_die('Not authorized',403);
  $records=self::records();
  $active=self::selected_tab();
  echo '<div class="wrap mad4b-import-workspace"><header class="mad4b-import-head"><div>';
  echo '<span class="mad4b-import-eyebrow">MAD4B · Content & Commerce</span><h1>Import Workspace</h1>';
  echo '<p>Import, match and review content by product. Native engines retain their own publishing and pricing controls.</p></div>';
  echo '<div class="mad4b-import-head-actions"><a class="button" href="'.esc_url(admin_url('admin.php?page=mad4b-source-hub')).'">Source Hub</a>';
  echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=mad4b-provider-connections')).'">Provider Connections</a></div></header>';
  if(is_wp_error($records)) {
   echo '<div class="notice notice-error"><p>'.esc_html($records->get_error_message()).'</p></div></div>';return;
  }
  $stats=self::stats($records);
  echo '<section class="mad4b-import-shell" aria-label="Import Workspace">';
  echo '<div class="mad4b-import-shell-title"><strong>Product workspace</strong><span>One navigation · product-specific workflows</span></div>';
  echo '<nav class="mad4b-import-tabs" aria-label="Product categories">';
  foreach(self::tabs() as $tab=>$item){
   [$label,$icon]=$item;
   echo '<a class="mad4b-import-tab'.($tab===$active?' is-current':'').'"'.($tab===$active?' aria-current="page"':'').
     ' href="'.esc_url(self::tab_url($tab)).'"><span class="dashicons '.esc_attr($icon).'" aria-hidden="true"></span>'.esc_html($label).
     ($tab==='review'?'<span class="mad4b-import-count">'.absint($stats['waiting']).'</span>':'').'</a>';
  }
  echo '</nav></section>';
  if(isset($_GET['saved'])&&in_array((string)$_GET['saved'],['WAITING_REVIEW','unchanged'],true))
   echo '<div class="notice notice-success inline"><p>Proposal saved for review. No property, unit or sellable rate published.</p></div>';
  if(isset($_GET['error'])&&is_string($_GET['error']))
   echo '<div class="notice notice-error inline"><p>Validation error: '.esc_html(sanitize_key(wp_unslash($_GET['error']))).'</p></div>';
  if(isset($_GET['review_error'])&&is_string($_GET['review_error']))
   echo '<div class="notice notice-error inline"><p>Review task not created: '.esc_html(sanitize_key(wp_unslash($_GET['review_error']))).'</p></div>';
  if($active==='overview')self::overview($stats);
  elseif(in_array($active,['properties','units'],true))self::intake_panel($active,$records);
  elseif($active==='review')self::review($records);
  else self::native_panel($active);
  echo '<p class="mad4b-import-boundary"><strong>Scope:</strong> proposal capture only. Provider verification, third-party capture, Content Jobs dispatch, product publication and Rate activation require their existing owners.</p></div>';
 }
 private static function overview($stats) {
  echo '<div class="mad4b-import-metrics">';
  foreach([['Pending review',$stats['waiting']],['Property proposals',$stats['properties']],['Unit proposals',$stats['units']]] as $m)
   echo '<article><small>'.esc_html($m[0]).'</small><strong>'.absint($m[1]).'</strong></article>';
  echo '</div><div class="mad4b-import-card-grid">';
  $cards=[
   ['tours','Tours & Cruises','Import source descriptions, programs and destination content.','Source Hub / Tour editor'],
   ['properties','Properties','Import a hotel, serviced apartment, venue or other property.','Create intake proposal'],
   ['units','Units & Rates','Describe rooms, cabins, tables or seats with observed rate data.','Create unit proposal'],
   ['transport','Transportation','Inspect vehicles and transport source data through native tools.','Open product records'],
   ['extras','Experiences & Services','Match activities and optional services without duplicate package charges.','Open activity records'],
   ['review','Review Queue','Inspect proposed products, unit definitions and unapproved prices.','Review proposals'],
  ];
  foreach($cards as $c){
   echo '<a class="mad4b-import-card" href="'.esc_url(self::tab_url($c[0])).'"><h2>'.esc_html($c[1]).'</h2><p>'.esc_html($c[2]).'</p><span>'.esc_html($c[3]).' <span aria-hidden="true">→</span></span></a>';
  }
  echo '</div>';
 }
 private static function native_panel($family) {
  $content=[
   'tours'=>['Tours & Cruises','Use the existing Source Hub to ingest sourced itineraries and the native Tour editor to review. Rate Generator remains the only native Tour Rate owner.',
     [['Source Hub','admin.php?page=mad4b-source-hub'],['Tour products','edit.php?post_type=tours-and-activities'],['Tour Rates','edit.php?post_type=tour-rates']]],
   'transport'=>['Transportation','Transportation content, vehicles and transport rate authoring remain managed by their existing product and Rate engines.',
     [['Transportation','edit.php?post_type=transportations'],['Transport Rates','edit.php?post_type=transport-rates'],['Rate Generator','admin.php?page=wc-settings&tab=rate_generation']]],
   'extras'=>['Experiences & Services','Extra trips and activities are Tours with explicit optional-component relationships. Included services must not be charged twice.',
     [['Tours & Activities','edit.php?post_type=tours-and-activities'],['Source Hub','admin.php?page=mad4b-source-hub']]],
  ];
  [$title,$desc,$actions]=$content[$family];
  echo '<section class="mad4b-import-panel"><div class="mad4b-import-section-title"><h2>'.esc_html($title).'</h2><p>'.esc_html($desc).'</p></div>';
  echo '<div class="mad4b-import-actions">';
  foreach($actions as [$label,$path])echo '<a class="button button-secondary" href="'.esc_url(admin_url($path)).'">'.esc_html($label).'</a>';
  echo '</div><div class="mad4b-import-info">Native product editors preserve JetEngine field definitions, WPML relations, pricing policies and publication permissions. This workspace does not duplicate their save logic.</div></section>';
 }
 private static function intake_panel($family,$records) {
  $isUnit=$family==='units';
  echo '<section class="mad4b-import-panel"><div class="mad4b-import-section-title"><div><h2>'.($isUnit?'Units & Rate Sources':'Property Sources').'</h2>';
  echo '<p>'.($isUnit?'Define a unit under a proposed property and attach observations for later Rate review.':'Start with the property identity, supplier evidence and source. Unit details can be added as needed.').'</p></div>';
  echo '<a class="button" href="'.esc_url(self::tab_url('review')).'">View review queue</a></div>';
  echo '<div class="mad4b-import-two-column"><div class="mad4b-import-main">';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="mad4b-import-form">';
  echo '<input type="hidden" name="action" value="'.esc_attr(self::ACTION).'"><input type="hidden" name="product_family" value="'.esc_attr($family).'">';
  wp_nonce_field(self::ACTION,'property_intake_nonce');
  echo '<fieldset class="mad4b-import-fieldset"><legend>1 · Choose the intake route</legend><div class="mad4b-import-field-grid">';
  self::input_select('mode','Data source',['owner_submitted'=>'My company / property owner','contracted_provider'=>'Contracted provider']);
  self::input_select('platform','Platform / provider',['direct_owner'=>'Direct owner','channel_manager'=>'Channel Manager','booking_com'=>'Booking.com','airbnb'=>'Airbnb']);
  self::input_select('proof_type','Evidence type',['company_declaration'=>'Company declaration',
    'ownership_document'=>'Ownership document reference','contract_reference'=>'Contract reference','partner_connection'=>'Partner connection reference']);
  self::input_field('proof_reference','Evidence / contract reference','Internal reference, never a password');
  self::input_field('connection_id','Provider connection ID','Only for contracted provider');
  self::input_field('source_url','Source listing URL','https://... (optional)');
  echo '</div></fieldset>';
  echo '<fieldset class="mad4b-import-fieldset"><legend>2 · Property identity</legend><div class="mad4b-import-field-grid">';
  self::input_field('company_property_key','Company property key','e.g. cairo-studio-001',true);
  self::input_field('title','Property name','Property / hotel / venue',true);
  self::input_field('address','Address / location','City, country, location',true);
  echo '</div></fieldset>';
  echo '<fieldset class="mad4b-import-fieldset"><legend>3 · '.($isUnit?'Unit & observed Rate':'Unit details (optional)').'</legend>';
  echo '<div class="mad4b-import-field-grid">';
  self::input_field('quick_unit_key','Unit reference','studio-1');
  self::input_field('quick_unit_name','Unit name','Studio / Room / Cabin');
  self::input_select('quick_unit_kind','Unit type',['room'=>'Room / Apartment','cabin'=>'Cabin','table'=>'Table',
    'seat'=>'Seat','hall'=>'Hall','ticket'=>'Ticket','space'=>'Space','other'=>'Other']);
  self::input_field('quick_capacity','Capacity','2',false,'2');
  self::input_field('quick_price','Observed amount','Not a sellable price');
  self::input_field('quick_currency','Currency','USD',false,'USD');
  self::input_select('quick_basis','Price basis',['per_room_night'=>'Room / night','per_cabin_night'=>'Cabin / night',
    'per_table'=>'Table','per_seat'=>'Seat','per_ticket'=>'Ticket','per_day'=>'Day','per_booking'=>'Booking']);
  self::input_field('quick_from','Valid from','YYYY-MM-DD');
  self::input_field('quick_to','Valid to','YYYY-MM-DD');
  echo '</div><details class="mad4b-import-advanced"><summary>Advanced · Multiple units and rate observations</summary>';
  echo '<label for="units_json">Units (JSON array)</label><textarea id="units_json" name="units_json" rows="5" class="large-text code">[]</textarea>';
  echo '<label for="rates_json">Unapproved Rate observations (JSON array)</label><textarea id="rates_json" name="rates_json" rows="5" class="large-text code">[]</textarea>';
  echo '</details></fieldset>';
  echo '<div class="mad4b-import-form-foot"><p>Submissions remain WAITING_REVIEW. No Property, Unit or Rate will be published.</p>';
  submit_button('Save for review','primary','submit',false);echo '</div></form></div>';
  echo '<aside class="mad4b-import-side"><h3>What happens next?</h3><ol>';
  foreach(['Validate the source and company identity','Match existing Property and Unit records','Inspect evidence and rate observations','Approve through the native product and pricing engines'] as $s)
   echo '<li>'.esc_html($s).'</li>';
  echo '</ol><p>Research-only data never becomes a supplier price automatically.</p>';
  echo '<h3>Existing records</h3><p><a href="'.esc_url(admin_url('edit.php?post_type=properties')).'">Properties</a><br>';
  echo '<a href="'.esc_url(admin_url('edit.php?post_type=units')).'">Units</a><br>';
  echo '<a href="'.esc_url(admin_url('edit.php?post_type=property-rates')).'">Property Rates</a></p></aside></div></section>';
 }
 private static function input_field($name,$title,$placeholder='',$required=false,$default='') {
  echo '<label class="mad4b-import-field" for="'.esc_attr($name).'"><span>'.esc_html($title).'</span>';
  echo '<input id="'.esc_attr($name).'" name="'.esc_attr($name).'" type="text" value="'.esc_attr($default).'" placeholder="'.esc_attr($placeholder).'"'.($required?' required':'').'></label>';
 }
 private static function input_select($name,$title,$values) {
  echo '<label class="mad4b-import-field" for="'.esc_attr($name).'"><span>'.esc_html($title).'</span><select id="'.esc_attr($name).'" name="'.esc_attr($name).'">';
  foreach($values as $value=>$label)echo '<option value="'.esc_attr($value).'">'.esc_html($label).'</option>';
  echo '</select></label>';
 }
 private static function review($records) {
  $q=isset($_GET['search'])&&is_string($_GET['search'])?sanitize_text_field(wp_unslash($_GET['search'])):'';
  $family=isset($_GET['family'])&&is_string($_GET['family'])?sanitize_key(wp_unslash($_GET['family'])):'all';
  $allowed=['all','properties','units'];
  if(!in_array($family,$allowed,true))$family='all';
  $items=array_values(array_filter(array_reverse($records,true),static function($r)use($family,$q){
   $rowFamily=$r['product_family']??'properties';
   if($family!=='all'&&$family!==$rowFamily)return false;
   $p=$r['plan']??[];
   return $q===''||str_contains(mb_strtolower((string)($p['proposed_title']??'').' '.($p['company_property_key']??'').' '.($p['platform']??'')),mb_strtolower($q));
  }));
  $native=class_exists('MAD4B_Hub_Review_UI') ?
    admin_url('admin.php?page='.MAD4B_Hub_Review_UI::INBOX) : null;
  echo '<section class="mad4b-import-panel mad4b-import-review"><div class="mad4b-import-section-title"><div><h2>Content &amp; Product Review</h2>';
  echo '<p>Review source evidence, unit candidates and unapproved prices. These intake proposals are distinct from Content Hub editorial batches.</p></div>';
  if($native)echo '<a class="button" href="'.esc_url($native).'">Open native Reviews Inbox</a>';
  echo '</div>';
  echo '<div class="mad4b-import-review-metrics">';
  foreach([
   ['Awaiting review',count(array_filter($records,static fn($r)=>($r['state']??'')==='WAITING_REVIEW')),'dashicons-clock'],
   ['Property proposals',count(array_filter($records,static fn($r)=>($r['product_family']??'properties')==='properties')),'dashicons-building'],
   ['Unit proposals',count(array_filter($records,static fn($r)=>($r['product_family']??'properties')==='units')),'dashicons-admin-multisite']
  ] as [$title,$count,$icon])echo '<article class="mad4b-import-review-metric"><span class="dashicons '.esc_attr($icon).'"></span><strong>'.absint($count).'</strong><span>'.esc_html($title).'</span></article>';
  echo '</div>';
  echo '<form method="get" class="mad4b-import-review-filters" aria-label="Filter import review">';
  echo '<input type="hidden" name="page" value="'.esc_attr(self::SLUG).'"><input type="hidden" name="tab" value="review">';
  echo '<label><span class="screen-reader-text">Search products</span><input type="search" name="search" placeholder="Search property, reference or source" value="'.esc_attr($q).'"></label>';
  echo '<label><span class="screen-reader-text">Product category</span><select name="family">';
  foreach(['all'=>'All products','properties'=>'Properties','units'=>'Units & Rates'] as $id=>$name)echo '<option value="'.esc_attr($id).'"'.selected($family,$id,false).'>'.esc_html($name).'</option>';
  echo '</select></label><button class="button button-primary" type="submit">Filter</button><a href="'.esc_url(self::tab_url('review')).'">Clear</a></form>';
  echo '<div class="mad4b-import-review-head"><h3>Import candidates</h3><span>'.count($items).' / '.count($records).'</span></div>';
  if(!$items)echo '<p class="mad4b-import-empty">No matching intake proposals. Create one from Properties or Units.</p>';
  foreach($items as $row) {
   $p=$row['plan']??[];$category=$row['product_family']??'properties';
   $name=$p['proposed_title']??'Untitled';$provider=$p['platform']??'unknown';
   echo '<article class="mad4b-import-review-item"><div class="mad4b-import-review-icon"><span class="dashicons dashicons-media-document"></span></div>';
   echo '<div class="mad4b-import-review-main"><h3>'.esc_html($name).'</h3>';
   echo '<p>'.esc_html((string)($p['proposed_address']??'Address not supplied')).'</p>';
   echo '<div class="mad4b-import-review-chips"><span>'.esc_html(ucfirst($category)).'</span><span>'.esc_html($provider).'</span><span>'.absint($p['unit_count']??0).' units</span><span>'.absint($p['price_observation_count']??0).' unapproved rate observations</span></div>';
   echo '<details><summary>Inspect evidence &amp; proposed values</summary>';
   echo '<p>Company reference: <code>'.esc_html((string)($p['company_property_key']??'')).'</code></p>';
   echo '<p>Source: '.esc_html((string)($p['source_url']??'Company supplied')).'</p>';
   echo '<p>Evidence type: '.esc_html((string)($p['proof_type']??'')).' · '.esc_html((string)($p['proof_reference']??'')).'</p>';
   foreach((array)($p['unit_candidates']??[]) as $unit)echo '<p>Unit: '.esc_html($unit['name'].' / '.$unit['kind']).' · capacity '.absint($unit['capacity']).'</p>';
   foreach((array)($p['price_observation_candidates']??[]) as $price)echo '<p>Observed (not authorized): '.esc_html(number_format_i18n($price['amount'],2).' '.$price['currency'].' / '.$price['basis']).' · '.esc_html($price['date_from'].' – '.$price['date_to']).'</p>';
   echo '</details></div><div class="mad4b-import-review-state"><span class="mad4b-import-status">'.esc_html($row['state']??'WAITING_REVIEW').'</span><small>Revision '.absint($row['revision']??1).'</small>';
   if(!empty($row['content_job_id'])&&class_exists('MAD4B_Property_Intake_Review_Bridge_V1')){
     echo '<small>Job: '.esc_html($row['content_job_id']).'</small>';
     echo '<a class="button button-small mad4b-import-native-review-link" href="'.esc_url(MAD4B_Property_Intake_Review_Bridge_V1::url($row['id'])).'">Open review request</a>';
   }else{
     echo '<span class="mad4b-import-review-unbound">Not yet in Content Jobs</span>';
     echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_property_intake_create_review_v1">';
     echo '<input type="hidden" name="intake_id" value="'.esc_attr($row['id']).'">';
     echo '<input type="hidden" name="candidate_digest" value="'.esc_attr($p['candidate_digest']).'">';
     wp_nonce_field('mad4b_property_intake_create_review_v1','intake_review_nonce');
     echo '<button class="button button-primary button-small" type="submit">Create Review Task</button></form>';
   }
   echo '</div></article>';
  }
  echo '<div class="mad4b-import-review-footer"><p><strong>Review authority:</strong> these intake candidates are not yet Content Hub jobs. Opening Reviews Inbox will show existing native review batches, not automatically include these proposals.</p>';
  if($native)echo '<a class="button button-primary" href="'.esc_url($native).'">Open Content Hub Reviews Inbox</a>';
  echo '</div>';
  echo '</section>';
 }
 private static function select($name,$title,array $values) {
  echo '<tr><th><label for="'.esc_attr($name).'">'.esc_html($title).'</label></th><td><select id="'.esc_attr($name).'" name="'.esc_attr($name).'">';
  foreach($values as $value=>$label)echo '<option value="'.esc_attr($value).'">'.esc_html($label).'</option>';
  echo '</select></td></tr>';
 }
}
MAD4B_Property_Intake_Admin_V1::register();
}
