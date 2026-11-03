<?php
declare(strict_types=1);
namespace Commerce\Composition;

/** Owner-managed map between source-owned package fields and existing Fluent Forms controls. */
final class BindingStore
{
    private const KEY='_commerce_form_field_bindings';
    public static function validate(int $parentId,?int $rateId,array $mapping):true|\WP_Error
    {
        if(array_key_exists('audit_trail',$mapping))
            return new \WP_Error('binding_audit_client_forbidden');
        if(($mapping['schema']??null)!==1||
           !is_int($mapping['revision']??null)||$mapping['revision']<1||
           !is_int($mapping['form_id']??null)||$mapping['form_id']<1||
           !is_array($mapping['groups']??null)||!array_is_list($mapping['groups'])||
           count($mapping['groups'])>100)
            return new \WP_Error('binding_schema_invalid');
        $policy=Integration::policyFor($parentId,$rateId);
        if(is_wp_error($policy))return $policy;
        $expectedGroups=[];
        foreach($policy['groups'] as $group){
            $allowed=match($group['role']??null){
                'choose_one_included','upgrade_delta'=>'select',
                'optional_attached','separately_accounted_extra'=>'checkbox',
                default=>null
            };
            if($allowed!==null)$expectedGroups[$group['key']]=$allowed;
        }
        global $wpdb;
        $table=$wpdb->prefix.'fluentform_forms';
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))
            return new \WP_Error('binding_form_table_invalid');
        $sql='SELECT status,form_fields FROM '.$table.' WHERE id=%d LIMIT 1';
        $form=$wpdb->get_row($wpdb->prepare($sql,$mapping['form_id']),ARRAY_A);
        if(!$form||$form['status']!=='published')
            return new \WP_Error('binding_form_not_published');
        $json=json_decode($form['form_fields'],true,64);
        if(!is_array($json)||json_last_error()!==JSON_ERROR_NONE)
            return new \WP_Error('binding_form_definition_invalid');
        $controls=[];$budget=0;
        $walk=function($node,$depth)use(&$walk,&$controls,&$budget){
            if(!is_array($node)||$depth>40)return;
            $budget++;
            if($budget>10000)return;
            $name=$node['attributes']['name']??null;
            $element=$node['element']??null;
            if(is_string($name)&&is_string($element))
                $controls[$name][]=$element;
            foreach($node as $child)if(is_array($child))$walk($child,$depth+1);
        };
        $walk($json,0);
        if($budget>10000)return new \WP_Error('binding_form_too_complex');
        $mappedFields=[];$mappedGroups=[];
        foreach($mapping['groups'] as $entry){
            if(!is_array($entry))return new \WP_Error('binding_entry_invalid');
            $key=$entry['key']??null;
            $name=$entry['field_name']??null;
            $control=$entry['control']??null;
            if(!is_string($key)||!isset($expectedGroups[$key])||
                !is_string($name)||!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,119}$/D',$name)||
                $control!==$expectedGroups[$key])
                return new \WP_Error('binding_role_or_key_invalid');
            $element=$control==='checkbox'?'input_checkbox':'select';
            if(($controls[$name]??null)!==[$element])
                return new \WP_Error('binding_form_field_missing_or_wrong_type');
            if(isset($mappedFields[$name])||isset($mappedGroups[$key]))
                return new \WP_Error('binding_duplicate_field_or_group');
            $mappedFields[$name]=true;$mappedGroups[$key]=true;
        }
        return true;
    }
    public static function save(int $parentId,?int $rateId,array $mapping,int $expectedRevision):array|\WP_Error
    {
        $target=$rateId??$parentId;
        if($parentId<1||$target<1||$expectedRevision<0)
            return new \WP_Error('binding_target_invalid');
        if(!current_user_can('edit_post',$target)||!current_user_can('edit_post',$parentId))
            return new \WP_Error('binding_permission_denied');
        $prev=get_post_meta($target,self::KEY,true);
        if($prev!==''&&!is_array($prev))return new \WP_Error('binding_previous_corrupt');
        $revision=is_array($prev)?(int)($prev['revision']??0):0;
        if($expectedRevision!==$revision)return new \WP_Error('binding_revision_conflict');
        if(($mapping['revision']??null)!==$revision+1)
            return new \WP_Error('binding_revision_not_next');
        $valid=self::validate($parentId,$rateId,$mapping);
        if(is_wp_error($valid))return $valid;
        $audits=is_array($prev)&&is_array($prev['audit_trail']??null)?$prev['audit_trail']:[];
        if(count($audits)>100)return new \WP_Error('binding_audit_over_limit');
        $audits[]=[
            'revision'=>$revision+1,'editor_id'=>get_current_user_id(),
            'saved_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
            'sha256'=>hash('sha256',wp_json_encode($mapping,JSON_UNESCAPED_SLASHES))
        ];
        $stored=$mapping;$stored['audit_trail']=$audits;
        if($prev===''){
            if(metadata_exists('post',$target,self::KEY))
                return new \WP_Error('binding_meta_ambiguous');
            $done=add_post_meta($target,self::KEY,$stored,true);
        }else $done=update_post_meta($target,self::KEY,$stored,$prev);
        if(!$done)return new \WP_Error('binding_cas_failed');
        wp_cache_delete($target,'post_meta');
        if(get_post_meta($target,self::KEY,true)!==$stored)
            return new \WP_Error('binding_readback_mismatch');
        do_action('commerce_composition_binding_saved',$target,$parentId,$rateId,$revision+1);
        return ['saved'=>true,'revision'=>$revision+1,'audit_entries'=>count($audits)];
    }
}
