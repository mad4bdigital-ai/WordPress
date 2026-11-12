<?php
/**
 * Plugin Name: MAD4B Company Exact Resource Evidence
 * Description: Strict read-only, authenticated target resource evidence; no original authority or mutation.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Company_Exact_Resource_Evidence_V1',false)) {
final class MAD4B_Company_Exact_Resource_Evidence_V1 {
    public const CONTRACT='mad4b.company-repair.resource-observation.v1';
    private static function hold(string $reason):array {
        return [
            'contract'=>self::CONTRACT,'state'=>'EXACT_RESOURCE_READBACK_HOLD',
            'reason'=>$reason,'original_313_signed'=>false,
            'original_312_signed'=>false,'mutation_performed'=>false,
            'native_job_created'=>false
        ];
    }
    private static function hash($value):string {
        $encoded=wp_json_encode($value,
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($encoded))
            throw new UnexpectedValueException('resource_snapshot_encoding_failed');
        return hash('sha256',$encoded);
    }
    private static function identity():array {
        if(!class_exists('MAD4B_SCP_Site_Profile',false)
            ||!is_callable(['MAD4B_SCP_Site_Profile','profile'])
            ||!is_callable(['MAD4B_SCP_Site_Profile','status']))
            return ['state'=>'SITE_PROFILE_PROVIDER_NOT_AVAILABLE'];
        $profile=MAD4B_SCP_Site_Profile::profile();
        $status=MAD4B_SCP_Site_Profile::status();
        $uuid=$profile['site_uuid']??null;
        $origin=untrailingslashit(home_url('/'));
        $environment=wp_get_environment_type();
        $valid=wp_is_uuid($uuid)
            &&$origin===($profile['canonical_origin']??null)
            &&$environment===($profile['environment']??null)
            &&($status['configured']??false)===true
            &&($status['origin_match']??false)===true
            &&($status['environment_match']??false)===true
            &&($status['deployment_binding_match']??false)===true
            &&($status['site_uuid']??null)===$uuid
            &&in_array($environment,['staging','production'],true)
            &&str_starts_with($origin,'https://');
        return $valid?[
            'state'=>'SITE_PROFILE_EXACT_MATCH','site_uuid'=>$uuid,
            'canonical_origin'=>$origin,'environment'=>$environment
        ]:['state'=>'SITE_PROFILE_IDENTITY_NOT_VERIFIED'];
    }
    private static function privateDigest():string {
        // No raw company configuration, email, credential, webhook, license,
        // WPML tokens, or customer/order data may be returned.
        $keys=['blogname','woocommerce_currency',
            'rank_math_titles_options','sitepress_settings',
            'mad4b_company_brand_kit','mad4b_age_collection_policy_v1',
            'mad4b_age_occupancy_policy_product_types_v1'];
        $hashes=[];
        foreach($keys as $key) {
            $value=get_option($key,null);
            $hashes[$key]=$value===null?null:self::hash($value);
        }
        return self::hash($hashes);
    }
    private static function postSnapshot(string $id):array {
        if(!preg_match('/^[1-9][0-9]{0,9}$/D',$id))
            return self::hold('POST_ID_NOT_NUMERIC');
        $post=get_post((int)$id);
        if(!$post instanceof WP_Post)return self::hold('POST_NOT_FOUND');
        $type=(string)$post->post_type;
        $typeObject=get_post_type_object($type);
        if(!$typeObject ||!$typeObject->public
            ||in_array($type,['shop_order','shop_order_refund','shop_coupon',
                'fluentform','fluentform_submissions','attachment'],true))
            return self::hold('POST_TYPE_NOT_IN_PUBLIC_CONTENT_SCOPE');
        $postFields=[
            'id'=>(int)$post->ID,'type'=>$type,'status'=>(string)$post->post_status,
            'slug'=>(string)$post->post_name,'parent'=>(int)$post->post_parent,
            'title_sha256'=>hash('sha256',(string)$post->post_title),
            'body_sha256'=>hash('sha256',(string)$post->post_content),
            'excerpt_sha256'=>hash('sha256',(string)$post->post_excerpt),
            'modified_gmt'=>(string)$post->post_modified_gmt
        ];
        $raw=get_post_meta($post->ID);
        if(!is_array($raw)||count($raw)>450)return self::hold('POST_METADATA_TOO_LARGE');
        $meta=[];
        foreach($raw as $key=>$value) {
            if(!is_string($key)||strlen($key)>191||!is_array($value))
                return self::hold('POST_METADATA_UNSTRUCTURED');
            $meta[$key]=self::hash($value);
        }
        ksort($meta,SORT_STRING);
        $taxonomies=get_object_taxonomies($type,'names');
        if(count($taxonomies)>50)return self::hold('POST_TAXONOMY_SET_TOO_LARGE');
        $terms=[];
        foreach($taxonomies as $tax) {
            if(!taxonomy_exists($tax))continue;
            $ids=wp_get_object_terms($post->ID,$tax,['fields'=>'ids']);
            if(is_wp_error($ids))return self::hold('POST_TERM_READBACK_FAILED');
            $ids=array_map('intval',$ids);sort($ids,SORT_NUMERIC);
            $terms[$tax]=$ids;
        }
        ksort($terms,SORT_STRING);
        $manifest=['post'=>$postFields,'meta_keys_sha256'=>$meta,'taxonomy_term_ids'=>$terms];
        $before=['post'=>$postFields,'metadata_and_terms_sha256'=>self::hash([
            $meta,$terms])];
        return [
            'ownership_scope'=>in_array($type,['elementor_library'],true)
                ?'shared_definition':'tenant_owned',
            'before_sha256'=>self::hash($before),
            'resource_manifest_sha256'=>self::hash($manifest),
            'metadata_key_count'=>count($meta),
            'taxonomy_count'=>count($terms),
        ];
    }
    private static function termSnapshot(string $id):array {
        if(!preg_match('/^[1-9][0-9]{0,9}$/D',$id))
            return self::hold('TERM_ID_NOT_NUMERIC');
        $term=get_term((int)$id);
        if(is_wp_error($term)||!$term instanceof WP_Term)
            return self::hold('TERM_NOT_FOUND');
        if(!taxonomy_exists($term->taxonomy))
            return self::hold('TAXONOMY_NOT_REGISTERED');
        $raw=get_term_meta($term->term_id);
        if(!is_array($raw)||count($raw)>200)return self::hold('TERM_META_TOO_LARGE');
        $meta=[];
        foreach($raw as $key=>$value){
            if(!is_string($key)||strlen($key)>191||!is_array($value))
                return self::hold('TERM_META_UNSTRUCTURED');
            $meta[$key]=self::hash($value);
        }
        ksort($meta,SORT_STRING);
        $manifest=[
            'id'=>$term->term_id,'taxonomy'=>$term->taxonomy,
            'parent'=>(int)$term->parent,'slug'=>$term->slug,
            'name_sha256'=>hash('sha256',(string)$term->name),
            'description_sha256'=>hash('sha256',(string)$term->description),
            'meta_keys_sha256'=>$meta
        ];
        return [
            'ownership_scope'=>'tenant_owned',
            'before_sha256'=>self::hash($manifest),
            'resource_manifest_sha256'=>self::hash($manifest),
            'metadata_key_count'=>count($meta),
        ];
    }
    private static function pluginSnapshot(string $id):array {
        // Identified by simple directory slug only, never a caller file path.
        if(!preg_match('/^[a-z][a-z0-9-]{2,62}$/D',$id))
            return self::hold('PLUGIN_SLUG_INVALID');
        $active=(array)get_option('active_plugins',[]);
        $entries=[];
        foreach($active as $entry) {
            if(!is_string($entry)||!str_starts_with($entry,$id.'/')
                ||!preg_match('~^[a-z0-9-]+/[a-z0-9._-]+[.]php$~D',$entry))
                continue;
            $file=realpath(WP_PLUGIN_DIR.'/'.$entry);
            $root=realpath(WP_PLUGIN_DIR);
            if(!$file||!$root||!str_starts_with($file,$root.'/')
                ||is_link(WP_PLUGIN_DIR.'/'.$entry)||!is_file($file))
                return self::hold('PLUGIN_ENTRYPOINT_NOT_TRUSTED');
            $digest=hash_file('sha256',$file);
            if(!is_string($digest)||!preg_match('/^[a-f0-9]{64}$/D',$digest))
                return self::hold('PLUGIN_ENTRYPOINT_HASH_UNREADABLE');
            $entries[$entry]=$digest;
        }
        ksort($entries,SORT_STRING);
        if(!$entries)return self::hold('PLUGIN_ACTIVE_ENTRYPOINT_NOT_FOUND');
        return [
            'ownership_scope'=>'site_generated',
            'before_sha256'=>self::hash($entries),
            'resource_manifest_sha256'=>self::hash($entries),
            'entrypoint_count'=>count($entries),
            'plugin_directory_not_fully_hashed'=>true
        ];
    }
    public static function inspect(string $resourceType,string $resourceId):array {
        if(!current_user_can('manage_options'))
            return self::hold('ADMINISTRATOR_REQUIRED');
        $identity=self::identity();
        if(($identity['state']??null)!=='SITE_PROFILE_EXACT_MATCH')
            return self::hold('EXACT_SITE_IDENTITY_NOT_VERIFIED');
        if(!is_string($resourceId)||strlen($resourceId)>128)
            return self::hold('RESOURCE_IDENTIFIER_INVALID');
        try {
        $preservationBefore=self::privateDigest();
        $reader=static fn()=>match($resourceType) {
            'wordpress.post'=>self::postSnapshot($resourceId),
            'wordpress.term'=>self::termSnapshot($resourceId),
            'wordpress.plugin'=>self::pluginSnapshot($resourceId),
            default=>self::hold('RESOURCE_TYPE_NOT_DECLARED')
        };
        // A read can straddle a simultaneous Elementor, taxonomy, pricing or
        // company-option edit. Two stable resource and ownership digests are
        // required; this is advisory consistency, NOT a SQL snapshot or 313.
        $facts=$reader();
        if(($facts['state']??null)==='EXACT_RESOURCE_READBACK_HOLD')
            return $facts;
        $second=$reader();
        if(($second['state']??null)==='EXACT_RESOURCE_READBACK_HOLD')
            return $second;
        $preservationAfter=self::privateDigest();
        if(($facts['before_sha256']??null)!==($second['before_sha256']??null)
           ||($facts['resource_manifest_sha256']??null)!==
               ($second['resource_manifest_sha256']??null)
           ||$preservationBefore!==$preservationAfter)
            return self::hold('RESOURCE_OR_COMPANY_CHANGED_DURING_READBACK');
        $now=time();
        }catch(Throwable $error){
            return self::hold('RESOURCE_READBACK_ENCODING_OR_INTERNAL_FAILURE');
        }
        return [
            'contract'=>self::CONTRACT,
            'state'=>'EXACT_RESOURCE_READ_ONLY_OBSERVED',
            'observation_level'=>'TARGET_WORDPRESS_AUTHENTICATED_READ_ONLY',
            'site_uuid'=>$identity['site_uuid'],
            'canonical_origin'=>$identity['canonical_origin'],
            'environment'=>$identity['environment'],
            'resource_type'=>$resourceType,'resource_id'=>$resourceId,
            'resource_key'=>$resourceType.':'.$resourceId,
            'ownership_scope'=>$facts['ownership_scope'],
            'before_sha256'=>$facts['before_sha256'],
            'resource_manifest_sha256'=>$facts['resource_manifest_sha256'],
            'company_preservation_sha256'=>$preservationAfter,
            'observed_epoch'=>$now,
            'source_of_evidence'=>'LOCAL_ADMINISTRATOR_WORDPRESS_READ_ONLY',
            'raw_sensitive_metadata_emitted'=>false,
            'resource_sample_counts'=>array_intersect_key($facts,array_flip([
                'metadata_key_count','taxonomy_count','entrypoint_count'])),
            'original_313_signed'=>false,
            'original_312_signed'=>false,
            'mutation_performed'=>false,
            'native_job_created'=>false
        ];
    }
    public static function rest():void {
        register_rest_route('mad4b/v1','/company-reconcile/resource-evidence',[
            'methods'=>'GET',
            'permission_callback'=>static fn()=>current_user_can('manage_options'),
            'callback'=>static function(WP_REST_Request $request){
                $row=self::inspect((string)$request->get_param('resource_type'),
                    (string)$request->get_param('resource_id'));
                $response=rest_ensure_response($row);
                $response->header('Cache-Control','private, no-store, max-age=0');
                return $response;
            }
        ]);
    }
    public static function cli(array $args,array $assoc):void {
        if(count($args)!==1||($args[0]??'')!=='inspect'
            ||array_diff(array_keys($assoc),['type','id']))
            WP_CLI::error('RESOURCE_EVIDENCE_INSPECT_ONLY');
        $row=self::inspect((string)($assoc['type']??''),
             (string)($assoc['id']??''));
        WP_CLI::line(wp_json_encode($row,JSON_UNESCAPED_SLASHES|
            JSON_UNESCAPED_UNICODE));
    }
    public static function boot():void {
        add_action('rest_api_init',[__CLASS__,'rest'],35);
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b resource-evidence',[__CLASS__,'cli']);
    }
}
MAD4B_Company_Exact_Resource_Evidence_V1::boot();
}
