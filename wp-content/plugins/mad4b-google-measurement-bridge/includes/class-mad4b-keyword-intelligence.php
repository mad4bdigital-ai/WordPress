<?php
if (!defined('ABSPATH')) exit;
/** Deterministic, explainable keyword opportunity scoring, clustering and draft planning. */
final class MAD4B_Keyword_Intelligence {
 const CONTRACT='mad4b.keyword-intelligence.v1';
 private static function phrase($s) {
  $s=trim(preg_replace('/\s+/u',' ',(string)$s));
  return function_exists('mb_strtolower')?mb_strtolower($s,'UTF-8'):strtolower($s);
 }
 private static function tokens($s) {
  $parts=preg_split('/[^\p{L}\p{N}]+/u',self::phrase($s),-1,PREG_SPLIT_NO_EMPTY);
  $stop=array('the','a','an','and','of','in','to','for','في','من','على','عن','الى','إلى','و');
  return array_values(array_unique(array_diff(is_array($parts)?$parts:array(),$stop)));
 }
 private static function intent($s) {
  $s=self::phrase($s);
  if (preg_match('/(?:price|cost|book|booking|buy|discount|deal|سعر|اسعار|أسعار|حجز|تكلفة|عرض|عروض)/u',$s))return 'transactional';
  if (preg_match('/(?:best|compare|review|top|vs|افضل|أفضل|مقارنة|تقييم)/u',$s))return 'commercial';
  if (preg_match('/(?:how|what|when|where|guide|itinerary|كيفية|كيف|ما |متى|دليل|برنامج)/u',$s))return 'informational';
  return 'mixed_or_unknown';
 }
 public static function generate($input=array()) {
  if (!MAD4B_Google_Measurement_Bridge::gate())return new WP_Error('keyword_intelligence_permission','Site permission denied.');
  $keywords=$input['keywords']??array();
  if (!is_array($keywords)||count($keywords)<1||count($keywords)>500)
   return new WP_Error('keyword_intelligence_input','Supply 1..500 keywords.');
  $rows=array();$seen=array();
  foreach($keywords as $item) {
   if (!is_array($item))return new WP_Error('keyword_intelligence_input','Structured keyword rows required.');
   $word=trim((string)($item['keyword']??''));
   if ($word===''||strlen($word)>160||preg_match('/[\x00-\x1f<>]/',$word))
    return new WP_Error('keyword_intelligence_input','Invalid keyword.');
   $key=self::phrase($word);if(isset($seen[$key]))continue;$seen[$key]=true;
   $vol=isset($item['avg_monthly_searches'])?max(0,min(100000000,(int)$item['avg_monthly_searches'])):null;
   $comp=isset($item['competition_index'])?max(0,min(100,(int)$item['competition_index'])):null;
   $impressions=isset($item['gsc_impressions'])?max(0,(int)$item['gsc_impressions']):null;
   $clicks=isset($item['gsc_clicks'])?max(0,(int)$item['gsc_clicks']):null;
   $position=isset($item['gsc_position'])?max(0,(float)$item['gsc_position']):null;
   $intent=self::intent($word);
   // Ads competition is paid-market competition, NOT organic SEO difficulty.
   // Opportunity uses only volume + verified GSC observations.
   $volume_score=$vol===null?null:min(50,round(log(1+$vol,10)*12,1));
   $gsc_score=$impressions===null?null:min(35,round(log(1+$impressions,10)*7,1));
   $position_score=$position===null?null:($position>=5&&$position<=20?15:($position>20?8:0));
   $score=$volume_score===null&&$gsc_score===null?null:round(($volume_score??0)+($gsc_score??0)+($position_score??0),1);
   $rows[]=array('keyword'=>$word,'intent_heuristic'=>$intent,'avg_monthly_searches'=>$vol,
    'ads_competition_index'=>$comp,'gsc_impressions'=>$impressions,'gsc_clicks'=>$clicks,'gsc_position'=>$position,
    'opportunity_score'=>$score,'score_is_provisional'=>true,'tokens'=>self::tokens($word));
  }
  $clusters=array();
  foreach($rows as $index=>$row) {
   $best=null;$overlap=0;
   foreach($clusters as $id=>$cluster) {
    $common=count(array_intersect($cluster['seed_tokens'],$row['tokens']));
    if ($common>$overlap) {$overlap=$common;$best=$id;}
   }
   if ($best===null||$overlap===0) {
    $id='cluster_'.(count($clusters)+1);
    $clusters[$id]=array('id'=>$id,'seed_keyword'=>$row['keyword'],
     'seed_tokens'=>$row['tokens'],'keywords'=>array(),'intent_mix'=>array(),'opportunity_max'=>null);
    $best=$id;
   }
   $clusters[$best]['keywords'][]=$row['keyword'];
   $intent=$row['intent_heuristic'];
   $clusters[$best]['intent_mix'][$intent]=($clusters[$best]['intent_mix'][$intent]??0)+1;
   if ($row['opportunity_score']!==null)
    $clusters[$best]['opportunity_max']=max($clusters[$best]['opportunity_max']??0,$row['opportunity_score']);
  }
  foreach($clusters as &$c)unset($c['seed_tokens']);
  unset($c);
  usort($rows,static function($a,$b){return ($b['opportunity_score']??-1)<=>($a['opportunity_score']??-1);});
  return array('contract'=>self::CONTRACT,'keywords'=>$rows,'clusters'=>array_values($clusters),
   'draft_briefs'=>array_map(static function($c){
     return array('working_title'=>$c['seed_keyword'],'primary_keyword'=>$c['seed_keyword'],
       'supporting_keywords'=>array_slice($c['keywords'],1,20),
       'suggested_output'=>'editorial_brief_for_review','publish_authorized'=>false);},array_values($clusters)),
   'notes'=>array('Intent is a lexical heuristic, not an ML or SERP validation.',
    'Scores are comparative and provisional, never SEO ranking difficulty.',
    'No Google data was fetched by this local analysis operation.'),
   'mutation_performed'=>false,'read_only'=>true);
 }
 public static function register() {
  if(!function_exists('wp_register_ability'))return;
  $name='mad4b/keyword-intelligence-plan';if(function_exists('wp_has_ability')&&wp_has_ability($name))return;
  wp_register_ability($name,array('label'=>'Keyword Clustering and Content Planning',
   'description'=>'Heuristic keyword clusters, provisional scoring and draft editorial briefs without publication.',
   'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'generate'),
   'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false,'required'=>array('keywords'),
    'properties'=>array('keywords'=>array('type'=>'array','minItems'=>1,'maxItems'=>500,
     'items'=>array('type'=>'object','additionalProperties'=>false,
      'required'=>array('keyword'),'properties'=>array('keyword'=>array('type'=>'string','maxLength'=>160),
       'avg_monthly_searches'=>array('type'=>'integer'),'competition_index'=>array('type'=>'integer'),
       'gsc_impressions'=>array('type'=>'integer'),'gsc_clicks'=>array('type'=>'integer'),
       'gsc_position'=>array('type'=>'number')))))),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
 }
}
