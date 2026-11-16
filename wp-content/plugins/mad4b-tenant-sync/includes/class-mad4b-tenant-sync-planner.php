<?php
/**
 * Deterministic three-way field inheritance planner.
 * No DB writes, no cross-site WP ID import and no financial sync.
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/class-mad4b-tenant-field-registry.php';
final class MAD4B_Tenant_Sync_Planner {
    const CONTRACT='mad4b.tenant-content-sync-plan.v2';
    private static function result($status,$reason,$operations=[],$conflicts=[],$preserved=[],$decisions=[],$shadowed=[]) {
        return [
            'contract'=>self::CONTRACT,'status'=>$status,'reason'=>$reason,
            'operations'=>$operations,'conflicts'=>$conflicts,
            'preserved_local_fields'=>array_values(array_unique($preserved)),
            'field_decisions'=>$decisions,'source_changes_shadowed_locally'=>$shadowed,
            'source_writes'=>0,'tenant_writes'=>0,
            'price_mutation_authorized'=>false,'checkout_authorized'=>false,
            'mutation_performed'=>false,
        ];
    }
    public static function plan($source,$mapping,$tenant_site_uuid) {
        if(!is_array($source)||!preg_match('/^[a-f0-9-]{36}$/D',(string)$tenant_site_uuid))
            return self::result('blocked','invalid_sync_identity');
        $global=(string)($source['global_content_id']??'');
        $version=(string)($source['source_version']??'');
        $fields=$source['fields']??null;
        $post_type=(string)($source['post_type']??'');
        $defs=MAD4B_Tenant_Field_Registry::definitions($post_type);
        if(!preg_match('/^content-[a-f0-9]{32}$/D',$global)
            ||!preg_match('/^[a-f0-9]{64}$/D',$version)
            ||!is_array($fields)||count($fields)>150
            ||array_diff(array_keys($fields),array_keys($defs)))
            return self::result('blocked','unverified_content_contract');
        foreach($fields as $field=>$value)
            if(!MAD4B_Tenant_Field_Registry::valid_value($field,$value,$tenant_site_uuid,'source',$defs))
                return self::result('blocked','invalid_source_field_value');
        if(($source['share_state']??'')!=='approved'
           ||!is_array($source['allowed_tenants']??null)
           ||!in_array($tenant_site_uuid,$source['allowed_tenants'],true))
            return self::result('blocked','content_not_approved_for_tenant');
        $mapping=is_array($mapping)?$mapping:[];
        $mode=(string)($mapping['mode']??'import');
        if(!in_array($mode,['reference','import','fork','override'],true))
            return self::result('blocked','unsupported_inheritance_mode');
        if(isset($mapping['global_content_id'])&&$mapping['global_content_id']!==$global)
            return self::result('blocked','local_mapping_points_to_different_global_object');
        if(isset($mapping['site_uuid'])&&$mapping['site_uuid']!==$tenant_site_uuid)
            return self::result('blocked','tenant_mapping_site_mismatch');
        $lang=(string)($source['language']??'');
        if($lang!=='' && isset($mapping['language']) && $mapping['language']!==$lang)
            return self::result('blocked','translation_language_mismatch');
        if($mode==='fork')return self::result('detached','fork_preserves_all_local_content');
        if($mode==='reference')return self::result('reference_only','remote_reference_no_local_write');
        $policy=$mapping['field_modes']??[];
        $overrides=$mapping['override_fields']??[];
        if(!is_array($policy)||!is_array($overrides)
            ||array_diff(array_keys($policy),array_keys($defs))
            ||array_diff($overrides,array_keys($defs)))
            return self::result('blocked','unknown_local_override_field');
        foreach($policy as $key=>$value)
            if(!in_array($value,MAD4B_Tenant_Field_Registry::POLICIES,true))
                return self::result('blocked','invalid_field_override_mode');
        foreach($overrides as $key)
            $policy[$key]='local_only'; // Legacy explicit local ownership.
        if(empty($mapping)){
            $create=[];
            foreach($fields as $key=>$value) {
                $field_mode=$policy[$key]??$defs[$key]['default'];
                if($field_mode!=='local_only')$create[$key]=$value;
            }
            return self::result('ready_plan','create_import',[[
                'action'=>'create_local_draft','global_content_id'=>$global,
                'source_version'=>$version,'fields'=>$create,
            ]]);
        }
        $baseline=$mapping['baseline_fields']??null;
        $local=$mapping['local_fields']??null;
        if(!is_array($baseline)||!is_array($local)
            ||array_diff(array_keys($baseline),array_keys($defs))
            ||array_diff(array_keys($local),array_keys($defs)))
            return self::result('blocked','invalid_three_way_mapping');
        foreach($baseline as $field=>$value)
            if(!MAD4B_Tenant_Field_Registry::valid_value($field,$value,$tenant_site_uuid,'source',$defs))
                return self::result('blocked','invalid_baseline_field_value');
        foreach($local as $field=>$value)
            if(!MAD4B_Tenant_Field_Registry::valid_value($field,$value,$tenant_site_uuid,'tenant',$defs))
                return self::result('blocked','invalid_tenant_field_value');
        $operations=[];$conflicts=[];$preserved=[];$decisions=[];$shadowed=[];
        foreach($fields as $field=>$incoming) {
            $m=$policy[$field]??$defs[$field]['default'];
            $hasOld=array_key_exists($field,$baseline);
            $hasLocal=array_key_exists($field,$local);
            $old=$hasOld?$baseline[$field]:null;
            $current=$hasLocal?$local[$field]:null;
            if($m==='local_only') {
                $preserved[]=$field;
                $decisions[$field]='tenant_owned';
                continue;
            }
            if(!$hasOld && $hasLocal) {
                $conflicts[]=['field'=>$field,'reason'=>'baseline_missing_for_existing_tenant_field'];
                $decisions[$field]='review_untracked_tenant_field';
                continue;
            }
            if(!$hasLocal) {
                // Tenant-side field value must be read from its current storage
                // before a sync planner may recommend updating it.
                $conflicts[]=['field'=>$field,'reason'=>'tenant_field_readback_missing'];
                $decisions[$field]='review_unread_local_field';
                continue;
            }
            $sourceChanged=!$hasOld||$old!==$incoming;
            $localChanged=$current!==$old;
            if($localChanged) {
                $preserved[]=$field;
                if($sourceChanged && $incoming!==$current && $m==='review') {
                    $conflicts[]=['field'=>$field,'reason'=>'both_source_and_tenant_changed'];
                    $decisions[$field]='manual_conflict';
                } else {
                    $decisions[$field]='local_edit_preserved';
                    if($sourceChanged && $incoming!==$current)
                        $shadowed[]=['field'=>$field,'source_version'=>$version,
                            'reason'=>'tenant_local_value_has_priority'];
                }
                continue;
            }
            if($incoming!==$current) {
                $operations[]=[
                    'action'=>'update_inherited_field','field'=>$field,
                    'value'=>$incoming,'source_version'=>$version,
                    'requires_media_resolution'=>in_array($defs[$field]['type'],
                        ['media_ref','media_collection'],true),
                    'locale'=>$defs[$field]['per_language']?$lang:null,
                ];
                $decisions[$field]='inherit_source_update';
            } else $decisions[$field]='unchanged';
        }
        if($conflicts)return self::result('requires_review','three_way_conflict',
            $operations,$conflicts,$preserved,$decisions,$shadowed);
        return self::result('ready_plan',count($operations)?'inherited_fields_changed':'no_changes',
            $operations,[],$preserved,$decisions,$shadowed);
    }
}
