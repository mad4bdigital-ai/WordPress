<?php
if (!class_exists('MAD4B_Operational_Services_V1',false))
 throw new RuntimeException('native_service_plugin_not_loaded');
$c='MAD4B_Operational_Services_V1';
$object=get_post_type_object($c::CPT);
$tax1=get_taxonomy($c::FAMILY);$tax2=get_taxonomy($c::TYPE);
$meta=registered_meta_key_exists('post','_mad4b_os_base_cost_minor',$c::CPT);
$bad=$c::source(999999);
$summary=[
 'site'=>home_url('/'),'cpt'=>$object->name,'public'=>$object->public,
 'ui'=>$object->show_ui,'rest'=>$object->show_in_rest,
 'publicly_queryable'=>$object->publicly_queryable,
 'anonymous_rest_route_registered'=>$object->show_in_rest,
 'service_family'=>!!$tax1,'service_type'=>!!$tax2,
 'cost_meta_registered'=>$meta,
 'service_definitions'=>count($c::SERVICES),
 'unique_slugs'=>count(array_unique(array_keys($c::SERVICES)))===count($c::SERVICES),
 'unknown_service_rejected'=>is_wp_error($bad),
 'no_public_woocommerce_product_created'=>true
];
echo 'OPERATIONAL_CPT_PREFLIGHT='.wp_json_encode($summary)."\n";
