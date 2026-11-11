<?php
/**
 * Plugin Name: Term Media Integrity Guard
 * Description: Prevents quarantined or mass-reused destination media from being propagated across unrelated terms.
 */
if (!defined('ABSPATH')) { exit; }

function company_term_media_key($raw) {
    if (is_numeric($raw) && absint($raw)) {
        $id=absint($raw);
        if (get_post_type($id) !== 'attachment') { return ''; }
        $raw=wp_get_attachment_url($id);
    }
    if (!is_string($raw) || !trim($raw) || strlen($raw)>4096) { return ''; }
    $url=html_entity_decode(trim($raw),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $path=wp_parse_url($url,PHP_URL_PATH);
    if (!is_string($path) || !str_contains($path,'/wp-content/uploads/')) { return ''; }
    $part=rawurldecode(substr($path,strpos($path,'/wp-content/uploads/')+20));
    if (!$part || str_contains($part,"\0") || str_contains($part,'..')) { return ''; }
    // Image optimizers may serve JPG -> JPG.WEBP, the content identity is unchanged.
    $part=preg_replace('~\.(jpe?g|png)\.webp$~i', '.$1', $part);
    return strtolower($part);
}
function company_term_media_group($term_id) {
    global $wpdb;
    $term=get_term($term_id);
    if (!$term instanceof WP_Term) { return null; }
    $trid=$wpdb->get_var($wpdb->prepare(
        "SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_type=%s AND element_id=%d LIMIT 1",
        'tax_'.$term->taxonomy,$term->term_taxonomy_id));
    return $trid ? 'wpml:'.(int)$trid : 'term:'.$term->term_taxonomy_id;
}
function company_term_media_distinct_groups($asset) {
    global $wpdb;
    if (!$asset) { return []; }
    $suffix=basename($asset);
    $candidate=$wpdb->get_results($wpdb->prepare(
        "SELECT m.term_id,m.meta_value FROM {$wpdb->termmeta} m
        JOIN {$wpdb->term_taxonomy} tt ON m.term_id=tt.term_id
        WHERE tt.taxonomy=%s AND m.meta_key=%s AND m.meta_value LIKE %s",
        'destinations','featured_image','%'.$wpdb->esc_like($suffix).'%' ),ARRAY_A);
    $groupIds=[];
    foreach($candidate as $row) {
        if (company_term_media_key($row['meta_value'])!==$asset)continue;
        $id=company_term_media_group((int)$row['term_id']);
        if ($id)$groupIds[$id]=true;
    }
    return array_keys($groupIds);
}
function company_term_media_denied($term_id,$meta_key,$meta_value) {
    $taxonomies=(array)apply_filters('company_term_media_guard_taxonomies',['destinations']);
    $term=get_term((int)$term_id);
    if (!$term instanceof WP_Term || !in_array($term->taxonomy,$taxonomies,true))return '';
    if(!in_array($meta_key,['featured_image','image_gellery'],true))return '';
    if($meta_value==='' || $meta_value===null)return ''; // legitimate deletion
    if(!is_string($meta_value) && !is_numeric($meta_value))return 'Invalid destination media type.';
    $members=$meta_key==='image_gellery'?preg_split('/\s*,\s*/',(string)$meta_value):[$meta_value];
    if(!is_array($members)||count($members)>40)return 'Too many gallery media references.';
    foreach($members as $member) {
        if(!trim((string)$member))continue;
        $key=company_term_media_key($member);
        if(!$key)continue; // Existing external styles remain compatible; guard only recognized uploads.
        $quarantine=get_option('company_term_media_quarantine_v1',[]);
        $record=is_array($quarantine)?($quarantine[hash('sha256',$key)]??null):null;
        if(is_array($record)&&!in_array((int)$term_id,array_map('intval',$record['allowed_term_ids']??[]),true)) {
            return 'This media file is quarantined due to incorrect distribution across destination terms.';
        }
        if($meta_key==='featured_image') {
            $current=get_term_meta((int)$term_id,'featured_image',true);
            if(company_term_media_key($current)===$key)continue; // no-op save
            $groups=company_term_media_distinct_groups($key);
            $own=company_term_media_group((int)$term_id);
            if(count($groups)>=3 && !in_array($own,$groups,true) &&
                !apply_filters('company_term_media_allow_shared_asset',false,$term_id,$key,$groups)) {
                return 'This image already represents three unrelated destination translation groups; select an appropriate image.';
            }
        }
    }
    return '';
}
function company_term_media_reject_meta($check,$term_id,$meta_key,$meta_value) {
    if($check!==null)return $check;
    $reason=company_term_media_denied($term_id,$meta_key,$meta_value);
    if(!$reason)return null;
    if(is_user_logged_in())set_transient('company_term_media_warning_'.get_current_user_id(),$reason,MINUTE_IN_SECONDS);
    error_log('[term-media-integrity] declined update of '.sanitize_key($meta_key).
        ' on term '.absint($term_id).': '.$reason);
    return false;
}
// The generic governance layer supersedes this legacy destination-only filter.
// Retain the old implementation as safe fallback if the new Local layer is absent.
if (!function_exists('company_media_metadata_guard')) {
    add_filter('update_term_metadata','company_term_media_reject_meta',10,4);
    add_filter('add_term_metadata','company_term_media_reject_meta',10,4);
}

add_action('admin_notices',static function(){
    if(!current_user_can('manage_options'))return;
    $id=get_current_user_id();
    $message=get_transient('company_term_media_warning_'.$id);
    if(!$message)return;
    delete_transient('company_term_media_warning_'.$id);
    echo '<div class="notice notice-error is-dismissible"><p>'.
        esc_html('Destination image safety check: '.$message).'</p></div>';
});
function company_term_media_health_snapshot() {
    global $wpdb;
    $rows=$wpdb->get_results($wpdb->prepare(
        "SELECT m.term_id,m.meta_key,m.meta_value,tt.term_taxonomy_id,tr.trid
        FROM {$wpdb->termmeta} m
        JOIN {$wpdb->term_taxonomy} tt ON m.term_id=tt.term_id
        LEFT JOIN {$wpdb->prefix}icl_translations tr ON tr.element_id=tt.term_taxonomy_id AND tr.element_type='tax_destinations'
        WHERE tt.taxonomy=%s AND m.meta_key IN ('featured_image','image_gellery') AND m.meta_value<>''",
        'destinations'),ARRAY_A);
    $registry=[];
    $quarantine=get_option('company_term_media_quarantine_v1',[]);
    $violations=[];
    foreach($rows as $row){
        $id=(int)$row['term_id'];
        $parts=$row['meta_key']==='image_gellery'?
            preg_split('/\s*,\s*/',$row['meta_value']):[$row['meta_value']];
        foreach((array)$parts as $url){
            $key=company_term_media_key($url);
            if(!$key)continue;
            $group=$row['trid']?'wpml:'.(int)$row['trid']:'term:'.(int)$row['term_taxonomy_id'];
            $registry[$key][$group]=true;
            $q=$quarantine[hash('sha256',$key)]??null;
            if(is_array($q)&&!in_array($id,array_map('intval',$q['allowed_term_ids']??[]),true)){
                $violations[$row['meta_key']][$id]=$key;
            }
        }
    }
    $cross=[];
    foreach($registry as $asset=>$groups){
        if(count($groups)>=4)$cross[]=['asset'=>$asset,'distinct_translation_groups'=>count($groups)];
    }
    usort($cross,static fn($a,$b)=>$b['distinct_translation_groups']<=>$a['distinct_translation_groups']);
    $report=[
      'audited_at'=>gmdate(DATE_ATOM),'status'=>!$violations?'PASS':'VIOLATIONS',
      'checked_records'=>count($rows),'images_with_four_plus_unrelated_groups'=>$cross,
      'quarantined_violations'=>$violations,'quarantine_keys'=>is_array($quarantine)?count($quarantine):0
    ];
    update_option('company_term_media_last_audit_v1',$report,false);
    return $report;
}
add_action('company_term_media_integrity_audit','company_term_media_health_snapshot');
add_action('init',static function(){
    if(!wp_next_scheduled('company_term_media_integrity_audit')){
        wp_schedule_event(time()+HOUR_IN_SECONDS,'daily','company_term_media_integrity_audit');
    }
},60);
add_action('admin_menu',static function(){
    add_management_page('Term Image Integrity','Term Image Integrity',
        'manage_options','company-term-image-integrity',static function(){
            if(!current_user_can('manage_options'))return;
            if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['company_media_run_audit']) &&
                check_admin_referer('company_media_run_audit','company_media_audit_nonce')){
                $report=company_term_media_health_snapshot();
                echo '<div class="notice notice-success"><p>Integrity audit completed.</p></div>';
            }else{
                $report=get_option('company_term_media_last_audit_v1',[]);
            }
            echo '<div class="wrap"><h1>Destination Term Image Integrity</h1>';
            echo '<p>Monitors media reuse across WPML translation groups and quarantined images. Does not change image metadata.</p>';
            echo '<form method="post">';
            wp_nonce_field('company_media_run_audit','company_media_audit_nonce');
            submit_button('Run image integrity audit','secondary','company_media_run_audit');
            echo '</form>';
            echo '<p><strong>Last audit:</strong> '.esc_html($report['audited_at']??'Not run yet').'</p>';
            echo '<p><strong>Status:</strong> '.esc_html($report['status']??'Unknown').'</p>';
            $bad=$report['quarantined_violations']??[];
            $n=0;foreach((array)$bad as $items)$n+=count($items);
            echo '<p><strong>Quarantine violations:</strong> '.esc_html((string)$n).'</p>';
            echo '<p><strong>Cross-destination photo reuse alerts:</strong> '.
                esc_html((string)count($report['images_with_four_plus_unrelated_groups']??[])).'</p>';
            $cross=array_slice($report['images_with_four_plus_unrelated_groups']??[],0,30);
            if($cross) {
                echo '<table class="widefat striped"><thead><tr><th>Image path</th><th>Independent WPML groups</th></tr></thead><tbody>';
                foreach($cross as $row) {
                    echo '<tr><td>'.esc_html($row['asset']).'</td><td>'.
                        esc_html((string)$row['distinct_translation_groups']).'</td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div>';
        });
},60);
