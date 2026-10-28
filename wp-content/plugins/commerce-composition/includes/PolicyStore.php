<?php
declare(strict_types=1);
namespace Commerce\Composition;

final class PolicyStore
{
    private const META_KEY='_commerce_composition_policy';
    public static function get(int $parentId,?int $rateId=null): array|\WP_Error
    {
        return Integration::policyFor($parentId,$rateId);
    }
    public static function validate(int $parentId,?int $rateId,array $policy): true|\WP_Error
    {
        // Audit is generated only by the server and never accepted from a submitted editor form.
        if(array_key_exists('audit_trail',$policy))
            return new \WP_Error('policy_audit_not_client_writable');
        if(($policy['schema']??null)!==1 || !is_array($policy['parent']??null)
            || !is_array($policy['groups']??null) || !array_is_list($policy['groups'])
            || count($policy['groups'])>100)
            return new \WP_Error('policy_schema_invalid');
        if(($policy['parent']['source_id']??null)!==$parentId ||
           ($rateId!==null&&($policy['parent']['rate_id']??null)!==$rateId))
            return new \WP_Error('policy_source_mismatch');
        $revision=$policy['revision']??null;
        if(!is_int($revision)||$revision<1||$revision>PHP_INT_MAX-1)
            return new \WP_Error('policy_revision_invalid');
        $provider=static function($saved,$id,$rate)use($policy,$parentId,$rateId){
            return $id===$parentId&&$rate===$rateId?$policy:$saved;
        };
        add_filter('commerce_composition_resolved_policy',$provider,PHP_INT_MAX,3);
        try{
            $result=Integration::formDescriptor($parentId,$rateId);
        }finally{
            remove_filter('commerce_composition_resolved_policy',$provider,PHP_INT_MAX);
        }
        if(is_wp_error($result))return $result;
        return true;
    }
    public static function save(int $parentId,?int $rateId,array $policy,int $expectedRevision): array|\WP_Error
    {
        if($parentId<1||$expectedRevision<0||($rateId!==null&&$rateId<1))
            return new \WP_Error('policy_target_invalid');
        $target=$rateId??$parentId;
        if(!current_user_can('edit_post',$target)||
            !current_user_can('edit_post',$parentId))
            return new \WP_Error('policy_permission_denied');
        $prior=get_post_meta($target,self::META_KEY,true);
        if($prior!==''&&!is_array($prior))
            return new \WP_Error('policy_existing_corrupt');
        $oldRevision=is_array($prior)?(int)($prior['revision']??0):0;
        if($oldRevision!==$expectedRevision)
            return new \WP_Error('policy_revision_conflict');
        if(($policy['revision']??null)!==$oldRevision+1)
            return new \WP_Error('policy_revision_not_next');
        $valid=self::validate($parentId,$rateId,$policy);
        if(is_wp_error($valid))return $valid;

        $audit=is_array($prior) && is_array($prior['audit_trail']??null)
            ? $prior['audit_trail']:[];
        if(count($audit)>100)return new \WP_Error('policy_audit_over_limit');
        $actor=get_current_user_id();
        $audit[]=[
            'revision'=>$oldRevision+1,
            'actor_id'=>$actor,
            'timestamp_utc'=>gmdate('Y-m-d\\TH:i:s\\Z'),
            'policy_sha256'=>hash('sha256',wp_json_encode($policy,JSON_UNESCAPED_SLASHES)),
        ];
        // A CAS update of one post_meta value stores policy + provenance together,
        // never a separate audit record that could be lost in a partial failure.
        $stored=$policy;
        $stored['audit_trail']=$audit;
        if($prior==='') {
            if(metadata_exists('post',$target,self::META_KEY))
                return new \WP_Error('policy_meta_ambiguous');
            $done=add_post_meta($target,self::META_KEY,$stored,true);
        }else{
            $done=update_post_meta($target,self::META_KEY,$stored,$prior);
        }
        if(!$done)return new \WP_Error('policy_cas_failed');
        wp_cache_delete($target,'post_meta');
        $read=get_post_meta($target,self::META_KEY,true);
        if($read!==$stored)return new \WP_Error('policy_readback_mismatch');
        do_action('commerce_composition_policy_saved',$target,$parentId,$rateId,$oldRevision+1);
        return ['schema'=>1,'post_id'=>$target,'parent_id'=>$parentId,
            'rate_id'=>$rateId,'revision'=>$oldRevision+1,
            'audit_entries'=>count($audit),'readback_verified'=>true];
    }
}
