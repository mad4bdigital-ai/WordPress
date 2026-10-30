<?php
/** Governed Wayfatours JetEngine design-model auto-activation (no source content transfer). */
if (!defined('WP_CLI') || !WP_CLI) exit(11);
global $wpdb;
$action=(string)getenv('MAD4B_JET_ACTION');
$payload=json_decode((string)getenv('MAD4B_JET_PAYLOAD'),true);
$identity=get_option('mad4b_company_site_identity_v1',array());
if (!in_array($action,array('plan','backup','apply'),true) ||
    DB_NAME!=='wayfatours_production' || wp_get_environment_type()!=='production' ||
    wp_parse_url(home_url(),PHP_URL_HOST)!=='wayfatours.mad4b.com' ||
    ($identity['company_code']??'')!=='wayfatours' ||
    ($identity['site_uuid']??'')!=='b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b' ||
    !is_array($payload) || ($payload['contract']??'')!=='mad4b.wayfatours-jet-config-autoload.v1' ||
    !is_array($payload['rows']??null) || count($payload['rows'])!==332 ||
    ($payload['schema']['state']??'')!=='SCHEMA_PARITY_CONFIRMED')
    WP_CLI::error('JET_TENANT_OR_MANIFEST_MISMATCH');
foreach(array('jet_post_types'=>307,'jet_taxonomies'=>34) as $tableName=>$count) {
    $table=$wpdb->prefix.$tableName;
    $rows=$wpdb->get_results("SELECT * FROM {$table} ORDER BY id",ARRAY_A);
    if(count($rows)!==$count || ($payload['schema']['target']['tables'][$tableName]['sha256']??null)!==
       hash('sha256',wp_json_encode($rows)))
       WP_CLI::error('JET_SCHEMA_DRIFT_'.$tableName);
}
$types=array_fill_keys((array)($payload['schema']['target']['registered_all_post_types']??array()),true);
$taxonomies=array_fill_keys((array)($payload['schema']['target']['registered_all_taxonomies']??array()),true);
$qmap=array();
foreach($wpdb->get_results("SELECT id,status FROM {$wpdb->prefix}jet_post_types WHERE status='query'",ARRAY_A) as $entry)
  $qmap[(int)$entry['id']]=true;
$table=$wpdb->prefix.'icl_translations';
$mapped=array();
foreach($wpdb->get_results("SELECT element_id,trid,language_code,source_language_code FROM {$table} WHERE element_type='post_jet-engine'",ARRAY_A) as $row)
 $mapped[(int)$row['element_id']]=$row;
$entries=array();$groups=array();$eligible=array();$reasons=array();
foreach($payload['rows'] as $r){
    $id=$r['id']??0;$trid=(string)($r['source_trid']??'');
    $lang=(string)($r['lang']??'');
    if(!is_int($id)||$id<1||!preg_match('/^[0-9]+$/D',$trid)||
       !in_array($lang,array('en','ar','fr','de','it','es'),true)||isset($entries[$id]))
       WP_CLI::error('JET_SOURCE_MEMBER_INVALID');
    $post=get_post($id);
    if(!$post||$post->post_type!=='jet-engine')WP_CLI::error('JET_TARGET_POST_MISSING_'.$id);
    $lock=get_post_meta($id,'_mad4b_design_import_lock',true);
    $srcstatus=get_post_meta($id,'_mad4b_source_design_status',true);
    if($lock!==($payload['blueprint_sha256']??null) || $srcstatus!==($r['status']??null))
       WP_CLI::error('JET_SOURCE_ATTESTATION_MISMATCH_'.$id);
    $groups[$trid][]=$id;
    $entries[$id]=$r;
    $blocks=array();
    if($r['status']!=='publish'||$post->post_status!=='draft')
      $blocks[]='not_eligible_source_state';
    $data=(string)get_post_meta($id,'_elementor_data',true);
    $content=$data.' '.$post->post_content;
    if($data!=='' && !is_array(json_decode($data,true)))$blocks[]='invalid_elementor_json';
    if($data==='')$blocks[]='missing_elementor_layout';
    if(preg_match('~tours\.dev\.mad4b\.com|wovacation|woo[\s-]*vacation|egypttourgates|allroyalegypt|donatours~i',$content))
      $blocks[]='source_identity_reference';
    if(preg_match('~/wp-content/uploads/|wp-image-[0-9]+~i',$content))
      $blocks[]='unmapped_source_media';
    $refs=maybe_unserialize(get_post_meta($id,'referenced_media_ids',true));
    if(is_array($refs) && count(array_filter($refs)))$blocks[]='unresolved_attachment_id';
    if(preg_match('~\bETG\b|egypttourgates|woo[\s-]*vacation~i',$post->post_title))
      $blocks[]='source_brand_title';
    $provider=maybe_unserialize(get_post_meta($id,'_listing_data',true));
    $source=is_array($provider)?(string)($provider['source']??''):'';
    $postType=is_array($provider)?(string)($provider['post_type']??''):'';
    $tax=is_array($provider)?(string)($provider['tax']??''):'';
    if($source==='posts' && $postType!=='' && !isset($types[$postType]))
       $blocks[]='unregistered_content_provider_'.$postType;
    if($source==='terms' && $tax!=='' && !isset($taxonomies[$tax]))
       $blocks[]='unregistered_taxonomy_provider_'.$tax;
    $qid=get_post_meta($id,'_query_id',true);
    if($qid!=='' && $qid!==null && is_numeric($qid) && !isset($qmap[(int)$qid]))
       $blocks[]='query_id_missing';
    if($post->post_status==='draft' && $r['status']==='publish' && !$blocks)$eligible[$id]=true;
    foreach(array_unique($blocks) as $reason)$reasons[$reason]=($reasons[$reason]??0)+1;
}
if(count($groups)!==83||count($entries)!==332)WP_CLI::error('JET_GROUP_COUNT_INVALID');
foreach($groups as $ids) {
    $langs=array();
    foreach($ids as $id){
        $lang=$entries[$id]['lang'];
        if(isset($langs[$lang]))WP_CLI::error('JET_DUPLICATE_GROUP_LANGUAGE');
        $langs[$lang]=true;
        if(isset($mapped[$id]) && $mapped[$id]['language_code']!==$lang)
          WP_CLI::error('JET_EXISTING_LANGUAGE_CONFLICT');
    }
}
ksort($eligible);ksort($reasons);
$sourceHash=(string)($payload['blueprint_sha256']??'');
$planHash=hash('sha256',wp_json_encode(array('source'=>$sourceHash,
  'eligible_ids'=>array_keys($eligible),
  'schema'=>$payload['schema']['fingerprint']??'',
  'existing_wpml'=>count($mapped))));
