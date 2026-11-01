<?php
defined('ABSPATH') || exit;
/**
 * MAD4B Reviews UI v1 — shared, accessible WordPress review surfaces.
 * Uses the original Role_Review inbox/preview/decide as the sole authority.
 * No invented records, approval, publishing or autonomous decisions.
 */
final class MAD4B_Hub_Review_UI {
 const VERSION='1.2.0';
 const INBOX='mad4b-hub-reviews-inbox';
 const MASTER='mad4b-hub-master-review';
 const REVISIONS='mad4b-hub-review-revisions';
 private static function eligible() {
  if(!is_user_logged_in() && !get_current_user_id())return false;
  if(!class_exists('MAD4B_SCP_Site_Profile') ||
     !MAD4B_SCP_Site_Profile::origin_enrolled() ||
     !MAD4B_SCP_Site_Profile::site_urls_match_enrollment())return false;
  $u=wp_get_current_user();
  return in_array('administrator',(array)$u->roles,true) && current_user_can('manage_options')
    || in_array('master_admin',(array)$u->roles,true) && current_user_can('mad4b_manage_company_operations')
    || current_user_can('edit_posts');
 }
 private static function master() {
  $u=wp_get_current_user();
  return (in_array('administrator',(array)$u->roles,true)&&current_user_can('manage_options'))
   || (in_array('master_admin',(array)$u->roles,true)&&current_user_can('mad4b_manage_company_operations'));
 }
 public static function menu() {
  if(!self::eligible())return;
  add_menu_page('Reviews Inbox','Reviews','read',self::INBOX,[__CLASS__,'inbox_page'],'dashicons-feedback',39);
  add_submenu_page(self::INBOX,'Reviews Inbox','Reviews Inbox','read',self::INBOX,[__CLASS__,'inbox_page']);
  add_submenu_page(self::INBOX,'Master Review','Master Review','read',self::MASTER,[__CLASS__,'master_page']);
  add_submenu_page(self::INBOX,'Revision Comparison','Revision Review','read',self::REVISIONS,[__CLASS__,'revisions_page']);
 }
 public static function assets($hook='') {
  if(!self::eligible())return;
  $page=isset($_GET['page'])?sanitize_key(wp_unslash($_GET['page'])):'';
  if(!in_array($page,[self::INBOX,self::MASTER,self::REVISIONS],true))return;
  $root=plugin_dir_url(dirname(__DIR__).'/mad4b-content-hub.php');
  wp_enqueue_style('mad4b-review-ui',$root.'assets/reviews.css',[],self::VERSION);
  wp_enqueue_script('mad4b-review-ui',$root.'assets/reviews.js',[],self::VERSION,true);
 }
 public static function boot() {
  add_action('admin_enqueue_scripts',[__CLASS__,'assets']);
 }
 private static function guard() {
  if(!self::eligible())wp_die(esc_html__('You do not have access to these reviews.','mad4b'),'', ['response'=>403]);
 }
 private static function operating_country_admin() {
  if(!current_user_can('manage_options'))return;
  if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_POST['rv_site_operating_country'])){
   check_admin_referer('mad4b_hub_review_country');
   $code=strtoupper(sanitize_text_field(wp_unslash($_POST['rv_site_operating_country'])));
   $choices=self::country_choices();
   if(!isset($choices[$code]))wp_die('Invalid site operating country.', '', ['response'=>400]);
   if($code==='')delete_option(MAD4B_Hub_Review_Language::COUNTRY_OPTION);
   else update_option(MAD4B_Hub_Review_Language::COUNTRY_OPTION,$code,false);
   wp_safe_redirect(self::route(self::INBOX),303);exit;
  }
 }
 private static function country_choices(){
  $countries=[''=>'Use saved WordPress store / site country'];
  if(class_exists('WC_Countries')){
   $wc=new WC_Countries();
   foreach((array)$wc->get_countries() as $code=>$label){
    if(is_string($code)&&preg_match('/^[A-Z]{2}$/D',$code))
     $countries[$code]=(string)$label;
   }
  }else{
   // Fallback when WooCommerce is not active; no inferred selection.
   foreach(['EG'=>'Egypt','SA'=>'Saudi Arabia','AE'=>'United Arab Emirates',
    'JO'=>'Jordan','KW'=>'Kuwait','QA'=>'Qatar','BH'=>'Bahrain','OM'=>'Oman',
    'MA'=>'Morocco','TN'=>'Tunisia','DZ'=>'Algeria','IQ'=>'Iraq',
    'LB'=>'Lebanon','LY'=>'Libya','SD'=>'Sudan','YE'=>'Yemen',
    'PS'=>'Palestine','SY'=>'Syria','MR'=>'Mauritania','SO'=>'Somalia',
    'DJ'=>'Djibouti','KM'=>'Comoros','US'=>'United States',
    'GB'=>'United Kingdom','FR'=>'France','DE'=>'Germany'] as $code=>$label)
      $countries[$code]=$label;
  }
  return $countries;
 }
 private static function render_country_setting(){
  if(!current_user_can('manage_options'))return;
  $state=MAD4B_Hub_Review_Language::status();
  $country=$state['operating_country'];
  $current=(string)get_option(MAD4B_Hub_Review_Language::COUNTRY_OPTION,'');
  $choices=self::country_choices();
  echo '<details class="mad4b-rv-country-settings"><summary>'
    .self::tr('Review-language policy','سياسة لغات المراجعة')
    .' · '.self::h($country?:'Country not configured').' · '
    .self::h($state['bilingual']?'Arabic + English':'Original language')
    .'</summary>';
  echo '<p>'.self::tr(
   'Uses the explicitly configured site operating country, otherwise the saved WordPress/WooCommerce country. Never inferred from visitor location.',
   'تعتمد على دولة تشغيل الموقع المسجلة، ثم دولة المتجر المحفوظة. لا تُستنتج من موقع الزائر.')
   .'</p>';
  echo '<form method="post">';
  wp_nonce_field('mad4b_hub_review_country');
  echo '<label for="mad4b-rv-country">'.self::tr('Operating country','دولة تشغيل الموقع').'</label>';
  echo '<select id="mad4b-rv-country" name="rv_site_operating_country">';
  foreach($choices as $code=>$name)
    echo '<option value="'.esc_attr($code).'"'.selected($current,(string)$code,false).'>'
      .self::h($name).'</option>';
  echo '</select> <button class="button button-secondary" type="submit">'
    .self::tr('Save country','حفظ الدولة').'</button></form></details>';
 }
 private static function route($page,$args=[]) {
  return add_query_arg(array_merge(['page'=>$page],$args),admin_url('admin.php'));
 }
 private static function h($txt){return esc_html((string)$txt);}
 private static function ui_label($value){
  return wp_kses((string)$value,[
   'span'=>['lang'=>true,'dir'=>true,'class'=>true,'aria-hidden'=>true]
  ]);
 }
 private static function label($status) {
  return [
   'AWAITING_MASTER_REVIEW'=>'Awaiting review',
   'approve'=>'Editorial approved',
   'needs_changes'=>'Changes requested',
   'reject'=>'Rejected'
  ][$status]??'Review status unknown';
 }
 private static function cls($status) {
  return [
   'AWAITING_MASTER_REVIEW'=>'pending','approve'=>'approved',
   'needs_changes'=>'changes','reject'=>'rejected'
  ][$status]??'pending';
 }
 private static function badge($status) {
  $ar=[
   'AWAITING_MASTER_REVIEW'=>'بانتظار المراجعة',
   'approve'=>'معتمد تحريرياً',
   'needs_changes'=>'مطلوب تعديل',
   'reject'=>'مرفوض'
  ][$status]??self::label($status);
  return '<span class="mad4b-rv-badge is-'.esc_attr(self::cls($status)).'">'
    .self::tr(self::label($status),$ar).'</span>';
 }
 private static function nav($active) {
  echo '<nav class="mad4b-rv-tabs" aria-label="Review pages">';
  foreach([self::INBOX=>['Reviews Inbox','صندوق المراجعات'],self::MASTER=>['Master Review','المراجعة الرئيسية'],self::REVISIONS=>['Revision Comparison','مقارنة النسخ']] as $slug=>$names){
   $name=self::tr($names[0],$names[1]);
   echo '<a '.($active===$slug?'aria-current="page" ':'').'href="'.esc_url(self::route($slug)).'">'.$name.'</a>';
  }
  echo '</nav>';
 }
 private static function header($title,$description,$active,$count='') {
  echo '<div class="wrap mad4b-reviews"><div class="mad4b-rv-heading">';
  echo '<div><div class="mad4b-rv-kicker">MAD4B <span>Content Hub</span></div><h1>'.self::ui_label($title).'</h1><p>'.self::ui_label($description).'</p></div>';
  echo '<div class="mad4b-rv-topmeta"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span> Editorial decisions only'.($count!==''?' · '.self::h($count).' loaded':'').'</div></div>';
  self::nav($active);
 }
 private static function end(){echo '</div>';}
 private static function inbox_data() {
  $data=MAD4B_Hub_Role_Review::inbox();
  if(is_wp_error($data)){echo '<div class="notice notice-error"><p>'.self::h($data->get_error_message()).'</p></div>';return null;}
  return $data;
 }
 private static function clean_items($items) {
  return array_values(array_filter((array)$items,static fn($item)=>is_array($item)&&!empty($item['batch_id'])));
 }
 private static function item_meta($item,$key,$default='') {
  return isset($item[$key]) && (is_scalar($item[$key])) ? (string)$item[$key] : $default;
 }
 public static function inbox_page() {
  self::guard();
  self::operating_country_admin();
  $data=self::inbox_data();if(!$data)return;
  $items=self::clean_items($data['items']);
  self::header(self::tr('Reviews Inbox','صندوق المراجعات'),self::tr(
   'Find and review AI-generated content candidates.','عرض المسودات ومراجعتها واتخاذ القرار المناسب.'),self::INBOX,count($items));
  self::render_country_setting();
  $counts=['AWAITING_MASTER_REVIEW'=>0,'needs_changes'=>0,'approve'=>0,'reject'=>0];
  $total=0;$durations=[];
  foreach($items as $i){
   $status=$i['review_decision']??'AWAITING_MASTER_REVIEW';
   if(isset($counts[$status]))$counts[$status]++;
   $total++;
   if(!empty($i['reviewed_at'])&&!empty($i['updated_at'])){
     $diff=strtotime($i['reviewed_at'])-strtotime($i['updated_at']);
     if($diff>=0)$durations[]=$diff;
   }
  }
  echo '<section class="mad4b-rv-metrics" aria-label="Review totals">';
  foreach([
   ['AWAITING_MASTER_REVIEW','Pending review','بانتظار المراجعة','dashicons-clock','pending'],
   ['needs_changes','Changes requested','مطلوب تعديل','dashicons-edit','changes'],
   ['approve','Editorial approved','اعتماد تحريري','dashicons-yes-alt','approved'],
   ['reject','Rejected','مرفوض','dashicons-dismiss','rejected']
  ] as $card){
   echo '<div class="mad4b-rv-metric is-'.esc_attr($card[4]).'"><span class="dashicons '.esc_attr($card[3]).'"></span><div><strong>'.(int)$counts[$card[0]].'</strong><span>'.self::tr($card[1],$card[2]).'</span></div></div>';
  }
  $avg=count($durations)?number_format_i18n(array_sum($durations)/count($durations)/3600,1).' h':'—';
  echo '<div class="mad4b-rv-metric is-neutral"><span class="dashicons dashicons-clock"></span><div><strong>'.self::h($avg).'</strong><span>'.self::tr('Observed review time','وقت المراجعة المسجل').'</span></div></div>';
  echo '</section><p class="mad4b-rv-data-note">Totals reflect the latest eligible candidates loaded from the current site, not historical or network-wide totals. Editorial approval does not imply publication.</p>';
  $search=isset($_GET['rv_search'])?sanitize_text_field(wp_unslash($_GET['rv_search'])):'';
  $status=isset($_GET['rv_status'])?sanitize_key(wp_unslash($_GET['rv_status'])):'all';
  $language=isset($_GET['rv_language'])?sanitize_text_field(wp_unslash($_GET['rv_language'])):'all';
  $type=isset($_GET['rv_type'])?sanitize_text_field(wp_unslash($_GET['rv_type'])):'all';
  $sort=isset($_GET['rv_sort'])?sanitize_key(wp_unslash($_GET['rv_sort'])):'recent';
  $languages=[];$types=[];
  foreach($items as $i){
   if(!empty($i['language']))$languages[(string)$i['language']]=true;
   if(!empty($i['task_type']))$types[(string)$i['task_type']]=true;
  }
  echo '<form method="get" class="mad4b-rv-filters" aria-label="Filter reviews"><input type="hidden" name="page" value="'.esc_attr(self::INBOX).'">';
  echo '<label class="mad4b-rv-filtersearch"><span class="dashicons dashicons-search" aria-hidden="true"></span><span class="screen-reader-text">Search candidates</span><input type="search" name="rv_search" value="'.esc_attr($search).'" placeholder="Search title, summary or job"></label>';
  self::selector('rv_status','Review status',['all'=>'All statuses','AWAITING_MASTER_REVIEW'=>'Pending review','approve'=>'Editorial approved','needs_changes'=>'Changes requested','reject'=>'Rejected'],$status);
  self::selector('rv_language','Language',array_merge(['all'=>'All languages'],array_combine(array_keys($languages),array_keys($languages))),$language);
  self::selector('rv_type','Content type',array_merge(['all'=>'All types'],array_combine(array_keys($types),array_keys($types))),$type);
  self::selector('rv_sort','Sort',['recent'=>'Newest first','oldest'=>'Oldest first','title'=>'Title A–Z'],$sort);
  echo '<button type="submit" class="button button-primary">'.self::tr('Apply','تطبيق').'</button><a class="mad4b-rv-clear" href="'.esc_url(self::route(self::INBOX)).'">'.self::tr('Clear','إلغاء الفلاتر').'</a></form>';
  $matches=array_values(array_filter($items,static function($i)use($search,$status,$language,$type){
   if($status!=='all'&&($i['review_decision']??'')!==$status)return false;
   if($language!=='all'&&($i['language']??'')!==$language)return false;
   if($type!=='all'&&($i['task_type']??'')!==$type)return false;
   if($search!==''&&!str_contains(mb_strtolower(($i['title']??'').' '.($i['summary']??'').' '.($i['job_subject']??'')),mb_strtolower($search)))return false;
   return true;
  }));
  usort($matches,static function($a,$b)use($sort){
   if($sort==='title')return strcasecmp((string)($a['title']??''),(string)($b['title']??''));
   $cmp=strcmp((string)($a['updated_at']??''),(string)($b['updated_at']??''));
   return $sort==='oldest'?$cmp:-$cmp;
  });
  echo '<section class="mad4b-rv-list"><div class="mad4b-rv-listhead"><h2>'.self::tr('Review candidates','المسودات المعروضة').'</h2><span>'.count($matches).' / '.count($items).'</span></div>';
  if(!$matches){self::empty_state('No matching reviews','Adjust the filters or check back when new source-grounded drafts are ready.');}
  foreach($matches as $i){
   $decision=(string)($i['review_decision']??'AWAITING_MASTER_REVIEW');
   $link=self::route(self::MASTER,['batch_id'=>$i['batch_id']]);
   echo '<article class="mad4b-rv-item"><div class="mad4b-rv-fileicon"><span class="dashicons dashicons-media-document" aria-hidden="true"></span></div>';
   echo '<div class="mad4b-rv-item-main"><h3><a href="'.esc_url($link).'">'.self::h($i['title']??'Untitled candidate').'</a></h3>';
   echo '<p>'.self::h(wp_trim_words((string)($i['summary']??$i['job_subject']??''),30,'…')).'</p>';
   $short_card=MAD4B_Hub_Review_Card_Policy::build(['output'=>[
   'task_type'=>$i['task_type']??'content','summary'=>$i['summary']??'']]);
  echo '<div class="mad4b-rv-item-question">'.self::tr($short_card['question']['en'],$short_card['question']['ar']).'</div>';
  echo '<div class="mad4b-rv-chips"><span>'.self::h($i['task_type']??'Content').'</span><span>'.self::h($i['language']??'Not specified').'</span><span>'.(int)($i['evidence_count']??0).' evidence references</span></div></div>';
   echo '<div class="mad4b-rv-item-meta"><span class="mad4b-rv-mini-label">Confidence</span>'.self::confidence($i['confidence']??null).'<span class="mad4b-rv-mini-label">Updated</span><span>'.self::date($i['updated_at']??'').'</span></div>';
   echo '<div class="mad4b-rv-item-action">'.self::badge($decision).'<a class="button" href="'.esc_url($link).'">'.self::tr('Open review','فتح المراجعة').'</a></div></article>';
  }
  echo '</section>';
  self::end();
 }
 private static function selector($name,$label,$options,$selected) {
  echo '<label class="mad4b-rv-select"><span>'.self::h($label).'</span><select name="'.esc_attr($name).'">';
  foreach($options as $value=>$text)echo '<option value="'.esc_attr($value).'"'.selected((string)$selected,(string)$value,false).'>'.self::h($text).'</option>';
  echo '</select></label>';
 }
 private static function date($date) {
  $time=strtotime((string)$date);
  return $time?wp_date('M j, Y · H:i',$time):'Not recorded';
 }
 private static function confidence($value) {
  if(!is_numeric($value))return '<span>Not assessed</span>';
  $percent=max(0,min(100,(float)$value<=1?round(100*(float)$value):round((float)$value)));
  $band=$percent>=80?'high':($percent>=50?'medium':'low');
  return '<div class="mad4b-rv-conf"><span>'.(int)$percent.'% <small>Model estimate</small></span><div role="progressbar" aria-label="Model-reported confidence" aria-valuemin="0" aria-valuemax="100" aria-valuenow="'.(int)$percent.'"><span class="is-'.esc_attr($band).'" style="width:'.(int)$percent.'%"></span></div></div>';
 }
 private static function empty_state($title,$description) {
  echo '<div class="mad4b-rv-empty"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><h3>'.self::h($title).'</h3><p>'.self::h($description).'</p></div>';
 }
 private static function data_for($batch) {
  $data=MAD4B_Hub_Role_Review::preview(['batch_id'=>$batch]);
  return $data;
 }
 public static function master_page(){
  self::guard();$inbox=self::inbox_data();if(!$inbox)return;
  $items=self::clean_items($inbox['items']);
  $batch=isset($_GET['batch_id'])?sanitize_text_field(wp_unslash($_GET['batch_id'])):'';
  if($batch==='' && $items)$batch=(string)$items[0]['batch_id'];
  $notice='';$failed=false;
  if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_POST['rv_media_save'])){
   check_admin_referer('mad4b_hub_review_media_save');
   $result=MAD4B_Hub_Review_Gallery::save_metadata(wp_unslash($_POST));
   $failed=is_wp_error($result);
   $notice=$failed?($result->get_error_message().' ('.$result->get_error_code().')'):
     'Shared WordPress image metadata saved. Company SEO was not changed.';
   if(!$failed){
    wp_safe_redirect(add_query_arg(['page'=>self::MASTER,'batch_id'=>$batch,'rv_media_saved'=>'1'],admin_url('admin.php')),303);
    exit;
   }
  }
  if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_POST['review_decision'])){
   check_admin_referer('mad4b_hub_editorial_review');
   $decision=sanitize_key(wp_unslash($_POST['review_decision']??''));
   $batch=sanitize_text_field(wp_unslash($_POST['batch_id']??''));
   if($decision==='approve' && empty($_POST['rv_ack_evidence'])){
    $notice='Review the evidence and limitations, then acknowledge them before approving.';$failed=true;
   }else{
    $result=MAD4B_Hub_Role_Review::decide([
     'batch_id'=>$batch,'expected_output_sha256'=>sanitize_text_field(wp_unslash($_POST['expected_output_sha256']??'')),
     'decision'=>$decision,'note'=>sanitize_textarea_field(wp_unslash($_POST['review_note']??'')),
     'expected_media_package_sha256'=>sanitize_text_field(wp_unslash($_POST['expected_media_package_sha256']??'')),
     'wp_nonce'=>sanitize_text_field(wp_unslash($_POST['_wpnonce']??''))]);
    $failed=is_wp_error($result);
    $notice=$failed?($result->get_error_message().' ('.$result->get_error_code().')'):'Editorial decision recorded. No content was published.';
   }
   // PRG for successful mutations prevents duplicate review on refresh.
   if(!$failed){
    wp_safe_redirect(add_query_arg(['page'=>self::MASTER,'batch_id'=>$batch,'rv_saved'=>'1'],admin_url('admin.php')),303);
    exit;
   }
  }
  self::header(self::tr('Master Review','المراجعة الرئيسية'),self::tr(
   'Choose one clear editorial decision. Details and evidence are available below.',
   'حدد قراراً تحريرياً واضحاً. يمكنك الاطلاع على التفاصيل والأدلة بالأسفل.'),self::MASTER);
  if($notice)echo '<div class="notice '.($failed?'notice-error':'notice-success').'"><p>'.self::h($notice).'</p></div>';
  // Do not show a success alert solely because a URL parameter was supplied.
  elseif(!empty($_GET['rv_saved'])) {
   $verified=MAD4B_Hub_Role_Review::preview(['batch_id'=>$batch]);
   if(!is_wp_error($verified)&&!empty($verified['review']))
    echo '<div class="notice notice-success"><p>Editorial decision saved. Publishing remains a separate workflow.</p></div>';
  }
  if(!$items){self::empty_state('No candidates ready for review','The Content Hub will show drafts here after they pass the formal output contract.');self::end();return;}
  $selected=null;
  foreach($items as $item)if($item['batch_id']===$batch){$selected=$item;break;}
  if(!$selected){self::empty_state('Review not available','The candidate is missing, changed or not visible to this account.');self::end();return;}
  $data=self::data_for($batch);
  if(is_wp_error($data)){self::empty_state('Review needs refresh',$data->get_error_message());self::end();return;}
  echo '<form method="get" class="mad4b-rv-switcher" aria-label="Choose candidate"><input type="hidden" name="page" value="'.esc_attr(self::MASTER).'"><label for="mad4b-rv-batch">Candidate</label><select id="mad4b-rv-batch" name="batch_id">';
  foreach($items as $i)echo '<option value="'.esc_attr($i['batch_id']).'"'.selected($batch,$i['batch_id'],false).'>'.self::h(mb_strimwidth((string)$i['title'],0,90,'…')).' · '.self::h(self::label($i['review_decision'])).'</option>';
  echo '</select><button class="button">Open</button></form>';
  self::detail($data,$selected);
  self::end();
 }
 private static function tr($english,$arabic){
  return MAD4B_Hub_Review_Language::label($english,$arabic);
 }
 /** Render untrusted source text as structured, fully escaped editorial HTML. */
 private static function structured_text($input,$language){
  $text=trim(str_replace(["\r\n","\r"],"\n",(string)$input));
  if($text==='')return '<p class="mad4b-rv-muted">'.self::tr('No content available','لا يوجد محتوى متاح').'</p>';
  $sections=preg_split('/\n{2,}/u',$text);
  $blocks=[];
  foreach((array)$sections as $section){
   $lines=explode("\n",trim($section));$pending='';
   foreach($lines as $line){
    $line=trim($line);
    if($line==='')continue;
    if(preg_match('/^#{1,4}\s+(.+)$/u',$line,$m)){
     if($pending!==''){$blocks[]=self::paragraphs($pending);$pending='';}
     $blocks[]='<h4>'.self::h($m[1]).'</h4>';
    }else{$pending.=($pending===''?'':' ').$line;}
   }
   if($pending!=='')$blocks[]=self::paragraphs($pending);
  }
  return implode("\n",array_filter($blocks));
 }
 private static function paragraphs($text){
  $text=trim((string)$text);
  if(mb_strlen($text)<=370)return '<p>'.self::h($text).'</p>';
  $sentences=preg_split('/(?<=[.!?؟])\s+(?=[\p{L}\p{N}])/u',$text,-1,PREG_SPLIT_NO_EMPTY);
  if(!is_array($sentences)||count($sentences)<2)return '<p>'.self::h($text).'</p>';
  $html='';$buffer='';
  foreach($sentences as $sentence){
   $sentence=trim($sentence);
   if($buffer!==''&&mb_strlen($buffer.' '.$sentence)>310){
    $html.='<p>'.self::h($buffer).'</p>';
    $buffer='';
   }
   $buffer.=($buffer===''?'':' ').$sentence;
  }
  if($buffer!=='')$html.='<p>'.self::h($buffer).'</p>';
  return $html;
 }
 private static function language_content($data,$language){
  $o=$data['output'];
  $original=strtolower(substr((string)($o['language']??'en'),0,2));
  if($original===$language)return ['title'=>$o['title']??'',
    'summary'=>$o['summary']??'','body'=>$o['body']??'',
    'limitations'=>$o['limitations']??[],'original'=>true,'verified'=>false];
  $saved=MAD4B_Hub_Review_Language::translations(
   (string)$data['batch_id'],(string)$data['output_sha256']);
  if(!isset($saved[$language]))return null;
  return array_merge($saved[$language],['original'=>false,'verified'=>false]);
 }
 private static function reader_panel($data,$language){
  $lang=$language==='ar'?'العربية':'English';
  $dir=$language==='ar'?'rtl':'ltr';
  $content=self::language_content($data,$language);
  echo '<section class="mad4b-rv-locale-panel" lang="'.esc_attr($language).'" dir="'.esc_attr($dir).'">';
  echo '<div class="mad4b-rv-locale-head"><h4>'.self::h($lang).'</h4>';
  if(!$content){
   echo '<span class="mad4b-rv-translation-state is-missing">'.($language==='ar'?'الترجمة العربية مطلوبة':'English translation needed').'</span></div>';
   echo '<p class="mad4b-rv-translation-empty">'.($language==='ar'?
     'النص غير مترجم بعد. يمكن مراجعته باللغة الأصلية، لكن لا يُعتبر معتمدًا باللغة العربية.':
     'A source-bound English translation is not available. Review the original text; do not treat this as an approved English version.').'</p>';
   echo '</section>';return;
  }
  echo '<span class="mad4b-rv-translation-state">'.($content['original']
    ?($language==='ar'?'النص الأصلي':'Original text')
    :($language==='ar'?'ترجمة مرشحة للمراجعة':'Translation awaiting review')).'</span></div>';
  echo '<div class="mad4b-rv-locale-body"><h4>'.self::h((string)$content['title']).'</h4>';
  if(!empty($content['summary']))echo '<div class="mad4b-rv-abstract"><strong>'.($language==='ar'?'الملخص':'Summary').'</strong><p>'.self::h($content['summary']).'</p></div>';
  echo '<div class="mad4b-rv-prose">'.self::structured_text($content['body']??'',$language).'</div></div>';
  if(!empty($content['limitations'])){
   echo '<details class="mad4b-rv-inline-disclosure"><summary>'.($language==='ar'?'القيود والمعلومات غير المتحققة':'Limitations & unverified information').'</summary><ul>';
   foreach((array)$content['limitations'] as $line)if(is_scalar($line))echo '<li>'.self::h($line).'</li>';
   echo '</ul></details>';
  }
  echo '</section>';
 }
 private static function quick_review_card($data){
  $card=MAD4B_Hub_Review_Card_Policy::build($data);
  $bilingual=MAD4B_Hub_Review_Language::bilingual();
  $recorded=!empty($data['review']) && is_array($data['review']);
  echo '<section class="mad4b-rv-request'.($recorded?' is-reviewed':'').'" aria-label="Review decision summary">';
  echo '<div class="mad4b-rv-request-head"><span class="mad4b-rv-step">'.($recorded?'✓':'01').'</span><div><small>'.($recorded?self::tr('Editorial decision recorded','تم تسجيل القرار التحريري'):self::tr('Your review task','المطلوب منك')).'</small>';
  echo '<h3>'.self::tr($card['question']['en'],$card['question']['ar']).'</h3></div></div>';
  echo '<p class="mad4b-rv-request-lead">'.self::h($card['short_summary']).'</p>';
  echo '<ul class="mad4b-rv-signal-list">';
  foreach($card['points'] as $point){
   echo '<li class="'.($point['ok']?'is-positive':'is-neutral').'"><span class="dashicons '.($point['ok']?'dashicons-yes-alt':'dashicons-info-outline').'" aria-hidden="true"></span><span>'.self::tr($point['en'],$point['ar']).'</span></li>';
  }
  echo '</ul>';
  if($recorded){
   if($bilingual)echo '<p class="mad4b-rv-request-hint" lang="ar" dir="rtl">تم تسجيل قرار المراجع. تظل مراجعة الحقائق والنشر خطوات مستقلة.</p>';
   echo '<p class="mad4b-rv-request-hint" lang="en" dir="ltr">Review decision recorded. Fact verification and publishing remain separate.</p>';
  }else{
   if($bilingual)echo '<p class="mad4b-rv-request-hint" lang="ar" dir="rtl">اختَر القرار، ثم استخدم سببًا مختصرًا. اعتماد التحرير لا يعني النشر.</p>';
   echo '<p class="mad4b-rv-request-hint" lang="en" dir="ltr">Choose a decision and a short reason. Editorial approval never publishes.</p>';
  }
  echo '</section>';
 }
 private static function detail($data,$item) {
  $o=$data['output'];$review=$data['review'];$decision=$review['decision']??'AWAITING_MASTER_REVIEW';
  $language=(string)($o['language']??'');
  $kind=(string)($o['task_type']??'Content');
  echo '<section class="mad4b-rv-hero"><div class="mad4b-rv-bigicon"><span class="dashicons dashicons-welcome-write-blog"></span></div><div class="mad4b-rv-hero-text">';
  echo '<span class="mad4b-rv-kicker">Research &amp; editorial review</span><h2>'.self::h($o['title']??'Untitled').'</h2><p>'.self::h($o['summary']??'Review the candidate against the source evidence.').'</p></div>';
  echo self::badge($decision).'</section>';
  echo '<section class="mad4b-rv-detailsstrip" aria-label="Candidate metadata">';
  foreach([
   ['Job type','نوع المهمة',$kind,'dashicons-category'],
   ['Language','اللغة',$language?:'Not specified','dashicons-translation'],
   ['Evidence refs','المراجع',(string)count((array)($o['evidence_refs']??[])),'dashicons-database'],
   ['Publication','النشر','Not published','dashicons-lock'],
   ['Review','المراجعة',self::label($decision),'dashicons-yes-alt']
  ] as $m){
   echo '<div class="mad4b-rv-detailchip"><span class="dashicons '.esc_attr($m[3]).'"></span><div><small>'.self::tr($m[0],$m[1]).'</small><strong>'.self::h($m[2]).'</strong></div></div>';
  }
  echo '</section>';
  self::quick_review_card($data);
  echo MAD4B_Hub_Review_SVG::render($data,MAD4B_Hub_Review_Language::bilingual());
  MAD4B_Hub_Review_Gallery::render($data);
  MAD4B_Hub_Review_Frontend_Preview::render($data);
  if(!$review)self::decision_form($data);
  echo '<div class="mad4b-rv-layout"><div class="mad4b-rv-main">';
  echo '<details class="mad4b-rv-panel mad4b-rv-document" id="rv-full-draft">';
  echo '<summary><span class="dashicons dashicons-media-text" aria-hidden="true"></span> '.self::tr('Read full AI-generated draft','قراءة المسودة بالكامل').'</summary>';
  echo '<div class="mad4b-rv-languages">';
  if(MAD4B_Hub_Review_Language::bilingual()){
   self::reader_panel($data,'ar');self::reader_panel($data,'en');
  }else{
   $native=strtolower(substr((string)($o['language']??'en'),0,2));
   self::reader_panel($data,$native==='ar'?'ar':'en');
  }
  echo '</div></details>';
  if(!empty($o['limitations'])){
   echo '<section class="mad4b-rv-panel is-warning" id="rv-evidence"><h3><span class="dashicons dashicons-warning" aria-hidden="true"></span> '.self::tr('Limitations & unverified claims','القيود والمعلومات غير المتحققة').'</h3><ul>';
   foreach((array)$o['limitations'] as $l)echo '<li>'.self::h($l).'</li>';
   echo '</ul></section>';
  }
  self::panel_start(self::tr('Evidence and sources','الأدلة والمصادر'),'dashicons-database');
  echo '<p class="mad4b-rv-secondary">References supplied with the AI output. Their presence does not independently verify factual claims.</p>';
  self::pills($o['evidence_refs']??[],'No evidence references recorded.');
  if(!empty($o['source_coverage'])){echo '<h4>Source coverage</h4>';self::text_list($o['source_coverage']);}
  if(!empty($o['open_questions'])){echo '<h4>Open questions</h4>';self::text_list($o['open_questions']);}
  self::panel_end();
  if($review){
   $review_package=MAD4B_Hub_Review_Gallery::package($data);
   $approved_hash=(string)($review['media_package_sha256']??'');
   $binding_current=$approved_hash!==''&&hash_equals($approved_hash,$review_package['sha256']);
   self::panel_start('Recorded editorial decision','dashicons-clipboard');
   if(!$binding_current)echo '<p class="mad4b-rv-secondary"><strong>Media package not approved in this recorded decision.</strong> Images may have changed, or this is a legacy editorial review. Publication must revalidate media independently.</p>';
   echo '<p>'.self::badge($decision).' <span class="mad4b-rv-muted">'.self::h(self::date($review['reviewed_at']??'')).'</span></p>';
   if(isset($review['review_note']))echo '<blockquote>'.self::h($review['review_note']).'</blockquote>';
   echo '<p class="mad4b-rv-secondary">This decision does not publish or distribute content.</p>';
   self::panel_end();
  }
  echo '</div><aside class="mad4b-rv-aside">';
  self::panel_start(self::tr('Review details','تفاصيل المراجعة'),'dashicons-id');
  self::kv('Content Job',$data['job_subject']??'');
  if(!empty($data['source_url']) && in_array(strtolower((string)wp_parse_url($data['source_url'],PHP_URL_SCHEME)),['http','https'],true) && wp_parse_url($data['source_url'],PHP_URL_HOST)){
   echo '<div class="mad4b-rv-kv"><span>Source</span><strong><a href="'.esc_url($data['source_url']).'" target="_blank" rel="noopener noreferrer">Open original source <span class="dashicons dashicons-external" aria-hidden="true"></span></a></strong></div>';
  }
  self::kv('Batch ID',$data['batch_id']??'');
  self::kv('Language',$language?:'Not specified');
  self::kv('Confidence',is_numeric($o['confidence']??null)?round((float)$o['confidence']*100).'% (model estimate)':'Not assessed');
  self::kv('Output contract',$o['contract']??'');
  self::kv('Model output',!empty($data['format_pass'])?'Format QA passed':'Not verified');
  self::kv('Independent fact check',!empty($data['facts_verified'])?'Verified':'Not completed');
  self::kv('Publication','Not authorized');
  self::panel_end();
  self::panel_start(self::tr('Review checklist','قائمة تحقق المراجعة'),'dashicons-yes-alt');
  $evidence=!empty($o['evidence_refs']);
  foreach([
   ['Output passed format QA',!empty($data['format_pass'])],
   ['Evidence references attached',$evidence],
   ['Limitations documented',!empty($o['limitations'])],
   ['Independent facts verified',!empty($data['facts_verified'])],
   ['Commercial prices and availability verified',false],
   ['Publishing approved',false]
  ] as $row){
   echo '<div class="mad4b-rv-check '.($row[1]?'is-checked':'').'"><span class="dashicons '.($row[1]?'dashicons-yes-alt':'dashicons-marker').'" aria-hidden="true"></span>'.self::h($row[0]).'</div>';
  }
  self::panel_end();
  echo '<div class="mad4b-rv-aside-actions"><a class="button" href="'.esc_url(self::route(self::REVISIONS,['batch_id'=>$data['batch_id']])).'">Compare real revisions</a><a class="button" href="'.esc_url(self::route(self::INBOX)).'">Back to inbox</a></div>';
  echo '</aside></div>';
 }
 private static function panel_start($heading,$icon) {echo '<section class="mad4b-rv-panel"><h3><span class="dashicons '.esc_attr($icon).'" aria-hidden="true"></span>'.self::ui_label($heading).'</h3>';}
 private static function panel_end(){echo '</section>';}
 private static function kv($a,$b){echo '<div class="mad4b-rv-kv"><span>'.self::h($a).'</span><strong>'.self::h($b).'</strong></div>';}
 private static function pills($items,$empty=''){
  if(!is_array($items)||!$items){echo '<p class="mad4b-rv-secondary">'.self::h($empty).'</p>';return;}
  echo '<div class="mad4b-rv-chips">';
  foreach($items as $v)if(is_scalar($v))echo '<span>'.self::h($v).'</span>';
  echo '</div>';
 }
 private static function text_list($items){
  echo '<ul class="mad4b-rv-textlist">';
  foreach((array)$items as $item)if(is_scalar($item))echo '<li>'.self::h($item).'</li>';
  echo '</ul>';
 }
 private static function decision_form($data){
  if(!self::master()){
   self::panel_start('Decision requires a reviewer','dashicons-lock');
   echo '<p>Only a site-authorized Master Admin or Administrator may record an editorial decision. You can inspect drafts within your own access scope.</p>';self::panel_end();return;
  }
  echo '<section class="mad4b-rv-panel mad4b-rv-decision"><h3><span class="dashicons dashicons-edit" aria-hidden="true"></span> '.self::tr('Your editorial decision','قرارك التحريري').'</h3>';
  echo '<p class="mad4b-rv-secondary">'.self::tr('Select a short reason, adjust if needed, and choose one decision.','اختر سبباً مختصراً وعدّله إن لزم، ثم حدد قراراً واحداً.').'</p>';
  echo '<form method="post" data-mad4b-review-form>';wp_nonce_field('mad4b_hub_editorial_review');
  echo '<input type="hidden" name="batch_id" value="'.esc_attr($data['batch_id']).'"><input type="hidden" name="expected_output_sha256" value="'.esc_attr($data['output_sha256']).'">';
  $media_package=MAD4B_Hub_Review_Gallery::package($data);
  echo '<input type="hidden" name="expected_media_package_sha256" value="'.esc_attr($media_package['sha256']).'">';
  echo '<p class="mad4b-rv-suggest-head">'.self::tr('Quick reasons — one tap to fill your note','أسباب سريعة — اضغط لملء الملاحظة').'</p>';
  echo '<div class="mad4b-rv-reason-presets">';
  foreach([
   [
    'ar'=>'الصياغة مناسبة تحريرياً مع إبقاء القيود واضحة',
    'en'=>'Editorial structure is acceptable with limitations retained.',
    'note'=>'Editorial structure is acceptable with stated limitations visible.'
   ],
   [
    'ar'=>'يحتاج إلى أدلة وتحقق إضافي من الحقائق',
    'en'=>'More source evidence and fact-checking needed.',
    'note'=>'Please add source evidence and independently verify factual claims.'
   ],
   [
    'ar'=>'المصدر لا يدعم الادعاءات الأساسية',
    'en'=>'Source does not support essential claims.',
    'note'=>'The available source does not support the central claims; revise or reject.'
   ]
  ] as $preset){
   echo '<button type="button" class="button mad4b-rv-reason" data-mad4b-reason="'.esc_attr(
     MAD4B_Hub_Review_Language::bilingual()?$preset['ar']:$preset['note']).'">'.self::tr($preset['en'],$preset['ar']).'</button>';
  }
  echo '</div>';
  echo '<label for="mad4b-rv-note"><strong>'.self::tr('Short review note','ملاحظة المراجع المختصرة').' <span class="mad4b-rv-required">'.self::tr('required','مطلوبة').'</span></strong></label>';
  echo '<textarea id="mad4b-rv-note" name="review_note" required minlength="8" maxlength="500" rows="3" placeholder="Reason for the decision / سبب القرار" aria-describedby="mad4b-rv-note-help"></textarea>';
  echo '<p id="mad4b-rv-note-help" class="mad4b-rv-secondary">'.self::tr('8–500 characters; saved with the reviewer identity.','من 8 إلى 500 حرف وتُحفظ بهوية المراجع.').'</p>';
  echo '<label class="mad4b-rv-ack"><input type="checkbox" name="rv_ack_evidence" value="1"><span>'.self::tr('I reviewed the evidence and limitations. Editorial approval does not publish or verify commercial facts.','راجعت الأدلة والقيود. الاعتماد التحريري لا يعني النشر أو إثبات الحقائق التجارية.').'</span></label>';
  echo '<div class="mad4b-rv-actions"><button type="submit" class="button button-primary" name="review_decision" value="approve" data-rv-approve><span class="dashicons dashicons-yes"></span> '.self::tr('Approve editorial','اعتماد تحريري').'</button>';
  echo '<button type="submit" class="button" name="review_decision" value="needs_changes"><span class="dashicons dashicons-edit"></span> '.self::tr('Request changes','طلب تعديل').'</button>';
  echo '<button type="submit" class="button is-danger" name="review_decision" value="reject"><span class="dashicons dashicons-no"></span> '.self::tr('Reject','رفض').'</button></div></form></section>';
 }
 public static function revisions_page(){
  self::guard();$d=self::inbox_data();if(!$d)return;
  $items=self::clean_items($d['items']);
  $batch=isset($_GET['batch_id'])?sanitize_text_field(wp_unslash($_GET['batch_id'])):'';
  if($batch===''&&$items)$batch=(string)$items[0]['batch_id'];
  self::header(self::tr('Revision Review','مراجعة الإصدارات'),self::tr(
   'Compare real versions and reviewer feedback.','قارن النسخ الحقيقية وملاحظات المراجعين.'),self::REVISIONS);
  $selected=null;foreach($items as $i)if($i['batch_id']===$batch){$selected=$i;break;}
  if(!$selected){self::empty_state('No revision selected','Select an accessible candidate from the Reviews Inbox.');self::end();return;}
  $related=array_values(array_filter($items,static fn($i)=>($i['job_id']??'')===$selected['job_id']));
  usort($related,static fn($a,$b)=>strcmp((string)($a['updated_at']??''),(string)($b['updated_at']??'')));
  echo '<section class="mad4b-rv-hero"><div class="mad4b-rv-bigicon"><span class="dashicons dashicons-backup"></span></div><div class="mad4b-rv-hero-text"><span class="mad4b-rv-kicker">Version history</span><h2>'.self::h($selected['title']??'Candidate').'</h2><p>'.count($related).' accessible version(s) associated with this Content Job.</p></div>'.self::badge($selected['review_decision']).'</section>';
  if(count($related)<2){self::empty_state('No earlier revision available','Only one eligible draft is recorded for this Content Job. Side-by-side comparison will appear after a genuine revised output is saved.');}
  else{
   $index=0;foreach($related as $k=>$v)if($v['batch_id']===$batch){$index=$k;break;}
   $newKey=$index===0?1:$index;
   $oldKey=$newKey-1;
   $older=self::data_for($related[$oldKey]['batch_id']);
   $newer=self::data_for($related[$newKey]['batch_id']);
   if(is_wp_error($older)||is_wp_error($newer)){self::empty_state('Comparison unavailable','An earlier revision is no longer accessible or its evidence binding changed.');}
   else{
    echo '<div class="mad4b-rv-compare-heading"><h3>Actual draft comparison</h3><div><a class="button" href="'.esc_url(self::route(self::MASTER,['batch_id'=>$related[$newKey]['batch_id']])).'">Review current draft</a></div></div>';
    echo '<div class="mad4b-rv-compare"><section class="mad4b-rv-panel"><h3>Earlier version</h3><p class="mad4b-rv-secondary">'.self::h(self::date($related[$oldKey]['updated_at']??'')).'</p><div class="mad4b-rv-prose">'.self::structured_text((string)($older['output']['body']??''),'en').'</div></section>';
    echo '<section class="mad4b-rv-panel"><h3>Later version</h3><p class="mad4b-rv-secondary">'.self::h(self::date($related[$newKey]['updated_at']??'')).'</p><div class="mad4b-rv-prose">'.self::structured_text((string)($newer['output']['body']??''),'en').'</div></section></div>';
    if(function_exists('wp_text_diff')){
     $diff=wp_text_diff((string)($older['output']['body']??''),(string)($newer['output']['body']??''),['title'=>'Changes in text','show_split_view'=>false]);
     if($diff){self::panel_start('Changes only','dashicons-editor-code');echo '<div class="mad4b-rv-diff">'.wp_kses_post($diff).'</div>';self::panel_end();}
    }
    if(!empty($older['review']['review_note'])){
     self::panel_start('Earlier reviewer feedback','dashicons-format-chat');
     echo '<blockquote>'.self::h($older['review']['review_note']).'</blockquote>';self::panel_end();
    }
   }
  }
  echo '<p><a class="button" href="'.esc_url(self::route(self::MASTER,['batch_id'=>$batch])).'">Back to Master Review</a></p>';
  self::end();
 }
}
