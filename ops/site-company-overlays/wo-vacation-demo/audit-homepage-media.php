<?php
/**
 * Staging CLI-only, READ-ONLY attachment provenance summary.
 * Does not output titles, alt text, URLs, full paths or media bytes.
 * Invoked with explicit attachment IDs from a verified public homepage.
 */
if (PHP_SAPI !== 'cli') { exit(40); }
require '/var/www/html/wp-load.php';
if (wp_get_environment_type() !== 'staging' ||
    DB_NAME !== 'etg_commerce_staging' ||
    parse_url(home_url(), PHP_URL_HOST) !== 'tours.dev.mad4b.com' ||
    !class_exists('MAD4B_SCP_Site_Profile') ||
    MAD4B_SCP_Site_Profile::site_uuid() !== 'e5e64bca-ed62-48d2-b2b3-dff693cd702a') {
    fwrite(STDERR,"BLOCKED: not authorized Wo Vacation staging site\n");
    exit(42);
}
$ids = array_slice(array_unique(array_map('intval',array_slice($argv,1))), 0, 20);
if (!$ids || in_array(0,$ids,true)) { fwrite(STDERR,"Provide 1-20 positive attachment IDs\n");exit(2); }
$items = [];
foreach ($ids as $id) {
    $post = get_post($id);
    if (!$post || $post->post_type !== 'attachment') {
        $items[] = ['attachment_id'=>$id,'state'=>'attachment_not_found','automatic_use_authorized'=>false];
        continue;
    }
    $alt = (string)get_post_meta($id,'_wp_attachment_image_alt',true);
    $name = (string)$post->post_title;
    $keys = array_keys(get_post_meta($id));
    $rightsKeys = array_values(array_filter($keys,static function ($key) {
        return (bool)preg_match('/license|licence|right|copyright|attribution|credit|permission|consent|source_author/i',$key);
    }));
    $items[] = [
        'attachment_id'=>$id,
        'mime'=>$post->post_mime_type,
        'alt_has_previous_brand'=>(bool)preg_match('/all\s*royal\s*egypt|allroyalegypt|dona\s*tours|egypt\s*tour\s*gates/i',$alt),
        'title_has_previous_brand'=>(bool)preg_match('/all\s*royal\s*egypt|allroyalegypt|dona\s*tours|egypt\s*tour\s*gates/i',$name),
        'rights_related_meta_key_count'=>count($rightsKeys),
        'rights_verified'=>false,
        'rights_state'=>'not_verified_by_attachment_metadata_only',
        'automatic_use_authorized'=>false,
        'next_action'=>'verify_existing_license_and_company_usage_rights_before_replacement_or_republication'
    ];
}
echo json_encode([
    'contract'=>'mad4b.wo-vacation-homepage-media-provenance-audit.v1',
    'environment'=>'staging','site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
    'records'=>$items,'image_bytes_copied'=>0,'database_mutation'=>false,
    'recommendation'=>'preserve original provenance; replace only with newly licensed company-specific media'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
