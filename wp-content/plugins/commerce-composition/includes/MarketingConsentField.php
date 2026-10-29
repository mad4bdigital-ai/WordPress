<?php
declare(strict_types=1);
namespace Commerce\Composition;

/** Explicit, opt-in native Fluent Forms field update. Never a subscription. */
final class MarketingConsentField
{
    public static function definition(string $fieldName,string $label,
        string $statement):array|\WP_Error
    {
        if(!preg_match('/^[a-z][a-z0-9_]{2,79}$/D',$fieldName)||
            trim($label)===''||trim($statement)==='')
            return new \WP_Error('marketing_consent_field_spec_invalid');
        return [
            'index'=>0,'element'=>'input_checkbox',
            'attributes'=>['type'=>'checkbox','name'=>$fieldName,'value'=>[]],
            'settings'=>[
                'dynamic_default_value'=>'',
                'container_class'=>'',
                'label'=>$label,
                'admin_field_label'=>'Optional marketing consent',
                'label_placement'=>'top',
                'display_type'=>'',
                'help_message'=>'Optional. Your request can be submitted without accepting promotional messages.',
                'advanced_options'=>[[
                    'label'=>$statement,'value'=>'yes',
                    'calc_value'=>'','image'=>'','id'=>0
                ]],
                'calc_value_status'=>false,
                'enable_image_input'=>false,
                'values_visible'=>false,
                'randomize_options'=>'no',
                'validation_rules'=>['required'=>[
                    'value'=>false,'message'=>'',
                    'global_message'=>'','global'=>false
                ]],
                'conditional_logics'=>[],
                'layout_class'=>''
            ],
            'editor_options'=>[
                'title'=>'Checkbox','icon_class'=>'ff-edit-checkbox-1',
                'template'=>'inputCheckable'
            ],
            'uniqElKey'=>'marketing_consent_'.substr(hash('sha256',$fieldName),0,16)
        ];
    }
    public static function inspect(array $form,string $name):array
    {
        $found=[];
        $walk=function($node)use(&$walk,&$found,$name){
            if(!is_array($node))return;
            if(isset($node['element'])&&
                ($node['attributes']['name']??null)===$name)
                $found[]=$node;
            foreach($node as $v)if(is_array($v))$walk($v);
        };
        $walk($form['fields']??[]);
        return $found;
    }
    public static function withOptionalField(array $original,array $field):array|\WP_Error
    {
        if(!isset($original['fields'])||!is_array($original['fields'])||
           !isset($field['attributes']['name'])||
           !is_string($field['attributes']['name']))
            return new \WP_Error('marketing_consent_form_schema_invalid');
        $name=$field['attributes']['name'];
        if(self::inspect($original,$name))
            return new \WP_Error('marketing_consent_field_already_exists');
        $existing=json_decode(wp_json_encode($original),true);
        $max=0;
        $walk=function($node)use(&$walk,&$max){
            if(!is_array($node))return;
            if(isset($node['element'])&&is_numeric($node['index']??null))
                $max=max($max,(int)$node['index']);
            foreach($node as $v)if(is_array($v))$walk($v);
        };
        $walk($existing['fields']);
        $field['index']=$max+1;
        $existing['fields'][]=$field;
        return $existing;
    }
    /** Only owner-invoked; preserves every old field and avoids plugin writes. */
    public static function install(int $formId,array $definition):array|\WP_Error
    {
        global $wpdb;
        if(!current_user_can('manage_options'))
            return new \WP_Error('marketing_consent_form_edit_forbidden');
        if($formId<1)return new \WP_Error('marketing_consent_form_id_invalid');
        $t=$wpdb->prefix.'fluentform_forms';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT form_fields FROM $t WHERE id=%d",$formId
        ),ARRAY_A);
        if(!is_array($row))return new \WP_Error('marketing_consent_form_missing');
        $old=json_decode((string)$row['form_fields'],true);
        if(!is_array($old))return new \WP_Error('marketing_consent_form_unparseable');
        $new=self::withOptionalField($old,$definition);
        if(is_wp_error($new))return $new;
        $encoded=wp_json_encode($new,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($encoded))return new \WP_Error('marketing_consent_encode_failed');
        $saved=$wpdb->update($t,['form_fields'=>$encoded],
            ['id'=>$formId,'form_fields'=>$row['form_fields']],['%s'],['%d','%s']);
        if($saved!==1)return new \WP_Error('marketing_consent_conflicting_update');
        $verified=json_decode((string)$wpdb->get_var($wpdb->prepare(
            "SELECT form_fields FROM $t WHERE id=%d",$formId)),true);
        $fields=self::inspect((array)$verified,$definition['attributes']['name']);
        if(count($fields)!==1||($fields[0]['attributes']['value']??null)!==[]||
            ($fields[0]['settings']['validation_rules']['required']['value']??null)!==false)
            return new \WP_Error('marketing_consent_readback_failed');
        return [
            'form_id'=>$formId,'old_sha256'=>hash('sha256',$row['form_fields']),
            'new_sha256'=>hash('sha256',$encoded),
            'field_name'=>$definition['attributes']['name'],
            'required'=>false,'default_checked'=>false
        ];
    }
}
