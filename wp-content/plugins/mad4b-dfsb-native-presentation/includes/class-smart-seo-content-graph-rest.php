<?php
namespace MAD4B\DFSB\NativePresentation;
if (!defined('ABSPATH')) exit;
final class SmartSeoContentGraphRest {
 const NS='mad4b/v1';
 public static function register(): void {
  $p=[__CLASS__,'authorized'];
  foreach ([
   ['/seo-intelligence/content-graph/term','GET','term'],
   ['/seo-intelligence/content-graph/site-audit','GET','siteAudit'],
   ['/seo-intelligence/content-graph/programs-readiness','GET','programReadiness'],
   ['/seo-intelligence/content-graph/crawl','POST','crawl'],
   ['/seo-intelligence/combinations/(?P<id>[a-f0-9]{64})/content-evaluate','POST','evaluate'],
   ['/seo-intelligence/combinations/(?P<id>[a-f0-9]{64})/content-review','POST','review']
  ] as $entry) register_rest_route(self::NS,$entry[0],[
   'methods'=>$entry[1],'permission_callback'=>$p,'callback'=>[__CLASS__,$entry[2]]]);
 }
 public static function authorized(): bool {return current_user_can('manage_options');}
 public static function term(\WP_REST_Request $req) {
  $term=SmartSeoContentGraph::term((int)$req->get_param('id'),
    (string)($req->get_param('taxonomy')??''),
    (string)($req->get_param('language')??''));
  return is_wp_error($term)?$term:rest_ensure_response($term);
 }
 public static function siteAudit(\WP_REST_Request $req) {
  $language=(string)($req->get_param('language')??'en');
  $taxonomy=sanitize_key((string)($req->get_param('taxonomy')??'destinations'));
  $limit=max(1,min(20,(int)($req->get_param('limit')??10)));
  $offset=max(0,min(5000,(int)($req->get_param('offset')??0)));
  if(!in_array($language,SmartSeoTranslation::languages(),true))
   return new \WP_Error('audit_language_invalid','Language inactive',['status'=>422]);
  $tax=get_taxonomy($taxonomy);
  if(!$tax || !$tax->public)
   return new \WP_Error('audit_taxonomy_invalid','Public taxonomy required',['status'=>422]);
  $selection=trim((string)($req->get_param('source_ids')??''));
  if($selection!=='') {
   $ids=array_filter(array_map('intval',explode(',',$selection)));
   if(!$ids||count($ids)>20||count($ids)!==count(explode(',',$selection)))
    return new \WP_Error('audit_source_ids_invalid','1–20 English term IDs required',['status'=>422]);
   $ids=array_values(array_unique($ids));
  } else {
   $ids=SmartSeoTranslation::withLanguage('en',static function()use($taxonomy,$limit,$offset) {
     $terms=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false,'number'=>$limit,
       'offset'=>$offset,'orderby'=>'count','order'=>'DESC']);
     if(is_wp_error($terms))return [];
     return array_values(array_map(static fn($t)=>(int)$t->term_id,array_filter($terms,
       static fn($t)=>apply_filters('wpml_element_language_code',null,[
         'element_id'=>$t->term_id,'element_type'=>'tax_'.$taxonomy])==='en')));
   });
  }
  return rest_ensure_response(SmartSeoContentGraph::siteAudit($taxonomy,$language,$ids));
 }
 public static function programReadiness(\WP_REST_Request $req) {
  global $wpdb;
  $tax='programs';
  if(!taxonomy_exists($tax))
   return new \WP_Error('program_taxonomy_unavailable','Programs taxonomy not registered',['status'=>404]);
  $terms=get_terms(['taxonomy'=>$tax,'hide_empty'=>false,'number'=>100]);
  $terms=is_wp_error($terms)?[]:$terms;
  $relations=(int)$wpdb->get_var($wpdb->prepare(
   "SELECT COUNT(*) FROM {$wpdb->term_relationships} r INNER JOIN {$wpdb->term_taxonomy} tt ON r.term_taxonomy_id=tt.term_taxonomy_id WHERE tt.taxonomy=%s",$tax));
  $rates=(int)$wpdb->get_var($wpdb->prepare(
   "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key=%s WHERE p.post_type='tour-rates' AND p.post_status='publish' AND pm.meta_value<>''",
   'related_program_term_id'));
  $tourIds=get_posts(['post_type'=>'tours-and-activities','post_status'=>'publish',
   'posts_per_page'=>-1,'fields'=>'ids','suppress_filters'=>true]);
  $cruises=0;$multi=0;$standaloneItineraries=0;$nileTagged=0;$unrecognizedNile=0;
  foreach($tourIds as $id) {
   $id=(int)$id;
   $cruiseMode=get_post_meta($id,'tour_mode',true)==='cruise_tour';
   if($cruiseMode) {
    $cruises++;
    if(function_exists('shared_product_tour_itinerary_contract_v1')) {
     $contract=shared_product_tour_itinerary_contract_v1($id);
     if(!empty($contract['customer_program_choice']))$multi++;
     if(($contract['state']??'')==='single_post_cruise_itinerary')$standaloneItineraries++;
    }
   }
   $nileTerms=wp_get_post_terms($id,'tour-types',['fields'=>'slugs']);
   if(!is_wp_error($nileTerms)&&in_array('nile-cruises',$nileTerms,true)) {
    $nileTagged++;
    if(!$cruiseMode)$unrecognizedNile++;
   }
  }
  return rest_ensure_response([
   'contract'=>'mad4b.seo.programs-readiness.v1',
   'taxonomy'=>$tax,'terms_count'=>count($terms),'native_taxonomy_relations'=>$relations,
   'published_rate_program_references'=>$rates,'published_cruise_mode_tours'=>$cruises,
   'multi_program_choice_tours'=>$multi,'single_post_cruise_itineraries'=>$standaloneItineraries,
   'nile_cruises_tagged'=>$nileTagged,'nile_cruises_without_cruise_tour_mode'=>$unrecognizedNile,
   'programs_archive_facet_ready'=>$relations>0&&$cruises>0,
   'program_rate_selector_ready'=>$multi>0&&$rates>0,
   'auto_derive_program_from_duration'=>false,
   'current_single_itinerary_cruises_valid'=>$cruises>0&&$standaloneItineraries===$cruises,
   'reusable_program_choice_required'=>$multi>0,
   'action_required'=>$multi>0 && !$rates
     ? 'Verify each reusable multi-program cruise and its actual program-linked Rates'
     : 'Existing cruises use valid single embedded itineraries; assign reusable programs only when a product truly offers separately selectable programs',
   'writes_performed'=>false
  ]);
 }
 public static function crawl(\WP_REST_Request $req) {
  $data=(array)$req->get_json_params();
  $root=(string)($data['root']??home_url('/'));
  if(SmartSeoLinkCrawler::key($root)==='')
   return new \WP_Error('crawl_root_rejected','Only this website can be crawled',['status'=>400]);
  return rest_ensure_response(SmartSeoLinkCrawler::crawl($root,
    (int)($data['max_pages']??12),(int)($data['max_depth']??3)));
 }
 public static function evaluate(\WP_REST_Request $req) {
  $id=(string)$req['id'];$record=SmartSeoCombinations::get($id);
  if(is_wp_error($record))return $record;
  $body=(array)$req->get_json_params();
  $rev=(string)($body['expected_revision']??'');
  if(!hash_equals(SmartSeoCombinations::revision($record),$rev))
   return new \WP_Error('revision_conflict','Reload record first',['status'=>409]);
  if(($record['status']??'')==='published')
   return new \WP_Error('published_immutable','Rollback first',['status'=>409]);
  $graph=SmartSeoContentGraph::combination($record);
  $graph['evaluated_at']=gmdate('c');
  $graph['policy_revision']=SmartSeoPolicyRegistry::revision();
  $record['content_graph']=$graph;$record['approval']=null;
  $record['updated_at']=gmdate('c');
  $saved=SmartSeoCombinations::put($id,$rev,$record);
  return is_wp_error($saved)?$saved:rest_ensure_response($saved);
 } 
 public static function review(\WP_REST_Request $r) {
  $id=(string)$r['id'];$record=SmartSeoCombinations::get($id);
  if(is_wp_error($record))return $record;
  $b=(array)$r->get_json_params();$rev=(string)($b['expected_revision']??'');
  if(!hash_equals(SmartSeoCombinations::revision($record),$rev))
   return new \WP_Error('revision_conflict','Reload first',['status'=>409]);
  if(($record['status']??'')==='published')
   return new \WP_Error('active_review_denied','Rollback first',['status'=>409]);
  if(!is_bool($b['approved']??null))
   return new \WP_Error('decision_required','Boolean approval required',['status'=>400]);
  $g=$record['content_graph']??[];
  if(($g['policy_revision']??'')!==SmartSeoPolicyRegistry::revision())
   return new \WP_Error('graph_stale','Evaluate under current policy',['status'=>409]);
  if($b['approved'] && (($g['classification']??'')!=='commercial_results'||empty($g['editorial_hubs'])))
   return new \WP_Error('content_not_qualified','Results and rich editorial hubs required',['status'=>409]);
  $origin=(string)($b['review_origin']??'wordpress_editor');
  if(!in_array($origin,['wordpress_editor','assistant_delegated'],true))
   return new \WP_Error('review_origin_invalid','Invalid origin',['status'=>422]);
  $record['content_review']=['approved'=>$b['approved'],
     'review_origin'=>$origin,'human_editor_reviewed'=>$origin==='wordpress_editor',
     'reviewed_by'=>get_current_user_id(),'reviewed_at'=>gmdate('c')];
  $record['approval']=null;
  $out=SmartSeoCombinations::put($id,$rev,$record);
  return is_wp_error($out)?$out:rest_ensure_response($out);
 }

}
