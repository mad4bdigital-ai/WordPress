<?php
/**
 * REV110 — Read-only source ownership certification. This registry CANNOT
 * promote a source to sellable filter or change a native JSF/JetEngine query.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type()!=='local'
    || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;

function shared_presentation_commercial_source_registry_v2():array {
    $result=[
        'schema_version'=>'2.0.0',
        'authority'=>'native_jetengine_jet_smart_filters_and_domain_engines',
        'policy'=>'discovery_never_grants_commercial_execution',
        'cct'=>[],
        'mounted_non_taxonomy'=>[],
        'fluent_forms'=>[],
    ];
    // Only registered JetEngine CCT definitions are authoritative. Never
    // guess a table name, trust a request slug, or read raw commercial rows.
    if(class_exists('\Jet_Engine\Modules\Custom_Content_Types\Module')) {
        $module=\Jet_Engine\Modules\Custom_Content_Types\Module::instance();
        $manager=$module->manager??null;
        if($manager && method_exists($manager,'get_content_types')) {
            foreach((array)$manager->get_content_types() as $slug=>$factory) {
                if(!is_string($slug) || !preg_match('/^[a-z0-9_]{1,80}$/D',$slug)
                    || !is_object($factory) || !method_exists($factory,'get_arg'))continue;
                $owner=(string)$factory->get_arg('related_post_type');
                $service= $owner && function_exists('service_channel_owner')
                    ?service_channel_owner($owner):null;
                $fields=[];
                foreach((array)($factory->fields??[]) as $field) {
                    if(!is_array($field))continue;
                    $name=(string)($field['name']??'');
                    if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,79}$/D',$name))continue;
                    $fields[]=['field'=>$name,'native_type'=>(string)($field['type']??'unknown')];
                }
                $result['cct'][$slug]=[
                    'id'=>(int)($factory->type_id??0),
                    'source'=>'registered_jetengine_cct',
                    'related_post_type'=>$owner?:null,
                    'service_owner'=>is_string($service)?$service:null,
                    'field_schema'=>$fields,
                    'status'=>'registered_not_auto_authorized_for_faceting',
                    'tenant_scope'=>'current_wordpress_site_only',
                    'option_policy'=>'deny_generic_option_override',
                    'commercial_eligibility'=>'requires_source_and_service_specific_provider_contract',
                    'write_authorized'=>false,
                ];
            }
        }
    }
    $mounted=function_exists('shared_presentation_native_typed_manifest_v1')
        ?shared_presentation_native_typed_manifest_v1():[];
    foreach($mounted as $filterId=>$filter) {
        if(($filter['data_source']??'')==='taxonomies')continue;
        $providerSlugs=array_values((array)($filter['provider_slugs']??[]));
        $category=(string)($filter['data_source']??'');
        if(!$category)$category=(string)($filter['filter_type']??'unknown');
        // Resolve owners from actual JetEngine Query Builder configurations
        // and the central MU Provider Registry; never infer from slug names.
        $service='unresolved';
        foreach($providerSlugs as $slug) {
            $postType='';
            if(class_exists('\Jet_Engine\Query_Builder\Manager')){
                $query=\Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id((string)$slug);
                if(is_object($query) && ($query->query_type??'')==='posts'){
                    $types=array_values(array_filter((array)($query->query['post_type']??[]),'is_string'));
                    if(count($types)===1)$postType=$types[0];
                }
            }
            if($postType==='tour-rates' && function_exists('shared_presentation_registry_v2')){
                foreach(shared_presentation_registry_v2() as $descriptor) {
                    if(($descriptor['provider']??'')==='jet-engine/'.$slug
                       && ($descriptor['entity_type']??'')===$postType
                       && !empty($descriptor['parent_type'])){
                        $postType=(string)$descriptor['parent_type'];
                        break;
                    }
                }
            }
            $resolved=$postType && function_exists('service_channel_owner')
                ?service_channel_owner($postType):null;
            if(is_string($resolved) && $resolved!==''){$service=$resolved;break;}
        }
        $public= $service!=='unresolved' && function_exists('service_channel_allowed')
            ?service_channel_allowed($service,'standalone'):false;
        $result['mounted_non_taxonomy'][(int)$filterId]=[
            'filter_id'=>(int)$filterId,
            'filter_type'=>(string)($filter['filter_type']??''),
            'data_source'=>(string)($filter['data_source']??''),
            'query_var'=>(string)($filter['query_var']??''),
            'reference'=>$filter['reference']??null,
            'source_state'=>(string)($filter['state']??'unresolved'),
            'provider_slugs'=>$providerSlugs,
            'service_owner'=>$service,
            'service_standalone_active'=>$public,
            'execution_owner'=>(string)($filter['owner']??'native'),
            'policy'=>'no_generic_override_commercial_domain_must_validate',
            'public_sale_authorized'=>false,
            'write_authorized'=>false,
        ];
    }
    if(function_exists('shared_presentation_fluent_form_sources_v1')) {
        foreach(shared_presentation_fluent_form_sources_v1() as $formId=>$schema){
            $result['fluent_forms'][(int)$formId]=[
                'form_id'=>(int)$formId,
                'field_count'=>(int)($schema['field_count']??0),
                'source'=>'original_fluent_forms_schema',
                'has_cpt_selection'=>(bool)($schema['has_cpt_selection']??false),
                'quote_authority'=>'existing_domain_engine_only',
                'submit_authority'=>'original_form_only',
                'write_authorized'=>false,
            ];
        }
    }
    ksort($result['cct']);
    ksort($result['mounted_non_taxonomy']);
    ksort($result['fluent_forms']);
    $result['counts']=[
        'registered_cct'=>count($result['cct']),
        'mounted_non_taxonomy_filters'=>count($result['mounted_non_taxonomy']),
        'published_form_schemas'=>count($result['fluent_forms'])
    ];
    return $result;
}

add_action('rest_api_init',static function():void {
    register_rest_route('shared-product/v1','/native-commercial-sources',[
        'methods'=>WP_REST_Server::READABLE,
        'permission_callback'=>static fn():bool =>
            wp_get_environment_type()==='local'
            && defined('SHARED_PRODUCT_LAB_ONLY') && SHARED_PRODUCT_LAB_ONLY
            && current_user_can('manage_options'),
        'callback'=>static function(){
            $response=new WP_REST_Response(shared_presentation_commercial_source_registry_v2());
            $response->header('Cache-Control','private, no-store, max-age=0');
            return $response;
        },
    ]);
},25);
