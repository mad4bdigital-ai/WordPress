<?php
/**
 * REV108 – Fluent Forms source schema discovery, read-only.
 * A published form is NOT an authorization to quote, publish orders or
 * contact providers. Existing native Fluent Forms adapters keep ownership.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local' ||
    !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;

function shared_presentation_fluent_form_sources_v1():array {
    static $building=false;
    if($building)return [];
    $building=true;
    global $wpdb;
    $table=$wpdb->prefix.'fluentform_forms';
    if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table){
        $building=false;return [];
    }
    $rows=$wpdb->get_results("SELECT id,status,form_fields FROM {$table} WHERE status='published' ORDER BY id LIMIT 201",ARRAY_A);
    $result=[];
    foreach((array)$rows as $row){
        $schema=json_decode((string)$row['form_fields'],true);
        if(!is_array($schema))continue;
        $fields=[];$types=[];
        $walk=function($nodes)use(&$walk,&$fields,&$types){
            foreach((array)$nodes as $node){
                if(!is_array($node))continue;
                $type=(string)($node['element']??'');
                $attr=$node['attributes']??[];
                $name=$attr['name']??($node['settings']['name']??null);
                if($type){
                    $types[$type]=($types[$type]??0)+1;
                    if(is_scalar($name) && strlen((string)$name)<=120
                        && preg_match('/^[A-Za-z0-9_-]+$/D',(string)$name)){
                        $fields[]=['name'=>(string)$name,'native_type'=>$type];
                    }
                }
                foreach(['columns','fields','children'] as $key)
                    if(isset($node[$key])&&is_array($node[$key]))
                        $walk($node[$key]);
            }
        };
        $walk($schema['fields']??[]);
        $id=(int)$row['id'];
        $result[$id]=[
           'form_id'=>$id,'source'=>'fluent_forms_native_schema',
           'published'=>true,'field_count'=>count($fields),
           'field_sources'=>$fields,'native_field_types'=>$types,
           'has_cpt_selection'=>isset($types['cpt_selection']),
           'has_date_input'=>isset($types['input_date']),
           'has_occupancy_counts'=>count(array_intersect(
               ['no_of_adults','no_of_children','no_of_infants'],array_column($fields,'name')))==3,
           'quote_authority'=>'none__use_existing_pricing_engine',
           'submit_authority'=>'none__use_existing_form_workflows',
           'dynamic_options_authority'=>'original_fluent_forms_and_approved_domain_adapters'
        ];
    }
    $building=false;
    return $result;
}
