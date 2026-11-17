<?php
/**
 * MAD4B Reviews WPML media gallery: narrow, idempotent template parity repair.
 * Invoke through wp eval-file with MAD4B_REVIEW_LANGS=es[,fr,de,it,ar].
 * Never overwrites existing target gallery widgets or localized copy.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('WP_CLI_ONLY');
}
if (!current_user_can('manage_options')) {
    throw new RuntimeException('ADMIN_CAPABILITY_REQUIRED');
}
global $wpdb;
$source_id=27219;
$source_raw=(string)get_post_meta($source_id,'_elementor_data',true);
// The DB's raw JSON can differ from the slashed WordPress meta API value.
$source_db_raw=(string)$wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s",
    $source_id,'_elementor_data'));

$source_tree=json_decode($source_raw,true,512,JSON_THROW_ON_ERROR);
$gallery=null;
$find = static function ($nodes) use (&$find,&$gallery):void {
    foreach ((array)$nodes as $node) {
        if (!is_array($node)) continue;
        if (($node['id']??'')==='d3b8065' && ($node['widgetType']??'')==='uael-image-gallery') {
            if ($gallery !== null) throw new RuntimeException('DUPLICATED_EN_GALLERY');
            $gallery=$node;
        }
        $find($node['elements']??[]);
    }
};
$find($source_tree);
if (!$gallery || !str_contains((string)($gallery['settings']['__dynamic__']['wp_gallery']??''),'review_gellery_slider')) {
    throw new RuntimeException('EN_GALLERY_CONTRACT_NOT_VERIFIED');
}

$requested=array_filter(array_map('trim',explode(',',(string)getenv('MAD4B_REVIEW_LANGS'))));
$active=(array)apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
if (!$requested || count($requested)>8) throw new RuntimeException('MISSING_LANGUAGE_SCOPE');
foreach ($requested as $lang) {
    if ($lang==='en' || !isset($active[$lang])) throw new RuntimeException('INVALID_LANG_'.$lang);
}
$result=[];
foreach ($requested as $lang) {
    $id=(int)apply_filters('wpml_object_id',$source_id,'elementor_library',false,$lang);
    if (!$id || $id===$source_id || get_post_type($id)!=='elementor_library' || get_post_status($id)!=='publish') {
        throw new RuntimeException('MISSING_PUBLISHED_TRANSLATION_'.$lang);
    }
    $rows=$wpdb->get_results($wpdb->prepare(
        "SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s",
        $id,'_elementor_data'),ARRAY_A);
    if (count((array)$rows)!==1) throw new RuntimeException('UNIQUE_META_CONTRACT_'.$lang);
    $raw=(string)$rows[0]['meta_value'];
    $tree=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    $found=0;$parentFound=0;
    $walk=static function (&$nodes) use (&$walk,&$found,&$parentFound,$gallery):void {
        foreach ($nodes as &$node) {
            if (!is_array($node)) continue;
            if (($node['widgetType']??'')==='uael-image-gallery')$found++;
            if (($node['id']??'')==='b9c2361') {
                $parentFound++;
                if (($node['elType']??'')!=='container'||!isset($node['elements'])||!is_array($node['elements']))
                    throw new RuntimeException('TRANSLATED_PARENT_STRUCTURE_CHANGED');
                if (!$node['elements'] && !$found) $node['elements'][]=$gallery;
            }
            if (!empty($node['elements']))$walk($node['elements']);
        }
        unset($node);
    };
    $walk($tree);
    // The inserted widget is now included in found; a target must have
    // exactly one such widget before applying a change.
    if ($parentFound!==1||$found!==1) throw new RuntimeException('WPML_GALLERY_PARITY_AMBIGUOUS_'.$lang);
    $new=wp_json_encode($tree,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if (!is_string($new)||!str_contains($new,'review_gellery_slider')) throw new RuntimeException('SERIALIZED_GALLERY_MISSING_'.$lang);
    $result[$lang]=['id'=>$id,'meta_id'=>(int)$rows[0]['meta_id'],'old_sha'=>hash('sha256',$raw),
        'old'=>$raw,'new'=>$new,'new_sha'=>hash('sha256',$new)];
}
$changes=array_filter($result,static fn($r)=>$r['new_sha']!==$r['old_sha']);
if (!$changes) {
    echo wp_json_encode(['status'=>'ALREADY_IN_SYNC','site'=>home_url(),'langs'=>array_keys($result)],JSON_UNESCAPED_SLASHES)."\n";
    return;
}
if (!empty(getenv('MAD4B_REVIEW_DRY_RUN'))) {
    echo wp_json_encode(['status'=>'DRY_RUN','site'=>home_url(),
        'changes'=>array_map(static fn($r)=>['id'=>$r['id'],'old_sha'=>$r['old_sha'],'new_sha'=>$r['new_sha']],$changes)],JSON_UNESCAPED_SLASHES)."\n";
    return;
}
if ($wpdb->query('START TRANSACTION')===false)throw new RuntimeException('SQL_TRANSACTION_UNAVAILABLE');
$done=[];
try {
    foreach ($changes as $lang=>$r) {
        $current=(string)$wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id=%d",$r['meta_id']));
        if (hash('sha256',$current)!==$r['old_sha'])throw new RuntimeException('CONCURRENT_EDITOR_CHANGE_'.$lang);
        $updated=$wpdb->update($wpdb->postmeta,['meta_value'=>$r['new']],['meta_id'=>$r['meta_id']],['%s'],['%d']);
        if ($updated!==1)throw new RuntimeException('META_UPDATE_FAILED_'.$lang);
        $done[]=$r['id'];
    }
    foreach ($changes as $lang=>$r) {
        $now=(string)$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id=%d",$r['meta_id']));
        if (hash('sha256',$now)!==$r['new_sha'])throw new RuntimeException('READBACK_FAILED_'.$lang);
    }
    $en_now=(string)$wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s",$source_id,'_elementor_data'));
    if (hash('sha256',$en_now)!==hash('sha256',$source_db_raw))throw new RuntimeException('EN_CHANGED_DURING_TRANSLATION_REPAIR');
    if ($wpdb->query('COMMIT')===false)throw new RuntimeException('COMMIT_FAILED');
} catch (Throwable $e) {
    $wpdb->query('ROLLBACK');
    throw new RuntimeException('REVIEW_TEMPLATE_REPAIR_ABORTED_'.$e->getMessage());
}
foreach ($done as $post_id) {
    wp_cache_delete($post_id,'post_meta');
    clean_post_cache($post_id);
    if (class_exists('Elementor\\Core\\Files\\CSS\\Post')) {
        try {
            $stylesheet=\Elementor\Core\Files\CSS\Post::create($post_id);
            if (is_object($stylesheet)&&method_exists($stylesheet,'delete'))$stylesheet->delete();
        } catch (Throwable $ignored) {}
    }
    do_action('litespeed_purge_post',$post_id);
}
MAD4B_Home_Reviews_Native_Visibility_V1::invalidate_home();
echo wp_json_encode(['status'=>'APPLIED_VERIFIED','site'=>home_url(),
    'translations'=>array_map(static fn($r)=>['id'=>$r['id'],'old_sha'=>$r['old_sha'],'new_sha'=>$r['new_sha']],$changes)],JSON_UNESCAPED_SLASHES)."\n";
