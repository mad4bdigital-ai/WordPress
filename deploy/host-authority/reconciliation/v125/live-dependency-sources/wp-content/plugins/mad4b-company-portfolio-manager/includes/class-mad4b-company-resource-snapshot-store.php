<?php
/**
 * Short-lived, unsigned snapshots imported ONLY via privileged central WP-CLI
 * from a separate target administrator readback. Never signs, writes tenant
 * data, grants Original authority or accepts HTTP POST evidence.
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Company_Resource_Snapshot_Store_V1',false)){
final class MAD4B_Company_Resource_Snapshot_Store_V1 {
    public const CONTRACT='mad4b.company-repair.imported-target-resource.v1';
    public const KEY_PREFIX='mad4b_company_resource_snapshot_v1_';
    public const MAX_AGE=300;
    public const MAX_BYTES=8192;
    private static function validSha($v):bool{
        return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;
    }
    private static function lock(string $key):?string {
        global $wpdb;
        if(!isset($wpdb)||!is_callable([$wpdb,'prepare'])
            ||!is_callable([$wpdb,'get_var']))return null;
        $name='mad4b_resource_snapshot_'.substr(hash('sha256',$key),0,32);
        $yes=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)',$name));
        return (int)$yes===1?$name:null;
    }
    private static function unlock(?string $name):void {
        if(!$name)return;
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));
    }
    private static function denied(string $reason):WP_Error{
        return new WP_Error('mad4b_resource_snapshot_'.$reason,
          'Exact resource snapshot not admitted: '.$reason,['status'=>409]);
    }
    public static function key(array $scope,string $type,string $id):string{
        $fields=['company_code','environment','site_uuid','canonical_origin',
          'incident_code','source_plan_sha256'];
        $a=['mad4b.company-repair.resource-snapshot.v1'];
        foreach($fields as $field){
            if(!is_string($scope[$field]??null))return '';
            $a[]=$scope[$field];
        }
        $a[]=$type;$a[]=$id;
        return self::KEY_PREFIX.hash('sha256',implode("\n",$a));
    }
    private static function safeSource(array $r,int $now):bool{
        $expected=['contract','state','observation_level','site_uuid',
            'canonical_origin','environment','resource_type','resource_id',
            'resource_key','ownership_scope','before_sha256',
            'resource_manifest_sha256','company_preservation_sha256',
            'observed_epoch','source_of_evidence','raw_sensitive_metadata_emitted',
            'resource_sample_counts','original_313_signed','original_312_signed',
            'mutation_performed','native_job_created'];
        if(array_keys($r)!==$expected)return false;
        if(($r['contract']??null)!==
             MAD4B_Company_Repair_Resource_Resolver_V2::OBSERVATION
            ||($r['state']??null)!=='EXACT_RESOURCE_READ_ONLY_OBSERVED'
            ||($r['observation_level']??null)!=='TARGET_WORDPRESS_AUTHENTICATED_READ_ONLY'
            ||($r['source_of_evidence']??null)!=='LOCAL_ADMINISTRATOR_WORDPRESS_READ_ONLY'
            ||($r['raw_sensitive_metadata_emitted']??true)!==false
            ||($r['original_313_signed']??true)!==false
            ||($r['original_312_signed']??true)!==false
            ||($r['mutation_performed']??true)!==false
            ||($r['native_job_created']??true)!==false
            ||!is_int($r['observed_epoch']??null)
            ||$now-$r['observed_epoch']>self::MAX_AGE
            ||$r['observed_epoch']>$now+30
            ||!is_array($r['resource_sample_counts']??null)
            ||count($r['resource_sample_counts'])>4
            ||!MAD4B_Company_Repair_Resource_Resolver_V2::selector_valid([
              'resource_type'=>$r['resource_type']??null,
              'resource_id'=>$r['resource_id']??null]))
            return false;
        foreach(['before_sha256','resource_manifest_sha256',
                'company_preservation_sha256'] as $field)
            if(!self::validSha($r[$field]??null))return false;
        if(($r['resource_key']??null)!==
            $r['resource_type'].':'.$r['resource_id'])return false;
        if(!in_array($r['ownership_scope']??null,[
            'shared_definition','tenant_owned','mixed','site_generated'],true))
            return false;
        foreach($r['resource_sample_counts'] as $key=>$n)
            if(!in_array($key,['metadata_key_count','taxonomy_count','entrypoint_count'],true)
                ||!is_int($n)||$n<0||$n>1000)return false;
        return true;
    }
    public static function ingest(array $plan,array $source):array|WP_Error{
        if(!defined('WP_CLI')||!WP_CLI||PHP_SAPI!=='cli'
            ||!current_user_can('manage_options')
            ||!MAD4B_Company_Reconcile_Repair_Jobs_V1::central())
            return self::denied('central_cli_owner_required');
        $now=time();
        if(!self::safeSource($source,$now))
            return self::denied('source_snapshot_schema_or_freshness_invalid');
        if(($source['site_uuid']??null)!==($plan['site_uuid']??null)
            ||($source['canonical_origin']??null)!==($plan['canonical_origin']??null)
            ||($source['environment']??null)!==($plan['environment']??null))
            return self::denied('source_site_uuid_environment_origin_mismatch');
        $type=$source['resource_type'];$id=$source['resource_id'];
        $scope=[
            'company_code'=>$plan['company_code'],
            'environment'=>$plan['environment'],
            'site_uuid'=>$plan['site_uuid'],
            'canonical_origin'=>$plan['canonical_origin'],
            'incident_code'=>$plan['incident_code'],
            'source_plan_sha256'=>$plan['source_plan_sha256']
        ];
        $key=self::key($scope,$type,$id);
        if($key==='')return self::denied('source_key_unbound');
        // A later but stale collector result cannot race a fresh import.
        // MySQL advisory lock is the same concurrency lane as repair journals.
        $lock=self::lock($key);
        if(!$lock)return self::denied('snapshot_observation_lease_busy');
        try{
        $data=[
            'contract'=>self::CONTRACT,
            'scope'=>$scope,
            'target_source'=>$source,
            'imported_at_epoch'=>$now,
            'import_level'=>'CENTRAL_OPERATOR_UPLOADED_UNSIGNED_TARGET_WPCLI',
            'independent_original_313'=>false,
            'target_mutated'=>false,
        ];
        $hash=hash('sha256',wp_json_encode($data,
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $data['record_sha256']=$hash;
        // Do not replace a saved observation of different bytes silently.
        $current=get_option($key,false);
        if($current!==false){
            if(!is_array($current)
                ||($current['contract']??null)!==self::CONTRACT
                ||!self::validSha($current['record_sha256']??null))
                return self::denied('existing_snapshot_integrity_invalid');
            $prev=$current;$previousHash=$prev['record_sha256'];
            unset($prev['record_sha256']);
            if(hash('sha256',wp_json_encode($prev,
                JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))!==$previousHash)
                return self::denied('stored_snapshot_modified');
            if(($current['target_source']['before_sha256']??'')!==$source['before_sha256']
               ||($current['target_source']['resource_manifest_sha256']??'')!==
                     $source['resource_manifest_sha256']
               ||($current['target_source']['company_preservation_sha256']??'')!==
                     $source['company_preservation_sha256'])
                return self::denied('previous_source_snapshot_drift_requires_new_plan');
            // Timestamp can be refreshed with identical source bytes; never
            // change the immutable resource identity or semantic content.
            $old=$current['target_source']['observed_epoch']??0;
            if($source['observed_epoch']<$old)
                return self::denied('older_snapshot_must_not_replace_newer');
            $ok=update_option($key,$data,false);
        }else $ok=add_option($key,$data,'',false);
        $stored=self::get($scope,$type,$id);
        if(is_wp_error($stored)
           ||$stored['before_sha256']!==$source['before_sha256'])
            return self::denied('write_readback_not_confirmed');
        return [
            'state'=>'UNSIGNED_TARGET_SNAPSHOT_STORED',
            'site_uuid'=>$scope['site_uuid'],
            'resource_key'=>$type.':'.$id,
            'record_sha256'=>$hash,
            'source_plan_sha256'=>$scope['source_plan_sha256'],
            'semantic_sha256'=>$source['before_sha256'],
            'independent_original_313'=>false,
            'native_job_submitted'=>false,'target_mutated'=>false
        ];
        }finally{self::unlock($lock);}
    }
    public static function get(array $scope,string $type,string $id):array|WP_Error {
        $key=self::key($scope,$type,$id);
        if(!$key)return self::denied('scope_not_bound');
        $row=get_option($key,false);
        if(!is_array($row)||($row['contract']??null)!==self::CONTRACT)
            return self::denied('snapshot_not_collected');
        $recordHash=$row['record_sha256']??null;
        if(!self::validSha($recordHash))return self::denied('snapshot_digest_missing');
        $without=$row;unset($without['record_sha256']);
        if(!hash_equals($recordHash,hash('sha256',wp_json_encode($without,
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))))
            return self::denied('snapshot_data_integrity_mismatch');
        if(($row['scope']??null)!==$scope
            ||($row['import_level']??null)!==
                'CENTRAL_OPERATOR_UPLOADED_UNSIGNED_TARGET_WPCLI'
            ||($row['independent_original_313']??true)!==false
            ||($row['target_mutated']??true)!==false)
            return self::denied('snapshot_scope_or_authority_spoofed');
        $source=$row['target_source'];
        if(!is_array($source)||!self::safeSource($source,time()))
            return self::denied('snapshot_stale_or_target_claim_invalid');
        if($source['site_uuid']!==$scope['site_uuid']
            ||$source['canonical_origin']!==$scope['canonical_origin']
            ||$source['environment']!==$scope['environment']
            ||$source['resource_type']!==$type||$source['resource_id']!==$id)
            return self::denied('snapshot_target_resource_changed');
        return $source;
    }
}
}
