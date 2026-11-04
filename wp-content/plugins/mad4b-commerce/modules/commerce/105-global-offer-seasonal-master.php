<?php
/** Lab-only seasonal Top Bar orchestration; content selection never changes checkout price. */
if(!defined('ABSPATH')||(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
function shared_offer_seasonal_rules(){
 $rows=get_option('shared_offer_seasonal_rules',[]);return is_array($rows)?array_slice($rows,0,50):[];
}
function shared_offer_visitor_country(){
 if(class_exists('MAD4B_SCP_Market_Context')){
  $selected=MAD4B_SCP_Market_Context::current()['selected_market']??null;
  if(is_array($selected)&&!empty($selected['country']))return (string)$selected['country'];
 }
 // Never infer visitor identity from IP or untrusted URL. WooCommerce customer if known.
 if(function_exists('WC')&&WC()->customer){$c=WC()->customer->get_billing_country()?:WC()->customer->get_shipping_country();if(preg_match('/^[A-Z]{2}$/D',(string)$c))return $c;}
 return '';
}
function shared_offer_valid_date($date){
 if(!is_string($date)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$date,$m))return false;
 return checkdate((int)$m[2],(int)$m[3],(int)$m[1]);
}
/** Resolve recurring travel-season term dates from authoritative JetEngine term meta. */
function shared_offer_term_window($term_id,$today){
 $term=get_term(absint($term_id),'season');if(!$term||is_wp_error($term)||!shared_offer_valid_date($today))return null;
 $mode=(string)get_term_meta($term_id,'season_mode',true);
 if($mode!==''&&$mode!=='travel')return null; // non-travel occasions may use fixed rule dates
 $sm=(int)get_term_meta($term_id,'season_start_month',true);$sd=(int)get_term_meta($term_id,'season_start_day',true);
 $em=(int)get_term_meta($term_id,'season_end_month',true);$ed=(int)get_term_meta($term_id,'season_end_day',true);
 if(!checkdate($sm,$sd,2024)||!checkdate($em,$ed,2024))return null;
 $cross=get_term_meta($term_id,'season_cross_year',true)==='1';$year=(int)substr($today,0,4);
 $windows=[];
 foreach([$year-1,$year,$year+1] as $y){
  if(!checkdate($sm,$sd,$y)||!checkdate($em,$ed,$y+($cross?1:0)))continue;
  $start=sprintf('%04d-%02d-%02d',$y,$sm,$sd);$end=sprintf('%04d-%02d-%02d',$y+($cross?1:0),$em,$ed);
  if($start<=$end&&$today<=$end)$windows[]=[$start,$end];
 }
 usort($windows,static function($a,$b)use($today){$aa=$a[0]<=$today?0:1;$bb=$b[0]<=$today?0:1;return $aa<=>$bb?:strcmp($a[0],$b[0]);});
 return $windows[0]??null;
}
/** Non-travel season terms may hold exact yearly windows; no guessed religious/holiday dates. */
function shared_offer_occasion_window($term_id,$today){
 if(!shared_offer_valid_date($today))return null;
 $term=get_term(absint($term_id),'season');if(!$term||is_wp_error($term))return null;
 $mode=(string)get_term_meta($term_id,'season_mode',true);
 if(!in_array($mode,['global','domestic'],true))return null;
 $windows=get_term_meta($term_id,'season_date_windows',true);if(!is_array($windows))return null;
 $eligible=[];
 foreach(array_slice($windows,0,30) as $row){
  if(!is_array($row))continue;$start=$row['start']??'';$end=$row['end']??'';
  if(!shared_offer_valid_date($start)||!shared_offer_valid_date($end)||$start>$end||$today>$end)continue;
  $eligible[]=[$start,$end];
 }
 usort($eligible,static fn($a,$b)=>($a[0]<=$today?0:1)<=>($b[0]<=$today?0:1)?:strcmp($a[0],$b[0]));
 return $eligible[0]??null;
}
function shared_offer_seasonal_selection($today=null,$visitor_country=null,$traveler_market=null,$use_case='default'){
 $today=$today?:apply_filters('shared_offer/seasonal_today',wp_date('Y-m-d'));if(!shared_offer_valid_date($today))return null;
 $visitor_country=$visitor_country===null?shared_offer_visitor_country():strtoupper($visitor_country);
 $traveler_market=$traveler_market===null?apply_filters('shared_offer/trusted_traveler_market',''):strtoupper($traveler_market);
 if($use_case==='default')$use_case=(string)apply_filters('shared_offer/trusted_use_case','default');
 $lang=function_exists('shared_offer_current_visible_language')?shared_offer_current_visible_language():(defined('ICL_LANGUAGE_CODE')?ICL_LANGUAGE_CODE:'');
 if(function_exists('shared_offer_current_visible_language')&&$lang==='')return null;
 $matches=[];
 foreach(shared_offer_seasonal_rules() as $i=>$r){
  if(!is_array($r)||empty($r['enabled']))continue;
  if(!apply_filters('shared_offer/seasonal_rule_eligible',true,$r))continue;
  $id=absint($r['template_id']??0);$upcoming_id=absint($r['upcoming_template_id']??0)?:$id;
  if(!$id||$id===absint(get_option('global_offer_elementor_template_id')))continue; // Master cannot render itself.
  $type=$r['scope']??'global';if(!in_array($type,['global','domestic'],true))continue;
  $start=(string)($r['starts_at']??'');$end=(string)($r['ends_at']??'');
  $term_id=absint($r['season_term_id']??0);
  $window=shared_offer_term_window($term_id,$today)?:shared_offer_occasion_window($term_id,$today);
  if($term_id&&!$window)continue;
  if($window){[$start,$end]=$window;}
  if(!shared_offer_valid_date($start)||!shared_offer_valid_date($end)||$start>$end||$today>$end)continue;
  $country=strtoupper((string)($r['visitor_country']??''));$market=strtoupper((string)($r['traveler_market']??''));
  if($type==='domestic'&&!$country&&!$market&&empty($r['market_profile_id']))continue; // A verified MAD4B market profile is a valid domestic targeting constraint.
  if($country&&($visitor_country!==$country))continue;
  if($market&&($traveler_market!==$market))continue;
  if(!empty($r['use_case'])&&$r['use_case']!=='any'&&$r['use_case']!==$use_case)continue;
  if(!empty($r['lang'])&&$r['lang']!==$lang)continue;
  $state=$today>=$start?'active':'upcoming';
  if($state==='upcoming'){$days=(int)((new DateTimeImmutable($today))->diff(new DateTimeImmutable($start))->days);if($days>min(365,max(0,(int)($r['upcoming_days']??0))))continue;}
  $selected_id=$state==='upcoming'?$upcoming_id:$id;
  if(!$selected_id||$selected_id===absint(get_option('global_offer_elementor_template_id')))continue;
  $selected=get_post($selected_id);if(!$selected||$selected->post_type!=='elementor_library'||$selected->post_status!=='publish')continue;
  $matches[]=['template_id'=>$selected_id,'state'=>$state,'rule_index'=>$i,'priority'=>(int)($r['priority']??0),'upcoming_takes_precedence'=>!empty($r['upcoming_takes_precedence']),'scope'=>$type,'season_term_id'=>$term_id,'starts_at'=>$start,'ends_at'=>$end];
 }
 // Transition takeover is explicit; a nearing new season otherwise does not displace the current one.
 $rank=static fn($r)=>$r['state']==='upcoming'&&!empty($r['upcoming_takes_precedence'])?0:($r['state']==='active'?1:2);
 usort($matches,static fn($a,$b)=>$rank($a)<=>$rank($b)?:$b['priority']<=>$a['priority']?:strcmp($a['starts_at'],$b['starts_at'])?:$a['rule_index']<=>$b['rule_index']);
 return $matches[0]??null;
}
function shared_offer_seasonal_visible(){
 $o=get_option('global-offers',[]);return !empty($o['seasonal_templates_enabled'])&&shared_offer_seasonal_selection()!==null;
}
function shared_offer_seasonal_master_shortcode($atts=[]){
 $atts=shortcode_atts(['mode'=>'any'],$atts,'global_offer_master_top_bar');
 $mode=$atts['mode'];
 $o=get_option('global-offers',[]);if(empty($o['seasonal_templates_enabled']))return $mode==='fallback'||$mode==='any'?do_shortcode('[global_offer_top_bar]'):'';
 $selection=shared_offer_seasonal_selection();if(!$selection)return $mode==='fallback'||$mode==='any'?do_shortcode('[global_offer_top_bar]'):'';
 if($mode==='fallback'||($mode!=='any'&&$mode!==$selection['state']))return '';
 $id=$selection['template_id'];static $rendering=[];if(isset($rendering[$id])||!class_exists('Elementor\\Plugin'))return '';
 $rendering[$id]=true;
 try{$html=(string)\Elementor\Plugin::$instance->frontend->get_builder_content_for_display($id,true);}finally{unset($rendering[$id]);}
 if(!$html)return '';
 return '<div class="shared-seasonal-topbar" data-seasonal-state="'.esc_attr($selection['state']).'" data-template-id="'.esc_attr((string)$id).'">'.$html.'</div>';
}
add_shortcode('global_offer_master_top_bar','shared_offer_seasonal_master_shortcode');
// Existing Elementor wrapper must remain when a certified campaign OR eligible seasonal content exists.
add_filter('elementor/frontend/builder_content_data',static function($nodes,$document_id){
 if(!is_array($nodes)||!shared_offer_seasonal_visible())return $nodes;
 // Earlier global-offer hide filter can already have removed the wrapper: restore source for the exact Top Bar template only.
 if((int)$document_id!==absint(get_option('global_offer_elementor_template_id')))return $nodes;
 $original=json_decode((string)get_post_meta($document_id,'_elementor_data',true),true);
 return is_array($original)?$original:$nodes;
},1300,2);
// Avoid cross-visitor cache leakage when location-specific rules are configured.
add_action('init',static function(){
 $o=get_option('global-offers',[]);if(empty($o['seasonal_templates_enabled']))return;
 foreach(shared_offer_seasonal_rules() as $r){if(!empty($r['enabled'])&&(!empty($r['visitor_country'])||!empty($r['traveler_market'])||!empty($r['market_profile_id'])||(!empty($r['use_case'])&&$r['use_case']!=='any'))){if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);return;}}
},2);

