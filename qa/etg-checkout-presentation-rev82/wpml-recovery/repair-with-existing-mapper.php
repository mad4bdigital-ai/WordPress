<?php
/** ETG Local Lab only. Reuse native WPML Meta ID Mapper conversion hook. */
require '/var/www/html/wp-load.php';
if (DB_NAME !== 'etg_woocommerce_lab' || wp_get_environment_type() !== 'local') exit(11);
$sourceRate = 61050;
$raw = get_post_meta($sourceRate, 'related_tour_id', true);
if (get_post_status($sourceRate) !== 'publish' || (int)$raw !== 60439) exit(12);
$targets = [61052=>'es',61054=>'en',61056=>'it',61058=>'de'];
$expected = [61052=>60609,61054=>60439,61056=>64853,61058=>60563];
$mapped = [];
foreach ($targets as $id=>$language) {
    $p=get_post($id);
    $details=apply_filters('wpml_post_language_details',null,$id);
    if (!$p || $p->post_type!=='tour-rates' || $p->post_status!=='draft' ||
        ($details['language_code']??'')!==$language ||
        get_post_meta($id,'related_tour_id',true)!=='' ||
        (int)get_post_meta($id,'_etg_last_synced_tour_id',true)!==0 ||
        get_post_meta($id,'tour_rate_start_date',true)!==get_post_meta($sourceRate,'tour_rate_start_date',true) ||
        get_post_meta($id,'tour_rate_end_date',true)!==get_post_meta($sourceRate,'tour_rate_end_date',true)) exit(13);
    $candidate=(int)apply_filters('wpml_sync_custom_field_copied_value',$raw,$id,$sourceRate,'related_tour_id');
    if ($candidate !== $expected[$id] || get_post_type($candidate)!=='tours-and-activities' || get_post_status($candidate)!=='publish') exit(14);
    $mapped[$id]=$candidate;
}
$mode=$argv[1]??'verify';
if ($mode==='verify') {echo json_encode(['mode'=>'preflight','conversion_provider'=>'wpml_meta_id_mapper_ui','mapping'=>$mapped,'replay_safe'=>true]),"\n";exit(0);}
if ($mode!=='apply') exit(15);
global $wpdb;
$wpdb->query('START TRANSACTION');
try {
    foreach($mapped as $id=>$tour) {
        if (!update_post_meta($id,'related_tour_id',$tour)) throw new RuntimeException('meta_update_failed_'.$id);
        $updated=wp_update_post(['ID'=>$id,'post_status'=>'publish'],true);
        if (is_wp_error($updated) || (int)$updated!==$id) throw new RuntimeException('publish_failed_'.$id);
        if ((int)get_post_meta($id,'related_tour_id',true)!==$tour ||
            (int)get_post_meta($id,'_etg_last_synced_tour_id',true)!==$tour ||
            get_post_status($id)!=='publish') throw new RuntimeException('readback_failed_'.$id);
    }
    $wpdb->query('COMMIT');
    echo json_encode(['mode'=>'applied','mapping'=>$mapped,'status'=>'published','money_changed'=>false,'wpml_language_changed'=>false]),"\n";
} catch (Throwable $e) {
    $wpdb->query('ROLLBACK');
    fwrite(STDERR,$e->getMessage()."\n");exit(18);
}
