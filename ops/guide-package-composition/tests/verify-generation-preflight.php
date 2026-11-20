<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
$cls='MAD4B_Guide_Service_Composition_V1';
$plan=$cls::generationPlan(64871,'english');
if(($plan['source_mode']??null)!=='component_cost_only'
   ||($plan['legacy_rate_price_copied']??null)!==false
   ||($plan['native_rate_generator_invoked']??null)!==false
   ||($plan['new_rate_created']??null)!==false
   ||($plan['generation_state']??null)!=='blocked_missing_certified_inputs'
   ||count($plan['missing_dependencies']??[])<4)WP_CLI::error('unsafe dynamic rate preflight');
WP_CLI::log('PASS dynamic_generation_excludes_legacy_rates_and_refuses_missing_costs');
$routes=rest_get_server()->get_routes();
if(!isset($routes['/mad4b-guide/v1/generation-preflight']))
 WP_CLI::error('Private rate generation preflight route missing');
wp_set_current_user(0);
$r=new WP_REST_Request('GET','/mad4b-guide/v1/generation-preflight');
$r->set_param('tour_id',64871);$r->set_param('language','english');
$response=rest_do_request($r);
if($response->get_status()<400)WP_CLI::error('Anonymous dynamic cost preflight exposed');
WP_CLI::log('PASS private_preflight_guest_http_'.$response->get_status());
WP_CLI::success('DYNAMIC_COMPONENT_GENERATION_PREFLIGHT_PASSED');
