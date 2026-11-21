<?php
/**
 * Read-only native sleeper train origin and site-local Bashtil station
 * isolation, against original Tour 60623 stages.
 */
defined('ABSPATH') || exit;
if(!defined('WP_CLI') || !WP_CLI)return;
$assertions=0;$fail=[];
$ok=static function(bool $yes,string $label)use(&$assertions,&$fail):void{
 $assertions++;if(!$yes)$fail[]=$label;
};
$source=str_contains(home_url(),'tours.dev.mad4b.com');
$target=str_contains(home_url(),'wayfatours.com');
$ok($source || $target,'known live company');
$ok(class_exists('MAD4B_Package_Stage_Sourcing_V1',false),'native source discovery class');
$station=get_page_by_path('upper-egypt-railway-station-bashteel',OBJECT,'stations');
$expected=$source?68930:68922;
$ok($station instanceof WP_Post && (int)$station->ID===$expected &&
 $station->post_type==='stations' && $station->post_status==='publish' &&
 $station->post_title==='Upper Egypt Railway Station (Bashteel)',
 'site local Bashtil station not foreign numeric ID');
$ok($station instanceof WP_Post && in_array('giza-city',
 (array)wp_get_object_terms($station->ID,'destinations',['fields'=>'slugs']),true),
 'exact Giza location of Bashtil source Station');
$plan=get_post_meta(60623,'_mad4b_package_accommodation_source_plan_v1',true);
$ok(is_array($plan)&&is_array($plan['stages']??null),'canonical Package saved');
$legs=[];$before=(int)wp_count_posts('tour-rates')->publish;
foreach((array)($plan['stages']??[]) as $stage){
 if(($stage['lodging_kind']??'')!=='sleeper_train')continue;
 $day=(int)($stage['source_day_start']??0);
 $tid=$day===2?54552:($day===6?54553:0);
 $number=$day===2?'86':'87';
 $res=MAD4B_Package_Stage_Sourcing_V1::inspect(60623,$stage,2354);
 $ok(($res['type']??'')==='published_sleeper_train_route_candidates',
    'separate specialist train stage '.$day);
 $ok(($res['route']['from']??'')===($day===2?'cairo':'luxor') &&
     ($res['route']['to']??'')===($day===2?'aswan':'cairo'),
     'original itinerary and itinerary stage source route '.$day);
 $candidates=(array)($res['train_candidates']??[]);
 $ok(count($candidates)===1 && (int)$candidates[0]['id']===$tid,
     'only native route-verified source train '.$day);
 if(count($candidates)===1){
  $x=$candidates[0];
  $ok((string)$x['number']===$number &&
      (int)$x['public_timetable_terminal_station_id']===$expected,
      'native Station ID is resolved for this site '.$day);
  $ok((bool)$x['native_bashtil_station_in_route']===$source,
      'stale vs site-reconciled Bashtil train stop state '.$day);
  $ok((bool)$x['native_terminal_needs_schedule_revalidation']===$target,
      'Wayfa old Ramses stop marked for verification '.$day);
  $ok(!$x['operator_timetable_confirmed'] &&
      !$x['cabin_supplier_contract_verified'] &&
      (int)$x['published_transport_rate_count']===0,
      'no time/cabin/fare approval from itinerary-only evidence '.$day);
  $legs[]=['train'=>$tid,'number'=>$number,'origin'=>$res['route']['from'],
    'destination'=>$res['route']['to'],'local_bashtil'=>$expected,
    'stale_ramses'=>$x['native_terminal_needs_schedule_revalidation'],
    'rate_count'=>0];
 }
}
$ok(count($legs)===2,'two original overnight train legs separately mapped');
$after=(int)wp_count_posts('tour-rates')->publish;
$ok($before===$after,'read-only does not write Tour Rates');
if($fail)WP_CLI::error(wp_json_encode(['passed'=>$assertions-count($fail),
 'failed'=>$fail,'legs'=>$legs],JSON_UNESCAPED_SLASHES));
WP_CLI::line(wp_json_encode(['site'=>home_url(),
 'passed'=>$assertions,'failed'=>0,'legs'=>$legs,
 'rates_unchanged'=>true],JSON_UNESCAPED_SLASHES));
