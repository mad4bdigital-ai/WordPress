<?php
/**
 * One-time company-owned, zero-legacy-data starting state for Wayfatours.
 * Must run on a CLEAN WordPress install in its own production database.
 */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'production' ||
 DB_NAME !== 'wayfatours_production' ||
 wp_parse_url(home_url(),PHP_URL_HOST) !== 'wayfatours.mad4b.com' ||
 get_option('blogname') !== 'Wayfatours') {
 WP_CLI::error('SCOPE_DENIED');
}
if (get_option('mad4b_company_site_identity_v1',false)!==false) {
 WP_CLI::error('TENANT_ALREADY_ENROLLED');
}
$identity=[
 'company_code'=>'wayfatours',
 'company_display_name'=>'Wayfatours',
 'site_uuid'=>wp_generate_uuid4(),
 'environment'=>'production',
 'host'=>'wayfatours.mad4b.com',
 'provisioning_state'=>'internal_only_dns_tls_pending',
 'schema_version'=>1,
];
if (!add_option('mad4b_company_site_identity_v1',$identity,'',false)) {
 WP_CLI::error('SITE_UUID_ENROLLMENT_FAILED');
}
$sample=get_page_by_path('sample-page',OBJECT,'page');
if ($sample && $sample->post_title === 'Sample Page') {
 wp_delete_post((int)$sample->ID,true);
}
$content=<<<'HTML'
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Discover Wayfatours</h1>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Thoughtfully planned travel experiences and new destinations are on the way.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>We're preparing our travel collection. Booking availability, prices, and company-specific terms will be published after verification.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
HTML;
$id=wp_insert_post([
 'post_type'=>'page',
 'post_status'=>'publish',
 'post_title'=>'Wayfatours',
 'post_name'=>'home',
 'post_content'=>$content,
 'comment_status'=>'closed',
],true);
if (is_wp_error($id) || !$id) WP_CLI::error('LANDING_PAGE_FAILED');
update_option('show_on_front','page');
update_option('page_on_front',(int)$id);
update_option('blog_public','0');
update_option('default_comment_status','closed');
if ((int)get_option('page_on_front') !== (int)$id ||
 (int)get_option('blog_public',1)!==0 ||
 !wp_is_uuid(get_option('mad4b_company_site_identity_v1')['site_uuid']??'')) {
 WP_CLI::error('COMPANY_SEED_READBACK_FAILED');
}
echo wp_json_encode(['status'=>'NEW_COMPANY_PRIVATE_PRODUCTION_INITIALIZED',
 'homepage_id'=>(int)$id,'unique_site_identity'=>true,'cross_company_content_imported'=>false],JSON_UNESCAPED_UNICODE).PHP_EOL;
