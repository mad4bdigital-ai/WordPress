<?php
/** Read-only, active-source regression for Company Reconcile + Start Company. */
if(!defined('WP_CLI')||!WP_CLI)exit;
$fail=[];$count=0;
$check=static function(string $name,bool $pass)use(&$fail,&$count){$count++;if(!$pass)$fail[]=$name;echo ($pass?'PASS ':'FAIL ').$name."\n";};
$company='wayfatours';$env='production';$uuid='a3a7cc36-db7e-4a14-8456-b41857def05c';
$r=MAD4B_Company_Start_Reconcile_Completeness_V1::project($company,[]);
$plan=$r['company_reconcile_work_plan']??[];
$check('start_report_34_stages',($r['check_count']??null)===34);
$check('start_requires_independent_readback',($r['state']??null)==='RECONCILE_EVIDENCE_REQUIRED'&&($r['ready_to_publish']??true)===false);
$check('commercial_four_checks_reported',count(array_filter((array)$r['unverified_checks'],static fn($x)=>str_starts_with((string)$x,'INDEPENDENT_READBACK_PENDING:')&&in_array(substr((string)$x,strlen('INDEPENDENT_READBACK_PENDING:')),['pricing_and_booking','payment_checkout','outbound_notifications','provider_account_connections'],true)))===4);
$check('start_exposes_full_workplan',($plan['work_count']??null)===34);
$check('start_has_dynamic_scenarios',($plan['scenario_count']??0)>=16);
$check('plan_source_is_exact_registered_target',($plan['registered_targets']['production']['site_uuid']??'')===$uuid);
$work=array_column((array)($plan['work_items']??[]),null,'check');
foreach(['wpml_translations','fluent_form_definitions','hero_and_css_assets','brand_kit_page_options','elementor_theme_conditions'] as $field)
 $check('mixed_ownership_'.$field,($work[$field]['ownership_scope']??null)==='mixed'&&($work[$field]['ownership_split_required']??false)===true&&($work[$field]['tenant_values_preserved']??false)===true);
$check('source_scope_only_inspection',($work['source_scope']['ownership_scope']??null)==='inspect_only');
$check('compiled_css_remains_target_local',($work['elementor_initial_ajax_css_parity']['ownership_scope']??null)==='site_generated');
$check('no_automatic_scenario_writes',count(array_filter((array)$plan['incident_scenarios'],static fn($x)=>($x['auto_apply']??true)!==false))===0);
$check('no_authorization_by_start_plan',($plan['authority_granted']??true)===false&&($plan['mutation_performed']??true)===false);
$matrix=MAD4B_Company_Reconcile_Operation_Readiness_V1::inspect($company,$env,$uuid);
$check('native_operation_matrix_built',is_array($matrix)&&($matrix['operation_count']??0)===5);
$check('operation_statuses_separate',is_array($matrix)&&count(array_unique(array_column($matrix['operations'],'operation')))===$matrix['operation_count']);
$check('operation_matrix_does_not_authorize',is_array($matrix)&&($matrix['execution_authorized_by_this_report']??true)===false&&($matrix['mutation_performed']??true)===false);
$blocked=MAD4B_Company_Reconcile_Operation_Readiness_V1::inspect($company,$env,'00000000-0000-4000-8000-000000000001');
$check('operation_matrix_wrong_site_rejected',is_wp_error($blocked)&&$blocked->get_error_code()==='target_uuid_mismatch');
$bad=MAD4B_Company_Reconcile_Operation_Readiness_V1::inspect('bad code',$env);
$check('operation_matrix_invalid_company_rejected',is_wp_error($bad));
$provider=is_array($matrix)?array_values(array_filter($matrix['operations'],static fn($x)=>($x['operation']??'')==='plugin.install')):[];
$check('plugin_handler_registered_and_heartbeat_diagnosable',count($provider)===1&&isset($provider[0]['provider_diagnostics']['exact_handler_registered']));
$check('resource_installation_not_grant',count($provider)===1&&($provider[0]['provider_diagnostics']['historic_resource_does_not_authorize_new_job']??false)===true);
$check('provider_signed_readback_not_claimed_as_new_job',count($provider)===1&&($provider[0]['new_job_313_verified']??true)===false);
$check('no_fake_target_probe_complete',($r['target_probe_not_authoritative_without_original_readback']??false)===true);
$check('one_provider_does_not_block_fleet',is_array($matrix)&&($matrix['one_blocked_provider_does_not_block_other_ready_operations']??false)===true);
echo 'SUMMARY '.($count-count($fail)).'/'.$count.' failed='.wp_json_encode($fail)."\n";
if($fail)WP_CLI::error('Company reconcile deep regression failed');
