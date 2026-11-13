<?php
/** Source-backed catalog, signed proof gate & negative tests, no database. */
declare(strict_types=1);
define('ABSPATH','/');
define('WP_CLI',true);
$GLOBALS['wp_admin_id']=0;
$GLOBALS['schema_signed_enabled']=false;
$GLOBALS['schema_broker_calls']=0;
$GLOBALS['site_scope_ready']=true;
$GLOBALS['schema_tests']=[];
function run_test(string $name,bool $value):void {
    $GLOBALS['schema_tests'][$name]=$value;
    if(!$value)throw new RuntimeException('FAILED_'.$name);
}
function wp_json_encode($data,$flags=0) {return json_encode($data,$flags);}
function add_action(...$args){return true;}
function add_shortcode(...$args){return true;}
function get_current_user_id():int{return (int)$GLOBALS['wp_admin_id'];}
function wp_get_environment_type():string{return 'production';}
function wp_is_uuid($uuid):bool{return is_string($uuid)&&
    preg_match('/^[a-f0-9-]{36}$/D',$uuid)===1;}
class FakeWPDB {public string $prefix='wp_';}
$wpdb=new FakeWPDB();
class MAD4B_SCP_Site_Profile {
    public static function status():array{
        if(!$GLOBALS['site_scope_ready'])return [];
        return [
            'configured'=>true,'authority_ready'=>true,
            'same_origin_clone_protection'=>true,
            'deployment_binding_match'=>true,
            'origin_match'=>true,'environment_match'=>true,
            'mutation_pending_audit'=>false,
            'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
            'configured_environment'=>'production',
        ];
    }
}
class MAD4B_SCP_Audit{
    public static function storage_status():array{return ['ready'=>true];}
}
/**
 * Synthetic proof seam for offline behavior testing only. Never accepted as
 * first-party signing evidence and never registered in production.
 */
