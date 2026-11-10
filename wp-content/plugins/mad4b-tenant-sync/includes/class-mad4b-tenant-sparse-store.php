<?php
/**
 * Sparse, tenant-bound content storage. No live presentation hooks.
 * The only automatic schema mutation is dbDelta on this site when explicitly
 * called by an authorized Staging operator.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Sparse_Store {
    const CONTRACT = 'mad4b.tenant-sparse-storage.v1';
    const SCHEMA = 'mad4b_tenant_sparse_schema_v1';
    const VERSION = 1;

    private static function table($suffix) {
        global $wpdb;
        return $wpdb->prefix . 'mad4b_tenant_' . $suffix;
    }
    private static function err($name) {
        return new WP_Error('mad4b_sparse_' . $name, $name);
    }
    private static function local_scope($write=false) {
        if(!class_exists('MAD4B_SCP_Site_Profile')) return self::err('profile_missing');
        $s=MAD4B_SCP_Site_Profile::status();
        if(empty($s['authority_ready']) || empty($s['same_origin_clone_protection'])
          || empty($s['deployment_binding_match']) || empty($s['origin_match'])
          || empty($s['site_uuid']) || !empty($s['mutation_pending_audit'])) return self::err('site_not_bound');
        // Read-only tenant lookups use enrolled UUID, origin and deployment
        // binding; a stale environment label is not data ownership proof.
        // Preserve the old exact-environment requirement for *writes*,
        // which still have their separate staging/local and audit gates.
        if($write) {
            if(($s['configured_environment']??'')!==wp_get_environment_type())
                return self::err('environment_mismatch');
            if(!in_array(wp_get_environment_type(),['staging','local'],true))return self::err('non_test_mutation_blocked');
            if(!class_exists('MAD4B_SCP_Audit') ||
              empty(MAD4B_SCP_Audit::storage_status()['ready']))return self::err('audit_unready');
        }
        return (string)$s['site_uuid'];
    }
    public static function names(){
        return ['maps'=>self::table('content_maps'),
            'overrides'=>self::table('field_overrides'),
            'cache'=>self::table('hub_cache')];
    }
    public static function exists(){
        global $wpdb;
        foreach(self::names() as $table) {
            if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table)return false;
        }
        return true;
    }
    public static function install(){
        global $wpdb;
        $scope=self::local_scope(true);
        if(is_wp_error($scope))return $scope;
        if(!defined('WP_CLI') || !WP_CLI || get_current_user_id()!==0)
            return self::err('schema_install_requires_host_cli');
        if(!self::exists()) {
            $a=self::audit('mad4b/tenant-sparse-schema-intent',[
                'site_uuid'=>$scope,'schema'=>self::VERSION,'environment'=>wp_get_environment_type(),
                'product_prices_modified'=>false,'external_distribution'=>false],'intent');
            if(is_wp_error($a))return $a;
        }
        if(!function_exists('dbDelta'))require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();
        $t=self::names();
        $sql1="CREATE TABLE {$t['maps']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
site_uuid char(36) NOT NULL,
global_content_id varchar(64) NOT NULL,
language varchar(12) NOT NULL,
source_site_uuid char(36) NOT NULL,
local_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
post_type varchar(64) NOT NULL,
mode varchar(16) NOT NULL DEFAULT 'reference',
state varchar(24) NOT NULL DEFAULT 'local_seed',
source_version char(64) NOT NULL DEFAULT '',
revision bigint(20) unsigned NOT NULL DEFAULT 1,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY tenant_object (site_uuid,global_content_id,language),
KEY local_post (site_uuid,local_post_id),
KEY state (state)
) $charset;";
        $sql2="CREATE TABLE {$t['overrides']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
map_id bigint(20) unsigned NOT NULL,
field_path varchar(96) NOT NULL,
value_json longtext NOT NULL,
value_sha256 char(64) NOT NULL,
revision bigint(20) unsigned NOT NULL DEFAULT 1,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY map_field (map_id,field_path),
KEY updated_at (updated_at)
) $charset;";
        $sql3="CREATE TABLE {$t['cache']} (
map_id bigint(20) unsigned NOT NULL,
source_version char(64) NOT NULL,
payload_json longtext NOT NULL,
payload_sha256 char(64) NOT NULL,
authority_state varchar(24) NOT NULL DEFAULT 'unverified',
expires_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (map_id),
KEY expires_at (expires_at)
) $charset;";
        foreach([$sql1,$sql2,$sql3] as $sql) dbDelta($sql);
        if(!self::exists())return self::err('schema_readback_failed');
        update_option(self::SCHEMA,self::VERSION,false);
        if((int)get_option(self::SCHEMA)!==self::VERSION)return self::err('schema_version_not_persisted');
        $a=self::audit('mad4b/tenant-sparse-schema-result',[
            'site_uuid'=>$scope,'schema'=>self::VERSION,'tables'=>array_values(self::names()),
            'live_content_changed'=>false],'ok');
        if(is_wp_error($a))return $a;
        return ['contract'=>self::CONTRACT,'schema'=>self::VERSION,
            'tables'=>self::names(),'verified'=>true,'site_uuid'=>$scope];
    }
    public static function source_identity($global,$language) {
        return is_string($global) && preg_match('/^(?:content|local)-[a-f0-9]{32}$/D',$global)
          && is_string($language) && preg_match('/^[a-z]{2,3}(?:-[a-zA-Z]{2,5})?$/D',$language);
    }
    public static function mapping($global,$language) {
        global $wpdb;
        $scope=self::local_scope(false);
        if(is_wp_error($scope))return $scope;
        if(!self::source_identity($global,$language))return self::err('identity_invalid');
        if(!self::exists())return self::err('schema_missing');
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM '.self::table('content_maps').' WHERE site_uuid=%s AND global_content_id=%s AND language=%s LIMIT 1',
            $scope,$global,$language),ARRAY_A);
    }
    private static function can_write_map($map,$field='') {
        // Host-scoped WP-CLI test authority is not exposed over REST or admin.
        if(defined('WP_CLI') && WP_CLI && get_current_user_id()===0)return true;
        // Company staff cannot bypass four-eyes approval via the legacy UI.
        return is_array($map) && ($map['state']??'')==='local_seed'
          && (int)($map['local_post_id']??0)>0 && is_string($field)&&$field!==''
          && class_exists('MAD4B_Tenant_Workflow')
          && MAD4B_Tenant_Workflow::permitted_apply($map,$field);
    }
    public static function ensure_local_seed($post_id) {
        global $wpdb;
        // First-touch map creation is permitted in Production only inside
        // the exact authorized Workflow Submit window. Neither a direct
        // admin/REST call nor an independent Sparse write can open it.
        $identity=self::local_scope(false);
        if(is_wp_error($identity))return $identity;
        $workflow_seed=class_exists('MAD4B_Tenant_Workflow')
            && MAD4B_Tenant_Workflow::permitted_seed($post_id,$identity);
        $scope=self::local_scope(!$workflow_seed);
        if(is_wp_error($scope))return $scope;
        if(!self::exists())return self::err('schema_missing');
        $ctx=class_exists('MAD4B_Tenant_Override_Policy')
            ?MAD4B_Tenant_Override_Policy::context($post_id):null;
        if(!$ctx || $ctx['site_uuid']!==$scope
           || (get_current_user_id()!==0 && !current_user_can('edit_post',$post_id)))
           return self::err('not_allowed_content_post');
        $post=$ctx['post'];$language=$ctx['language'];
        if(!self::source_identity('local-'.str_repeat('a',32),$language))
            return self::err('unverified_language');
        $global='local-'.substr(hash('sha256',$scope.'|'.$post->post_type.'|'.$post->ID),0,32);
        $existing=self::mapping($global,$language);
        if(is_wp_error($existing))return $existing;
        if($existing){
            if((int)$existing['local_post_id']!==$post->ID || $existing['site_uuid']!==$scope)
                return self::err('seed_binding_conflict');
            return $existing;
        }
        $row=['site_uuid'=>$scope,'global_content_id'=>$global,
            'language'=>$language,'source_site_uuid'=>$scope,
            'local_post_id'=>$post->ID,'post_type'=>$post->post_type,
            'mode'=>'override','state'=>'local_seed',
            'source_version'=>'','revision'=>1,
            'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)];
        $receipt=self::audit('mad4b/tenant-map-seed-intent',[
            'site_uuid'=>$scope,'global_content_id'=>$global,'local_post_id'=>$post->ID,
            'source_state'=>'local_seed','commercial_publishing'=>false],'intent');
        if(is_wp_error($receipt))return $receipt;
        $insert=$wpdb->insert(self::table('content_maps'),$row);
        if($insert!==1) {
            $after=self::mapping($global,$language);
            if(is_array($after)&&(int)$after['local_post_id']===$post->ID)return $after;
            return self::err('map_insert_uncertain');
        }
        $after=self::mapping($global,$language);
        if(!$after || (int)$after['local_post_id']!==$post->ID)return self::err('map_readback_uncertain');
        $receipt=self::audit('mad4b/tenant-map-seed-result',[
            'site_uuid'=>$scope,'global_content_id'=>$global,'map_id'=>(int)$after['id'],
            'local_post_id'=>$post->ID,'source_publication_approved'=>false],'ok');
        if(is_wp_error($receipt))return $receipt;
        return $after;
    }
    private static function audit($event,$data,$state) {
        if(!class_exists('MAD4B_SCP_Audit'))return self::err('audit_missing');
        $r=MAD4B_SCP_Audit::record($event,$data,$state);
        return is_wp_error($r)?self::err('audit_failed'):$r;
    }
    private static function check_map($map,$write=false){
        $uuid=self::local_scope($write);
        if(is_wp_error($uuid))return $uuid;
        if(!is_array($map) || ($map['site_uuid']??'')!==$uuid
            || (int)($map['id']??0)<1)return self::err('map_scope_mismatch');
        return $uuid;
    }
    public static function override_rows($map) {
        global $wpdb;
        $scope=self::check_map($map);
        if(is_wp_error($scope))return $scope;
        $rows=$wpdb->get_results($wpdb->prepare(
          'SELECT field_path,value_json,value_sha256,revision FROM '.self::table('field_overrides').' WHERE map_id=%d ORDER BY field_path',
          (int)$map['id']),ARRAY_A);
        $out=[];
        foreach((array)$rows as $row){
            if(!hash_equals($row['value_sha256'],hash('sha256',$row['value_json'])))
                return self::err('override_integrity_mismatch');
            $v=json_decode($row['value_json'],true);
            if(json_last_error()!==JSON_ERROR_NONE)return self::err('override_decode_error');
            $out[$row['field_path']]=['value'=>$v,'revision'=>(int)$row['revision']];
        }
        return $out;
    }
    public static function set_override($map,$field,$value,$expected_revision) {
        global $wpdb;
        // Only this operation's four-eyes-approved Workflow apply may
        // rely on the current tenant's verified read scope in Production.
        // All ordinary sparse writes retain their former write gate.
        $approved=class_exists('MAD4B_Tenant_Workflow')
            && MAD4B_Tenant_Workflow::permitted_apply($map,$field);
        $scope=self::check_map($map,!$approved);
        if(is_wp_error($scope))return $scope;
        if(!self::can_write_map($map,$field))return self::err('actor_not_authorized');
        if(!is_int($expected_revision)||$expected_revision<0)return self::err('revision_invalid');
        $defs=MAD4B_Tenant_Field_Registry::definitions($map['post_type']);
        if(!isset($defs[$field]))return self::err('field_unregistered');
        if(!MAD4B_Tenant_Field_Registry::valid_value($field,$value,$scope,'tenant',$defs))
            return self::err('value_invalid');
        if(!self::media_owned($value,$defs[$field]['type'],$scope))return self::err('media_attachment_invalid');
        $json=wp_json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($json)||strlen($json)>40000)return self::err('value_too_large');
        $digest=hash('sha256',$json);
        $t=self::table('field_overrides');
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT id,revision,value_sha256 FROM $t WHERE map_id=%d AND field_path=%s LIMIT 1",
            (int)$map['id'],$field),ARRAY_A);
        if($expected_revision!==(int)($row['revision']??0))return self::err('stale_revision');
        if($row && hash_equals($row['value_sha256'],$digest))
            return ['status'=>'unchanged','revision'=>$expected_revision,'field'=>$field];
        $intent=self::audit('mad4b/tenant-sparse-override-intent',[
            'site_uuid'=>$scope,'global_content_id'=>$map['global_content_id'],
            'field'=>$field,'expected_revision'=>$expected_revision,
            'value_sha256'=>$digest,'price_or_post_modified'=>false],'intent');
        if(is_wp_error($intent))return $intent;
        if(!$row){
            $ok=$wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $t (map_id,field_path,value_json,value_sha256,revision,updated_at) VALUES (%d,%s,%s,%s,1,%s)",
                (int)$map['id'],$field,$json,$digest,current_time('mysql',true)));
        } else {
            $ok=$wpdb->query($wpdb->prepare(
                "UPDATE $t SET value_json=%s,value_sha256=%s,revision=revision+1,updated_at=%s WHERE map_id=%d AND field_path=%s AND revision=%d",
                $json,$digest,current_time('mysql',true),(int)$map['id'],$field,$expected_revision));
        }
        if($ok!==1)return self::err('concurrent_change_or_write_uncertain');
        $read=self::override_rows($map);
        if(is_wp_error($read)||!isset($read[$field])||$read[$field]['revision']!==$expected_revision+1
            ||$read[$field]['value']!==json_decode($json,true))return self::err('override_readback_uncertain');
        $receipt=self::audit('mad4b/tenant-sparse-override-result',[
            'site_uuid'=>$scope,'global_content_id'=>$map['global_content_id'],
            'field'=>$field,'revision'=>$expected_revision+1,'value_sha256'=>$digest,
            'live_wordpress_meta_changed'=>false,'commerce_write'=>false],'ok');
        if(is_wp_error($receipt))return $receipt;
        return ['status'=>'stored','revision'=>$expected_revision+1,'field'=>$field,
            'sparse_row_only'=>true];
    }
    private static function media_owned($value,$type,$scope){
        if(!in_array($type,['media_ref','media_collection'],true)||$value===null)return true;
        $refs=$type==='media_collection'?$value:[$value];
        foreach($refs as $ref){
            if(is_string($ref))continue; // Canonical media refs resolved by later adapter.
            $id=(int)($ref['attachment_id']??0);
            if(($ref['scope']??'')!=='tenant'||($ref['site_uuid']??'')!==$scope
                ||get_post_type($id)!=='attachment'
                ||!str_starts_with((string)get_post_mime_type($id),'image/'))return false;
        }
        return true;
    }
    public static function clear_override($map,$field,$expected_revision) {
        global $wpdb;
        $scope=self::check_map($map,true);
        if(is_wp_error($scope))return $scope;
        if(!self::can_write_map($map,$field))return self::err('actor_not_authorized');
        $defs=MAD4B_Tenant_Field_Registry::definitions($map['post_type']);
        if(!isset($defs[$field])||!is_int($expected_revision)||$expected_revision<1)return self::err('delete_arguments_invalid');
        $t=self::table('field_overrides');
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT revision,value_sha256 FROM $t WHERE map_id=%d AND field_path=%s LIMIT 1",
            (int)$map['id'],$field),ARRAY_A);
        if(!$row||(int)$row['revision']!==$expected_revision)return self::err('stale_revision');
        $intent=self::audit('mad4b/tenant-sparse-override-clear-intent',[
            'site_uuid'=>$scope,'global_content_id'=>$map['global_content_id'],
            'field'=>$field,'revision'=>$expected_revision,'value_sha256'=>$row['value_sha256']],'intent');
        if(is_wp_error($intent))return $intent;
        $deleted=$wpdb->query($wpdb->prepare(
            "DELETE FROM $t WHERE map_id=%d AND field_path=%s AND revision=%d",
            (int)$map['id'],$field,$expected_revision));
        if($deleted!==1)return self::err('clear_uncertain');
        $read=self::override_rows($map);
        if(is_wp_error($read)||isset($read[$field]))return self::err('clear_readback_uncertain');
        $r=self::audit('mad4b/tenant-sparse-override-clear-result',[
            'site_uuid'=>$scope,'global_content_id'=>$map['global_content_id'],
            'field'=>$field,'inherited_value_restored'=>true,'post_modified'=>false],'ok');
        if(is_wp_error($r))return $r;
        return ['status'=>'cleared','field'=>$field,'inherits_again'=>true];
    }
    public static function qa_store_cache($map,$fields,$source_version,$expires_seconds=3600) {
        global $wpdb;
        $scope=self::check_map($map,true);
        if(is_wp_error($scope))return $scope;
        // Synthetic fixtures only, in host WP-CLI; NEVER a publication route.
        if(!defined('WP_CLI')||!WP_CLI||!defined('MAD4B_SPARSE_QA_FIXTURE')
            ||MAD4B_SPARSE_QA_FIXTURE!==true || ($map['state']??'')!=='qa_fixture'
            ||!str_starts_with($map['global_content_id'],'content-'))return self::err('qa_cache_only');
        $defs=MAD4B_Tenant_Field_Registry::definitions($map['post_type']);
        if(!is_array($fields)||count($fields)>80
            ||array_diff(array_keys($fields),array_keys($defs))
            ||!preg_match('/^[a-f0-9]{64}$/D',(string)$source_version))return self::err('cache_contract_invalid');
        foreach($fields as $f=>$v)
            if(!MAD4B_Tenant_Field_Registry::valid_value($f,$v,$scope,'source',$defs))
                return self::err('cache_field_invalid');
        $json=wp_json_encode($fields,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($json)||strlen($json)>250000)return self::err('cache_too_large');
        $hash=hash('sha256',$json);
        $t=self::table('hub_cache');
        $expiry=gmdate('Y-m-d H:i:s',time()+max(1,min($expires_seconds,86400)));
        $r=$wpdb->query($wpdb->prepare(
            "INSERT INTO $t (map_id,source_version,payload_json,payload_sha256,authority_state,expires_at,updated_at)
             VALUES (%d,%s,%s,%s,'qa_fixture',%s,%s)
             ON DUPLICATE KEY UPDATE source_version=VALUES(source_version),payload_json=VALUES(payload_json),
               payload_sha256=VALUES(payload_sha256),expires_at=VALUES(expires_at),updated_at=VALUES(updated_at)",
            (int)$map['id'],$source_version,$json,$hash,$expiry,current_time('mysql',true)));
        if(!$r)return self::err('qa_cache_not_stored');
        return ['status'=>'qa_cached','sha256'=>$hash,'expires_at'=>$expiry];
    }
    public static function effective($global,$language) {
        global $wpdb;
        $map=self::mapping($global,$language);
        if(is_wp_error($map))return $map;
        if(!$map)return self::err('mapping_absent');
        if(($map['mode']??'')==='fork')
            return ['contract'=>self::CONTRACT.'.effective','status'=>'detached','fields'=>[],
                'remote_sync_authorized'=>false];
        $t=self::table('hub_cache');
        $cache=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE map_id=%d LIMIT 1",(int)$map['id']),ARRAY_A);
        $base=[];$basis='unavailable';$source_version='';
        if($cache) {
            if(!hash_equals($cache['payload_sha256'],hash('sha256',$cache['payload_json'])))
                return self::err('cache_integrity_mismatch');
            if(strtotime($cache['expires_at'].' UTC')<time())return self::err('cache_expired');
            if($cache['authority_state']!=='qa_fixture')
                return self::err('cache_not_certified_for_read');
            $base=json_decode($cache['payload_json'],true);
            if(!is_array($base))return self::err('cache_decode_failed');
            $source_version=$cache['source_version'];$basis='qa_fixture_cache';
        }elseif($map['state']==='local_seed' && (int)$map['local_post_id']>0){
            // Existing WP content is already here: no duplicated post rows.
            $post=get_post((int)$map['local_post_id']);
            if(!$post || $post->post_type!==$map['post_type'])
                return self::err('local_shadow_missing');
            $base=['title'=>(string)$post->post_title,
                'excerpt'=>(string)$post->post_excerpt,
                'body_sha256'=>hash('sha256',(string)$post->post_content)];
            foreach(['seo_title'=>'rank_math_title','seo_description'=>'rank_math_description',
                'seo_focus_keyword'=>'rank_math_focus_keyword'] as $field=>$meta){
                $v=get_post_meta($post->ID,$meta,true);
                if(is_string($v))$base[$field]=$v;
            }
            $layout=get_post_meta($post->ID,'_mad4b_presentation_variant',true);
            if(is_string($layout)&&in_array($layout,['card','accordion'],true))
                $base['presentation_variant']=$layout;
            $featured=(int)get_post_thumbnail_id($post->ID);
            if($featured && get_post_type($featured)==='attachment')
                $base['featured_media_global_id']=['scope'=>'tenant',
                    'site_uuid'=>$map['site_uuid'],'attachment_id'=>$featured];
            $basis='existing_wp_post_no_copy';
        }else return self::err('verified_hub_snapshot_unavailable');
        $overrides=self::override_rows($map);
        if(is_wp_error($overrides))return $overrides;
        foreach($overrides as $field=>$entry)$base[$field]=$entry['value'];
        return ['contract'=>self::CONTRACT.'.effective','status'=>'preview_only',
            'global_content_id'=>$global,'site_uuid'=>$map['site_uuid'],
            'language'=>$language,'mode'=>$map['mode'],
            'source'=>$basis,'source_version'=>$source_version,
            'fields'=>$base,'override_fields'=>array_keys($overrides),
            'override_count'=>count($overrides),
            'local_post_id'=>(int)$map['local_post_id'],
            'remote_sync_authorized'=>false,'woocommerce_repricing_authorized'=>false,
            'wordpress_posts_modified'=>false,'mutation_performed'=>false];
    }
}