// WooCommerce -> Top Bar Seasons. Editable template rules, independent from financial activation.
add_action('admin_menu',static function(){
 if(class_exists('WooCommerce'))add_submenu_page('woocommerce','Top Bar Seasons','Top Bar Seasons','manage_woocommerce','shared-offer-seasons','shared_offer_seasons_admin');
},91);
add_action('admin_post_shared_offer_seasons_save',static function(){
 if(!current_user_can('manage_woocommerce'))wp_die('Access denied');
 check_admin_referer('shared_offer_seasons_save');
 $raw=isset($_POST['seasonal_rows'])&&is_array($_POST['seasonal_rows'])?wp_unslash($_POST['seasonal_rows']):[];
 $rows=[];
 foreach(array_slice($raw,0,50) as $r){
  if(!is_array($r))continue;
  $id=absint($r['template_id']??0);if(!$id||$id===absint(get_option('global_offer_elementor_template_id')))continue;
  $post=get_post($id);if(!$post||$post->post_type!=='elementor_library'||$post->post_status!=='publish')continue;
  $upcoming_id=absint($r['upcoming_template_id']??0)?:$id;
  if($upcoming_id===absint(get_option('global_offer_elementor_template_id')))continue;
  $upcoming=get_post($upcoming_id);if(!$upcoming||$upcoming->post_type!=='elementor_library'||$upcoming->post_status!=='publish')continue;
  $certified_campaign_id=absint($r['certified_campaign_id']??0);
  if($certified_campaign_id){$campaign=get_post($certified_campaign_id);if(!$campaign||$campaign->post_type!=='offer-campaign'||$campaign->post_status!=='publish')continue;}
  $start=sanitize_text_field($r['starts_at']??'');$end=sanitize_text_field($r['ends_at']??'');
  if(absint($r['season_term_id']??0)&&in_array((string)get_term_meta(absint($r['season_term_id']),'season_mode',true),['','travel','global','domestic'],true)){$start='';$end='';}
  elseif(!shared_offer_valid_date($start)||!shared_offer_valid_date($end)||$start>$end)continue;
  $scope=in_array($r['scope']??'',['global','domestic'],true)?$r['scope']:'global';
  $country=strtoupper(sanitize_text_field($r['visitor_country']??''));$market=strtoupper(sanitize_text_field($r['traveler_market']??''));
  if($country&&!preg_match('/^[A-Z]{2}$/D',$country))continue;
  if($market&&!preg_match('/^[A-Z]{2}$/D',$market))continue;
  if($scope==='domestic'&&!$country&&!$market&&empty($r['market_profile_id']))continue;
  $rows[]=['season_term_id'=>absint($r['season_term_id']??0),'market_profile_id'=>sanitize_key($r['market_profile_id']??''),'brand_approval_required'=>!empty($r['brand_approval_required']),'upcoming_takes_precedence'=>!empty($r['upcoming_takes_precedence']),'enabled'=>!empty($r['enabled']),'title'=>sanitize_text_field($r['title']??''),'certified_campaign_id'=>$certified_campaign_id,'scope'=>$scope,'template_id'=>$id,'upcoming_template_id'=>$upcoming_id,
   'starts_at'=>$start,'ends_at'=>$end,'upcoming_days'=>min(365,max(0,(int)($r['upcoming_days']??0))),
   'visitor_country'=>$country,'traveler_market'=>$market,'lang'=>sanitize_key($r['lang']??''),'use_case'=>sanitize_key($r['use_case']??'any'),
   'priority'=>min(1000,max(-1000,(int)($r['priority']??0)))];
 }
 update_option('shared_offer_seasonal_rules',$rows,false);
 $settings=get_option('global-offers',[]);if(!is_array($settings))$settings=[];
 $settings['seasonal_templates_enabled']=!empty($_POST['seasonal_templates_enabled']);
 update_option('global-offers',$settings);
 wp_safe_redirect(admin_url('admin.php?page=shared-offer-seasons&saved=1'));exit;
});
function shared_offer_seasons_admin(){
 if(!current_user_can('manage_woocommerce'))wp_die('Access denied');
 $settings=get_option('global-offers',[]);$rules=shared_offer_seasonal_rules();
 ob_start();do_action('shared_offer/seasons_admin_before_rules');$context_diagnostics=ob_get_clean();
 echo '<div class="wrap"><h1>Top Bar — Seasonal Template Rules</h1><p>Master Elementor template #'.(int)get_option('global_offer_elementor_template_id').' contains Active, Upcoming and Certified-Offer Fallback nested containers. Selection changes presentation only; discount authorization stays in Shared Product Core.</p>';
 $preview_day=isset($_GET['preview_day'])?sanitize_text_field(wp_unslash($_GET['preview_day'])):wp_date('Y-m-d');
 if(!shared_offer_valid_date($preview_day))$preview_day=wp_date('Y-m-d');
 $preview_country=isset($_GET['preview_country'])?strtoupper(sanitize_text_field(wp_unslash($_GET['preview_country']))):'';
 if($preview_country&&!preg_match('/^[A-Z]{2}$/D',$preview_country))$preview_country='';
 $preview_market=isset($_GET['preview_market'])?strtoupper(sanitize_text_field(wp_unslash($_GET['preview_market']))):'';
 if($preview_market&&!preg_match('/^[A-Z]{2}$/D',$preview_market))$preview_market='';
 $preview_case=isset($_GET['preview_case'])?sanitize_key(wp_unslash($_GET['preview_case'])):'default';
 $preview=shared_offer_seasonal_selection($preview_day,$preview_country,$preview_market,$preview_case);
 echo '<h2>Read-only selection preview</h2><form method="get" action="'.esc_url(admin_url('admin.php')).'"><input type="hidden" name="page" value="shared-offer-seasons">';
 foreach(['preview_day'=>[$preview_day,'date'],'preview_country'=>[$preview_country,'text'],'preview_market'=>[$preview_market,'text'],'preview_case'=>[$preview_case,'text']] as $name=>$value)echo '<label style="display:inline-block;margin-right:12px">'.esc_html(str_replace('preview_','',$name)).' <input type="'.esc_attr($value[1]).'" name="'.esc_attr($name).'" value="'.esc_attr($value[0]).'"></label>';
 echo '<button type="submit" class="button">Preview match</button></form>';
 echo '<p><strong>Selection:</strong> '.($preview?'Template #'.(int)$preview['template_id'].' — '.esc_html($preview['state']).' (rule '.((int)$preview['rule_index']+1).')':'No matching seasonal template; certified-offer fallback only').'. This is a rules preview, not a rendered-banner or discount certification.</p>';

 echo $context_diagnostics; // admin-only HTML generated by our registered diagnostic table.
 echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post"><input type="hidden" name="action" value="shared_offer_seasons_save">';wp_nonce_field('shared_offer_seasons_save');
 echo '<p><label><input type="checkbox" name="seasonal_templates_enabled" value="1" '.checked(!empty($settings['seasonal_templates_enabled']),true,false).'> Enable seasonal template selection (independent of WooCommerce financial offers)</label></p>';
 echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Enabled</th><th>Label</th><th>Season</th><th>Market Profile</th><th>Brand approval</th><th>Scope</th><th>Active template</th><th>Upcoming template</th><th>Certified campaign (optional)</th><th>From</th><th>Until</th><th>Upcoming days</th><th>Upcoming takeover</th><th>Visitor country</th><th>Traveler market</th><th>Language</th><th>Use case</th><th>Priority</th></tr></thead><tbody>';
 $rules[]=[]; // one empty row for a new rule
 foreach($rules as $i=>$r){
  echo '<tr>';
  $fields=['enabled','title','season_term_id','market_profile_id','brand_approval_required','scope','template_id','upcoming_template_id','certified_campaign_id','starts_at','ends_at','upcoming_days','upcoming_takes_precedence','visitor_country','traveler_market','lang','use_case','priority'];
  foreach($fields as $k){$name='seasonal_rows['.$i.']['.$k.']';$value=(string)($r[$k]??'');echo '<td>';
   if(in_array($k,['enabled','brand_approval_required','upcoming_takes_precedence'],true))echo '<input type="checkbox" name="'.esc_attr($name).'" value="1" '.checked(!empty($r[$k]),true,false).'>';
   elseif($k==='season_term_id'){
    echo '<select name="'.esc_attr($name).'"><option value="0">No season selected (legacy)</option>';
    $terms=get_terms(['taxonomy'=>'season','hide_empty'=>false]);
    if(!is_wp_error($terms))foreach($terms as $term)echo '<option value="'.(int)$term->term_id.'" '.selected((int)$value,$term->term_id,false).'>'.esc_html($term->name).' (#'.(int)$term->term_id.')</option>';
    echo '</select>';
   }
   elseif($k==='market_profile_id'){
    $available=apply_filters('shared_offer/mad4b_available_market_profiles',[]);
    echo '<select name="'.esc_attr($name).'"><option value="">No market profile</option>';
    if(is_array($available))foreach($available as $profile){if(!is_array($profile))continue;$pid=sanitize_key($profile['profile_id']??'');if(!$pid)continue;echo '<option value="'.esc_attr($pid).'" '.selected($value,$pid,false).'>'.esc_html($profile['label']??$pid).'</option>';}
    if($value && (!is_array($available)||!in_array($value,array_column($available,'profile_id'),true)))echo '<option selected value="'.esc_attr($value).'">Unavailable: '.esc_html($value).'</option>';
    echo '</select>';
   }
   elseif($k==='template_id'||$k==='upcoming_template_id'){
    $templates=get_posts(['post_type'=>'elementor_library','post_status'=>'publish','posts_per_page'=>150,'orderby'=>'title','order'=>'ASC']);
    echo '<select name="'.esc_attr($name).'"><option value="0">Select Elementor template</option>';
    foreach($templates as $tpl){if((int)$tpl->ID===absint(get_option('global_offer_elementor_template_id')))continue;
     echo '<option value="'.(int)$tpl->ID.'" '.selected((int)$value,$tpl->ID,false).'>'.esc_html($tpl->post_title?:('#'.$tpl->ID)).' (#'.(int)$tpl->ID.')</option>';}
    echo '</select>';
   }
   elseif($k==='certified_campaign_id'){
    echo '<select name="'.esc_attr($name).'"><option value="0">No campaign — informational only</option>';
    $campaigns=get_posts(['post_type'=>'offer-campaign','post_status'=>'publish','posts_per_page'=>80,'orderby'=>'title','order'=>'ASC']);
    foreach($campaigns as $campaign)echo '<option value="'.(int)$campaign->ID.'" '.selected((int)$value,$campaign->ID,false).'>'.esc_html($campaign->post_title).' (#'.(int)$campaign->ID.')</option>';
    echo '</select>';
   }
   elseif($k==='scope')echo '<select name="'.esc_attr($name).'"><option value="global" '.selected($value,'global',false).'>Global</option><option value="domestic" '.selected($value,'domestic',false).'>Domestic</option></select>';
   else echo '<input style="width:100%;min-width:75px" type="'.(in_array($k,['starts_at','ends_at'],true)?'date':(in_array($k,['template_id','season_term_id','upcoming_days','priority'],true)?'number':'text')).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'">';
   echo '</td>';
  }
  echo '</tr>';
 }
 echo '</tbody></table></div><p>Active and Upcoming can render different Elementor templates. Upcoming takeover means the next season can replace a current season during its configured upcoming-days window. An optional certified campaign may display financial claims only when Shared Product Core verifies that exact campaign and Brand Approval is required. The Fallback stays under certified Shared Product Core offers only. Countries use ISO 2-letter codes (e.g. EG). Unknown location never matches targeted rules. Traveler market and use case require trusted provider filters; URL parameters are ignored. Maximum 50 rules. Add further rows after saving existing ones.</p>';
 submit_button('Save seasonal templates');echo '</form></div>';
}
