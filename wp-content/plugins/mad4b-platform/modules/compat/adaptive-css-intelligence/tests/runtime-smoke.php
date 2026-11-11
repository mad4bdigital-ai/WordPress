<?php
defined('ABSPATH') || exit;
$plugin=WP_CONTENT_DIR.'/plugins/mad4b-platform/modules/compat/adaptive-css-intelligence';
$manifest=WP_CONTENT_DIR.'/mad4b-predictive-css-v2/manifest.json';
$critical=WP_CONTENT_DIR.'/mad4b-predictive-css-v2/5b9f09bc0c76ffbac2faaa47a96b2b1db1bddc4a7ad753906a381bf5e7a22711.css';
$before=[hash_file('sha256',$manifest),hash_file('sha256',$critical),
         get_option('mad4b_perf_predictive_css_v2','off')];
$first=MAD4B_Company_Performance_Intelligence::scan_plan([]);
$second=MAD4B_Company_Performance_Intelligence::scan_plan([]);
$plan=$first['adaptive_css_group_intelligence']??[];
$inventory=$first['adaptive_css_source_inventory']??[];
if(($plan['contract']??null)!=='mad4b.adaptive-css-intelligence.v1'
   || ($plan['release']??'')!=='hold_live_activation'
   || !empty($plan['live_activation_eligible'])
   || ($inventory['state']??'')!=='source_inventory_current'
   || ($inventory['mapped_unique']??0)!==46
   || ($inventory['verified_exclusion_count']??-1)!==0)throw new RuntimeException('Intelligence contract failure');
if(($first['readonly']??false)!==true || ($first['mutation_performed']??true)!==false)
   throw new RuntimeException('Read-only report changed');
if(count($first['remediation_plan'])!==count($second['remediation_plan']))
   throw new RuntimeException('Duplicate jobs on repeated scan');
$after=[hash_file('sha256',$manifest),hash_file('sha256',$critical),
         get_option('mad4b_perf_predictive_css_v2','off')];
if($before!==$after)throw new RuntimeException('CSS or runtime options unexpectedly mutated');
$admin=get_users(['role'=>'administrator','number'=>1,'fields'=>'ids']);
wp_set_current_user((int)$admin[0]);
$r=rest_do_request(new WP_REST_Request('GET','/mad4b/v1/company-performance/scan'));
if($r->get_status()!==200 || !isset($r->get_data()['adaptive_css_source_inventory']))
   throw new RuntimeException('Admin REST source inventory unavailable');
wp_set_current_user(0);
$r=rest_do_request(new WP_REST_Request('GET','/mad4b/v1/company-performance/scan'));
if($r->get_status()!==401)throw new RuntimeException('Guest REST access regression');
echo "ADAPTIVE_CSS_RUNTIME_REGRESSION_PASS\n";
