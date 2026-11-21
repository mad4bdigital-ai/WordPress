<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$cls='MAD4B_Guide_Taxonomy_Pricing_V1';
$box=$cls::META_BOX;
$component=jet_engine()->meta_boxes;
$all=$component->data->get_raw();
if(!isset($all[$box]) || !is_array($all[$box]) ||
   ($all[$box]['args']['allowed_tax']??[])!==['guide-languages'] ||
   count($all[$box]['meta_fields']??[])!==9)
 WP_CLI::error('Native JetEngine term meta box not registered after WordPress boot');
WP_CLI::log('PASS native JetEngine Guide Languages Meta Box loaded by runtime');
if(($all[$box]['id']??null)!==$box)
 WP_CLI::error('Meta Box missing JetEngine native id');
$_GET['page']='dynamic-pricing-controls';
ob_start();$cls::panel();$html=ob_get_clean();
if(!str_contains($html,'Guide Service · Supplier Cost per Language') ||
   !str_contains($html,'mad4b-guide-default-form')||
   substr_count($html,'class="mad4b-guide-term-row"')!==5 ||
   !str_contains($html,'Approve cost')||
   !str_contains($html,'Edit taxonomy term'))
 WP_CLI::error('Pricing Controls panel missing controls or languages');
WP_CLI::log('PASS Dynamic Pricing Controls has EGP fallback and 5 taxonomy language rows');
$act='wp_ajax_'.$cls::ACTION;
if(!has_action($act))WP_CLI::error('Scoped AJAX save handler missing');
WP_CLI::log('PASS scoped AJAX save handler registered');
wp_set_current_user(0);
if($cls::permitted())WP_CLI::error('Guest gained Guide pricing capability');
$try=$cls::save(['scope'=>'global','expected_hash'=>str_repeat('0',64),'amounts'=>[]]);
if(!is_wp_error($try)||$try->get_error_code()!=='guide_cost_company_pricing_permission_required')
 WP_CLI::error('Guest global mutation not denied');
WP_CLI::log('PASS guest cannot read cost panel or save global policy');
wp_set_current_user(1);
$_GET['page']='not-pricing-controls';
ob_start();$cls::panel();$none=ob_get_clean();
if($none!=='')WP_CLI::error('Guide cost panel leaked into unrelated screens');
WP_CLI::log('PASS screen isolation');
WP_CLI::success('GUIDE_TAXONOMY_ADMIN_AND_RUNTIME_ACCEPTED');
