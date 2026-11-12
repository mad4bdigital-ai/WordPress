<?php
/**
 * Plugin Name: MAD4B Original Executor-Time Resource CAS V1
 * Description: Signed original-job-scoped, one-use transactional CAS for an exact WordPress resource. No public write routes.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Company_Executor_Time_CAS_V1',false)){
final class MAD4B_Company_Executor_Time_CAS_V1 {
    public const CONTRACT='mad4b.company.original-executor-resource-cas.v1';
    public const STATUS_CONTRACT='mad4b.company.original-executor-resource-cas-result.v1';
    public const MAX_AGE=300;
    public const FIELDS=[
        'contract','original_job_id','company_code','environment',
        'site_uuid','canonical_origin','native_operation',
        'source_plan_sha256','resource_type','resource_id','post_type',
        'meta_key','meta_id','expected_before_sha256',
        'expected_manifest_sha256','expected_company_preservation_sha256',
        'expected_meta_value_sha256','new_meta_value_sha256',
        'expected_after_sha256','issued_epoch','expires_epoch','nonce'
    ];
    // Existing Original 305 broker public key, pinned at first-party source release.
    // No secret key is present in WordPress. A rotation requires governed source deployment.
    public const ORIGINAL_305_PUBLIC_B64='cvvlVSS9kS+GaqvX+1iSuAO1ewAdKfnvRB5f8oVTwdU=';
    private static function sha($value):bool {
        return is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value)===1;
    }
    private static function hold(string $reason):array {
        return [
            'contract'=>self::STATUS_CONTRACT,
            'state'=>'EXECUTOR_CAS_HOLD',
            'reason'=>$reason,
            'before_cas_verified'=>false,
            'atomic_mutation_applied'=>false,
            'original_305_job_issued'=>false,
            'original_313_signed'=>false,
            'automatic_retry_authorized'=>false
        ];
    }
    private static function eligible_actor():bool {
        return PHP_SAPI==='cli' &&defined('WP_CLI')&&WP_CLI
            &&current_user_can('manage_options')
            &&class_exists('MAD4B_Company_Exact_Resource_Evidence_V1',false);
    }
    private static function identity():array {
        if(!class_exists('MAD4B_SCP_Site_Profile',false)
           ||!is_callable(['MAD4B_SCP_Site_Profile','profile'])
           ||!is_callable(['MAD4B_SCP_Site_Profile','status']))
            return [];
        $p=MAD4B_SCP_Site_Profile::profile();
        $s=MAD4B_SCP_Site_Profile::status();
        if(!is_array($p)||!is_array($s)
           ||($s['configured']??false)!==true
           ||($s['origin_match']??false)!==true
           ||($s['environment_match']??false)!==true
           ||($s['deployment_binding_match']??false)!==true
           ||!wp_is_uuid($p['site_uuid']??null)
           ||($s['site_uuid']??null)!==$p['site_uuid']
           ||($p['canonical_origin']??null)!==untrailingslashit(home_url('/'))
           ||($p['environment']??null)!==wp_get_environment_type())
            return [];
        return [
            'site_uuid'=>$p['site_uuid'],
            'canonical_origin'=>$p['canonical_origin'],
            'environment'=>$p['environment']
        ];
    }
    private static function proof_valid(array $proof,string $value,int $now):bool {
        if(array_keys($proof)!==array_merge(self::FIELDS,['signature']))return false;
        foreach(self::FIELDS as $field)
            if(!is_string($proof[$field])&&!is_int($proof[$field]))return false;
        if(($proof['contract']??null)!==self::CONTRACT
            ||!preg_match('/^[a-f0-9]{32}$/D',$proof['original_job_id'])
            ||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$proof['company_code'])
            ||!in_array($proof['environment'],['production','staging'],true)
            ||!wp_is_uuid($proof['site_uuid'])
            ||!preg_match('~^https://[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$~iD',
                $proof['canonical_origin'])
            ||!preg_match('/^[a-z][a-z0-9_.-]{2,79}$/D',$proof['native_operation'])
            ||$proof['resource_type']!=='wordpress.post'
            ||!preg_match('/^[1-9][0-9]{0,9}$/D',$proof['resource_id'])
            ||!preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$proof['post_type'])
            ||!preg_match('/^[_a-zA-Z][_a-zA-Z0-9-]{0,190}$/D',$proof['meta_key'])
            ||!is_int($proof['meta_id'])||$proof['meta_id']<1
            ||!is_int($proof['issued_epoch'])
            ||!is_int($proof['expires_epoch'])
            ||$proof['issued_epoch']>$now+10
            ||$proof['issued_epoch']<$now-self::MAX_AGE
            ||$proof['expires_epoch']<=$now
            ||$proof['expires_epoch']>$proof['issued_epoch']+self::MAX_AGE
            ||!preg_match('/^[a-f0-9]{32}$/D',$proof['nonce'])
            ||$value===''||strlen($value)>4096)
            return false;
        foreach(['source_plan_sha256','expected_before_sha256',
                 'expected_manifest_sha256','expected_company_preservation_sha256',
                 'expected_meta_value_sha256','new_meta_value_sha256',
                 'expected_after_sha256'] as $field)
            if(!self::sha($proof[$field]))return false;
        if(hash_equals($proof['expected_before_sha256'],$proof['expected_after_sha256'])
           ||!hash_equals($proof['new_meta_value_sha256'],hash('sha256',$value)))
            return false;
        $pub=base64_decode(self::ORIGINAL_305_PUBLIC_B64,true);
        $signature=base64_decode((string)$proof['signature'],true);
        if(!function_exists('sodium_crypto_sign_verify_detached')
           ||!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
           ||!is_string($signature)||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES)
            return false;
        $raw=implode("\n",array_map(
            static fn($field)=>(string)$proof[$field],self::FIELDS));
        return sodium_crypto_sign_verify_detached($signature,$raw,$pub);
    }
    /**
     * Independent target-site first-party scope policy. No WordPress option,
     * arbitrary plugin callback or remotely supplied provider name can grant
     * mutation rights. This is a root-owned release file, reconciled from
     * Central source with each tenant; new capabilities are catalog-driven.
     */
    private static function source_scope_valid(array $proof):bool {
        $path=__DIR__.'/mad4b-company-executor-time-cas-policy-v1.json';
        if(is_link($path)||!is_file($path)
            ||fileowner($path)!==0
            ||(fileperms($path)&0022)!==0
            ||filesize($path)<80||filesize($path)>16384)
            return false;
        $contents=file_get_contents($path);
        if(!is_string($contents))return false;
        $catalog=json_decode($contents,true,16);
        if(!is_array($catalog)
            ||array_keys($catalog)!==['contract','capabilities']
            ||$catalog['contract']!==
                'mad4b.company.original-cas-provider-scope-policy.v1'
            ||!is_array($catalog['capabilities'])
            ||count($catalog['capabilities'])<1
            ||count($catalog['capabilities'])>64)return false;
        $names=[];
        foreach($catalog['capabilities'] as $row) {
            if(!is_array($row)
               ||!isset($row['operation_id'],$row['resource_type'],
                         $row['ownership_scope'],$row['commercial_pricing_allowed'],
                         $row['allowed_post_types'],$row['allowed_meta_keys'])
               ||count($row)!==6
               ||!is_string($row['operation_id'])
               ||!preg_match('/^[a-z][a-z0-9_.-]{2,79}$/D',$row['operation_id'])
               ||in_array($row['operation_id'],$names,true)
               ||$row['resource_type']!=='wordpress.post'
               ||$row['ownership_scope']!=='tenant_owned'
               ||$row['commercial_pricing_allowed']!==false
               ||!is_array($row['allowed_post_types'])
               ||!is_array($row['allowed_meta_keys'])
               ||!count($row['allowed_post_types'])
               ||!count($row['allowed_meta_keys'])
               ||count($row['allowed_post_types'])>16
               ||count($row['allowed_meta_keys'])>32)return false;
            $names[]=$row['operation_id'];
            if($row['operation_id']===$proof['native_operation'])
                return in_array($proof['post_type'],$row['allowed_post_types'],true)
                    &&in_array($proof['meta_key'],$row['allowed_meta_keys'],true)
                    &&$proof['resource_type']===$row['resource_type'];
        }
        return false;
    }
    private static function scope_matches(array $proof):bool {
        $site=self::identity();
        return $site!==[]
            &&($site['site_uuid']??null)===$proof['site_uuid']
            &&($site['canonical_origin']??null)===$proof['canonical_origin']
            &&($site['environment']??null)===$proof['environment']
            &&($proof['resource_type']??null)==='wordpress.post'
            &&isset($proof['resource_id'])
            &&isset($proof['post_type']);
    }
    private static function cache_reset(int $postId):void {
        wp_cache_delete($postId,'posts');
        wp_cache_delete($postId,'post_meta');
        wp_cache_delete($postId,'terms');
        foreach(['blogname','woocommerce_currency','rank_math_titles_options',
            'sitepress_settings','mad4b_company_brand_kit',
            'mad4b_age_collection_policy_v1',
            'mad4b_age_occupancy_policy_product_types_v1'] as $key)
            wp_cache_delete($key,'options');
        wp_cache_delete('alloptions','options');
        wp_cache_delete('notoptions','options');
    }
    private static function lock_rows(int $postId):bool {
        global $wpdb;
        $post=$wpdb->get_row($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE",$postId),
            ARRAY_A);
        if(!is_array($post)||!isset($post['ID']))return false;
        $meta=$wpdb->get_results($wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id=%d FOR UPDATE",
            $postId),ARRAY_A);
        $terms=$wpdb->get_results($wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_relationships}
             WHERE object_id=%d FOR UPDATE",$postId),ARRAY_A);
        if(!is_array($meta)||!is_array($terms)
            ||count($meta)>450||count($terms)>200)return false;
        // Lock every company configuration source used by the existing
        // Evidence V1 digest, including missing-option index gaps.
        $names=['blogname','woocommerce_currency','rank_math_titles_options',
            'sitepress_settings','mad4b_company_brand_kit',
            'mad4b_age_collection_policy_v1',
            'mad4b_age_occupancy_policy_product_types_v1'];
        foreach($names as $key) {
            $value=$wpdb->get_results($wpdb->prepare(
                "SELECT option_id FROM {$wpdb->options}
                 WHERE option_name=%s FOR UPDATE",$key),ARRAY_A);
            if(!is_array($value)||count($value)>1)return false;
        }
        return true;
    }
    private static function snapshot(int $id):array {
        self::cache_reset($id);
        return MAD4B_Company_Exact_Resource_Evidence_V1::inspect(
            'wordpress.post',(string)$id);
    }
    public static function inspect_only(
        string $resourceId,string $expectedBeforeSha,
        string $expectedManifestSha,string $expectedPreservationSha
    ):array {
        if(!self::eligible_actor() ||!preg_match('/^[1-9][0-9]{0,9}$/D',$resourceId)
           ||!self::sha($expectedBeforeSha)
           ||!self::sha($expectedManifestSha)
           ||!self::sha($expectedPreservationSha))
            return self::hold('READ_ONLY_SCOPE_REQUIRED');
        // Dry inspection cannot prove an executor-time CAS (no transaction).
        $before=self::snapshot((int)$resourceId);
        if(($before['state']??null)!=='EXACT_RESOURCE_READ_ONLY_OBSERVED')
            return self::hold('RESOURCE_READBACK_UNAVAILABLE');
        $matched=hash_equals($before['before_sha256'],$expectedBeforeSha)
            &&hash_equals($before['resource_manifest_sha256'],$expectedManifestSha)
            &&hash_equals($before['company_preservation_sha256'],$expectedPreservationSha);
        return [
            'contract'=>self::STATUS_CONTRACT,
            'state'=>$matched?'PRECONDITION_MATCH_READ_ONLY_UNSIGNED':
                'PRECONDITION_DRIFT_READ_ONLY_HOLD',
            'resource_key'=>'wordpress.post:'.$resourceId,
            'site_uuid'=>$before['site_uuid'],
            'canonical_origin'=>$before['canonical_origin'],
            'environment'=>$before['environment'],
            'expected_before_sha256'=>$expectedBeforeSha,
            'current_before_sha256'=>$before['before_sha256'],
            'before_cas_verified'=>false,
            'atomic_mutation_applied'=>false,
            'original_305_job_issued'=>false,
            'original_313_signed'=>false,
            'automatic_retry_authorized'=>false
        ];
    }
    /**
     * Only an Original 305/306/312 host worker may supply a pre-verified
     * broker-signed proof. Signature is verified AGAIN on the target.
     *
     * Execution is a ONE-USE SQL transaction: target identity, locked source
     * snapshot, exact signed metadata row, expected after-image and a replay
     * reservation all commit or all roll back. No REST or public write hook.
     */
    public static function apply_signed(array $proof,string $newValue):array {
        if(!self::eligible_actor())return self::hold('ORIGINAL_CLI_ADMIN_REQUIRED');
        $now=time();
        if(!self::proof_valid($proof,$newValue,$now))
            return self::hold('SIGNED_ORIGINAL_JOB_CAS_WITNESS_INVALID');
        if(!self::scope_matches($proof))
            return self::hold('ORIGINAL_COMPANY_TARGET_IDENTITY_MISMATCH');
        if(!self::source_scope_valid($proof))
            return self::hold('FIRST_PARTY_TARGET_PROVIDER_SCOPE_NOT_ADMITTED');
        $id=(int)$proof['resource_id'];
        if($id<1||$id>2147483647)return self::hold('RESOURCE_ID_OVERFLOW');
        $object=get_post_type_object($proof['post_type']);
        if(!$object||!($object->public??false)
            ||in_array($proof['post_type'],[
                'attachment','shop_order','shop_order_refund','shop_coupon'],true))
            return self::hold('RESOURCE_NOT_PUBLIC_CONTENT_SCOPE');
        global $wpdb;
        $started=$wpdb->query('START TRANSACTION');
        if($started===false)return self::hold('CAS_TRANSACTION_NOT_AVAILABLE');
        $committed=false;
        $result=self::hold('EXECUTION_TRANSACTION_ABORTED');
        try{
            if(!self::lock_rows($id)) {
                $result=self::hold('CAS_RESOURCE_ROW_LOCK_FAILED');
            }else{
                $post=$wpdb->get_row($wpdb->prepare(
                    "SELECT ID,post_type FROM {$wpdb->posts}
                     WHERE ID=%d FOR UPDATE",$id),ARRAY_A);
                if(!is_array($post)
                    ||$post['post_type']!==$proof['post_type']){
                    $result=self::hold('CAS_POST_TYPE_CHANGED');
                }else{
                    $old=self::snapshot($id);
                    $matches=($old['state']??null)==='EXACT_RESOURCE_READ_ONLY_OBSERVED'
                      &&($old['resource_key']??null)==='wordpress.post:'.$id
                      &&hash_equals($proof['expected_before_sha256'],
                           $old['before_sha256'])
                      &&hash_equals($proof['expected_manifest_sha256'],
                           $old['resource_manifest_sha256'])
                      &&hash_equals($proof['expected_company_preservation_sha256'],
                           $old['company_preservation_sha256']);
                    if(!$matches){
                        $result=self::hold('CAS_EXECUTOR_TIME_BEFORE_IMAGE_CHANGED');
                    }else{
                        $rows=$wpdb->get_results($wpdb->prepare(
                            "SELECT meta_id,meta_value FROM {$wpdb->postmeta}
                             WHERE post_id=%d AND meta_key=%s
                             ORDER BY meta_id FOR UPDATE",
                            $id,$proof['meta_key']),ARRAY_A);
                        if(!is_array($rows)||count($rows)!==1
                           ||(int)$rows[0]['meta_id']!==$proof['meta_id']
                           ||!hash_equals($proof['expected_meta_value_sha256'],
                               hash('sha256',(string)$rows[0]['meta_value']))){
                            $result=self::hold('CAS_META_ROW_CHANGED_OR_NOT_UNIQUE');
                        }else{
                            $once='mad4b_executor_cas_once_'.$proof['original_job_id'];
                            $reservation=$wpdb->query($wpdb->prepare(
                                "INSERT INTO {$wpdb->options}
                                 (option_name,option_value,autoload)
                                 VALUES (%s,%s,%s)",
                                $once,$proof['new_meta_value_sha256'],'no'));
                            if($reservation!==1){
                                $result=self::hold('CAS_JOB_ALREADY_CONSUMED_OR_UNCERTAIN');
                            }else{
                                $n=$wpdb->query($wpdb->prepare(
                                    "UPDATE {$wpdb->postmeta}
                                     SET meta_value=%s
                                     WHERE meta_id=%d AND post_id=%d AND meta_key=%s
                                       AND BINARY meta_value=BINARY %s",
                                    $newValue,$proof['meta_id'],$id,
                                    $proof['meta_key'],(string)$rows[0]['meta_value']));
                                if($n!==1){
                                    $result=self::hold('CAS_ATOMIC_META_COMPARE_FAILED');
                                }else{
                                    $after=self::snapshot($id);
                                    $pass=($after['state']??null)==='EXACT_RESOURCE_READ_ONLY_OBSERVED'
                                      &&hash_equals($proof['expected_after_sha256'],
                                           $after['before_sha256'])
                                      &&hash_equals($old['company_preservation_sha256'],
                                           $after['company_preservation_sha256']);
                                    if(!$pass){
                                        $result=self::hold('CAS_POSTIMAGE_OR_COMPANY_PRESERVATION_MISMATCH');
                                    }elseif($wpdb->query('COMMIT')===false){
                                        $result=self::hold('CAS_COMMIT_UNCERTAIN_DO_NOT_RETRY');
                                    }else{
                                        $committed=true;
                                        $result=[
                                            'contract'=>self::STATUS_CONTRACT,
                                            'state'=>'ATOMIC_CAS_APPLIED_PENDING_INDEPENDENT_313',
                                            'original_job_id'=>$proof['original_job_id'],
                                            'resource_key'=>'wordpress.post:'.$id,
                                            'site_uuid'=>$proof['site_uuid'],
                                            'canonical_origin'=>$proof['canonical_origin'],
                                            'environment'=>$proof['environment'],
                                            'operation'=>$proof['native_operation'],
                                            'before_sha256'=>$old['before_sha256'],
                                            'after_sha256'=>$after['before_sha256'],
                                            'company_preservation_sha256'=>$after['company_preservation_sha256'],
                                            'before_cas_verified'=>true,
                                            'atomic_mutation_applied'=>true,
                                            'original_313_signed'=>false,
                                            'automatic_retry_authorized'=>false,
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }catch(Throwable $exception){
            $result=self::hold('CAS_TRANSACTION_FAILED_CLOSED');
        }finally{
            if(!$committed)$wpdb->query('ROLLBACK');
            self::cache_reset($id);
        }
        return $result;
    }
    public static function cli(array $args,array $assoc):void {
        if(count($args)!==1||($args[0]??'')!=='inspect'
           ||array_diff(array_keys($assoc),[
               'resource-id','before-sha','manifest-sha','preservation-sha'
           ]))
            WP_CLI::error('READ_ONLY_CAS_INSPECT_ONLY');
        $row=self::inspect_only(
            (string)($assoc['resource-id']??''),
            (string)($assoc['before-sha']??''),
            (string)($assoc['manifest-sha']??''),
            (string)($assoc['preservation-sha']??'')
        );
        WP_CLI::line(wp_json_encode($row,JSON_UNESCAPED_SLASHES));
    }
    public static function boot():void {
        // An apply route is intentionally NOT exposed. The verified Original
        // executor invokes apply_signed after its own 305/306/312 preflight.
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b executor-cas',[__CLASS__,'cli']);
    }
}
MAD4B_Company_Executor_Time_CAS_V1::boot();
}