$summary=array('contract'=>'mad4b.wayfatours-jet-design-autoload.v1',
 'state'=>'JET_CONFIG_AUTO_PLAN',
 'schema_parity'=>true,'post_count'=>332,'groups'=>count($groups),
 'eligible'=>count($eligible),'eligible_ids'=>array_keys($eligible),
 'currently_linked'=>count($mapped),'linkable'=>332-count($mapped),
 'blocked_reason_counts'=>$reasons,'plan_sha256'=>$planHash,
 'source_sha256'=>$sourceHash,'site_uuid'=>$identity['site_uuid'],
 'mutation_performed'=>false,'media_copied'=>false);
if($action==='plan'){echo wp_json_encode($summary,JSON_UNESCAPED_SLASHES).PHP_EOL;return;}
if($action==='backup'){
    $statuses=array();
    foreach($entries as $id=>$r)$statuses[$id]=get_post_status($id);
    echo wp_json_encode(array('state'=>'JET_BACKUP','plan_sha256'=>$planHash,
     'statuses'=>$statuses,'prior_links'=>$mapped,'source_sha256'=>$sourceHash,
     'site_uuid'=>$identity['site_uuid']),JSON_UNESCAPED_SLASHES).PHP_EOL;
    return;
}
if(getenv('MAD4B_JET_BACKUP_CONFIRMED')!=='1' ||
   getenv('MAD4B_JET_EXPECTED_PLAN')!==$planHash)
    WP_CLI::error('JET_BACKUP_OR_EXACT_PLAN_REQUIRED');
$lock='mad4b:wayfa-jet-config-autoload';
if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,15)',$lock))!==1)
  WP_CLI::error('JET_ACTIVATION_LOCK_BUSY');
$wpdb->query('START TRANSACTION');
try{
    $newgroups=0;$newlinks=0;$next=(int)$wpdb->get_var("SELECT COALESCE(MAX(trid),0) FROM {$table}")+1;
    foreach($groups as $group){
        $trid=null;
        foreach($group as $id)if(isset($mapped[$id])){$trid=(int)$mapped[$id]['trid'];break;}
        if($trid===null){$trid=$next++;$newgroups++;}
        $originLang='en';
        $groupLangs=array_map(static function($id)use($entries){return $entries[$id]['lang'];},$group);
        if(!in_array('en',$groupLangs,true))$originLang=$groupLangs[0];
        foreach($group as $id){
            if(isset($mapped[$id]))continue;
            $lang=$entries[$id]['lang'];
            if($wpdb->insert($table,array('element_type'=>'post_jet-engine',
                   'element_id'=>$id,'trid'=>$trid,'language_code'=>$lang,
                   'source_language_code'=>$lang===$originLang?null:$originLang),
                   array('%s','%d','%d','%s','%s'))!==1)
               throw new Exception('JET_LANGUAGE_INSERT_FAILED_'.$id);
            $newlinks++;
        }
    }
    $activated=0;
    foreach(array_keys($eligible) as $id){
        if($wpdb->update($wpdb->posts,array('post_status'=>'publish'),array('ID'=>$id),
           array('%s'),array('%d'))!==1)throw new Exception('JET_POST_UPDATE_FAILED_'.$id);
        $activated++;
    }
    $pub=0;
    foreach(array_keys($eligible) as $id)if($wpdb->get_var($wpdb->prepare(
      "SELECT post_status FROM {$wpdb->posts} WHERE ID=%d",$id))==='publish')$pub++;
    if($pub!==$activated)throw new Exception('JET_STATUS_READBACK_FAILED');
    if($wpdb->query('COMMIT')===false)throw new Exception('JET_COMMIT_FAILED');
    wp_cache_flush();
    $summary['state']='JET_CONFIG_AUTO_APPLIED';
    $summary['activated']=$activated;$summary['wpml_links_inserted']=$newlinks;
    $summary['new_target_translation_groups']=$newgroups;
    $summary['mutation_performed']=$activated>0||$newlinks>0;
    echo wp_json_encode($summary,JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){$wpdb->query('ROLLBACK');WP_CLI::error('JET_AUTOMATION_ROLLED_BACK:'.$e->getMessage());}
finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}

