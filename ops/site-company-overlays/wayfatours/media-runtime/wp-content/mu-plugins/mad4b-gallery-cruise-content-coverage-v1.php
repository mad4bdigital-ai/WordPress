<?php
/**
 * Plugin Name: MAD4B Cruise Gallery Content Coverage Handoff
 * Description: Governed read-only Program-day media gap candidates for the existing native Content Jobs workflow; does not create or approve jobs.
 * Version: 1.0.0
 */
defined('ABSPATH')||exit;
final class MAD4B_Cruise_Gallery_Coverage_V1 {
 const CONTRACT='mad4b.cruise-gallery-coverage.v1';
 public static function plan(int $tour):array {
  $base=['contract'=>self::CONTRACT,'tour_id'=>$tour,
    'state'=>'not_ready','jobs_created'=>0,'media_approved'=>0,
    'requires_governed_content_job'=>true,'auto_publish'=>false,
    'candidates'=>[]];
  if($tour<1||get_post_type($tour)!=='tours-and-activities'
    ||get_post_status($tour)!=='publish')return $base;
  if(!class_exists('MAD4B_Gallery_Itinerary_Policy_V1',false)
    ||!class_exists('MAD4B_UAEL_Tour_Gallery_Addon_V1',false))return $base;
  $default=(string)apply_filters('wpml_default_language','en');
  $canonical=(int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,$default);
  if(!$canonical)$canonical=$tour;
  $policy=MAD4B_Gallery_Itinerary_Policy_V1::resolve($canonical,$canonical);
  $base['canonical_tour_id']=$canonical;
  $base['tour_mode']=(string)($policy['mode']??'');
  $base['itinerary_authority']=(string)($policy['authority']??'');
  $pid=(int)($policy['program_id']??0);
  $base['program_id']=$pid;
  if($pid===0||($policy['authority']??'')!=='taxonomy:programs:program_itinerary'){
    $base['state']='no_authoritative_single_program';return $base;
  }
  $view=MAD4B_UAEL_Tour_Gallery_Addon_V1::coverage_snapshot($canonical);
  if(($view['status']??'')!=='ready'
    ||(int)($view['program_id']??0)!==$pid){
    $base['state']='timeline_coverage_not_verified';return $base;
  }
  $ref=get_term_meta($pid,'program_itinerary',true);
  $hash=hash('sha256',wp_json_encode($ref));
  $base['program_itinerary_sha256']=$hash;
  $base['media_revision']=$view['media_revision'];
  $base['site']=home_url('/');
  $base['site_uuid']=class_exists('MAD4B_SCP_Site_Profile',false)
    ?(string)MAD4B_SCP_Site_Profile::site_uuid():'';
  $base['state']='review_proposals_ready';
  $base['candidate_count']=0;
  $base['logistics_days_without_photos']=[];
  $canonicalDays=(array)($policy['canonical_days']??$policy['days']??[]);
  $authoritative=array_column($canonicalDays,null,'day');
  foreach((array)($view['days']??[]) as $row){
    $day=absint($row['day']??0);
    if(!$day||!isset($authoritative[$day])
      ||(int)($row['photo_count']??0)>0)continue;
    $title=(string)($authoritative[$day]['title']??'');
    $description=(string)($authoritative[$day]['description']??'');
    // A pure departure / check-out day (including optional excursions)
    // does not justify inventing tour inclusions merely to add a photo.
    $logistics=(bool)preg_match('/\b(?:departure|depart|disembark|check.?out|transfer only)\b/iu',$title)
      && !(bool)preg_match('/\b(?:visit|tour|temple|sightseeing|excursion included)\b/iu',$title);
    if($logistics){
      $base['logistics_days_without_photos'][]=$day;
      continue;
    }
    $key=hash('sha256',implode('|',[
      self::CONTRACT,$base['site_uuid'],$canonical,$pid,$day,$hash]));
    $clean=trim(preg_replace('/^\s*(?:Day|Día|Jour|Tag|Giorno)\s*\d+\s*[:\-–.]?\s*/iu','',$title));
    $subject='[MAD4B_CRUISE_GALLERY_'.$key.'] Verify licensed photos for Program '.$pid.' Day '.$day.' — '.mb_substr($clean,0,120);
    $reason='Find exact-location cruise photos, verify original provenance, image rights and absence of watermarks; require operator/editor approval. No auto-publish. Tour '.$canonical.' Program '.$pid.' Day '.$day.'; evidence '.$hash;
    $base['candidates'][]=[
      'key'=>$key,'tour_id'=>$canonical,'program_term_id'=>$pid,'day'=>$day,
      'day_title'=>$title,'location_evidence'=>mb_substr($description,0,280),
      'photo_count'=>0,'severity'=>'content_gap',
      'review_scope'=>'specific_program_day','rights_review_required'=>true,
      'content_job_request'=>[
        'subject'=>$subject,'language'=>'en','country'=>'eg',
        'content_type'=>'destination_media','target_post_type'=>'tours-and-activities',
        'automation_level'=>'assisted','research_depth'=>'standard',
        'reason'=>mb_substr($reason,0,490),
      ],'status'=>'PENDING_AUTHORIZED_CONTENT_JOB'
    ];
  }
  $base['candidate_count']=count($base['candidates']);
  return $base;
 }
 public static function cli($args,$assoc):void {
   $id=absint($args[0]??0);
   WP_CLI::line(wp_json_encode(self::plan($id),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 }
}
if(defined('WP_CLI')&&WP_CLI)
  WP_CLI::add_command('mad4b gallery-coverage',[MAD4B_Cruise_Gallery_Coverage_V1::class,'cli']);
