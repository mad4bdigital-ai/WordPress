<?php
/** Read-only Staging QA for Content Graph and source-backed cruise program readiness. */
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('CLI only');
use MAD4B\DFSB\NativePresentation\SmartSeoContentGraph as Graph;
$before=get_option('jet-smart-filters-settings',[]);
$policy=get_option('mad4b_smart_seo_policy_registry_v1',[]);
$checks=[];
$term=Graph::term(123,'destinations','en');
$checks['cairo_is_informational_candidate']=!is_wp_error($term)
 && $term['information_page_content_candidate']===true;
$checks['editorial_inlinks_are_verifiable_evidence']=!is_wp_error($term)
 && is_int($term['actual_editorial_inlinks_count'])
 && is_array($term['elementor_configuration_link_candidates']);
$checks['taxonomy_depth_is_not_crawl_depth']=!is_wp_error($term)
 && $term['hierarchy_depth']>=1
 && $term['link_depth_measured_here']===false
 && $term['page_crawl_orphan_status']==='not_determined';
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
if(!$admins)throw new RuntimeException('Admin required for route QA');
$old=get_current_user_id();
try{
 rest_get_server();
 wp_set_current_user(0);
 $req=new WP_REST_Request('GET','/mad4b/v1/seo-intelligence/content-graph/programs-readiness');
 $anon=rest_do_request($req);
 $checks['programs_anonymous_denied']=in_array($anon->get_status(),[401,403],true);
 wp_set_current_user((int)$admins[0]);
 $response=rest_do_request($req);
 $data=$response->get_data();
 $checks['programs_admin_read_only']=$response->get_status()===200
   && $data['writes_performed']===false;
 $checks['programs_actual_source_count']=$data['terms_count']>=0
   && $data['native_taxonomy_relations']>=0
   && $data['published_rate_program_references']>=0;
 $checks['programs_not_guessed_from_duration']=$data['auto_derive_program_from_duration']===false;
 $checks['no_false_program_facet_ready']=($data['native_taxonomy_relations']===0
   || $data['published_cruise_mode_tours']===0)
   ? $data['programs_archive_facet_ready']===false:true;
 $checks['cruise_count_consistent']=$data['nile_cruises_without_cruise_tour_mode']
   <=$data['nile_cruises_tagged'];
}finally{wp_set_current_user($old);}
$checks['jet_smart_filters_unchanged']=$before===get_option('jet-smart-filters-settings',[]);
$checks['policy_unchanged']=$policy===get_option('mad4b_smart_seo_policy_registry_v1',[]);
foreach($checks as $key=>$ok)echo($ok?'PASS ':'FAIL ').$key.PHP_EOL;
echo 'TESTS_PASSED='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
if(in_array(false,$checks,true))exit(1);
