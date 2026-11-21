<?php
/**
 * Original Company Skill Set planning/acceptance source.
 * Real first-party Skill policy JSON, synthetic local Skill Registry.
 * No content mutation, provider receipt, external Skill install or network.
 */
define('ABSPATH',__DIR__.'/');
if(!function_exists('str_starts_with')){function str_starts_with($value,$prefix){return substr($value,0,strlen($prefix))===$prefix;}}
$root=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/mad4b-company-skills-'.getmypid();
$dir=$tmp.'/mad4b-skills/workflow/company-reconcile/content-jobs-ui-reconcile/references';
if(!mkdir($dir,0700,true))throw new RuntimeException('Cannot create bounded Skill fixture');
define('WP_CONTENT_DIR',$tmp);
$policyFile=$root.'/wp-content/plugins/mad4b-site-control-plane/skill-seeds/content-jobs-ui-reconcile/references/dynamic-skill-family-policy-v1.json';
$dst=$dir.'/dynamic-skill-family-policy-v1.json';
if(!copy($policyFile,$dst))throw new RuntimeException('Original policy fixture missing');
$GLOBALS['company_skills_policy']=json_decode(file_get_contents($policyFile),true,512,JSON_THROW_ON_ERROR);
$GLOBALS['skills_current_target']=true;
$GLOBALS['skills_available']=true;
$GLOBALS['skills_mutations']=0;
$GLOBALS['skills_registry']=array();
$GLOBALS['skills_registry']['all']=array();
$all=array_unique(array_merge(
 (array)$GLOBALS['company_skills_policy']['core_skills'],
 ...array_values((array)$GLOBALS['company_skills_policy']['dimension_skills']),
 ...array_map(static function($family){return (array)($family['skills']??array());},
  array_values((array)$GLOBALS['company_skills_policy']['plugin_families']))));
foreach($all as $name)$GLOBALS['skills_registry']['all'][]=array(
 'logical_id'=>$name,'sha256'=>str_repeat('a',64));
function add_action($name,$callback,$priority=10){}
function wp_register_ability($name,$config){$GLOBALS['skills_native_abilities'][$name]=$config;}
function wp_has_ability($name){return isset($GLOBALS['skills_native_abilities'][$name]);}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function wp_is_uuid($v){return (bool)preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$v);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function get_option($name,$default=array()){
 return $name==='active_plugins'?array('elementor/elementor.php','jet-engine/jet-engine.php'):$default;
}
function get_template(){return 'astra';}
function is_multisite(){return false;}
function get_site_option($name,$default=array()){return $default;}
class MAD4B_Manager_Auto_Write_V1 {
 public static function site(){return $GLOBALS['skills_current_target'];}
 public static function company_target($company){return array(
   'state'=>'REGISTERED','environment'=>'production',
   'site_uuid'=>'12345678-1234-4123-8123-123456789abc',
   'canonical_origin'=>'https://company.example.invalid'
 );}
}
class MAD4B_SCP_Skill_Registry {
 public static function list_skills($filters=array()){
  return $GLOBALS['skills_available']?$GLOBALS['skills_registry']['all']:array();
 }
}
function check_skill($ok,$reason){
 if(!$ok)throw new RuntimeException('FAIL COMPANY RECONCILE SKILLS: '.$reason);
}
require $root.'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-company-reconcile-skill-set.php';
$c='MAD4B_SCP_Company_Reconcile_Skill_Set_V1';
$c::register_abilities();
check_skill(count($GLOBALS['skills_native_abilities'])===2,
 'Exactly two source owned read-only skill plan/acceptance tools');
foreach($GLOBALS['skills_native_abilities'] as $name=>$cfg)
 check_skill($cfg['meta']['annotations']['readonly']===true
 && $cfg['input_schema']['additionalProperties']===false
 && $cfg['permission_callback']===array('MAD4B_SCP_Policy','can_read')
 && $cfg['meta']['mcp']['surface']==='read',
 'Original skill registry cannot register owner write or accept extra arguments');
$input=array('company_code'=>'demo-company','environment'=>'production',
 'site_uuid'=>'12345678-1234-4123-8123-123456789abc');
$ready=$c::plan($input);
check_skill(is_array($ready)
 && $ready['state']==='READY_FOR_GOVERNED_REPAIR_PLAN'
 && $ready['pre_execution_skill_selection_passed']===true
 && $ready['source_inventory_is_not_target_proof']===true
 && $ready['target_runtime_probe_verified']===false
 && $ready['native_execution_authorized']===false
 && $ready['mutation_performed']===false
 && in_array('frontend',$ready['dimensions'],true)
 && in_array('security',$ready['dimensions'],true)
 && in_array('translations',$ready['dimensions'],true)
 && in_array('elementor',$ready['detected_plugin_families'],true),
 'Source plugin/Skill union must retain mandatory UX/security/translation dimensions and deny native execution');
foreach($ready['external_skill_recommendations'] as $external)
 check_skill($external['state']==='EXTERNAL_REVIEW_REQUIRED_NOT_INSTALLED'
  && $external['part_of_mandatory_set']===false
  && $external['authorizes_execution']===false,
 'An untrusted GitHub recommended skill cannot auto-install or grant rights');
$stale=$c::acceptance(array_replace($input,array('expected_skill_set_sha256'=>str_repeat('b',64))));
check_skill($stale['state']==='HOLD'
 && $stale['reason']==='skill_set_plan_sha256_missing_or_changed',
 'Skill approval requires an exact fresh canonical snapshot digest');
$inspection=$c::acceptance(array_replace($input,array('expected_skill_set_sha256'=>$ready['skill_set_sha256'])));
check_skill($inspection['state']==='UNTESTED_NATIVE_PROVIDER_RECEIPTS_REQUIRED'
 && $inspection['native_execution_authorized']===false
 && $inspection['browser_interactions_verified']===false
 && $inspection['locale_parity_verified']===false
 && $inspection['reconcile_closure_accepted']===false,
 'Correct Skill inventory is NOT target browser, locale or provider execution proof');
$wrongSite=$c::plan(array_replace($input,array('site_uuid'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')));
check_skill($wrongSite['state']==='HOLD' && $wrongSite['reason']==='original_manager_target_binding_unverified',
 'Caller cannot override company target Site Profile');
$badDimension=$c::plan(array_replace($input,array('dimensions'=>array('untrusted-shell'))));
check_skill($badDimension['state']==='HOLD' && $badDimension['reason']==='invalid_requested_dimensions',
 'Unregistered dimensions cannot become mandatory Skill instructions');
$unknownFamily=$c::plan(array_replace($input,array('impacted_families'=>array('unknown-operator'))));
check_skill($unknownFamily['state']==='HOLD'
 && $unknownFamily['reason']==='impacted_provider_family_not_mapped',
 'Unknown provider family must be held for review');
$GLOBALS['skills_available']=false;
$missing=$c::plan($input);
check_skill($missing['state']==='HOLD_REQUIRED_SKILLS_MISSING'
 && $missing['pre_execution_skill_selection_passed']===false
 && $missing['native_execution_authorized']===false,
 'Missing local active mandatory Skills must fail closed');
$GLOBALS['skills_available']=true;
$GLOBALS['skills_current_target']=false;
check_skill($c::plan($input)['state']==='HOLD',
 'Missing source Manager authority must not trigger target-side actions');
check_skill($GLOBALS['skills_mutations']===0,
 'Skill plan and acceptance must never run providers or mutate WordPress');
unlink($dst);rmdir($dir);
echo "PASS: COMPANY_RECONCILE_SKILL_SET dynamic union, stale SHA, no provider/browser grant\n";
