<?php
/**
 * Plugin Name: MAD4B Tour Leader — Itinerary Source Audit
 * Description: Source-backed Tour Leader candidate discovery, separate from tourist guides, transport, and financial billing.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Tour_Leader_Itinerary_Audit_V1',false)){
final class MAD4B_Tour_Leader_Itinerary_Audit_V1 {
 const CONTRACT='mad4b.tour-leader-itinerary-review.v1';
 const SLUG='mad4b-tour-leader-itinerary-review';
 const VARIANTS=[
  'airport_meet_assist'=>'Airport Meet & Assist',
  'airport_departure_assistance'=>'Airport Departure Assistance',
  'airport_arrival_handover'=>'Airport Arrival Handover',
  'airport_transfer_handover'=>'Airport Transfer / Handover',
  'airport_standby_review'=>'Airport Waiting / Flight Delay Review',
  'station_meet_handover'=>'Train Station Meet & Handover',
  'cruise_embark_disembark'=>'Cruise Embarkation / Disembarkation Support',
  'hotel_meeting_checkin'=>'Hotel Meeting / Check-in Assistance',
  'group_assembly_meeting'=>'Meeting Point / Group Assembly',
  'intercity_handover'=>'Intercity Transfer Handover',
  'tour_leader_escort'=>'Tour Leader Escort / Coordination'
 ];
 private static function text($v):string {
   if(!is_scalar($v)||is_bool($v))return '';
   $v=strip_shortcodes((string)$v);
   $v=html_entity_decode(wp_strip_all_tags($v),ENT_QUOTES|ENT_HTML5,'UTF-8');
   return trim((string)preg_replace('/[\p{Z}\s]+/u',' ',$v));
 }
 private static function yes(string $body,string $pattern):bool {
   return @preg_match($pattern,$body)===1;
 }
 public static function classify(string $title,string $description):array {
   $body=self::text($title).' '.self::text($description);
   // Evidence signals: these are lexical matches, not supplier commitments.
   $staff=self::yes($body,'/(?:\b(?:tour manager|tour leader|tour escort|tour representative|our representative|the representative|airport representative|local representative|meet\s*(?:&|and)\s*assist|meet\s*(?:&|and)\s*greet|representante|représentant|accompagnat(?:eur|rice|ore)|reiseleiter)\b|مندوب|ممثل الشركة|مشرف الرحلة|مرافق الرحلة|استقبال ومساعدة)/iu');
   $airport=self::yes($body,'/(?:\b(?:airport|aeropuerto|aéroport|flughafen|aeroporto)\b|مطار|المطار)/iu');
   $arrival=self::yes($body,'/(?:\b(?:arriv(?:e|al|ing)|land(?:ing)?|touch down|llegada|llegar|arrivée|ankunft|arrivo)\b|الوصول|القدوم|عند وصول)/iu');
   $departure=self::yes($body,'/(?:\b(?:depart(?:ure)?|fly back|return flight|flight home|salida|regreso|départ|abflug|partenza)\b|المغادرة|العودة|رحلة العودة)/iu');
   $wait=self::yes($body,'/(?:\b(?:waiting|waits?|standing by|stand ?by|esperando|en espera|attend(?:re|ant)|wartet|warten|attesa)\b|انتظار|ينتظر|بانتظار)/iu');
   $station=self::yes($body,'/(?:\b(?:train station|railway station|train terminal|sleeper train|railway|gare|bahnhof|estación de tren|stazione ferroviaria)\b|محطة القطار|قطار النوم)/iu');
   $cruise=self::yes($body,'/(?:\b(?:embark(?:ation)?|disembark(?:ation)?|board(?:ing)? (?:the |your )?(?:nile )?cruise|check[- ]?in on ?board|check[- ]?out from cabins|check[- ]?out of (?:the |your )?cruise|embarque|desembarque|embarquement|débarquement|imbarco|sbarco)\b|الصعود على المركب|صعود الباخرة|النزول من المركب|إبحار وركوب)/iu');
   $hotel=self::yes($body,'/(?:\b(?:hotel check[- ]?in|hotel check[- ]?out|check into (?:your |the |a )?hotel|check into (?:your |the |a )?(?:beach )?resort|check[- ]?in at (?:the |a )?hotel|hotel lobby|hotel reception|rooming list)\b|تسجيل دخول الفندق|تسكين الفندق)/iu');
   $meeting=self::yes($body,'/(?:\b(?:meeting point|meet(?:ing)? at|meet you at|meet your|group assembly|gathering point|rendezvous|punto de encuentro|point de rencontre|treffpunkt)\b|نقطة الالتقاء|نقطة التجمع|مكان اللقاء)/iu');
   $transfer=self::yes($body,'/(?:\b(?:transfer(?:red|s)?|private driver|drive to|driven to|transport|traslado|transfert|transferimento)\b|انتقال|نقل|التوصيل)/iu');
   $guide=self::yes($body,'/(?:\b(?:tour guide|private guide|egyptologist guide|guided tour|expert guide|gu[ií]a tur[ií]stic[oa]|guida turistica|guide touristique)\b|مرشد سياحي|المرشد)/iu');
   $leisure=self::yes($body,'/(?:\b(?:free day|at leisure|on your own|día libre|jour libre|freier tag)\b|يوم حر)/iu');
   $optional=self::yes($body,'/(?:\b(?:optional|if selected|if booked|opcional|facultatif|facoltativo)\b|اختياري|اختيارية)/iu');
   $flags=array_keys(array_filter(compact('staff','airport','arrival','departure','wait','station','cruise','hotel','meeting','transfer','guide','leisure','optional')));
   $variant='';$status='not_indicated';
   if($staff && $airport && $arrival)$variant='airport_meet_assist';
   elseif($staff && $airport && $departure)$variant='airport_departure_assistance';
   elseif($staff && $station)$variant='station_meet_handover';
   elseif($staff && $cruise)$variant='cruise_embark_disembark';
   elseif($staff && $meeting)$variant='group_assembly_meeting';
   elseif($staff && $hotel)$variant='hotel_meeting_checkin';
   elseif($staff)$variant='tour_leader_escort';
   elseif($airport && $arrival)$variant='airport_arrival_handover';
   elseif($airport && $departure)$variant='airport_departure_assistance';
   elseif($station)$variant='station_meet_handover';
   elseif($cruise)$variant='cruise_embark_disembark';
   elseif($hotel)$variant='hotel_meeting_checkin';
   elseif($meeting && $transfer)$variant='group_assembly_meeting';
   elseif($airport && $transfer)$variant='airport_transfer_handover';
   elseif($transfer && self::yes($body,'/\b(?:from|to|between)\s+(?:cairo|luxor|aswan|sharm el sheikh|hurghada)\b/iu'))$variant='intercity_handover';
   if($variant!=='')$status=$staff?'explicit_service_text_review':'logistics_only_needs_owner_review';
   if($guide && !$staff && $wait && $airport)$status='guide_role_ambiguous_needs_review';
   // No "airport waiting" surcharge is inferred from ordinary welcome duty.
   $standbyExtra=($airport&&$wait&&!$staff&&!$guide)?'waiting_text_without_named_staff':'not_verified';
   return ['contract'=>self::CONTRACT,'status'=>$status,
     'variant'=>$variant,'variant_title'=>self::VARIANTS[$variant]??'',
     'evidence_flags'=>$flags,'explicit_staff_in_source'=>$staff,
     'airport_waiting_phrase'=>$airport&&$wait,
     'extra_standby_billable'=>false,'extra_standby_status'=>$standbyExtra,
     'guide_only_not_tour_leader'=>$guide&&!$staff,
     'original_text_sha256'=>hash('sha256',$body),
     'no_commercial_or_supplier_authorization'=>true];
 }
 private static function rowsFromRaw($raw,int $owner,string $kind,int $tour,int $program,string $sourceHash=''):array {
   if(!is_array($raw))return [];
   $result=[];$number=0;
   foreach($raw as $key=>$item){
     if(!is_array($item))continue;
     ++$number;
     $label=self::text($item['title']??'');
     $description=self::text($item['description']??'');
     $e=self::classify($label,$description);
     $result[]=$e+['tour_id'=>$tour,'program_id'=>$program,'original_owner_id'=>$owner,
       'original_owner_type'=>$kind,'day_key'=>(string)$key,'day_index'=>$number,
       'day_title'=>$label,'source_hash'=>$sourceHash,'excerpt'=>mb_substr($description,0,350)];
   }
   return $result;
 }
 public static function sourceRows(int $tour,int $program=0):array {
   if(!class_exists('MAD4B_Guide_Service_Composition_V1',false))return [];
   $source=MAD4B_Guide_Service_Composition_V1::source($tour,$program);
   if(is_wp_error($source))return [];
   $owner=(int)$source['source_id'];$kind=(string)$source['source_kind'];
   $raw=$kind==='term'?get_term_meta($owner,'program_itinerary',true):get_post_meta($owner,'itinerary_item',true);
   $out=self::rowsFromRaw($raw,$owner,$kind,$tour,$program,(string)$source['source_hash']);
   // Daily/stop count follows the native guide source day unit proof.
   if(in_array((string)($source['day_unit_basis']??''),[
      'explicit_one_day_aggregated_stages','explicit_single_day_duration_taxonomy'],true))
      foreach($out as &$row)$row['day_index']=1;
   return $out;
 }
 public static function serviceRows(int $tour,int $program=0):array{
   $out=[];
   foreach(self::sourceRows($tour,$program) as $r){
     if(!in_array($r['status'],['explicit_service_text_review','logistics_only_needs_owner_review'],true))continue;
     $identity='tour_leader|'.$r['original_owner_type'].'|'.$r['original_owner_id'].'|'.$r['day_key'];
     $variantMap=[
       'airport_meet_assist'=>'airport-meet-assist',
       'airport_arrival_handover'=>'airport-meet-assist',
       'airport_transfer_handover'=>'meeting-point-group-assembly',
       'airport_departure_assistance'=>'airport-departure-assistance',
       'airport_standby_review'=>'airport-waiting-standby',
       'station_meet_handover'=>'train-station-assistance',
       'cruise_embark_disembark'=>'cruise-embark-disembark',
       'hotel_meeting_checkin'=>'hotel-check-in-assistance',
       'group_assembly_meeting'=>'meeting-point-group-assembly',
       'intercity_handover'=>'intercity-handover',
       'tour_leader_escort'=>'tour-leader-escort'
     ];
     $catalogSlug=$variantMap[$r['variant']]??'';
     $catalogId=$catalogSlug!==''&&class_exists('MAD4B_Operational_Services_V1',false)
       ?MAD4B_Operational_Services_V1::byVariant($catalogSlug):0;
     if($catalogId<1)continue; // no fake or non-native catalog identity
     $out[]=[
       'identity'=>$identity,'title'=>'Tour Leader — '.$r['variant_title'],
       'role'=>$r['status']==='explicit_service_text_review'
            ?'tour_leader_explicit_text_review':'tour_leader_logistics_unconfirmed',
       'day'=>$r['day_index'],'type'=>'operational-services',
       'id'=>$catalogId,'stage'=>$r['day_key'],'family'=>'tour_leader',
       'proof'=>['contract'=>self::CONTRACT,'service_variant'=>$r['variant'],
         'catalog_type_slug'=>$catalogSlug,'catalog_service_post_id'=>$catalogId,
         'assignment_state'=>'review_candidate_only',
         'day_title'=>$r['day_title'],'day_key'=>$r['day_key'],
         'program_id'=>$program,'source_hash'=>$r['source_hash'],
         'original_text_sha256'=>$r['original_text_sha256'],
         'evidence_flags'=>$r['evidence_flags'],
         'source_excerpt'=>$r['excerpt'],
         'explicit_staff_in_itinerary'=>$r['explicit_staff_in_source'],
         'airport_waiting_phrase'=>$r['airport_waiting_phrase'],
         'extra_airport_waiting_approved'=>false,
         'guide_is_separate_role'=>true,
         'included_in_tour_rate'=>false,
         'supplier_cost_verified'=>false,
         'supplier_booked'=>false,
         'no_double_billing'=>true]
     ];
   }
   return $out;
 }
 private static function can():bool{
   return current_user_can('manage_woocommerce')||current_user_can('manage_options');
 }
 public static function adminMenu():void {
   if(!self::can())return;
   add_submenu_page('woocommerce','Tour Leader — Itinerary Audit',
     'Tour Leader Review','manage_woocommerce',self::SLUG,[self::class,'admin']);
 }
 public static function auditRows(int $tour=0,int $program=0):array{
   $all=[];
   if($program>0){
     $term=get_term($program,'programs');if($term instanceof WP_Term)
       $all=self::rowsFromRaw(get_term_meta($program,'program_itinerary',true),
         $program,'term',0,$program);
   }else{
     $ids=$tour>0?[$tour]:get_posts(['post_type'=>'tours-and-activities',
       'post_status'=>['publish','draft','pending','private'],'fields'=>'ids',
       'posts_per_page'=>-1,'suppress_filters'=>true]);
     foreach($ids as $id){
       if(get_post_type($id)!=='tours-and-activities')continue;
       $raw=get_post_meta($id,'itinerary_item',true);
       $all=array_merge($all,self::rowsFromRaw($raw,$id,'post',$id,0));
     }
     if($tour===0){
       $terms=get_terms(['taxonomy'=>'programs','hide_empty'=>false]);
       if(is_array($terms))foreach($terms as $term)
         $all=array_merge($all,self::rowsFromRaw(get_term_meta($term->term_id,'program_itinerary',true),
           $term->term_id,'term',0,(int)$term->term_id));
     }
   }
   return $all;
 }
 public static function admin():void {
   if(!self::can())wp_die('Tour Leader itinerary audit access denied');
   $tour=absint($_GET['tour_id']??0);
   $program=absint($_GET['program_id']??0);
   $status=sanitize_key((string)($_GET['status']??'all'));
   $rows=self::auditRows($tour,$program);$counts=[];
   foreach($rows as $row)$counts[$row['status']]=($counts[$row['status']]??0)+1;
   if($status!=='all')$rows=array_values(array_filter($rows,static fn($r)=>$r['status']===$status));
   $page=max(1,absint($_GET['paged']??1));
   $total=count($rows);$perPage=60;
   $rows=array_slice($rows,($page-1)*$perPage,$perPage);
   $url=admin_url('admin.php?page='.self::SLUG);
   $export=wp_nonce_url(admin_url('admin-post.php?action=mad4b_tour_leader_audit_export'
      .'&tour_id='.$tour.'&program_id='.$program.'&status='.$status),
       'mad4b_tour_leader_audit_export');
   echo '<div class="wrap"><h1>Tour Leader — Original Itinerary Day Audit</h1>';
   echo '<p>Read-only candidate audit, not a supplier booking, tourist guide, or extra charge. '
      .'Counts include localized posts; use tour ID or program ID to narrow to one original.</p>';
   echo '<form method="get"><input type="hidden" name="page" value="'.esc_attr(self::SLUG).'">';
   echo 'Tour ID <input type="number" min="0" name="tour_id" value="'.esc_attr($tour).'"> ';
   echo 'Program term ID <input type="number" min="0" name="program_id" value="'.esc_attr($program).'"> ';
   echo '<select name="status"><option value="all">All days</option>';
   foreach(['explicit_service_text_review','logistics_only_needs_owner_review',
       'guide_role_ambiguous_needs_review','not_indicated'] as $v)
     echo '<option value="'.esc_attr($v).'" '.selected($status,$v,false).'>'.esc_html($v).'</option>';
   echo '</select> <button class="button button-primary">Filter</button> ';
   echo '<a class="button" href="'.esc_url($export).'">Export full CSV (nonce protected)</a></form>';
   echo '<p><b>Total matching days:</b> '.esc_html($total).' &nbsp; <b>All source days:</b> '
     .esc_html(array_sum($counts)).' &nbsp; '.esc_html(wp_json_encode($counts)).'</p>';
   echo '<table class="widefat striped"><thead><tr><th>Source</th><th>Day</th><th>Original title</th><th>Candidate</th><th>Evidence excerpt</th></tr></thead><tbody>';
   foreach($rows as $r){
     $id=(int)($r['tour_id']?:$r['program_id']);
     $link=$r['tour_id']?get_edit_post_link($id):get_edit_term_link($id,'programs');
     if(is_wp_error($link))$link=false;
     echo '<tr><td>'.($link?'<a href="'.esc_url($link).'">':'').esc_html($r['original_owner_type'].' #'.$id)
         .($link?'</a>':'').'</td><td>'.esc_html($r['day_index'].' / '.$r['day_key']).'</td>'
         .'<td>'.esc_html($r['day_title']).'</td><td>'.esc_html($r['status'])
         .'<small style="display:block">'.esc_html($r['variant_title']).'</small></td>'
         .'<td><details><summary>Original evidence</summary><p>'.esc_html($r['excerpt'])
         .'</p></details></td></tr>';
   }
   echo '</tbody></table>';
   $pages=max(1,(int)ceil($total/$perPage));
   if($pages>1)echo '<p>'.wp_kses_post(paginate_links([
      'base'=>add_query_arg(['paged'=>'%#%'],'?page='.self::SLUG.'&tour_id='.$tour.'&program_id='.$program.'&status='.$status),
      'format'=>'','current'=>$page,'total'=>$pages,'type'=>'plain'])) .'</p>';
   echo '</div>';
 }
 public static function export():void{
   if(!self::can())wp_die('Access denied',403);
   check_admin_referer('mad4b_tour_leader_audit_export');
   $tour=absint($_GET['tour_id']??0);$program=absint($_GET['program_id']??0);
   $status=sanitize_key((string)($_GET['status']??'all'));
   $rows=self::auditRows($tour,$program);
   if($status!=='all')$rows=array_values(array_filter($rows,static fn($r)=>$r['status']===$status));
   nocache_headers();header('Content-Type: text/csv; charset=UTF-8');
   header('Content-Disposition: attachment; filename="mad4b-tour-leader-itinerary-day-audit.csv"');
   $f=fopen('php://output','wb');fwrite($f,"\xEF\xBB\xBF");
   $headers=['tour_id','program_id','original_owner_id','original_owner_type','day_index',
     'day_key','day_title','status','variant','evidence_flags','explicit_staff_in_source',
     'airport_waiting_phrase','extra_standby_billable','source_hash','original_text_sha256','excerpt'];
   fputcsv($f,$headers);
   foreach($rows as $r){
     $line=[];foreach($headers as $key)
       $line[]=is_array($r[$key]??null)?implode('|',$r[$key]):(string)($r[$key]??'');
     fputcsv($f,$line);
   }
   fclose($f);exit;
 }
 public static function boot():void{
   add_action('admin_menu',[self::class,'adminMenu']);
   add_action('admin_post_mad4b_tour_leader_audit_export',[self::class,'export']);
 }
}
MAD4B_Tour_Leader_Itinerary_Audit_V1::boot();
}
