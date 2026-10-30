<?php
namespace MAD4B\DFSB\NativePresentation;
if (!defined('ABSPATH')) exit;
final class SmartSeoContentGraphRest {
 const NS='mad4b/v1';
 public static function register(): void {
  $p=[__CLASS__,'authorized'];
  foreach ([
   ['/seo-intelligence/content-graph/term','GET','term'],
   ['/seo-intelligence/content-graph/crawl','POST','crawl'],
   ['/seo-intelligence/combinations/(?P<id>[a-f0-9]{64})/content-evaluate','POST','evaluate'],
   ['/seo-intelligence/combinations/(?P<id>[a-f0-9]{64})/content-review','POST','review']
  ] as $entry) register_rest_route(self::NS,$entry[0],[
   'methods'=>$entry[1],'permission_callback'=>$p,'callback'=>[__CLASS__,$entry[2]]]);
 }
 public static function authorized(): bool {return current_user_can('manage_options');}
 public static function term(\WP_REST_Request $req) {
  $term=SmartSeoContentGraph::term((int)$req->get_param('id'),
    (string)($req->get_param('taxonomy')??''));
  return is_wp_error($term)?$term:rest_ensure_response($term);
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
  $record['content_review']=['approved'=>$b['approved'],
     'reviewed_by'=>get_current_user_id(),'reviewed_at'=>gmdate('c')];
  $record['approval']=null;
  $out=SmartSeoCombinations::put($id,$rev,$record);
  return is_wp_error($out)?$out:rest_ensure_response($out);
 }

}