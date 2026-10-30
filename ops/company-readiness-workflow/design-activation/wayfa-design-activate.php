<?php
/**
 * MAD4B company-neutral Elementor selective activation.
 * Only non-auto-applied sections/containers. Never rewrites source media.
 */
if (!defined('WP_CLI') || !WP_CLI) exit(11);
global $wpdb;
$act=(string)getenv('MAD4B_DESIGN_ACTION');
$manifest=json_decode((string)getenv('MAD4B_DESIGN_TEMPLATE_SOURCE'),true);
$identity=get_option('mad4b_company_site_identity_v1',array());
if(DB_NAME!=='wayfatours_production'||wp_get_environment_type()!=='production'||
    wp_parse_url(home_url(),PHP_URL_HOST)!=='wayfatours.mad4b.com'||
    ($identity['company_code']??'')!=='wayfatours'||
    ($identity['site_uuid']??'')!=='b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b'||
    get_option('blogname')!=='Wayfatours'||
    (int)get_option('elementor_active_kit')!==4||
    !is_array($manifest)||($manifest['contract']??'')!=='mad4b.wayfatours-template-source.v1'||
    !preg_match('/^[a-f0-9]{64}$/D',(string)($manifest['source_sha256']??''))||
    !is_array($manifest['templates']??null)||count($manifest['templates'])!==470||
    !in_array($act,array('plan','backup','apply'),true))WP_CLI::error('SOURCE_SITE_IDENTITY_OR_INPUT_DENIED');

$originalBrand='~(?:woo[\s_-]*vacation|wovacation|all[\s_-]*royal[\s_-]*egypt|allroyalegypt|egypt[\s_-]*tour[\s_-]*gates|egypttourgates|dona[\s_-]*tours|donatours|\bETG\b)~i';
$sourceHost='tours.dev.mad4b.com';
$srcMap=$manifest['templates'];
$compat=(string)($manifest['config_mode']??'strict');
if(!in_array($compat,array('strict','schema-auto'),true))WP_CLI::error('INVALID_COMPATIBILITY_MODE');
if($compat==='schema-auto'){
    $att=$manifest['schema_attestation']??null;
    if(!is_array($att)||($att['contract']??'')!=='mad4b.company-config-compatibility.v1'||
       ($att['state']??'')!=='SCHEMA_PARITY_CONFIRMED'||
       !is_array($att['target']['tables']??null))WP_CLI::error('SCHEMA_PARITY_PROOF_MISSING');
    foreach(array('jet_post_types'=>307,'jet_taxonomies'=>34) as $name=>$count){
        $table=$wpdb->prefix.$name;
        $actual=$wpdb->get_results("SELECT * FROM {$table} ORDER BY id",ARRAY_A);
        $expected=$att['target']['tables'][$name]??null;
        if(!is_array($expected)||count($actual)!==$count ||
           ($expected['count']??-1)!==$count ||
           ($expected['sha256']??'')!==hash('sha256',wp_json_encode($actual)))
           WP_CLI::error('ACTUAL_TENANT_SCHEMA_DRIFT_'.$name);
    }
    foreach(array('jet-engine','elementor','elementor-pro','jet-smart-filters') as $provider)
        if(empty($att['target']['providers'][$provider]))WP_CLI::error('MISSING_PROVIDER_'.$provider);
}
$targetAccount=(string)get_permalink((int)get_option('woocommerce_myaccount_page_id'));
$accountCanRemap=($targetAccount==='https://wayfatours.mad4b.com/my-account/' && get_post_status((int)get_option('woocommerce_myaccount_page_id'))==='publish');
$linksRewritten=0;
$brandRewritten=0;
$allowedTextKeys=array_fill_keys(array('title','heading','text','editor','description','button_text','label','caption','content','placeholder','prefix','suffix','sub_title','subtitle','testimonial_content','short_description','field_label'),true);
$brandReplace=function($s) {
    return preg_replace('~(?:woo[\s_-]*vacation|wovacation|all[\s_-]*royal[\s_-]*egypt|allroyalegypt|egypt[\s_-]*tour[\s_-]*gates|egypttourgates|dona[\s_-]*tours|donatours)~i','Wayfatours',$s);
};
$transform=function(&$v,$key='')use(&$transform,&$linksRewritten,&$brandRewritten,$allowedTextKeys,$brandReplace,$accountCanRemap) {
    if(is_array($v)){foreach($v as $k=>&$value)$transform($value,(string)$k);unset($value);return;}
    if(!is_string($v))return;
    if($accountCanRemap && $v==='https://tours.dev.mad4b.com/account/' &&
        in_array($key,array('url','link','href'),true)){
        $v='https://wayfatours.mad4b.com/my-account/';$linksRewritten++;return;
    }
    if(isset($allowedTextKeys[$key]) && preg_match('~(?:woo[\s_-]*vacation|wovacation|all[\s_-]*royal[\s_-]*egypt|allroyalegypt|egypt[\s_-]*tour[\s_-]*gates|egypttourgates|dona[\s_-]*tours|donatours)~i',$v)){
        $new=$brandReplace($v);
        if($new!==$v){$v=$new;$brandRewritten++;}
    }
};

