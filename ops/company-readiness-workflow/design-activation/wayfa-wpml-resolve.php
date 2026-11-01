<?php
/**
 * MAD4B Wayfatours: governed, idempotent WPML relation restoration.
 * Input is a structural source manifest, never source DB records or credentials.
 * No source trid copied. Target trid is minted in the tenant database.
 */
if (!defined('WP_CLI') || !WP_CLI) { exit(10); }
global $wpdb;
$action = (string)getenv('MAD4B_DESIGN_ACTION');
$raw = (string)getenv('MAD4B_DESIGN_WPML_MAP');
$payload = json_decode($raw, true);
$id = get_option('mad4b_company_site_identity_v1', array());
if (DB_NAME !== 'wayfatours_production' || wp_get_environment_type() !== 'production' ||
    wp_parse_url(home_url(), PHP_URL_HOST) !== 'wayfatours.mad4b.com' ||
    ($id['site_uuid'] ?? '') !== 'b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b' ||
    ($id['company_code'] ?? '') !== 'wayfatours' ||
    !is_array($payload) || ($payload['contract'] ?? '') !== 'mad4b.wayfatours-wpml-resolution.v1' ||
    !preg_match('/^[a-f0-9]{64}$/D', $payload['source_sha256'] ?? '') ||
    !is_array($payload['groups'] ?? null) || count($payload['groups']) !== 176 ||
    !in_array($action, array('plan','backup','apply'),true)) WP_CLI::error('INVALID_SITE_OR_PAYLOAD');
$table = $wpdb->prefix . 'icl_translations';
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table)) !== $table) WP_CLI::error('WPML_TABLE_MISSING');
$allowed = array('en','fr','de','it','es','ar');
$ids = array();
foreach($payload['groups'] as $group) {
    if (!is_array($group) || count($group)<1 || count($group)>6) WP_CLI::error('INVALID_TRANSLATION_GROUP');
    $langs=array();
    foreach($group as $entry){
        $pid = $entry['id'] ?? 0;
        $lang = $entry['lang'] ?? '';
        if (!is_int($pid) || $pid<1 || isset($ids[$pid]) ||
            !in_array($lang,$allowed,true) || isset($langs[$lang]))
            WP_CLI::error('INVALID_TRANSLATION_MEMBER');
        $p = get_post($pid);
        if (!$p || $p->post_type !== 'elementor_library') WP_CLI::error('MISSING_ELEMENTOR_TEMPLATE_'.$pid);
        $ids[$pid] = true; $langs[$lang]=true;
    }
}
if (count($ids)!==470) WP_CLI::error('SOURCE_COUNT_MISMATCH');
$existing=$wpdb->get_results("SELECT translation_id,element_type,element_id,trid,language_code,source_language_code FROM {$table} WHERE element_type='post_elementor_library' ORDER BY translation_id",ARRAY_A);
$indexed=array();
foreach($existing as $r)if(isset($ids[(int)$r['element_id']])){
    if(isset($indexed[(int)$r['element_id']]))WP_CLI::error('WPML_DUPLICATE_ROW');
    $indexed[(int)$r['element_id']]=$r;
}
$unmapped=count($ids)-count($indexed);
$groups_with_existing=0;
foreach($payload['groups'] as $group){
    $trids=array();
    foreach($group as $e){
        if(isset($indexed[$e['id']])){
            $row=$indexed[$e['id']];
            if($row['language_code']!==$e['lang'])WP_CLI::error('LANGUAGE_CONFLICT_'.$e['id']);
            $trids[(string)$row['trid']]=true;
        }
    }
    if(count($trids)>1)WP_CLI::error('TRID_CONFLICT');
    if($trids)$groups_with_existing++;
}
if($action==='backup'){
  echo wp_json_encode(array('state'=>'WPML_BEFORE','source_sha256'=>$payload['source_sha256'],
      'site_uuid'=>$id['site_uuid'],'prior_rows'=>$existing,
      'target_ids'=>array_keys($ids)), JSON_UNESCAPED_SLASHES) . PHP_EOL;
  return;
}
if($action==='plan'){
  echo wp_json_encode(array('state'=>'WPML_PLAN','source_sha256'=>$payload['source_sha256'],
      'site_uuid'=>$id['site_uuid'],'template_count'=>count($ids),
      'source_translation_groups'=>count($payload['groups']),
      'currently_linked'=>count($indexed),'missing_links'=>$unmapped,
      'existing_groups'=>$groups_with_existing,'target_trids_copied'=>false,
      'mutation_performed'=>false), JSON_UNESCAPED_SLASHES) . PHP_EOL;
  return;
}
if(getenv('MAD4B_DESIGN_BACKUP_CONFIRMED')!=='1')WP_CLI::error('BACKUP_RECEIPT_REQUIRED');
$lock='mad4b:wayfa-wpml-template-group-resolve';
if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,15)',$lock))!==1)
    WP_CLI::error('WPML_RESOLVE_LOCK_BUSY');