class MAD4B_Original_Tenant_Schema_Migration_Authority_V1 {
    public static bool $admissible=false;
    public static string $tamper='';
    public static int $calls=0;
    public static function verify_exact($operation,$uuid,$env,$tables):array {
        self::$calls++;
        $source=MAD4B_Tenant_Schema_Provider_Catalog_V1::resolve($operation,$tables);
        return [
            'original_signed_312_verified'=>self::$admissible,
            'source_declaration_sha256'=>self::$tamper==='declaration_sha'?
                 str_repeat('a',64):($source['declaration_sha256']??null),
            'provider_source_sha256'=>self::$tamper==='source_sha'?
                 str_repeat('b',64):($source['source_sha256']??null),
            'operation'=>self::$tamper==='operation'?'tenant.fake.schema':$operation,
            'site_uuid'=>self::$tamper==='site_uuid'?'a0000000-0000-4000-8000-000000000000':$uuid,
            'environment'=>self::$tamper==='environment'?'staging':$env,
            'tables'=>self::$tamper==='tables'?['wp_users']:$tables,
            'schema_migration_only'=>self::$tamper!=='purpose',
        ];
    }
}
$includes=dirname(__DIR__).'/wp-content/plugins/mad4b-tenant-sync/includes';
require $includes.'/class-mad4b-tenant-schema-provider-catalog.php';
require $includes.'/class-mad4b-tenant-operation-authority.php';
require $includes.'/class-mad4b-tenant-sparse-store.php';
require $includes.'/class-mad4b-tenant-workflow.php';
require $includes.'/class-mad4b-tenant-company-media.php';
$catalog='MAD4B_Tenant_Schema_Provider_Catalog_V1';
$authority='MAD4B_Tenant_Operation_Authority';
final class MAD4B_Tenant_Untrusted_External_Source {
    public static function schema_source():array{
        return [
            'contract'=>MAD4B_Tenant_Schema_Provider_Catalog_V1::DECLARATION,
            'operation'=>'tenant.fake.schema',
            'tables'=>['users'=>'mad4b_tenant_fake_users']
        ];
    }
}
try{
 $found=$catalog::discover();
 run_test('SOURCE_ONLY_CATALOG_DISCOVERED',
     $found['state']==='SOURCE_OWNED_DISCOVERY_ONLY');
 run_test('THREE_REAL_PROVIDER_DECLARATIONS',
     count($found['operations'])===3);
 run_test('UNTRUSTED_CLASS_DECLARATION_IGNORED',
     !isset($found['operations']['tenant.fake.schema']));
 run_test('DISCOVERY_NEVER_GRANTS_SCHEMA',
     $found['schema_mutation_authorized']===false
     &&$found['signed_grant_present']===false);
 $desired=[
     'tenant.sparse.schema'=>[
       'wp_mad4b_tenant_content_maps',
       'wp_mad4b_tenant_field_overrides',
       'wp_mad4b_tenant_hub_cache'],
     'tenant.workflow.schema'=>['wp_mad4b_tenant_approvals'],
     'tenant.media.schema'=>['wp_mad4b_tenant_media_seo']
 ];
 foreach($desired as $op=>$tables){
    $scope=$catalog::resolve($op,$tables);
    run_test($op.'_SOURCE_MATCHED',
       is_array($scope)&&($scope['source_catalog_verified']??false)===true
       &&($scope['schema_mutation_authorized']??true)===false);
    run_test($op.'_MISSING_SIGNED_PROOF_DENIED',
        $authority::schema($op,$tables)===false);
    run_test($op.'_REJECTED_WRONG_TABLES',
        $catalog::resolve($op,['wp_users'])===null);
 }
 // Positive case uses an explicitly simulated verifier, NOT a live grant.
 // This proves that signed exact scope remains a separate necessary gate.
 MAD4B_Original_Tenant_Schema_Migration_Authority_V1::$admissible=true;
 run_test('OFFLINE_SYNTHETIC_EXACT_SIGNED_SCOPE_ADMITTED',
    $authority::schema('tenant.workflow.schema',$desired['tenant.workflow.schema'])===true);
 foreach(['operation','site_uuid','environment','tables','purpose',
          'declaration_sha','source_sha'] as $tamper){
    MAD4B_Original_Tenant_Schema_Migration_Authority_V1::$tamper=$tamper;
    run_test('SYNTHETIC_SIGNED_PROOF_'.$tamper.'_TAMPER_DENIED',
        $authority::schema('tenant.workflow.schema',$desired['tenant.workflow.schema'])===false);
 }
 MAD4B_Original_Tenant_Schema_Migration_Authority_V1::$tamper='';
 $beforeCalls=MAD4B_Original_Tenant_Schema_Migration_Authority_V1::$calls;
 run_test('UNDECLARED_TABLES_NEVER_REACH_SIGNED_ADAPTER',
    $authority::schema('tenant.workflow.schema',['wp_users'])===false
    &&MAD4B_Original_Tenant_Schema_Migration_Authority_V1::$calls===$beforeCalls);
 MAD4B_Original_Tenant_Schema_Migration_Authority_V1::$admissible=false;
 $reordered=[
    'wp_mad4b_tenant_hub_cache','wp_mad4b_tenant_field_overrides',
    'wp_mad4b_tenant_content_maps'
 ];
 run_test('TABLE_ORDER_BOUND_TO_DECLARATION',
    $catalog::resolve('tenant.sparse.schema',$reordered)===null);
 run_test('UNREGISTERED_OPERATION_NEVER_GUESSABLE',
    $catalog::resolve('tenant.unknown.schema',['wp_mad4b_tenant_approval'])===null);
 run_test('SCHEMA_SQL_TABLE_SYNTAX_REJECTED',
    $catalog::resolve('tenant.sparse.schema',['wp_users; DROP TABLE wp_users'])===null);
 $decl=MAD4B_Tenant_Sparse_Store::schema_source();
 $decl['tables']['users']='wp_users';
 run_test('PROVIDER_FOREIGN_WORDPRESS_TABLE_REJECTED',
    !$catalog::declaration_valid($decl));
 $decl=MAD4B_Tenant_Sparse_Store::schema_source();
 $decl['tables']['duplicate']='mad4b_tenant_content_maps';
 run_test('PROVIDER_DUPLICATE_TABLE_DENIED',
    !$catalog::declaration_valid($decl));
 $decl=MAD4B_Tenant_Sparse_Store::schema_source();
 $decl['operation']='tenant.x;drop';
 run_test('OPERATION_TEXT_MUST_BE_BOUNDED',
    !$catalog::declaration_valid($decl));
 $site=[
    'company'=>'wayfatours','environment'=>'production',
    'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
    'origin'=>'https://wayfatours.com'
 ];
 $m=$catalog::manifest_for(
    ['tenant.sparse.schema','tenant.workflow.schema'],$site);
 run_test('MULTIPROVIDER_MANIFEST_COMPOSED_FROM_SOURCE',
    is_array($m)&&count($m['manifest']['tables'])===4
    &&$m['read_only']===true&&$m['original_313_signed']===false);
 run_test('CURRENT_WAYFA_MANIFEST_SHA_REPRODUCED',
    $m['manifest_sha256']==='1cd226a502972c977d60fa913d63de919ba1c85aa1122fcd2f9c8e986348297b');
 run_test('SOURCE_CAPABILITY_BOUND_TO_ACTUAL_PROVIDER_HASHES',
    preg_match('/^[a-f0-9]{64}$/D',$m['source_capability_sha256'])===1
    &&count($m['source_declarations'])===2);
 $reverse=$catalog::manifest_for(['tenant.workflow.schema','tenant.sparse.schema'],$site);
 run_test('OPERATION_ORDER_DOES_NOT_AFFECT_MANIFEST_SHA',
    $reverse['manifest_sha256']===$m['manifest_sha256']);
 $wrongSite=$site;$wrongSite['site_uuid']='aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
 $cross=$catalog::manifest_for(['tenant.sparse.schema','tenant.workflow.schema'],$wrongSite);
 $qaSite=$site;$qaSite['environment']='qa';
 $qa=$catalog::manifest_for(['tenant.sparse.schema'],$qaSite);
 run_test('SITE_ENVIRONMENT_IS_DYNAMICALLY_SCOPED_NOT_HARDCODED',
    is_array($qa)&&($qa['manifest']['environment']??null)==='qa'
    &&$qa['migration_authorized']===false);
 run_test('CROSS_COMPANY_UUID_CHANGES_MANIFEST_SHA',
    $cross['manifest_sha256']!==$m['manifest_sha256']);
 run_test('MALICIOUS_OPERATION_CANNOT_INJECT_TABLES',
    $catalog::manifest_for(['tenant.sparse.schema','tenant.unknown.schema'],$site)===null);
 run_test('DUPLICATE_OPERATION_SELECTION_DENIED',
    $catalog::manifest_for(['tenant.sparse.schema','tenant.sparse.schema'],$site)===null);
 run_test('MEDIA_OPTIONAL_UNLESS_EXPLICITLY_SELECTED',
    !in_array('mad4b_tenant_media_seo',$m['manifest']['tables'],true)
    &&$catalog::manifest_for(['tenant.media.schema'],$site)['manifest']['tables']===
          ['media'=>'mad4b_tenant_media_seo']);
 $wpdb->prefix='evil.';
 run_test('WORDPRESS_PREFIX_MUST_BE_SQL_IDENTIFIER',
    $catalog::resolve('tenant.workflow.schema',['evil.mad4b_tenant_approvals'])===null);
 $wpdb->prefix='company2_';
 $scope=$catalog::resolve('tenant.workflow.schema',['company2_mad4b_tenant_approvals']);
 run_test('SCHEMA_CATALOG_PREFIX_ADAPTIVE',
    is_array($scope)&&$scope['tables']===['company2_mad4b_tenant_approvals']);
 $wpdb->prefix='wp_';
 $GLOBALS['wp_admin_id']=5;
 run_test('NON_ZERO_WPCLI_USER_NOT_DDL_OWNER',
    $authority::schema('tenant.workflow.schema',$desired['tenant.workflow.schema'])===false);
 $GLOBALS['wp_admin_id']=0;
 $GLOBALS['site_scope_ready']=false;
 run_test('UNBOUND_SITE_DENIED',
    $authority::schema('tenant.workflow.schema',$desired['tenant.workflow.schema'])===false);
 $GLOBALS['site_scope_ready']=true;
 echo json_encode(['pass'=>true,'assertions'=>count($GLOBALS['schema_tests']),
 'catalog_only'=>true,'original_signatures_issued'=>false,
 'original_jobs_created'=>false,'schema_modified'=>false]),"\n";
}catch(Throwable $e){
 echo json_encode(['pass'=>false,
 'assertions_passed'=>count($GLOBALS['schema_tests']),
 'reason'=>$e->getMessage()]),"\n";exit(2);
}
