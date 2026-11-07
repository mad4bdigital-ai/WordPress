<?php
if(!defined('WP_CLI') || !WP_CLI) { echo "CLI_ONLY\n";return; }
global $wpdb;
$c='MAD4B_Page_Dependency_Cache';
$s='MAD4B_Published_Content_Scope_Cache';
if(!class_exists($c)||!class_exists($s))WP_CLI::error('CACHE_ENGINES_NOT_LOADED');
$pages=$wpdb->prefix.'mad4b_pd_pages';
$edges=$wpdb->prefix.'mad4b_pd_edges';
$q=(object)['id'=>998877,'query_type'=>'posts',
    'final_query'=>['post_type'=>['tours-and-activities'],'post_status'=>'publish',
        'orderby'=>'date','order'=>'DESC']];
$unproven=(object)['id'=>998878,'query_type'=>'posts',
    'final_query'=>$q->final_query];
$info=(new ReflectionMethod($c,'query_signature'))->invoke(null,$q);
if(empty($info['supported']))WP_CLI::error('QA_QUERY_UNSUPPORTED');
$hash=$info['hash'];$ttl=900;
$old=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $pages WHERE page_hash=%s",$hash));
if($old)WP_CLI::error('QA_HASH_COLLISION');
$decision=['cache'=>true,'ttl'=>$ttl];
$log=['contract'=>'mad4b.cache.hybrid-authority-live-qa.v1'];
$wpdb->query('START TRANSACTION');
$pass=false;
try {
    $now=gmdate('Y-m-d H:i:s');
    $exp=gmdate('Y-m-d H:i:s',time()+35*DAY_IN_SECONDS);
    $ok=$wpdb->insert($pages,['page_hash'=>$hash,'page_path'=>'/_mad4b_query/'.$hash,
        'language'=>'en','device'=>'desktop','node_kind'=>'query','epoch'=>3,
        'hit_count'=>1,'coverage'=>'complete','updated_at'=>$now,'expires_at'=>$exp]);
    if($ok!==1)throw new RuntimeException('QA_MANIFEST_INSERT_FAILED');
    foreach($info['dependencies'] as $dep) {
        if($wpdb->insert($edges,['dep_hash'=>hash('sha256',$dep),'page_hash'=>$hash,
            'dep_key'=>$dep,'expires_at'=>$exp])!==1)throw new RuntimeException('QA_EDGE_INSERT_FAILED');
    }
    $cert=$c::authority($q,$decision);
    $scopeKey=$s::query_version('gen-qa',$q,$decision);
    $graphKey=$c::query_version($scopeKey,$q,$decision);
    $oldScope=$s::query_version('gen-qa',$unproven,$decision);
    $oldGraph=$c::query_version($oldScope,$unproven,$decision);
    $certified=$cert==='dependency'
        && $scopeKey==='gen-qa'
        && str_contains($graphKey,':hybrid-graph-v1:')
        && !str_contains($graphKey,':scope:')
        && $oldScope!== 'gen-qa'
        && str_contains($oldScope,':scope:')
        && $oldGraph===$oldScope;
    $log['certified_authority']=$cert;
    $log['certified_cache_key_contains_graph']=str_contains($graphKey,':hybrid-graph-v1:');
    $log['certified_cache_key_contains_post_type']=str_contains($graphKey,':scope:');
    $log['unproven_fallback_post_type']=str_contains($oldGraph,':scope:');
    $wpdb->update($pages,['expires_at'=>gmdate('Y-m-d H:i:s',time()+300)],
        ['page_hash'=>$hash],['%s'],['%s']);
    wp_cache_delete('authority-'.$hash.'-'.$ttl,$c::GROUP);
    $expiresSafe=$c::authority($q,$decision)==='legacy';
    $log['near_expiry_reverts_to_legacy']=$expiresSafe;
    $pass=$certified&&$expiresSafe;
    $log['pass']=$pass;
} catch(Throwable $e) {
    $log['pass']=false;
    $log['error_class']=get_class($e);
    $log['error']=$e->getMessage();
}
$wpdb->query('ROLLBACK');
wp_cache_delete('authority-'.$hash.'-'.$ttl,$c::GROUP);
wp_cache_delete('epoch-'.$hash,$c::GROUP);
$after=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $pages WHERE page_hash=%s",$hash));
$edgesAfter=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $edges WHERE page_hash=%s",$hash));
$log['qa_rows_after_rollback']=$after;
$log['qa_edges_after_rollback']=$edgesAfter;
$log['rollback_clean']=$after===0 && $edgesAfter===0;
$log['registry_revision']=(int)MAD4B_Company_Policy_Registry_V2::state()['revision'];
$sample=MAD4B_Company_Policy_Registry_V2::validate_rule([
    'provider_id'=>'page-dependency-cache','scope'=>'query',
    'scope_id'=>998877,'state'=>'draft',
    'values'=>['strategy'=>'legacy_post_type']
]);
$log['query_override_validated']=!is_wp_error($sample);
echo wp_json_encode($log,JSON_UNESCAPED_SLASHES)."\n";
if(!$pass || !$log['rollback_clean'] || !$log['query_override_validated'])
    WP_CLI::error('HYBRID_ACCEPTANCE_FAILED');