$wpml=$wpdb->prefix.'icl_translations';
$data=$wpdb->get_results("SELECT p.ID,p.post_title,p.post_name,p.post_status,p.post_content,
  COALESCE(t.meta_value,'') template_type,COALESCE(d.meta_value,'') elementor_data,
  COALESCE(r.meta_value,'') media_refs,COALESCE(c.meta_value,'') conditions,
  COALESCE(w.language_code,'') language
 FROM {$wpdb->posts} p
 LEFT JOIN {$wpdb->postmeta} t ON p.ID=t.post_id AND t.meta_key='_elementor_template_type'
 LEFT JOIN {$wpdb->postmeta} d ON p.ID=d.post_id AND d.meta_key='_elementor_data'
 LEFT JOIN {$wpdb->postmeta} r ON p.ID=r.post_id AND r.meta_key='referenced_media_ids'
 LEFT JOIN {$wpdb->postmeta} c ON p.ID=c.post_id AND c.meta_key='_elementor_conditions'
 LEFT JOIN {$wpml} w ON w.element_id=p.ID AND w.element_type='post_elementor_library'
 WHERE p.post_type='elementor_library' AND p.post_status IN ('draft','publish')",ARRAY_A);
$eligible=array();$reasons=array();$stateMap=array();$alreadyPublished=0;
foreach($data as $row){
 $id=(int)$row['ID'];
 if(!isset($srcMap[(string)$id]))continue;
 $source=$srcMap[(string)$id];$block=array();$localLink=0;$localBrand=0;
 if($source['status']==='publish' && $row['post_status']==='publish') {
     $alreadyPublished++;
     continue;
 }
 if($source['status']!=='publish'||$row['post_status']!=='draft')
     $block[]='not_published_source_draft_target';
 $allowedKinds=$compat==='schema-auto'?
     array('section','container','popup','archive','single-page','single-post','page','jet-listing-items','header'):
     array('section','container');
 if(!in_array($row['template_type'],$allowedKinds,true))$block[]='requires_theme_or_special_activation';
 if($row['template_type']!==$source['kind'])$block[]='template_type_changed';
 if($row['language']!==$source['lang'])$block[]='missing_or_mismatched_wpml_language';
 if(!$accountCanRemap)$block[]='account_target_not_verified';
 if($row['conditions']!=='' && $row['conditions']!=='a:0:{}')$block[]='needs_scoped_theme_condition_resolver';
 if(preg_match($originalBrand,(string)$row['post_name']))$block[]='old_brand_slug';
 if($compat!=='schema-auto' &&
    preg_match('~(?:book(?:ing)?|check.?out|cart|payment|order|rate|pricing|price|reservation)~i',$row['post_title']))
     $block[]='commerce_template_needs_separate_acceptance';
 $media=maybe_unserialize($row['media_refs']);
 if(is_array($media)&&count(array_filter($media)))$block[]='unmapped_media_ids';
 $v=json_decode((string)$row['elementor_data'],true);
 if(!is_array($v) || json_last_error()!==JSON_ERROR_NONE)$block[]='elementor_json_invalid';
 $next=$row['elementor_data'];
 $nextTitle=$row['post_title'];
 $widgetTypes=array();
 $dynamicKinds=array();
 $walker=function($a)use(&$walker,&$widgetTypes,&$dynamicKinds){
   if(!is_array($a))return;
   if(isset($a['widgetType']) && is_string($a['widgetType']))
     $widgetTypes[$a['widgetType']]=true;
   if(isset($a['__dynamic__']) && is_array($a['__dynamic__'])){
     foreach($a['__dynamic__'] as $dv)if(is_string($dv)){
       if(strpos($dv,'jet-option')!==false)$dynamicKinds['jet-option']=true;
       if(strpos($dv,'jet-post-')!==false)$dynamicKinds['jet-post']=true;
     }
   }
   foreach($a as $nested)$walker($nested);
 };
 if(is_array($v)){
    $walker($v);
    $linksRewritten=0;$brandRewritten=0;
    $transform($v);
    $localLink=$linksRewritten;$localBrand=$brandRewritten;
    $next=wp_json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($next))$block[]='elementor_encode_failed';
 }
 if(isset($widgetTypes['fluent-form-widget'])||isset($widgetTypes['uael-social-share']))
     $block[]='missing_qualified_widget_provider_or_form';
 if(isset($dynamicKinds['jet-option']) && $compat!=='schema-auto')
     $block[]='company_option_binding_not_resolved';
 if(!empty($source['commercial_risk']) && $compat!=='schema-auto')
     $block[]='commercial_family_requires_rate_admission';
 if(preg_match($originalBrand,$next.' '.$row['post_content']))$block[]='unresolved_source_brand';
 if(stripos($next.' '.$row['post_content'],$sourceHost)!==false)$block[]='unresolved_source_host';
 if(preg_match('~(?:/wp-content/uploads/|wp-image-[0-9]+|[\"\\\\]image[\"\\\\]\s*:\s*\{)~i',$next.' '.$row['post_content']))$block[]='unmapped_media_asset';
 if($compat!=='schema-auto' &&
    preg_match('~(?:query[_-]?id|listing[_-]?id|template[_-]?id|shortcode|dynamic-tags|\[fluentform|\[elementor-template)~i',$next.' '.$row['post_content']))
       $block[]='query_shortcode_or_dynamic_dependency';
 if(preg_match($originalBrand,$nextTitle))$nextTitle=$brandReplace($nextTitle);
 if(preg_match($originalBrand,$nextTitle))$block[]='unresolved_source_title';
 if(stripos($nextTitle,$sourceHost)!==false)$block[]='source_title_hostname';
 if(!empty($block)){
    foreach(array_unique($block) as $v)$reasons[$v]=($reasons[$v]??0)+1;
    continue;
 }
 $eligible[$id]=array('id'=>$id,'type'=>$row['template_type'],'lang'=>$row['language'],
    'old_status'=>$row['post_status'],'new_status'=>'publish',
    'old_sha256'=>hash('sha256',$row['elementor_data']),
    'new_sha256'=>hash('sha256',$next),
    'title_sha256'=>hash('sha256',$row['post_title']),
    'title_next'=>$nextTitle,'brand_replacements'=>$localBrand,
    'link_replacements'=>$localLink,'_data'=>$next);
 $stateMap[$id]=$row;
}
ksort($eligible);ksort($reasons);
$public=array();
foreach($eligible as $v){$copy=$v;unset($copy['_data']);$public[]=$copy;}
$planHash=hash('sha256',wp_json_encode($public,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$summary=array('contract'=>'mad4b.wayfatours-design-activation-plan.v1',
 'state'=>'DESIGN_ACTIVATION_PLAN','source_sha256'=>$manifest['source_sha256'],
 'site_uuid'=>$identity['site_uuid'],'eligible'=>count($eligible),'blocked_reasons'=>$reasons,
 'eligible_by_type'=>array_count_values(array_column($public,'type')),
 'already_published_source_templates'=>$alreadyPublished,
 'eligible_ids'=>array_keys($eligible),
 'brand_replacements'=>array_sum(array_column($public,'brand_replacements')),
 'link_replacements'=>array_sum(array_column($public,'link_replacements')),
 'plan_sha256'=>$planHash,'kit_preserved'=>4,'compatibility_mode'=>$compat,'media_copied'=>false,
 'automatic_theme_conditions_activated'=>false,'production_mutated'=>false);
if($act==='plan'){
 echo wp_json_encode($summary,JSON_UNESCAPED_SLASHES).PHP_EOL;return;
}
if($act==='backup'){
    $backup=array();
    foreach($eligible as $i=>$change){$old=$stateMap[$i];$backup[]=array(
     'id'=>$i,'post_status'=>$old['post_status'],'post_title'=>$old['post_title'],
     '_elementor_data'=>$old['elementor_data'],'source_sha256'=>$manifest['source_sha256']);
    }
    echo wp_json_encode(array('state'=>'DESIGN_ACTIVATION_BACKUP',
       'site_uuid'=>$identity['site_uuid'],'plan_sha256'=>$planHash,'records'=>$backup),
       JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;return;
}
if(getenv('MAD4B_DESIGN_BACKUP_CONFIRMED')!=='1'||
   getenv('MAD4B_DESIGN_EXPECTED_PLAN')!==$planHash)
  WP_CLI::error('BACKUP_OR_EXACT_PLAN_DENIED');
$lock='mad4b:wayfa-company-neutral-activation';
if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,15)',$lock))!==1)
 WP_CLI::error('ACTIVATION_LOCK_BUSY');
$wpdb->query('START TRANSACTION');
try{
  $updated=0;
  foreach($eligible as $i=>$change){
    $live=$wpdb->get_row($wpdb->prepare("SELECT p.post_status,p.post_title,
       (SELECT m.meta_value FROM {$wpdb->postmeta} m
        WHERE m.post_id=p.ID AND m.meta_key='_elementor_data' LIMIT 1) AS data
       FROM {$wpdb->posts} p WHERE p.ID=%d FOR UPDATE",$i),ARRAY_A);
    if(!$live||$live['post_status']!=='draft'||
       hash('sha256',(string)$live['data'])!==$change['old_sha256']||
       hash('sha256',(string)$live['post_title'])!==$change['title_sha256'])
      throw new Exception('CONCURRENT_CHANGE_'.$i);
    $ret=$wpdb->update($wpdb->posts,array('post_status'=>'publish','post_title'=>$change['title_next']),
                      array('ID'=>$i),array('%s','%s'),array('%d'));
    if($ret!==1)throw new Exception('UPDATE_POST_FAILED_'.$i);
    if($change['old_sha256']!==$change['new_sha256']){
      $m=$wpdb->update($wpdb->postmeta,array('meta_value'=>$change['_data']),
          array('post_id'=>$i,'meta_key'=>'_elementor_data'),array('%s'),array('%d','%s'));
      if($m!==1)throw new Exception('UPDATE_ELEMENTOR_DATA_FAILED_'.$i);
    }
    $updated++;
  }
  $n=0;foreach(array_keys($eligible) as $pid){
   $s=$wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID=%d",$pid));
   if($s==='publish')$n++;
  }
  if($n!==count($eligible))throw new Exception('STATUS_READBACK_FAILED');
  if($wpdb->query('COMMIT')===false)throw new Exception('COMMIT_FAILED');
  wp_cache_flush();
  $summary['state']='DESIGN_ACTIVATION_APPLIED';
  $summary['activated']=$updated;
  $summary['production_mutated']=$updated>0;
  echo wp_json_encode($summary,JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){$wpdb->query('ROLLBACK');WP_CLI::error('ACTIVATION_ROLLED_BACK:'.$e->getMessage());}
finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}

