<?php
/**
 * Plugin Name: MAD4B Ground Transport Content Readiness
 * Version: 0.1.0
 * Description: Source-backed, deduplicated ground vehicle/rate content alerts and native Content Job intake.
 */
defined('ABSPATH') || exit;
final class MAD4B_Ground_Content_Readiness_V1 {
 const CONTRACT='mad4b.ground-transport-readiness.v1';
 const STORE='mad4b_ground_transport_readiness_v1';
 const ALERTS='mad4b_ground_provider_content_alerts_v1';
 static function road_terms():array {
  return ['private-car','private-van','private-minibus','private-coach-bus',
   'shared-shuttle','limousine','luxury-suv','airport-transfer'];
 }
 static function discover():array {
  $ids=get_posts(['post_type'=>'transportations','post_status'=>'publish',
     'posts_per_page'=>300,'fields'=>'ids','suppress_filters'=>true]);
  $issues=[];
  foreach($ids as $id){
    $id=absint($id);
    $terms=wp_get_post_terms($id,'transportation-types',['fields'=>'slugs']);
    if(is_wp_error($terms)||!array_intersect(self::road_terms(),$terms))continue;
    $title=wp_strip_all_tags(get_the_title($id));
    $make=trim((string)get_post_meta($id,'trans_vehicle_brand',true));
    $brands=['Vinfast','Toyota','Mercedes','BMW','Ford','Chevrolet','Hyundai',
      'Nissan','Kia','Peugeot','Mitsubishi','Volkswagen','Honda'];
    foreach($brands as $brand){
      if(preg_match('/\b'.preg_quote($brand,'/').'\b/iu',$title) &&
         $make!=='' && strcasecmp($make,$brand)!==0){
         $issues["vehicle:".$id.":brand"]=[
           'code'=>'ground_vehicle_brand_mismatch','object_id'=>$id,
           'post_type'=>'transportations','title'=>$title,'severity'=>'high',
           'details'=>'JetEngine vehicle brand metadata conflicts with the named manufacturer in the published transportation title.',
           'source_evidence'=>['title_brand'=>$brand,'trans_vehicle_brand'=>$make],
           'recommended_action'=>'Verify manufacturer from original vehicle/supplier evidence; correct native JetEngine fields only after review.',
         ];break;
      }
    }
    $rates=get_posts(['post_type'=>'transport-rates','post_status'=>'publish',
      'posts_per_page'=>150,'fields'=>'ids','suppress_filters'=>true,
      'meta_key'=>'source_post_id','meta_value'=>(string)$id]);
    $active=0;$expired=0;
    foreach($rates as $rate){
      $from=(string)get_post_meta($rate,'valid_from',true);
      $to=(string)get_post_meta($rate,'valid_to',true);
      if(!preg_match('/^20\d\d-\d\d-\d\d$/D',$to))continue;
      if($to<gmdate('Y-m-d'))$expired++;
      $stop=(string)get_post_meta($rate,'stop_sale',true);
      $verified=(array)get_post_meta($rate,'_booking_experience_verified_transport_rate_v1',true);
      if($to>=gmdate('Y-m-d') &&
         $from!==''&&$from<=gmdate('Y-m-d') &&
         !in_array($stop,['true','1','yes'],true)&&!empty($verified['approved']))
         $active++;
    }
    if($expired>0&&$active===0){
       $issues["vehicle:".$id.":rate"]=[
         'code'=>'ground_transport_fares_expired',
         'object_id'=>$id,'post_type'=>'transportations',
         'title'=>$title,'severity'=>'high',
         'details'=>'Transport has expired published Rates but no confirmed currently eligible native Rate.',
         'source_evidence'=>['expired_published_rates'=>$expired,'eligible_current_rates'=>$active],
         'recommended_action'=>'Request source-backed supplier net fare and availability; review native transport-rates before selling.',
       ];
    }
  }
  if(class_exists('MAD4B_Ground_Provider_Core_V1')){
   foreach(MAD4B_Ground_Provider_Core_V1::definitions() as $provider=>$p){
     if($provider==='local')continue;
     $state=MAD4B_Ground_Provider_Core_V1::ready($provider);
     if(empty($state['connected'])){
       $issues['provider:'.$provider.':connection']=[
         'code'=>'ground_provider_api_access_missing',
         'object_id'=>0,'post_type'=>'provider',
         'title'=>$p['title'],'severity'=>'medium',
         'details'=>'No currently certified and authenticated partner API connection for the enabled provider.',
         'source_evidence'=>[
           'provider_id'=>$provider,
           'connection_state'=>$state['state']??'unknown'],
         'recommended_action'=>'Obtain partner onboarding credentials and run an authenticated API test in WooCommerce Ground Transport Providers.',
       ];
     }
   }
  }
  return ['complete'=>true,'issues'=>$issues,
    'published_road_transportations_examined'=>count($ids),
    'observed_at'=>gmdate('c')];
 }
 static function reconcile(bool $intake=false){
  if(!current_user_can('manage_options'))return new WP_Error('ground_content_readiness_denied');
  $scan=self::discover();
  $previous=(array)get_option(self::STORE,[]);
  $result=[];$now=gmdate('c');
  foreach($scan['issues'] as $key=>$row){
    $old=(array)($previous[$key]??[]);
    $result[$key]=array_replace($old,$row,[
      'id'=>$key,'status'=>'open',
      'first_seen'=>$old['first_seen']??$now,
      'last_seen'=>$now,
      'content_job_id'=>$old['content_job_id']??null,
    ]);
    if($intake&&$row['object_id']>0 && empty($result[$key]['content_job_id'])
       &&class_exists('MAD4B_SCP_Content_Jobs') &&
       class_exists('MAD4B_SCP_Operational_Integrity')){
      $scope=MAD4B_SCP_Operational_Integrity::capture();
      if(!is_wp_error($scope)){
        $brand=(string)($scope['scope']['brand_ref']??'');
        if($brand!==''){
          $job=MAD4B_SCP_Content_Jobs::create_job([
            'brand_id'=>$brand,'language'=>'en','country'=>'eg',
            'content_type'=>'research_observation',
            'subject'=>'Ground Transport Readiness · '.$row['code'].' · Transportation #'.$row['object_id'],
            'primary_keyword'=>'ground transportation supplier evidence',
            'reason'=>$row['details'].' '.$row['recommended_action'].
              ' Readiness issue: '.$key.'. This is a research request; do not modify published prices or supplier identity without evidence.',
            'research_depth'=>'standard',
          ]);
          if(!is_wp_error($job)){
            $jid=(string)($job['job']['job_id']??$job['job_id']??'');
            if($jid!=='')$result[$key]['content_job_id']=$jid;
          }else{
            $result[$key]['content_job_intake_error']=$job->get_error_code();
          }
        }
      }
    }
  }
  foreach($previous as $key=>$row){
    if(!isset($result[$key])){
      $result[$key]=array_replace($row,[
        'status'=>'resolved','resolved_at'=>$now,
      ]);
    }
  }
  update_option(self::STORE,$result,false);
  $actual=(array)get_option(self::STORE,[]);
  if(count($actual)!==count($result))return new WP_Error('ground_readiness_readback_invalid');
  $summary=[];
  foreach($actual as $row){
    if(($row['status']??'')==='open'){
      $code=(string)($row['code']??'unknown');
      $summary[$code]=($summary[$code]??0)+1;
    }
  }
  return ['contract'=>self::CONTRACT,'at'=>$now,
    'total_open'=>array_sum($summary),'open_by_code'=>$summary,
    'linked_job_ids'=>array_values(array_filter(array_map(
      fn($r)=>($r['status']??'')==='open'?($r['content_job_id']??null):null,$actual))),
    'mutation_scope'=>'alerts_and_review_jobs_only',
    'rates_created'=>0,'woo_orders_created'=>0];
 }
 static function page_section():void {
  if(!current_user_can('manage_woocommerce'))return;
  $stored=(array)get_option(self::STORE,[]);
  echo '<hr><h3>Ground Content Readiness Alerts</h3>';
  echo '<p>Missing supplier API credentials, contradictory native JetEngine vehicle facts and expired native transport rates appear here. No prices or vehicles are corrected automatically.</p>';
  if(!$stored){
    echo '<p>No readiness scan yet.</p>';return;
  }
  echo '<table class="widefat striped"><thead><tr><th>Issue</th><th>Object / supplier</th><th>Status</th><th>Content Job</th></tr></thead><tbody>';
  $n=0;
  foreach($stored as $key=>$r){
    if(($r['status']??'')!=='open')continue;
    if(++$n>30)break;
    echo '<tr><td>'.esc_html((string)($r['code']??'')).'</td><td>'.
      esc_html((string)($r['title']??'')).'</td><td>OPEN</td><td>'.
      esc_html((string)($r['content_job_id']??'Pending source research')).'</td></tr>';
  }
  echo '</tbody></table>';
 }
}
add_action('woocommerce_settings_tabs_booking_experience',
 ['MAD4B_Ground_Content_Readiness_V1','page_section'],125);
if(defined('WP_CLI')&&WP_CLI){
 WP_CLI::add_command('mad4b ground-readiness',static function($args,$assoc){
  $op=$args[0]??'status';
  if($op==='status'){
    $data=(array)get_option(MAD4B_Ground_Content_Readiness_V1::STORE,[]);
    $r=['contract'=>MAD4B_Ground_Content_Readiness_V1::CONTRACT,
      'open'=>count(array_filter($data,fn($x)=>($x['status']??'')==='open')),
      'issues'=>array_map(fn($x)=>[
        'code'=>$x['code']??'','object_id'=>$x['object_id']??0,
        'status'=>$x['status']??'','content_job_id'=>$x['content_job_id']??null],$data)];
  }elseif($op==='scan')$r=MAD4B_Ground_Content_Readiness_V1::discover();
  elseif($op==='reconcile')$r=MAD4B_Ground_Content_Readiness_V1::reconcile(!empty($assoc['intake']));
  else WP_CLI::error('Use status | scan | reconcile [--intake]');
  if(is_wp_error($r))WP_CLI::error($r->get_error_code());
  WP_CLI::line(wp_json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
 });
}
