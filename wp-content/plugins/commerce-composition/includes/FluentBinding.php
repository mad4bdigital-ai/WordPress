<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Populate fields on the EXISTING published Fluent Forms template without
 * saving a modified form or replacing Elementor/JetEngine product pages.
 * Field bindings are explicitly configured on a source/Rate, not guessed by name.
 */
final class FluentBinding
{
    public static function bootstrap():void
    {
        add_filter('fluentform/rendering_field_data_select',[self::class,'select'],50,2);
        add_filter('fluentform/rendering_field_data_input_checkbox',[self::class,'checkbox'],50,2);
    }
    private static function context():array|\WP_Error
    {
        $override=apply_filters('commerce_composition_render_context',null);
        if($override!==null){
            if(!is_array($override))return new \WP_Error('render_context_invalid');
            return $override;
        }
        $id=get_queried_object_id();
        if(!$id)return new \WP_Error('render_context_unavailable');
        // This is a public display source, not an authority from arbitrary
        // browser fields. Rate must be reverified before any booking.
        $rate=isset($_GET['rate_id'])?absint($_GET['rate_id']):null;
        return ['parent_id'=>(int)$id,'rate_id'=>$rate?:null];
    }
    private static function config(int $parentId,?int $rateId):array|\WP_Error
    {
        $mapping=$rateId ? get_post_meta($rateId,'_commerce_form_field_bindings',true):null;
        if(!is_array($mapping))$mapping=get_post_meta($parentId,'_commerce_form_field_bindings',true);
        $mapping=apply_filters('commerce_composition_form_field_bindings',
            $mapping,$parentId,$rateId);
        if(!is_array($mapping)||($mapping['schema']??null)!==1||
            !is_int($mapping['form_id']??null)||$mapping['form_id']<1 ||
            !is_array($mapping['groups']??null)||count($mapping['groups'])>100)
            return new \WP_Error('form_field_binding_missing_or_invalid');
        return $mapping;
    }
    public static function select(array $field,$form):array
    {
        return self::bind($field,$form,'select');
    }
    public static function checkbox(array $field,$form):array
    {
        return self::bind($field,$form,'checkbox');
    }
    public static function bind(array $field,$form,string $control):array
    {
        if(!is_object($form)||!isset($form->id)||!is_numeric($form->id))
            return $field;
        $context=self::context();
        if(is_wp_error($context))return $field;
        $parent=(int)($context['parent_id']??0);
        $rate=isset($context['rate_id'])?(int)$context['rate_id']:null;
        if($parent<1||($rate!==null&&$rate<1))return $field;
        $map=self::config($parent,$rate);
        if(is_wp_error($map)||$map['form_id']!==(int)$form->id)return $field;
        $descriptor=FormRouter::describe($parent,$rate);
        if(is_wp_error($descriptor) || !$descriptor['booking_available'] ||
           $descriptor['booking_form_id']!==(int)$form->id)
            return $field;
        $name=$field['attributes']['name']??null;
        if(!is_string($name)||!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,119}$/D',$name))
            return $field;
        $binding=null;
        foreach($map['groups'] as $entry){
            if(!is_array($entry))return $field;
            if(($entry['field_name']??null)===$name) {
                if($binding!==null)return $field;
                $binding=$entry;
            }
        }
        if($binding===null||($binding['control']??null)!==$control)return $field;
        $choice=null;
        foreach($descriptor['booking_inputs'] as $group){
            if(($group['key']??null)===($binding['key']??null)){
                $choice=$group;break;
            }
        }
        if(!is_array($choice)||!$choice['available'] ||
           $choice['control']!==$control || !is_array($choice['options']))return $field;
        $options=[];
        foreach($choice['options'] as $option){
            if(!isset($option['source_id'],$option['source_type']) ||
               !is_int($option['source_id']) || $option['source_id']<1)
                return $field;
            $label=$option['label']??'';
            if(!is_string($label)||$label==='')$label=get_the_title($option['source_id']);
            $options[]=[
                'label'=>sanitize_text_field((string)$label),
                'value'=>(string)$option['source_id'],
                'calc_value'=>'0'
            ];
        }
        if(!$options && $choice['required'])return $field;
        if(!isset($field['settings'])||!is_array($field['settings']))$field['settings']=[];
        if(!isset($field['attributes'])||!is_array($field['attributes']))$field['attributes']=[];
        $field['settings']['advanced_options']=$options;
        $field['attributes']['value']='';
        if(!isset($field['settings']['validation_rules'])||
           !is_array($field['settings']['validation_rules']))
            $field['settings']['validation_rules']=[];
        $field['settings']['validation_rules']['required']['value']=$choice['required'];
        return $field;
    }
}
