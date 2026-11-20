<?php
/**
 * MAD4B native content-only reviews gallery layout/quality reconciliation.
 *
 * Invoked via WP CLI eval-file in each company. Applies to WPML translations
 * of Elementor library template 27219 only, with the original data SHA
 * checked against a read-only backup before each write.
 *
 * No changes to original media, Look & Feel settings or payment/rate data.
 */
defined('ABSPATH') || exit(1);
if (!defined('WP_CLI') || !WP_CLI || !current_user_can('manage_options')) {
    throw new RuntimeException('WPCLI_AND_ADMIN_REQUIRED');
}
global $wpdb;
$backup_file = (string) getenv('MAD4B_GALLERY_BACKUP');
if (!is_file($backup_file)) throw new RuntimeException('BACKUP_REQUIRED');
$backup = json_decode((string) file_get_contents($backup_file), true, 512, JSON_THROW_ON_ERROR);
if (!hash_equals((string)($backup['site'] ?? ''), home_url())) {
    throw new RuntimeException('WRONG_SITE_BACKUP');
}
$langs = array_keys((array)apply_filters('wpml_active_languages', null, ['skip_missing'=>0]));
if (count($langs) < 2 || count($langs) > 10) throw new RuntimeException('UNEXPECTED_WPML_LANGUAGES');
if (count($backup['templates'] ?? []) !== count($langs)) {
    throw new RuntimeException('TRANSLATION_COVERAGE_CHANGED');
}
$settings_target = [
    'image_size' => 'large',
    'slides_to_show' => 3,
    'slides_to_show_tablet' => 2,
    'slides_to_show_mobile' => 1,
    'autoplay' => '', // prevent below-fold carousels rotating/downloads before viewport
    'gallery_rand' => '', // retain user-selected media order; stable for screenshots and visitors
];
$updates=[];
foreach ($langs as $lang) {
    if (!isset($backup['templates'][$lang])) throw new RuntimeException('BACKUP_LANGUAGE_MISSING:'.$lang);
    $prev = $backup['templates'][$lang];
    $id=(int)apply_filters('wpml_object_id',27219,'elementor_library',false,$lang);
    if ($id !== (int)$prev['id'] || get_post_status($id)!=='publish') {
        throw new RuntimeException('TRANSLATED_TEMPLATE_CHANGED:'.$lang);
    }
    $rows=$wpdb->get_results($wpdb->prepare(
        "SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s",
        $id,'_elementor_data'),ARRAY_A);
    if (count($rows)!==1 || (int)$rows[0]['meta_id'] !== (int)$prev['meta_id']) {
        throw new RuntimeException('TEMPLATE_META_CHANGED:'.$lang);
    }
    $raw=(string)$rows[0]['meta_value'];
    if (!hash_equals((string)$prev['sha256'],hash('sha256',$raw))) {
        throw new RuntimeException('CONCURRENT_TEMPLATE_EDITOR_CHANGE:'.$lang);
    }
    $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    $gallery_count=0;
    $parent_count=0;
    $change=function (&$nodes) use (&$change,&$gallery_count,&$parent_count,$settings_target):void {
        foreach ($nodes as &$node) {
            if (!is_array($node))continue;
            if (($node['id']??'')==='d3b8065') {
                if (($node['widgetType']??'')!=='uael-image-gallery')throw new RuntimeException('GALLERY_WIDGET_TYPE_CHANGED');
                $gallery_count++;
                $settings=&$node['settings'];
                if (!isset($settings['__dynamic__']['wp_gallery'])
                    || !str_contains($settings['__dynamic__']['wp_gallery'],'review_gellery_slider')
                    || ($settings['gallery_style']??'')!=='carousel') {
                    throw new RuntimeException('DYNAMIC_MEDIA_CONTRACT_CHANGED');
                }
                foreach ($settings_target as $key=>$value)$settings[$key]=$value;
                unset($settings);
            }
            if (($node['id']??'')==='b9c2361') {
                if (($node['elType']??'')!=='container')throw new RuntimeException('PARENT_NOT_CONTAINER');
                $parent_count++;
                $node['settings']['width']=['unit'=>'%','size'=>100,'sizes'=>[]];
                $node['settings']['min_height']=['unit'=>'px','size'=>0,'sizes'=>[]];
            }
            if (!empty($node['elements']))$change($node['elements']);
        }
        unset($node);
    };
    $change($data);
    if ($gallery_count!==1 || $parent_count!==1)throw new RuntimeException('UNEXPECTED_GALLERY_COMPONENT_COUNTS:'.$lang);
    $new=wp_json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if (!is_string($new)) throw new RuntimeException('SERIALIZATION_FAILED:'.$lang);
    $updates[$lang]=['id'=>$id,'meta_id'=>(int)$prev['meta_id'],'old'=>$raw,'new'=>$new,
        'old_sha'=>hash('sha256',$raw),'new_sha'=>hash('sha256',$new)];
}
$dirty=array_filter($updates,static fn($x)=>$x['old_sha']!==$x['new_sha']);
if (getenv('MAD4B_GALLERY_DRY_RUN')==='1') {
    echo 'DRY_RUN '.wp_json_encode([
        'site'=>home_url(),'languages'=>array_keys($updates),
        'changed'=>array_keys($dirty),'settings'=>$settings_target,
        'template_ids'=>array_map(static fn($x)=>$x['id'],$updates)
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    return;
}
if (!$dirty) {echo "ALREADY_APPLIED\n";return;}
if ($wpdb->query('START TRANSACTION')===false)throw new RuntimeException('TRANSACTION_FAILED');
try {
    foreach ($dirty as $lang=>$row) {
        $now=(string)$wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id=%d",$row['meta_id']));
        if (!hash_equals($row['old_sha'],hash('sha256',$now)))
            throw new RuntimeException('CONCURRENT_CHANGE_DURING_WRITE:'.$lang);
        if ($wpdb->update($wpdb->postmeta,['meta_value'=>$row['new']],
            ['meta_id'=>$row['meta_id']],['%s'],['%d'])!==1)
            throw new RuntimeException('WRITE_FAILED:'.$lang);
    }
    foreach ($dirty as $lang=>$row) {
        $now=(string)$wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id=%d",$row['meta_id']));
        if (!hash_equals($row['new_sha'],hash('sha256',$now)))
            throw new RuntimeException('READBACK_FAILED:'.$lang);
    }
    if ($wpdb->query('COMMIT')===false)throw new RuntimeException('COMMIT_FAILED');
} catch (Throwable $e) {
    $wpdb->query('ROLLBACK');
    throw new RuntimeException('ABORTED_WITH_ROLLBACK:'.$e->getMessage());
}
foreach ($dirty as $row) {
    wp_cache_delete($row['id'],'post_meta');
    clean_post_cache($row['id']);
    if (class_exists('Elementor\\Core\\Files\\CSS\\Post')) {
        try {
            $css=\Elementor\Core\Files\CSS\Post::create($row['id']);
            if (is_object($css) && method_exists($css,'delete'))$css->delete();
        } catch (Throwable $ignore) {}
    }
    do_action('litespeed_purge_post',$row['id']);
}
if (class_exists('MAD4B_Home_Reviews_Native_Visibility_V1',false)) {
    MAD4B_Home_Reviews_Native_Visibility_V1::invalidate_home();
}
echo 'APPLIED_VERIFIED '.wp_json_encode(['site'=>home_url(),
    'updated'=>array_map(static fn($x)=>['id'=>$x['id'],'sha'=>$x['new_sha']],$dirty)],
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
