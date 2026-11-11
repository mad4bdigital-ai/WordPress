<?php
/** ETG lab availability search windows; rates do not prove bookable inventory. */
if (!defined('ABSPATH')) exit;
final class ETG_Lab_Availability_API {
 public static function boot(){if(wp_get_environment_type()!=='local')return;add_action('rest_api_init',function(){register_rest_route('etg-lab/v1','/rate-dates',['methods'=>'GET','callback'=>[__CLASS__,'dates'],'permission_callback'=>'__return_true','args'=>['tour_id'=>['required'=>true,'sanitize_callback'=>'absint'],'season'=>['sanitize_callback'=>'sanitize_key'],'program_id'=>['sanitize_callback'=>'absint'],'from'=>['sanitize_callback'=>'sanitize_text_field'],'to'=>['sanitize_callback'=>'sanitize_text_field']]]);register_rest_route('etg-lab/v1','/validate-rate-date',['methods'=>'GET','callback'=>[__CLASS__,'validate_date'],'permission_callback'=>'__return_true']);});}
 public static function validate_date($req){
  $d=(string)$req->get_param('date');if(!self::parse_date($d))return new WP_Error('etg_invalid_date','Invalid date',['status'=>400]);
  $sub=new WP_REST_Request('GET','/etg-lab/v1/rate-dates');foreach(['tour_id','season','program_id'] as $p)$sub->set_param($p,$req->get_param($p));$sub->set_param('from',$d);$sub->set_param('to',$d);
  $response=self::dates($sub);if(is_wp_error($response))return $response;$data=$response->get_data();return rest_ensure_response(['date'=>$d,'rate_covered'=>in_array($d,$data['dates'],true),'matching_rate_ids'=>array_column($data['rate_windows'],'rate_id'),'booking_confirmed'=>false]);
 }
 private static function parse_date($v){if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v))return false;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('UTC'));return $d&&$d->format('Y-m-d')===$v?$d:false;}
 public static function dates($request){$tour=(int)$request->get_param('tour_id');if(get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')return new WP_Error('etg_invalid_tour','Published tour required',['status'=>404]);
  $from=self::parse_date($request->get_param('from')?:gmdate('Y-m-d'));$to=self::parse_date($request->get_param('to')?:gmdate('Y-m-d',strtotime('+180 days')));if(!$from||!$to||$to<$from||$from->diff($to)->days>366)return new WP_Error('etg_invalid_window','Date window must be valid and at most 366 days',['status'=>400]);
  $season=(string)$request->get_param('season');$term=null;if($season!==''){$term=get_term_by(ctype_digit($season)?'id':'slug',ctype_digit($season)?(int)$season:$season,'season');if(!$term)return new WP_Error('etg_invalid_season','Unknown season',['status'=>400]);}
  $program=(int)$request->get_param('program_id');$ids=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','meta_key'=>'related_tour_id','meta_value'=>(string)$tour,'suppress_filters'=>true]);$windows=[];
  foreach($ids as $id){if($program&&(int)get_post_meta($id,'related_program_term_id',true)!==$program)continue;if($term&&!has_term((int)$term->term_id,'season',$id))continue;
   $start=get_post_meta($id,'tour_rate_start_date',true);$end=get_post_meta($id,'tour_rate_end_date',true);if(!is_numeric($start)||!is_numeric($end))continue;$s=(int)$start;$e=(int)$end;if($s<=0||$e<$s)continue;
   $a=max($from->getTimestamp(),$s);$b=min($to->getTimestamp(),$e);if($a>$b)continue;$windows[]=['rate_id'=>(int)$id,'start'=>gmdate('Y-m-d',$a),'end'=>gmdate('Y-m-d',$b)];
  }
  $days=[];foreach($windows as $w){$d=new DateTimeImmutable($w['start'],new DateTimeZone('UTC'));$last=new DateTimeImmutable($w['end'],new DateTimeZone('UTC'));while($d<=$last){$days[$d->format('Y-m-d')]=true;$d=$d->modify('+1 day');}}
  $out=array_keys($days);sort($out);return rest_ensure_response(['tour_id'=>$tour,'season'=>$term?$term->slug:null,'program_id'=>$program?:null,'from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d'),'dates'=>$out,'rate_windows'=>$windows,'semantics'=>'rate_coverage_only_not_confirmed_booking_availability']);
 }
}
add_action('init',['ETG_Lab_Availability_API','boot'],40);
