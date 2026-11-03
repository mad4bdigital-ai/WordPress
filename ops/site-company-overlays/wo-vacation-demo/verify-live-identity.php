<?php
declare(strict_types=1);
/**
 * Read-only, Staging-specific company identity smoke test.
 * No contact values, secrets, orders, customers, or media bytes emitted.
 * Usage: docker exec -i etg-commerce-staging-php php < verify-live-identity.php
 */
require '/var/www/html/wp-load.php';
global $wpdb;
$environment=wp_get_environment_type();
$siteUuid=class_exists('MAD4B_SCP_Site_Profile')
    ? MAD4B_SCP_Site_Profile::site_uuid() : '';
$homeHost=parse_url(home_url(), PHP_URL_HOST);
$expectedUuid='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
if ($environment !== 'staging' || $homeHost !== 'tours.dev.mad4b.com' ||
    $siteUuid !== $expectedUuid || DB_NAME !== 'etg_commerce_staging') {
    fwrite(STDERR, "BLOCKED: wrong site, database, or environment\n");
    exit(42);
}
$kitId=(int)get_option('elementor_active_kit');
$kit=is_array($x=get_post_meta($kitId,'_elementor_page_settings',true))?$x:[];
$rank=is_array($x=get_option('rank-math-options-titles'))?$x:[];
$wpml=is_array($x=get_option('icl_sitepress_settings'))?$x:[];
$observed=[
    'site_name'=>get_option('blogname'),
    'site_tagline'=>get_option('blogdescription'),
    'elementor_kit_id'=>$kitId,
    'elementor_site_name'=>$kit['site_name']??null,
    'rank_math_organization'=>$rank['knowledgegraph_name']??null,
    'rank_math_website'=>$rank['website_name']??null,
    'source_language'=>$wpml['default_language']??null,
];
$expected=[
    'site_name'=>'Wo Vacation',
    'elementor_kit_id'=>60050,
    'elementor_site_name'=>'Wo Vacation',
    'rank_math_organization'=>'Wo Vacation',
    'rank_math_website'=>'Wo Vacation',
    'source_language'=>'en'
];
$matches=[];
foreach($expected as $field=>$value){
    $matches[$field]=($observed[$field]??null)===$value;
}
$oldName='%allroyalegypt%';
$counts=[];
foreach(['old_allroyalegypt'=>'allroyalegypt',
         'old_dona_tours'=>'dona tours'] as $key=>$term){
    $like='%'.$wpdb->esc_like($term).'%';
    $counts[$key.'_alt']=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_wp_attachment_image_alt' AND meta_value LIKE %s",
        $like
    ));
    $counts[$key.'_title']=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_title LIKE %s",
        $like
    ));
}
$allMatched=!in_array(false,$matches,true);
$status=!$allMatched?'BRAND_IDENTITY_MISMATCH':
    (array_sum($counts)>0?'IDENTITY_ALIGNED_MEDIA_PROVENANCE_REVIEW':'IDENTITY_ALIGNED');
echo json_encode([
    'contract'=>'mad4b.wo-vacation-demo.identity-readback.v1',
    'status'=>$status,'site_uuid'=>$siteUuid,
    'site_origin'=>'https://'.$homeHost,
    'environment'=>$environment,
    'observed'=>$observed,'identity_checks'=>$matches,
    'legacy_media_metadata_counts'=>$counts,
    'siteurl_home_scheme_mismatch'=>(parse_url(site_url(),PHP_URL_SCHEME)!==parse_url(home_url(),PHP_URL_SCHEME)),
    'is_mutation'=>false,'publishing_authorized'=>false,
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
exit($allMatched?0:2);
