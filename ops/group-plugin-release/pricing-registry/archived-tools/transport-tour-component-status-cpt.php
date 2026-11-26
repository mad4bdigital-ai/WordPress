<?php
/**
 * Transportation CPT field parity with Property "Use in Tour Rate Generation".
 * Exact insertion immediately after trans_description. Source field stays
 * independent of post_status and trans_available_status. Default 0.
 */
defined('ABSPATH') && defined('WP_CLI') && WP_CLI || exit(2);
$site=rtrim(home_url('/'),'/');
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
    WP_CLI::error('site_not_approved');
if($site==='https://wayfatours.com' &&
   !(defined('MAD4B_TRANSPORT_STATUS_WAYFA_APPROVED') &&
     MAD4B_TRANSPORT_STATUS_WAYFA_APPROVED===true))
    WP_CLI::error('wayfa_explicit_guard_missing');
$apply=defined('MAD4B_TRANSPORT_STATUS_APPLY') &&
    MAD4B_TRANSPORT_STATUS_APPLY===true;
$rollback=defined('MAD4B_TRANSPORT_STATUS_ROLLBACK') &&
    MAD4B_TRANSPORT_STATUS_ROLLBACK===true;
global $wpdb;
$table=$wpdb->prefix.'jet_post_types';
$row=$wpdb->get_row($wpdb->prepare("SELECT id,slug,status,meta_fields FROM {$table} WHERE slug=%s",'transportations'),ARRAY_A);
if(!$row || $row['slug']!=='transportations' || $row['status']!=='publish' ||
   (int)$row['id']!==173) WP_CLI::error('unexpected_transport_jet_cpt');
$beforeRaw=(string)$row['meta_fields'];
$defs=(array)maybe_unserialize($beforeRaw);
$names=array_column($defs,'name');
$position=array_search('trans_description',$names,true);
if($position!==10)WP_CLI::error('description_position_changed');
if(($defs[$position]['title']??'')!=='Description')
    WP_CLI::error('source_description_label_changed');
$meta='trans_use_in_tour_rate_generation';
$existingPosition=array_search($meta,$names,true);
$field=[
    'name'=>$meta,
    'title'=>'Use in Tour Rate Generation',
    'object_type'=>'field','type'=>'radio',
    'options_source'=>'glossary','glossary_id'=>'321',
    'default_val'=>'0','width'=>'50%',
    'description'=>'Explicit opt-in for Transportation selection as a Tour Component. Default No. Publishing, availability and native Rate approval remain independent.',
    'id'=>827104,'isNested'=>false
];
$settings=get_option('icl_sitepress_settings',[]);
if(!is_array($settings))WP_CLI::error('wpml_unavailable');
$oldTranslation=$settings['translation-management']['custom_fields_translation'][$meta]??null;
$backupKey='mad4b_transport_tour_component_cpt_backup_20261119_v1';
$backup=get_option($backupKey,false);
$postIds=get_posts([
    'post_type'=>'transportations',
    'post_status'=>['publish','draft'],
    'posts_per_page'=>1000,'fields'=>'ids','suppress_filters'=>true
]);
if(!in_array($existingPosition,[false,11],true))
    WP_CLI::error('status_field_position_wrong');
