<?php
/** Lab-only reusable product date coverage core. */
if (!defined('ABSPATH')) exit;
interface Shared_Product_Date_Provider {
 public function supports(int $product_id): bool;
 public function windows(int $product_id, ?int $season_id, ?int $program_id, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
final class Shared_Product_Season_Registry {
 public static function resolve($season) {
  if ($season === null || $season === '') return null;
  if (!taxonomy_exists('season')) return new WP_Error('season_unavailable','Season taxonomy unavailable');
  $term=get_term_by(ctype_digit((string)$season)?'id':'slug',ctype_digit((string)$season)?(int)$season:(string)$season,'season');
  return $term ?: new WP_Error('season_invalid','Unknown season');
 }
}
final class Shared_Product_Tour_Rate_Provider implements Shared_Product_Date_Provider {
 public function supports(int $id): bool { return get_post_type($id)==='tours-and-activities' && get_post_status($id)==='publish'; }
 public function windows(int $id, ?int $season, ?int $program, DateTimeImmutable $from, DateTimeImmutable $to): array {
  $ids=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','meta_key'=>'related_tour_id','meta_value'=>(string)$id,'suppress_filters'=>true]);$out=[];
  foreach($ids as $rid){if($program && (int)get_post_meta($rid,'related_program_term_id',true)!==$program)continue;
   if($season && !has_term($season,'season',$rid))continue;
   if((string)get_post_meta($rid,'stop_sale',true)==='1')continue;
   $start=get_post_meta($rid,'tour_rate_start_date',true);$end=get_post_meta($rid,'tour_rate_end_date',true);
   if(!is_numeric($start)||!is_numeric($end))continue;$s=(int)$start;$e=(int)$end;if($s<=0||$e<$s)continue;
   $left=max($from->getTimestamp(),$s);$right=min($to->getTimestamp(),$e);if($left>$right)continue;
   $out[]=['rate_id'=>(int)$rid,'start'=>gmdate('Y-m-d',$left),'end'=>gmdate('Y-m-d',$right)];
  }return $out;
 }
}
final class Shared_Product_Provider_Registry {
 public static function resolve(int $id) {
  $defaults=class_exists('Shared_Product_Provider_CPT_Registry')?Shared_Product_Provider_CPT_Registry::providers($id):[];
  $providers=apply_filters('shared_product_date_providers',$defaults);
  if (!is_array($providers)) return new WP_Error('invalid_provider_registry','Provider registry must be an array');
  $matches=[];
  foreach($providers as $provider) {
   if(!$provider instanceof Shared_Product_Date_Provider) return new WP_Error('invalid_provider','Provider does not implement contract');
   if($provider->supports($id)) $matches[]=$provider;
  }
  if(count($matches)>1) return new WP_Error('ambiguous_provider','Multiple availability providers match product');
  if(!$matches) return new WP_Error('unsupported_product','No provider for this published product');
  return $matches[0];
 }
}
final class Shared_Product_Date_Service {
 public static function parse($value){if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))return false;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));return $d&&$d->format('Y-m-d')===$value?$d:false;}
 public static function coverage(int $id,$season,$program,$from,$to){
  $a=self::parse($from);$b=self::parse($to);if(!$a||!$b||$b<$a||$a->diff($b)->days>366)return new WP_Error('invalid_window','Date window must be valid and <=366 days');
  $term=Shared_Product_Season_Registry::resolve($season);if(is_wp_error($term))return $term;
  $provider=Shared_Product_Provider_Registry::resolve($id);
  if(is_wp_error($provider))return $provider;
   $windows=$provider->windows($id,$term?(int)$term->term_id:null,$program?(int)$program:null,$a,$b);$dates=[];
   foreach($windows as $w){$x=self::parse($w['start']);$end=self::parse($w['end']);if(!$x||!$end)continue;for(;$x<=$end;$x=$x->modify('+1 day'))$dates[$x->format('Y-m-d')]=true;}
   $keys=array_keys($dates);sort($keys);return ['product_id'=>$id,'product_type'=>get_post_type($id),'season'=>$term?$term->slug:null,'program_id'=>$program?(int)$program:null,'from'=>$from,'to'=>$to,'dates'=>$keys,'rate_windows'=>$windows,'semantics'=>'rate_coverage_only_not_confirmed_booking_availability'];
 }
}