$wpdb->query('START TRANSACTION');
try{
    $latest=$wpdb->get_results("SELECT translation_id,element_type,element_id,trid,language_code,source_language_code FROM {$table} WHERE element_type='post_elementor_library'",ARRAY_A);
    $prev=array();
    foreach($latest as $r)if(isset($ids[(int)$r['element_id']]))$prev[(int)$r['element_id']]=$r;
    if(count($prev)!==count($indexed))throw new Exception('CONCURRENT_TRANSLATION_MODIFICATION');
    $next=(int)$wpdb->get_var("SELECT COALESCE(MAX(trid),0) FROM {$table}") + 1;
    $inserted=0;$newgroups=0;
    foreach($payload['groups'] as $group){
        $assignedTrid=null;
        foreach($group as $entry) {
            if(isset($prev[$entry['id']])){
                if($prev[$entry['id']]['language_code']!==$entry['lang'])throw new Exception('LANGUAGE_REVALIDATION_FAILED');
                $assignedTrid=(int)$prev[$entry['id']]['trid'];
                break;
            }
        }
        if($assignedTrid===null){$assignedTrid=$next++;$newgroups++;}
        $sourceLang = in_array('en',array_column($group,'lang'),true)?'en':$group[0]['lang'];
        foreach($group as $entry){
            if(isset($prev[$entry['id']]))continue;
            $sourceCode = $entry['lang']===$sourceLang?null:$sourceLang;
            $ok=$wpdb->insert($table,array('element_type'=>'post_elementor_library',
                'element_id'=>$entry['id'],'trid'=>$assignedTrid,
                'language_code'=>$entry['lang'],'source_language_code'=>$sourceCode),
                array('%s','%d','%d','%s','%s'));
            if($ok!==1)throw new Exception('INSERT_FAILED_'.$entry['id'].':'.$wpdb->last_error);
            $inserted++;
        }
    }
    $verify=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} t INNER JOIN {$wpdb->posts} p ON p.ID=t.element_id WHERE t.element_type='post_elementor_library' AND p.post_type='elementor_library'");
    if($verify<count($ids))throw new Exception('READBACK_MISMATCH');
    if($wpdb->query('COMMIT')===false)throw new Exception('COMMIT_FAILED');
    wp_cache_flush();
    echo wp_json_encode(array('state'=>'WPML_LANGUAGE_RELATIONS_RESTORED',
      'linked_posts'=>count($ids),'inserted'=>$inserted,'new_groups'=>$newgroups,
      'verification_rows'=>$verify,'source_sha256'=>$payload['source_sha256'],
      'tenant_site_uuid'=>$id['site_uuid'],'source_trids_reused'=>false),JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch(Throwable $e){
    $wpdb->query('ROLLBACK');
    WP_CLI::error('WPML_TRANSACTION_ROLLED_BACK:'.$e->getMessage());
} finally {
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
}

