<?php
/** Read-only, virtual Staging fixtures: NEVER registered as governed sources/assets. */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Staging_Context_Fixtures {
    const CONTRACT='mad4b.staging-context-fixtures.v1';
    const MENU='mad4b-staging-context-fixtures';
    public static function canRead():bool {
        return wp_get_environment_type()==='staging' &&
            (current_user_can('manage_options') || current_user_can('mad4b_manage_company_operations'));
    }
    public static function scope() {
        if(wp_get_environment_type()!=='staging')
            return new WP_Error('mad4b_test_fixtures_staging_only','Not a Staging environment',['status'=>403]);
        if(!class_exists('MAD4B_SCP_Context_Authority') || !class_exists('MAD4B_SCP_Site_Profile'))
            return new WP_Error('mad4b_test_fixtures_authority_missing','Context Authority is unavailable',['status'=>503]);
        $brand=MAD4B_SCP_Context_Authority::profile();
        $site=MAD4B_SCP_Site_Profile::status();
        $archive=get_option(MAD4B_SCP_Context_Authority::STAGING_TRIAL_ARCHIVE,false);
        if(!is_array($brand)||($brand['status']??'')!=='test_only'||
           !is_array($archive)||($archive['trial_id']??'')!==($brand['brand_id']??'')||
           !isset($site['site_uuid'],$brand['site_uuid']) ||
           !hash_equals(strtolower((string)$site['site_uuid']),strtolower((string)$brand['site_uuid'])) ||
           ($site['environment']??'')!=='staging' || empty($site['configured']) ||
           empty($site['origin_match']) || empty($site['environment_match']))
            return new WP_Error('mad4b_test_fixtures_scope_invalid','Active site-bound TEST Brand required',['status'=>409]);
        return ['site_uuid'=>$site['site_uuid'],'brand_id'=>$brand['brand_id'],
            'brand_name'=>$brand['brand_name'],'brand_revision'=>(int)$brand['revision']];
    }
    public static function catalog() {
        $scope=self::scope();if(is_wp_error($scope))return $scope;
        $path=dirname(__DIR__).'/data/qa28-staging-context-fixtures.json';
        $raw=@file_get_contents($path);
        $fixture=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($fixture)||($fixture['contract']??'')!=='mad4b.staging-brand-fixture-catalog.v1'||
            count($fixture['sources']??[])!==2||count($fixture['assets']??[])!==4)
            return new WP_Error('mad4b_test_fixture_catalog_invalid','Test catalog does not match expected contract',['status'=>503]);
        $sources=[];
        foreach($fixture['sources'] as$r){
            if(($r['provider']??'')!=='in_memory_test_fixture'||($r['mode']??'')!=='sandbox_only'||($r['write_policy']??'')!=='none')
                return new WP_Error('mad4b_test_fixture_source_invalid','Source is not sandbox-only',['status'=>503]);
            $key=sanitize_key($r['key']??'');
            if(!$key||isset($sources[$key]))return new WP_Error('mad4b_test_fixture_duplicate_source','Invalid fixture source',['status'=>503]);
            $sources[$key]=['source_id'=>hash('sha256','qa28-source|'.$scope['site_uuid'].'|'.$scope['brand_id'].'|'.$key),
                'source_key'=>$key,'site_uuid'=>$scope['site_uuid'],'brand_id'=>$scope['brand_id'],
                'label'=>sanitize_text_field($r['label']??''),'provider'=>'in_memory_test_fixture',
                'mode'=>'sandbox_only','status'=>'draft','post_status'=>'draft',
                'is_test_data'=>true,'data_classification'=>'test_only','classification_locked'=>true,
                'write_policy'=>'none',
                'governed'=>false,'external_root_id'=>null,'real_provider_access'=>false];
        }
        $assets=[];
        foreach($fixture['assets'] as$r){
            $key=sanitize_key($r['key']??'');$sourceKey=sanitize_key($r['source_key']??'');
            if(!$key||isset($assets[$key])||!isset($sources[$sourceKey]))
                return new WP_Error('mad4b_test_fixture_asset_invalid','Asset without unique source',['status'=>503]);
            $body=sanitize_text_field($r['body']??'');
            if(!$body||strlen($body)>1000)return new WP_Error('mad4b_test_fixture_body_invalid','Invalid test content',['status'=>503]);
            $assets[$key]=['asset_id'=>hash('sha256','qa28-asset|'.$scope['site_uuid'].'|'.$scope['brand_id'].'|'.$key),
                'source_id'=>$sources[$sourceKey]['source_id'],'site_uuid'=>$scope['site_uuid'],
                'brand_id'=>$scope['brand_id'],'asset_key'=>$key,'title'=>sanitize_text_field($r['title']??''),
                'content'=>$body,'content_sha256'=>hash('sha256',$body),'category'=>sanitize_key($r['category']??''),
                'language'=>'en','status'=>'draft','post_status'=>'draft','is_test_data'=>true,
                'data_classification'=>'test_only','classification_locked'=>true,
                'approved'=>false,'publishable'=>false,
                'governed'=>false,'external_file_id'=>null,'file_url'=>null];
        }
        $context=MAD4B_SCP_Context_Authority::status();
        return ['contract'=>self::CONTRACT,'site_uuid'=>$scope['site_uuid'],'brand_id'=>$scope['brand_id'],
            'brand_name'=>$scope['brand_name'],'brand_status'=>'test_only',
            'test_only'=>true,'virtual'=>true,'draft_by_default'=>true,
            'data_classification'=>'test_only','classification_locked'=>true,
            'persisted_to_governed_registry'=>false,
            'catalog_sha256'=>hash('sha256',$raw),'sources'=>array_values($sources),
            'assets'=>array_values($assets),'governed_context_state'=>$context['state']??'unknown',
            'governed_context_ready'=>(bool)($context['ready']??false),
            'authorized_for_commerce'=>false,'authorized_for_email'=>false,
            'authorized_for_content_publication'=>false,
            'draft_storage_contract'=>'isolated_private_wordpress_cpt',
            'test_metadata_keys'=>['_mad4b_is_test_data','_mad4b_data_classification',
                '_mad4b_fixture_site_uuid','_mad4b_fixture_brand_id','_mad4b_fixture_key']];
    }
    public static function consumers(){
        $data=self::catalog();if(is_wp_error($data))return $data;
        global $wpdb;
        $form=(int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}fluentform_forms WHERE id=6 LIMIT 1");
        $mail=MAD4B_SCP_Company_Communication::brandIdentity();
        $canSend=MAD4B_SCP_Company_Communication::canSend();
        $governedSources=MAD4B_SCP_Context_Authority::sources();
        $governedAssets=MAD4B_SCP_Context_Authority::assets();
        $blocked=$canSend===false && $data['brand_status']==='test_only';
        return ['contract'=>'mad4b.staging-context-consumer-tests.v1',
            'scope'=>['site_uuid'=>$data['site_uuid'],'brand_id'=>$data['brand_id'],'test_only'=>true],
            'fixtures'=>['source_count'=>count($data['sources']),'asset_count'=>count($data['assets']),
                'category_count'=>count(array_unique(array_column($data['assets'],'category'))),
                'all_assets_non_publishable'=>!in_array(true,array_column($data['assets'],'publishable'),true),
                'all_assets_draft_only'=>count(array_filter($data['assets'],static fn($a)=>($a['post_status']??'')==='draft' && ($a['data_classification']??'')==='test_only'))===count($data['assets'])],
            'content'=>['wordpress_available'=>function_exists('get_post_types'),
                'brand_name_inherited'=>($mail['valid']??false) && ($mail['brand_name']??'')===$data['brand_name'],
                'fixture_preview_only'=>true,'published_posts_created'=>0,'translations_created'=>0],
            'commerce'=>['woocommerce_available'=>class_exists('WooCommerce'),
                'fluent_form_6_exists'=>$form===6,'synthetic_booking_state'=>'requires_review',
                'rate_id'=>null,'selling_price'=>null,'order_created'=>false,'payment_allowed'=>false,
                'inventory_modified'=>false],
            'communication'=>['identity_readable'=>($mail['valid']??false),
                'sender_brand_name'=>$data['brand_name'],'outbound_blocked'=>$blocked,
                'smtp_verified'=>MAD4B_SCP_Company_Communication::smtpConfigured(),
                'real_notifications_sent'=>0],
            'draft_storage'=>class_exists('MAD4B_SCP_Staging_Fixture_Drafts')?
                MAD4B_SCP_Staging_Fixture_Drafts::report():['status'=>'draft_layer_not_installed'],
            'authority'=>['governed_source_count'=>count($governedSources),
                'governed_asset_count'=>count($governedAssets),
                'context_ready'=>false,'test_assets_not_masquerading_as_real'=>true],
            'status'=>$blocked&&count($data['assets'])===4?'PASS_READ_ONLY_TEST':'FAILED_SAFE'];
    }
    public static function restDrafts($request){
        $result=class_exists('MAD4B_SCP_Staging_Fixture_Drafts')?
            MAD4B_SCP_Staging_Fixture_Drafts::report():new WP_Error('mad4b_draft_provider_missing','Test draft layer unavailable',['status'=>503]);
        return self::privateResponse($result);
    }
    public static function restCatalog($request){$result=self::catalog();return self::privateResponse($result);}
    public static function restConsumers($request){$result=self::consumers();return self::privateResponse($result);}
    private static function privateResponse($result){
        if(is_wp_error($result))return $result;
        $response=rest_ensure_response($result);$response->header('Cache-Control','private, no-store');return $response;
    }
    public static function page(){
        if(!self::canRead())wp_die('Company operations access required');
        $catalog=self::catalog();$tests=self::consumers();
        echo '<div class="wrap"><h1>Staging TEST Brand — Virtual Context Fixtures</h1>';
        echo '<p>Read-only synthetic catalog for current Staging TEST Brand. Not a governed source, not an approved asset, not a price, and not a sendable message.</p>';
        $drafts=class_exists('MAD4B_SCP_Staging_Fixture_Drafts')?
            MAD4B_SCP_Staging_Fixture_Drafts::report():[];
        foreach([['Source and Asset Catalog',$catalog],['WordPress Drafts and Test Metadata',$drafts],['Consumer Contract Checks',$tests]] as$row){
            echo '<h2>'.esc_html($row[0]).'</h2><pre style="max-height:500px;overflow:auto">'.esc_html(wp_json_encode(is_wp_error($row[1])?['error'=>$row[1]->get_error_code()]:$row[1],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
        }
        echo '</div>';
    }
    public static function register():void {
        if(wp_get_environment_type()!=='staging')return;
        MAD4B_SCP_Admin_Route_Registry::register(self::MENU,'mad4b_manage_company_operations');
        MAD4B_SCP_Admin_Route_Registry::schedule_submenu(static function(){
            add_submenu_page('mad4b-company','Staging Context Fixtures',
                'Staging Context Fixtures','mad4b_manage_company_operations',self::MENU,[__CLASS__,'page']);
        },73);
        add_action('rest_api_init',static function(){
            register_rest_route('mad4b/v1','/brand-staging-trial/fixtures',[
                'methods'=>'GET','permission_callback'=>[__CLASS__,'canRead'],'callback'=>[__CLASS__,'restCatalog']]);
            register_rest_route('mad4b/v1','/brand-staging-trial/draft-fixtures',[
                'methods'=>'GET','permission_callback'=>[__CLASS__,'canRead'],'callback'=>[__CLASS__,'restDrafts']]);
            register_rest_route('mad4b/v1','/brand-staging-trial/consumer-checks',[
                'methods'=>'GET','permission_callback'=>[__CLASS__,'canRead'],'callback'=>[__CLASS__,'restConsumers']]);
        },35);
    }
}
MAD4B_SCP_Staging_Context_Fixtures::register();