if($existingPosition===false){
    if(count($defs)!==69 || in_array($field['id'],array_column($defs,'id'),true))
        WP_CLI::error('source_schema_count_or_id_changed');
    array_splice($defs,$position+1,0,[$field]);
}else{
    if(count($defs)!==70 ||
       ($defs[$existingPosition]['name']??'')!==$meta)
        WP_CLI::error('unexpected_existing_status_field');
}
$afterRaw=serialize($defs);
$metaExisting=0;$bad=[];
foreach($postIds as $id){
    $value=get_post_meta((int)$id,$meta,true);
    if(metadata_exists('post',(int)$id,$meta)){
        $metaExisting++;
        if(!in_array((string)$value,['0','1'],true)) $bad[]=(int)$id;
    }
}
if($bad)WP_CLI::error('existing_status_values_unrecognized_'.implode(',',$bad));
if($rollback){
    if(!is_array($backup)||$existingPosition!==11||
       hash('sha256',$beforeRaw)!==$backup['after_hash'])
        WP_CLI::error('rollback_schema_conflict');
    $toRestore=$backup['before_raw'];
    if($wpdb->update($table,['meta_fields'=>$toRestore],['id'=>(int)$row['id']],
        ['%s'],['%d'])!==1)WP_CLI::error('rollback_cpt_failed');
    $current=get_option('icl_sitepress_settings',[]);
    if(($current['translation-management']['custom_fields_translation'][$meta]??null)!==1)
        WP_CLI::error('rollback_wpml_conflict');
    if($backup['prior_wpml']===null)
        unset($current['translation-management']['custom_fields_translation'][$meta]);
    else
        $current['translation-management']['custom_fields_translation'][$meta]=$backup['prior_wpml'];
    update_option('icl_sitepress_settings',$current);
    // Restore only site rows populated by this migration, preserving
    // subsequent manually edited status choices.
    foreach($backup['initialized_ids'] as $id)
        if((string)get_post_meta((int)$id,$meta,true)==='0')
            delete_post_meta((int)$id,$meta);
    WP_CLI::log(wp_json_encode(['status'=>'rolled_back','field_count'=>69]));
    return;
}
if(!$apply){
    WP_CLI::log(wp_json_encode([
        'status'=>'dry_run','site'=>$site,
        'transport_posts'=>count($postIds),'existing_status_values'=>$metaExisting,
        'fields_before'=>$existingPosition===false?69:70,'fields_after'=>70,
        'insert_after'=>'trans_description','default'=>'0',
        'glossary'=>321,'wpml_copy'=>1,
        'commercial_price_mutations'=>0,'booking_changes'=>false]));
    return;
}
if($backup!==false && $existingPosition===false)
    WP_CLI::error('schema_backup_conflict');
$admin=get_user_by('id',1);
if(!$admin||!user_can($admin,'manage_options'))WP_CLI::error('admin_required');
wp_set_current_user((int)$admin->ID);
$initialized=[];
foreach($postIds as $id){
    if(!metadata_exists('post',(int)$id,$meta))
        $initialized[]=(int)$id;
}
if($backup===false){
    if(!add_option($backupKey,[
        'before_raw'=>$beforeRaw,'after_hash'=>hash('sha256',$afterRaw),
        'prior_wpml'=>$oldTranslation,
        'initialized_ids'=>$initialized,'created_at'=>gmdate('c')
    ],'',false))WP_CLI::error('backup_write_failed');
}
if($existingPosition===false)
    if($wpdb->update($table,['meta_fields'=>$afterRaw],
        ['id'=>(int)$row['id']],['%s'],['%d'])!==1)
        WP_CLI::error('field_insert_failed');
$settings['translation-management']['custom_fields_translation'][$meta]=1;
update_option('icl_sitepress_settings',$settings);
foreach($initialized as $id)
    update_post_meta($id,$meta,'0');
$after=(array)maybe_unserialize($wpdb->get_var($wpdb->prepare(
    "SELECT meta_fields FROM {$table} WHERE id=%d",(int)$row['id'])));
$verified=get_option('icl_sitepress_settings',[]);
if(count($after)!==70 ||
   ($after[11]['name']??'')!==$meta ||
   ($after[11]['glossary_id']??'')!=='321' ||
   ($verified['translation-management']['custom_fields_translation'][$meta]??null)!==1)
    WP_CLI::error('schema_or_wpml_readback_failed');
foreach($postIds as $id)
    if(!in_array((string)get_post_meta((int)$id,$meta,true),['0','1'],true))
        WP_CLI::error('post_status_init_failed_'.$id);
WP_CLI::log(wp_json_encode([
    'status'=>'applied','site'=>$site,'field'=>$meta,
    'insert_position'=>11,'after_field'=>'trans_description',
    'field_count'=>70,'default'=>'0',
    'wpml_copy'=>1,'posts_scoped'=>count($postIds),
    'initialized'=>count($initialized),'preserved_existing'=>$metaExisting,
    'tour_components_enabled'=>count($postIds)-count($initialized),
    'rate_posts_modified'=>0,'checkout_authorized'=>false
]));
