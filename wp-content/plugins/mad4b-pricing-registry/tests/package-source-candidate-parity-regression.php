<?php
/**
 * Noncommercial canonical Property candidate parity across tours / tiers.
 * Assert 3 stages x 7 original Property candidates. No new Units/Cost.
 */
defined('ABSPATH')||exit;
$expected=[
 60627=>['hotel-luxor-02'=>[2353=>[36142],2354=>[36481],2356=>[36481]]],
 60629=>['resort-sharm-03'=>[2353=>[36302],2354=>[36084],2356=>[36084]]],
 60437=>['hotel-aswan-02'=>[2353=>[36181],2354=>[],2356=>[]]]
];
$original=apply_filters('wpml_current_language',null);
$available=(array)apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
$check=[];$add=static function(string $name,bool $pass)use(&$check):void{$check[$name]=$pass;};
$beforeRates=get_posts(['post_type'=>'tour-rates','post_status'=>['publish','draft'],
 'fields'=>'ids','posts_per_page'=>-1,'suppress_filters'=>true]);
try {
 foreach(array_keys($available) as $lang){
  do_action('wpml_switch_language',$lang);
  foreach($expected as $tour=>$stages){
   $binding=get_post_meta($tour,'_mad4b_package_stay_verified_room_program_links_v1',true);
   $source=get_post_meta($tour,'_mad4b_package_accommodation_source_plan_v1',true);
   $add("$lang:$tour:source_digest_valid",is_array($source)&&is_array($binding)&&
    ($binding['source_plan_sha256']??'')===hash('sha256',
      wp_json_encode($source,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
   foreach($stages as $key=>$tiers){
    $entry=null;
    foreach((array)($binding['bindings']??[]) as $item)
     if(($item['stage_key']??null)===$key)$entry=$item;
    $add("$lang:$tour:$key:entry_exists",is_array($entry));
    $add("$lang:$tour:$key:noncommercial",is_array($entry)&&
      empty($entry['selected_property_id'])&&
      empty($entry['bookable_unit_ids'])&&
      empty($entry['supplier_cost_receipt'])&&
      empty($entry['native_rate_stage_written'])&&
      empty($entry['price']));
    foreach($tiers as $tier=>$ids){
     $r=MAD4B_Package_Stage_Rate_Guard_V1::review(
       $tour,$tier,'2026-12-05','2026-12-19');
     $stage=null;
     foreach((array)($r['stages']??[]) as $row)
      if(($row['key']??'')===$key)$stage=$row;
     $actual=array_values(array_map('intval',(array)($stage['eligible_property_ids']??[])));
     sort($actual);$wanted=$ids;sort($wanted);
     $add("$lang:$tour:$key:$tier:candidates",$actual===$wanted);
     $add("$lang:$tour:$key:$tier:blocked",is_array($stage)&&
       in_array('stage_supplier_cost_receipt_missing',
         (array)($stage['blocked']??[]),true)&&
       $r['ready']===false&&$r['checkout_ready']===false);
     $references=(array)($entry['room_candidate_refs'][(string)$tier]??[]);
     $props=array_map(static fn($x)=>(int)($x['property_id_en']??0),$references);
     sort($props);
     $add("$lang:$tour:$key:$tier:source_ref_ids",$props===$wanted);
     // Tours.dev preserves already-recorded *editorial* draft Unit choices;
     // Wayfatours intentionally imports zero Unit refs. Neither is bookable.
     $unitLinksSafe=true;
     foreach($references as $candidate){
      if(!empty($candidate['commercially_approved_unit_ids']))$unitLinksSafe=false;
      $units=(array)($candidate['unit_ids_en']??[]);
      if(parse_url(home_url('/'),PHP_URL_HOST)==='wayfatours.com'&&$units)
       $unitLinksSafe=false;
      foreach($units as $uid){
       if(get_post_type((int)$uid)!=='units'||
          !in_array(get_post_status((int)$uid),['draft','publish'],true)||
          in_array((string)get_post_meta((int)$uid,'unit_available',true),
             ['1','true','yes','on'],true))$unitLinksSafe=false;
      }
     }
     $add("$lang:$tour:$key:$tier:zero_commercial_unit_links",$unitLinksSafe);
     foreach($ids as $id){
      $add("$lang:$id:published_property",
       get_post_type($id)==='properties'&&get_post_status($id)==='publish');
     }
    }
   }
  }
  $add("$lang:locale_restored",apply_filters('wpml_current_language',null)===$lang);
 }
}finally{
 if(is_string($original)&&$original!=='')do_action('wpml_switch_language',$original);
}
$afterRates=get_posts(['post_type'=>'tour-rates','post_status'=>['publish','draft'],
 'fields'=>'ids','posts_per_page'=>-1,'suppress_filters'=>true]);
$add('no_rate_creation_or_deletion',$beforeRates===$afterRates);
$fails=array_keys(array_filter($check,static fn($pass)=>!$pass));
echo wp_json_encode([
 'contract'=>'mad4b.package-source-candidate-parity-regression.v1',
 'site'=>home_url(),'languages'=>array_keys($available),
 'stages'=>3,'tier_candidate_links'=>7,
 'passed'=>count($check)-count($fails),'total'=>count($check),
 'failures'=>$fails,'cost_receipt_approved'=>false,
 'rate_published'=>false,'checkout_authorized'=>false
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
if($fails&&class_exists('WP_CLI'))WP_CLI::halt(1);

