/**
 * Original CAS exact WordPress postimage predictor (read-only).
 * Invoked using an authenticated wp eval source file from an authorized VPS.
 * No original Job, grant, price, or database mutation.
 *
 * Input: MAD4B_CAS_FORECAST_POST_ID, MAD4B_CAS_FORECAST_META_KEY,
 * MAD4B_CAS_FORECAST_VALUE_B64.
 */
$casHold = static function(string $why):void {
    echo wp_json_encode([
        'contract'=>'mad4b.original-cas-exact-postimage-forecast.v1',
        'state'=>'READ_ONLY_FORECAST_HOLD',
        'reason'=>$why,
        'mutation_performed'=>false,
        'original_313_signed'=>false
    ],JSON_UNESCAPED_SLASHES),"\n";
    return;
};
$resource=(string)getenv('MAD4B_CAS_FORECAST_POST_ID');
$key=(string)getenv('MAD4B_CAS_FORECAST_META_KEY');
$newEncoded=(string)getenv('MAD4B_CAS_FORECAST_VALUE_B64');
$newValue=base64_decode($newEncoded,true);
if(!defined('WP_CLI')||!WP_CLI||!current_user_can('manage_options')
    ||!class_exists('MAD4B_Company_Exact_Resource_Evidence_V1',false)
    ||!preg_match('/^[1-9][0-9]{0,9}$/D',$resource)
    ||!preg_match('/^[_a-zA-Z][_a-zA-Z0-9-]{0,190}$/D',$key)
    ||!is_string($newValue)||strlen($newValue)>4096||$newValue==='') {
    $casHold('PREVIEW_EXACT_CLI_ADMIN_INPUT_REQUIRED');
} else {
    $id=(int)$resource;
    $post=get_post($id);
    $obj=$post instanceof WP_Post?get_post_type_object($post->post_type):null;
    $policy=WPMU_PLUGIN_DIR.'/mad4b-company-executor-time-cas-policy-v1.json';
    $policyData=is_file($policy)&&!is_link($policy)
       &&fileowner($policy)===0 &&(fileperms($policy)&0022)===0
       ?json_decode((string)file_get_contents($policy),true):null;
    $validScope=false;
    if(is_array($policyData)
        &&($policyData['contract']??'')==='mad4b.company.original-cas-provider-scope-policy.v1') {
        foreach(($policyData['capabilities']??[]) as $cap) {
            if(($cap['operation_id']??null)==='company.resource.cas.apply'
               &&($cap['resource_type']??null)==='wordpress.post'
               &&($cap['ownership_scope']??null)==='tenant_owned'
               &&($cap['commercial_pricing_allowed']??true)===false
               &&in_array($post->post_type??null,$cap['allowed_post_types']??[],true)
               &&in_array($key,$cap['allowed_meta_keys']??[],true)){
                $validScope=true;
                break;
            }
        }
    }
    if(!$obj||!($obj->public??false)||!$validScope) {
        $casHold('SOURCE_OWNED_NONCOMMERCIAL_RESOURCE_SCOPE_DENIED');
    }else {
        global $wpdb;
        $sql=$wpdb->prepare(
            "SELECT meta_id,meta_value FROM {$wpdb->postmeta}
             WHERE post_id=%d AND meta_key=%s ORDER BY meta_id",
            $id,$key);
        $metaRows=$wpdb->get_results($sql,ARRAY_A);
        $raw=get_post_meta($id);
        $before=MAD4B_Company_Exact_Resource_Evidence_V1::inspect(
            'wordpress.post',$resource);
        if(!is_array($metaRows)||count($metaRows)!==1
          ||!is_array($raw)||!isset($raw[$key])
          ||!is_array($raw[$key])||count($raw[$key])!==1
          ||(string)$raw[$key][0] !==(string)$metaRows[0]['meta_value']
          ||($before['state']??null)!=='EXACT_RESOURCE_READ_ONLY_OBSERVED'){
            $casHold('META_ROW_OR_WP_EVIDENCE_NOT_EXACT');
        }else{
            $hash=static function($value):string {
                $json=wp_json_encode($value,
                    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                if(!is_string($json))
                    throw new UnexpectedValueException('JSON_HASH_UNAVAILABLE');
                return hash('sha256',$json);
            };
            $postFields=[
                'id'=>(int)$post->ID,'type'=>(string)$post->post_type,
                'status'=>(string)$post->post_status,
                'slug'=>(string)$post->post_name,
                'parent'=>(int)$post->post_parent,
                'title_sha256'=>hash('sha256',(string)$post->post_title),
                'body_sha256'=>hash('sha256',(string)$post->post_content),
                'excerpt_sha256'=>hash('sha256',(string)$post->post_excerpt),
                'modified_gmt'=>(string)$post->post_modified_gmt
            ];
            $taxonomyNames=get_object_taxonomies($post->post_type,'names');
            $terms=[];
            $termError=false;
            foreach($taxonomyNames as $tax){
                if(!taxonomy_exists($tax))continue;
                $ids=wp_get_object_terms($id,$tax,['fields'=>'ids']);
                if(is_wp_error($ids)){$termError=true;break;}
                $ids=array_map('intval',$ids);sort($ids,SORT_NUMERIC);
                $terms[$tax]=$ids;
            }
            ksort($terms,SORT_STRING);
            $hashMeta=static function(array $rows)use($hash):array {
                $result=[];
                foreach($rows as $name=>$values)
                    $result[$name]=$hash($values);
                ksort($result,SORT_STRING);
                return $result;
            };
            $meta=$hashMeta($raw);
            $baseline=$hash([
                'post'=>$postFields,
                'metadata_and_terms_sha256'=>$hash([$meta,$terms])
            ]);
            $manifest=$hash([
                'post'=>$postFields,'meta_keys_sha256'=>$meta,
                'taxonomy_term_ids'=>$terms
            ]);
            if($termError||!hash_equals($baseline,(string)$before['before_sha256'])
                ||!hash_equals($manifest,(string)$before['resource_manifest_sha256'])) {
                $casHold('PREDICTOR_DOES_NOT_MATCH_CANONICAL_SOURCE_HASH');
            }else{
                $raw[$key]=[$newValue];
                $meta=$hashMeta($raw);
                $forecast=$hash([
                    'post'=>$postFields,
                    'metadata_and_terms_sha256'=>$hash([$meta,$terms])
                ]);
                if(hash_equals($forecast,$baseline)) {
                    $casHold('PREVIEW_NO_EFFECT_NO_CAS_REQUIRED');
                }else{
                    echo wp_json_encode([
                        'contract'=>'mad4b.original-cas-exact-postimage-forecast.v1',
                        'state'=>'EXACT_EXPECTED_POSTIMAGE_READ_ONLY',
                        'operation_id'=>'company.resource.cas.apply',
                        'resource_key'=>'wordpress.post:'.$resource,
                        'site_uuid'=>$before['site_uuid'],
                        'canonical_origin'=>$before['canonical_origin'],
                        'environment'=>$before['environment'],
                        'meta_id'=>(int)$metaRows[0]['meta_id'],
                        'meta_key'=>$key,
                        'old_meta_value_sha256'=>hash('sha256',
                            (string)$metaRows[0]['meta_value']),
                        'new_meta_value_sha256'=>hash('sha256',$newValue),
                        'expected_before_sha256'=>$baseline,
                        'expected_manifest_sha256'=>$manifest,
                        'expected_after_sha256'=>$forecast,
                        'expected_company_preservation_sha256'=>$before['company_preservation_sha256'],
                        'expected_value_after_stable'=>true,
                        'predicted_not_executed'=>true,
                        'source_owned_noncommercial_policy_verified'=>true,
                        'mutation_performed'=>false,
                        'original_305_job_issued'=>false,
                        'original_313_signed'=>false
                    ],JSON_UNESCAPED_SLASHES),"\n";
                }
            }
        }
    }
}
