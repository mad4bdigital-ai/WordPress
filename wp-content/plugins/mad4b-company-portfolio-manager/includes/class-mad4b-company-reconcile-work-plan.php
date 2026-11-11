<?php
/**
 * Source-bound, non-authorizing work plan for Company Reconcile and Start Company.
 * Uses the original staged capability policy; never copies tenant values or
 * accepts caller-supplied PASS as an independent target provider receipt.
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-mad4b-company-reconcile-depth-audit.php';
if(!class_exists('MAD4B_Company_Reconcile_Work_Plan_V1',false)){
final class MAD4B_Company_Reconcile_Work_Plan_V1 {
 const CONTRACT='mad4b.company-reconcile.work-plan.v1';
 private static function scope(string $stage,string $check,array $policy):string {
  $custom=$policy['reconcile_ownership_by_check'][$check]??null;
  if(is_string($custom)&&in_array($custom,['shared_definition','tenant_owned','site_generated','reconnect','never_clone','inspect_only','mixed'],true))return $custom;
  if(in_array($check,['wpml_translations','post_taxonomy_schema','jetengine_queries_options','fluent_form_definitions',
   'elementor_theme_conditions','provider_dependency_graph','plugin_activation','source_scope','dynamic_tag_bindings','mu_presentation_dependencies','frontend_shortcodes'],true))return 'shared_definition';
  if(in_array($check,['hero_and_css_assets','elementor_visual_css_source_readback','elementor_initial_ajax_css_parity','jetsmartfilters_target_index'],true))return 'site_generated';
  if(in_array($check,['payment_checkout','outbound_notifications','provider_account_connections'],true))return 'reconnect';
  if(in_array($check,['user_verifier_continuity','original_authority'],true))return 'never_clone';
  if(in_array($check,['brand_kit_page_options','eligible_content_manifest','post_term_relationships','internal_link_target_readback','tenant_field_mapping','pricing_and_booking','media_provenance'],true))return 'tenant_owned';
  return $stage==='bootstrap'?'inspect_only':'tenant_owned';
 }
 private static function action(string $scope):string {
  return match($scope){
   'shared_definition'=>'diff_semantic_definition_then_governed_transfer',
   'mixed'=>'separate_generic_definitions_from_company_values_then_remap_local_references',
   'site_generated'=>'regenerate_target_native_cache_then_visual_readback',
   'tenant_owned'=>'preserve_company_values_and_reconcile_references',
   'reconnect'=>'reconnect_via_company_approved_provider',
   'never_clone'=>'verify_original_local_authority_without_copy',
   default=>'collect_source_target_evidence_and_review'
  };
 }
 private static function acceptance(string $check):string {
  if(in_array($check,['desktop_visual_render','mobile_visual_render','all_public_languages_render','interactive_filters_and_forms','elementor_initial_ajax_css_parity'],true))
   return 'target_browser_locale_interaction_and_independent_readback';
  if(in_array($check,['payment_checkout','pricing_and_booking','outbound_notifications'],true))
   return 'tenant_authorized_commerce_contract_readback_no_test_charge';
  if(in_array($check,['media_provenance','hero_and_css_assets'],true))
   return 'target_attachment_identity_license_and_display_readback';
  return 'target_original_provider_readback_bound_to_site_and_manifest';
 }
 /**
  * Reuses the existing center-only, identity-bound host WP-CLI observation.
  * No remote REST GET, credentials or shell execution from HTTP requests.
  * A fresh unsigned observation can advance diagnostics but never close a
  * required Original 313 provider receipt.
  */
 public static function original_policy():array {
  $file=WPMU_PLUGIN_DIR.'/company-start-reconcile/policy.json';
  if(!is_file($file)||is_link($file)||!is_readable($file))return [];
  $size=filesize($file);
  if(!is_int($size)||$size<10||$size>120000)return [];
  $decoded=json_decode((string)file_get_contents($file),true);
  if(!is_array($decoded)
    ||($decoded['contract']??'')!=='mad4b.company-start-reconcile-completeness.v1'
    ||!is_array($decoded['stages']??null))return [];
  return $decoded;
 }
 /** Fast, synchronous and mutation-free company admission assessment. */
 public static function inspect(string $company,string $requestedEnvironment='production'):array|WP_Error {
  if(!class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
    ||!MAD4B_Company_Portfolio_Manager_V1::staging())
   return new WP_Error('mad4b_reconcile_central_identity_required',
     'Exact central Company Manager identity is required',['status'=>403]);
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
    ||!in_array($requestedEnvironment,['staging','production'],true))
   return new WP_Error('mad4b_reconcile_invalid_scope',
     'Valid exact company/environment required',['status'=>422]);
  $policy=self::original_policy();
  if(!$policy)return new WP_Error('mad4b_reconcile_policy_missing',
      'Original source policy unavailable',['status'=>503]);
  $registered=[];
  if(class_exists('MAD4B_Company_Environment_Registry_V2',false))
   foreach(['staging','production'] as $env){
    $row=MAD4B_Company_Environment_Registry_V2::target($company,$env);
    if(is_array($row))$registered[$env]=$row;
   }
  $report=[];
  if(class_exists('MAD4B_Company_Readiness_Governance',false)){
   $all=get_option(MAD4B_Company_Readiness_Governance::STORE,[]);
   if(is_array($all)&&is_array($all[$company]??null))$report=$all[$company];
  }
  $plan=self::build($company,$registered,$policy,$report);
  $matchedBinding=null;
  if($registered){
   $binding=MAD4B_Company_Portfolio_Manager_V1::company_binding($company);
   $matchedBinding=!is_wp_error($binding)
    &&is_array($binding)
    &&($binding['company_code']??'')===$company;
  }
  $target=$plan['environment_inventory'][$requestedEnvironment]??null;
  $plan['requested_environment']=$requestedEnvironment;
  $plan['requested_environment_registered']=is_array($target);
  $plan['company_binding_verified']=$matchedBinding;
  // A Production-local WP-CLI observation is never reused as a Staging
  // observation, even if the same company appears in both environments.
  if($requestedEnvironment!=='production'
     ||!is_array($target)
     ||($plan['observed_runtime']['environment']??'')!==$requestedEnvironment
        &&($plan['observed_runtime']['state']??'')==='LOCAL_WORDPRESS_OBSERVED_UNSIGNED'){
   $plan['observed_runtime']=[
    'state'=>'NOT_OBSERVED_FOR_REQUESTED_ENVIRONMENT',
    'environment'=>$requestedEnvironment,
    'evidence_level'=>'none',
    'original_313_signed'=>false,
    'full_company_reconcile_verified'=>false,
    'write_authorized'=>false,
    'reason'=>'do_not_reuse_other_environment_readback',
   ];
   if(!$target){
    $plan['next_action']='REGISTER_OR_VERIFY_EXACT_ENVIRONMENT_SITE_IDENTITY';
   }
  }
  if($registered && $matchedBinding!==true){
   $plan['state']='IDENTITY_REGISTRY_REVIEW_REQUIRED';
   $plan['next_action']='RECONCILE_COMPANY_BINDING_BEFORE_SITE_OPERATIONS';
  }
  $plan['environment_is_not_interchangeable']=true;
  $plan['full_clone_admission']='NOT_AUTHORIZED_BY_RECONCILE_PLAN';
  $plan['clone_preservation_contract']=[
   'code_and_generic_schema'=>'compare_semantically_before_transfer',
   'company_woocommerce_settings'=>'preserve_company',
   'fluent_form_submissions_and_destinations'=>'preserve_company',
   'elementor_kit_and_brand_options'=>'preserve_company',
   'rank_math_and_seo_metadata'=>'preserve_company',
   'site_uuid_and_owner_users'=>'never_clone',
   'wpml_languages_and_translation_links'=>'target_identity_remap',
   'currency_and_tax_policies'=>'preserve_company',
   'guest_rendered_css_and_filters'=>'regenerate_on_target',
   'payment_secrets_and_api_credentials'=>'reconnect_explicitly',
  ];
  $plan['operation_readiness_path']=
    '/wp-json/mad4b/v1/company-brand-readiness/companies/'.$company.'/reconcile-operations';
  $plan['operation_readiness_is_on_demand']=true;
  $plan['operation_readiness_not_a_release_receipt']=true;
  $plan['source_is_master_for_code_not_company_options']=true;
  $plan['existing_target_must_not_be_recreated']=is_array($target);
  $plan['no_company_site_mutation']=true;
  $plan['no_new_site_reserved']=true;
  $plan['no_new_grants_created']=true;
  $plan['native_313_receipts_required_before_publication']=true;
  return $plan;
 }
 public static function permission(){
  return class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
   ?MAD4B_Company_Portfolio_Manager_V1::admin_permission()
   :new WP_Error('mad4b_company_reconcile_missing_manager',
       'Company Manager is unavailable',['status'=>503]);
 }
 public static function rest($request){
  $company=(string)$request->get_param('company');
  $environment=(string)($request->get_param('environment')?:'production');
  $result=self::inspect($company,$environment);
  if(is_wp_error($result))return $result;
  $response=rest_ensure_response($result);
  if($response instanceof WP_REST_Response)
   $response->header('Cache-Control','private, no-store, max-age=0');
  return $response;
 }
 public static function routes():void {
  if(!class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
    ||!MAD4B_Company_Portfolio_Manager_V1::staging())return;
  register_rest_route('mad4b/v1',
   '/company-management/companies/(?P<company>[a-z][a-z0-9-]{2,47})/reconcile/assessment',[
    'methods'=>'GET','permission_callback'=>[__CLASS__,'permission'],
    'callback'=>[__CLASS__,'rest'],
   ]);
 }
 public static function admin_menu():void {
  if(!class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
    ||!MAD4B_Company_Portfolio_Manager_V1::staging())return;
  add_submenu_page('mad4b-company-brand-readiness',
    'Start Company / Reconcile','Start / Reconcile','manage_options',
    'mad4b-company-reconcile-start',[__CLASS__,'screen']);
 }
 public static function screen():void {
  $allow=self::permission();
  if(is_wp_error($allow))wp_die(esc_html($allow->get_error_message()),403);
  echo '<div class="wrap"><h1>Company Reconcile / Start Company</h1>';
  echo '<p>Source-owned code vs company-owned options. Inspect independent Staging/Production identities and repair plans. '
      .'Local WP-CLI observations are NOT Original 313 signed receipts. This screen never clones or writes.</p>';
  echo '<p><a href="'.esc_url(admin_url('tools.php?page=mad4b-tourism-company-launch')).'">Start Company applications</a></p>';
  echo '<form id="mad4b-reconcile-assess" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">';
  echo '<label for="mad4b-reconcile-code">Company code</label>'
      .'<input id="mad4b-reconcile-code" name="company" pattern="[a-z][a-z0-9-]{2,47}" required'
      .' autocomplete="off" placeholder="company-code" maxlength="47">';
  echo '<label for="mad4b-reconcile-env">Environment</label><select id="mad4b-reconcile-env" name="environment">'
      .'<option value="production">Production</option><option value="staging">Staging</option></select>';
  echo '<button class="button button-primary" type="submit">Inspect exact-site readiness</button></form>';
  echo '<p id="mad4b-reconcile-state" role="status" aria-live="polite">No assessment requested.</p>';
  echo '<div id="mad4b-reconcile-result"></div>';
  echo '<p><strong>Boundary:</strong> No automatic Full Clone, tenant settings overwrite, '
      .'payment activation, company creation or native Grant from this view.</p></div>';
 }
 public static function assets():void {
  if(!is_admin()||!current_user_can('manage_options')
   ||sanitize_key((string)($_GET['page']??''))!=='mad4b-company-reconcile-start')
   return;
  $config=['url'=>rest_url('mad4b/v1/company-management/companies/'),
   'nonce'=>wp_create_nonce('wp_rest')];
  $script='(()=>{const f=document.getElementById("mad4b-reconcile-assess");'
   .'const state=document.getElementById("mad4b-reconcile-state");'
   .'const box=document.getElementById("mad4b-reconcile-result");'
   .'if(!f||!state||!box)return;const cfg='.wp_json_encode($config).';'
   .'f.addEventListener("submit",async e=>{e.preventDefault();box.replaceChildren();'
   .'const code=f.elements.company.value.trim().toLowerCase();'
   .'const env=f.elements.environment.value;'
   .'if(!/^[a-z][a-z0-9-]{2,47}$/.test(code)||!["staging","production"].includes(env))return;'
   .'const button=f.querySelector("button");button.disabled=true;'
   .'state.textContent="Reading exact company readiness (no writes)…";'
   .'try{const url=cfg.url+encodeURIComponent(code)+"/reconcile/assessment?environment="+env;'
   .'const res=await fetch(url,{credentials:"same-origin",cache:"no-store",'
   .'headers:{"X-WP-Nonce":cfg.nonce}});'
   .'if(!res.ok)throw new Error("not_authorized_or_missing");const r=await res.json();'
   .'state.textContent=String(r.state||"UNVERIFIED")+" · "+String(r.next_action||"")'
   .'+" · Signed Original313 receipt: NOT VERIFIED by this assessment";'
   .'const summary=document.createElement("p");'
   .'summary.textContent="Lane: "+String(r.start_company_lane||"?")'
   .'+" · Registered: "+String(r.requested_environment_registered)'
   .'+" · Local WordPress: "+String(r.observed_runtime?.state||"NOT_OBSERVED")'
   .'+" · "+String(r.work_count||0)+" checks · "+String(r.scenario_count||0)+" scenarios"'
   .'+" · "+String(r.depth_audit?.dependency_wave_count||0)+" diagnostic waves"'
   .'+" · "+String(r.depth_audit?.incident_scenarios_without_named_probe||0)+" incident probes undeclared";'
   .'box.appendChild(summary);'
   .'const depth=r.depth_audit||{};'
   .'const evidence=document.createElement("p");'
   .'const lanes=depth.incident_evidence_lane_counts||{};'
   .'evidence.textContent="Incident coverage: "+String(depth.checks_with_incident_playbook||0)'
   .'+" of "+String(depth.policy_checks||0)+" checks have named incident playbooks"'
   .'+" · Local probes "+String(lanes.target_local||0)'
   .'+" · Probe design pending "+String(lanes.target_probe_design_pending||0)'
   .'+" · Original provider "+String(lanes.original_provider||0)'
   .'+" · Before/after digest "+String(lanes.company_pre_post_signed_digest||0)'
   .'+" · No automatic publication approval";box.appendChild(evidence);'
   .'if(depth.dependency_graph_valid && Array.isArray(depth.dependency_waves)){'
   .'const waves=document.createElement("details");'
   .'const ws=document.createElement("summary");ws.textContent="Diagnostic dependency order ("+depth.dependency_wave_count+" waves)";'
   .'waves.appendChild(ws);const ol=document.createElement("ol");'
   .'for(const wave of depth.dependency_waves){const li=document.createElement("li");'
   .'li.textContent=(wave.checks||[]).join(", ");ol.appendChild(li)}'
   .'waves.appendChild(ol);box.appendChild(waves)}'
   .'const table=document.createElement("table");table.className="widefat striped";'
   .'const head=document.createElement("tr");'
   .'for(const x of ["Check","Stage","Ownership","Depends on","Required action","Evidence"]){'
   .'const th=document.createElement("th");th.textContent=x;head.appendChild(th)}table.appendChild(head);'
   .'for(const item of r.work_items||[]){const tr=document.createElement("tr");'
   .'for(const k of ["check","stage","ownership_scope","depends_on","proposed_repair","target_state"]){'
   .'const td=document.createElement("td");td.textContent=Array.isArray(item[k])?item[k].join(", "):String(item[k]||"");tr.appendChild(td)}'
   .'table.appendChild(tr)}box.appendChild(table);'
   .'}catch(err){state.textContent="Assessment unavailable or forbidden. No readiness inferred.";}'
   .'finally{button.disabled=false;}});})();';
  wp_register_script('mad4b-company-reconcile-start',false,[],null,true);
  wp_enqueue_script('mad4b-company-reconcile-start');
  wp_add_inline_script('mad4b-company-reconcile-start',$script);
 }
 public static function boot():void {
  add_action('rest_api_init',[__CLASS__,'routes'],93);
  add_action('admin_menu',[__CLASS__,'admin_menu'],94);
  add_action('admin_enqueue_scripts',[__CLASS__,'assets'],24);
 }
 public static function native_runtime_observation(string $company,array $environments):array {
  $base=[
   'state'=>'NOT_OBSERVED',
   'environment'=>null,'site_uuid'=>null,'observed_utc'=>null,
   'evidence_level'=>'none','original_313_signed'=>false,
   'full_company_reconcile_verified'=>false,'write_authorized'=>false,
  ];
  if(!isset($environments['production'])
     ||!class_exists('MAD4B_Company_Service_Catalog_V1',false)
     ||!MAD4B_Company_Service_Catalog_V1::observation_enabled($company))
     return $base+['reason'=>'no_enrolled_production_observation_pilot'];
  $row=MAD4B_Company_Service_Catalog_V1::native_observation($company);
  if(!is_array($row)
     ||($row['state']??'')!=='fresh_local_wordpress_wpcli_unsigned'
     ||($row['original313_signed_receipt']??true)!==false
     ||($row['company_plan_applied']??true)!==false)
     return $base+['reason'=>$row['reason']??'stale_or_unverified'];
  $expected=$environments['production'];
  if(($row['site_uuid']??'')!==$expected['site_uuid']
     ||($row['site_host']??'')!==wp_parse_url($expected['canonical_origin'],PHP_URL_HOST)
     ||!is_int($row['observed_epoch']??null))
     return $base+['reason'=>'identity_or_observation_envelope_mismatch'];
  $snap=$row['snapshot']??[];
  $options=$snap['related_options_live_status']??[];
  if(!is_array($snap)||!is_array($options)
      ||($options['pricing_configuration']['schema_fields']??0)<1)
      return $base+['reason'=>'company_configuration_readback_missing'];
  return array_replace($base,[
   'state'=>'LOCAL_WORDPRESS_OBSERVED_UNSIGNED',
   'environment'=>'production',
   'site_uuid'=>$expected['site_uuid'],
   'observed_utc'=>$row['observed_utc']??null,
   'evidence_level'=>'bounded_same_vps_wpcli_digest_not_cryptographic_signature',
   'schema_fields'=>$options['pricing_configuration']['schema_fields'],
   'destination_hierarchy_consistent'=>($options['destinations']['hierarchy_and_public_flags_consistent']??false)===true,
   'financial_policy_complete'=>($options['pricing_configuration']['financial_policy_complete']??false)===true,
   'company_values_preserved_by_observer'=>true,
  ]);
 }
 public static function build(string $company,array $registered,array $policy,array $report=[]):array {
  $stages=(array)($policy['stages']??[]);
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)||!$stages)
   return ['contract'=>self::CONTRACT,'state'=>'UNAVAILABLE','authority_granted'=>false];
  // Different environments may legitimately have different Site UUIDs.
  // An old report matches one *exact* environment/origin/UUID, never just
  // any company's UUID and never a user-submitted PASS.
  $uuid=(string)($report['site_uuid']??'');
  $reportedEnv=(string)($report['environment']??'');
  $reportedOrigin=rtrim((string)($report['canonical_origin']??''),'/');
  $bound=false;$matchingEnvironments=[];
  $environmentInventory=[];$invalidTargets=[];
  foreach($registered as $env=>$row){
   if(!is_array($row) || !in_array($env,['staging','production'],true)
    || ($row['company_id']??'')!==$company
    || ($row['environment']??'')!==$env
    || !wp_is_uuid((string)($row['site_uuid']??''))
    || !is_string($row['canonical_origin']??null)
    || !preg_match('~^https://[a-z0-9][a-z0-9.-]*\\.[a-z]{2,}$~iD',$row['canonical_origin'])){
      $invalidTargets[]=(string)$env;continue;
   }
   $environmentInventory[$env]=[
     'environment'=>$env,'site_uuid'=>$row['site_uuid'],
     'canonical_origin'=>$row['canonical_origin'],'identity_record_present'=>true,
     'independent_wpcli_verified'=>false,'native_release_authorized'=>false
   ];
   if($reportedEnv===$env && $reportedOrigin===$row['canonical_origin']
      && $uuid!=='' && hash_equals((string)$row['site_uuid'],$uuid))
     $matchingEnvironments[]=$env;
  }
  $bound=count($matchingEnvironments)===1;
  $hints=(array)($report['stages']??[]);
  $work=[];$seen=[];
  foreach(['bootstrap','functional','presentation','commercial'] as $stage){
   foreach((array)($stages[$stage]??[]) as $check){
    if(!is_string($check)||!preg_match('/^[a-z][a-z0-9_]{1,63}$/D',$check)||isset($seen[$check]))continue;
    $seen[$check]=true;
    $scope=self::scope($stage,$check,$policy);
    $owner=(string)($policy['provider_owners'][$check]??match($scope){
     'shared_definition'=>'Native WordPress / shared provider',
     'mixed'=>'Source definitions + company data owner',
     'site_generated'=>'Target local renderer',
     'tenant_owned'=>'Company content owner',
     'reconnect'=>'Company provider owner',
     'never_clone'=>'Original site authority',
     default=>'Company Reconcile',
    });
    $hint=$hints[$check]??null;
    $hint=is_array($hint)?(string)($hint['state']??'UNKNOWN'):'UNKNOWN';
    if(!in_array($hint,['PASS','HOLD','MISSING','FAILED','DRIFT','UNKNOWN'],true))$hint='UNKNOWN';
    $work[]=[
     'check'=>$check,'stage'=>$stage,'priority'=>$stage==='bootstrap'?'P0':($stage==='commercial'?'P1':'P1'),
     'owner'=>$owner,'ownership_scope'=>$scope,
     'depends_on'=>(array)($policy['check_dependencies'][$check]??[]),
     'dependencies_are_advisory_not_release_authority'=>true,
     'ownership_split_required'=>$scope==='mixed',
     'tenant_values_preserved'=>in_array($scope,
        ['mixed','tenant_owned','reconnect','never_clone'],true),
     'proposed_repair'=>self::action($scope),
     'target_state'=>'INDEPENDENT_READBACK_REQUIRED',
     'existing_stage_hint'=>$bound?$hint:'UNBOUND',
     'stage_hint_is_not_target_proof'=>true,
     'acceptance'=>self::acceptance($check),
     'original_305_312_313_required_for_mutation'=>true,
     'reversible_apply_required'=>true,
     'auto_apply'=>false,
    ];
   }
  }
  // Extend without growing the mandatory gate. These scenario candidates are
  // discovered from policy, not hardcoded execution instructions or grants.
  $scenarios=[];
  foreach(array_slice((array)($policy['incident_scenarios']??[]),0,30) as $candidate){
   if(!is_array($candidate))continue;
   $code=(string)($candidate['code']??'');
   $stageCheck=(string)($candidate['related_check']??'');
   if(!preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$code)||!isset($seen[$stageCheck]))continue;
   $scope=(string)($candidate['ownership_scope']??'');
   if(!in_array($scope,['shared_definition','tenant_owned','site_generated','reconnect','never_clone','inspect_only','company_policy'],true))continue;
   $priority=(string)($candidate['priority']??'P1');
   if(!in_array($priority,['P0','P1','P2'],true))$priority='P1';
   $probe=$candidate['target_probe_check']??null;
   if($probe!==null&&(!is_string($probe)||!preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$probe)))$probe=null;
   $scenarios[]=[
    'code'=>$code,'priority'=>$priority,'related_check'=>$stageCheck,
    'target_probe_check'=>$probe,'ownership_scope'=>$scope,
    'owner'=>substr((string)($candidate['owner']??'Company Reconcile'),0,120),
    'repair_strategy'=>substr((string)($candidate['repair_strategy']??'collect_evidence'),0,160),
    'acceptance'=>substr((string)($candidate['acceptance']??'independent_readback'),0,160),
    'state'=>'DISCOVERY_REQUIRED','auto_apply'=>false,
    'native_execution_authorized'=>false,
   ];
  }
  // Source-plan digest must cover the ownership/acceptance definitions too.
  // A change of provider owner or explicit preservation rules changes the plan.
  $sourceHash=hash('sha256',wp_json_encode([$company,$environmentInventory,$policy['contract']??'',
   $stages,$policy['ownership_rules']??[],$policy['provider_owners']??[],
   $policy['reconcile_ownership_by_check']??[],
   $policy['incident_scenarios']??[],
   $policy['check_dependencies']??[],
   $policy['dependency_policy_kind']??null,
   $policy['evidence_levels']??[],
   $policy['incident_evidence_lanes']??[],
   $policy['repair_provider_discovery_contract']??null,
   $policy['repair_provider_discovery_mode']??null,
   $policy['repair_ambiguous_provider_policy']??null],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  $newCompany=!$environmentInventory;
  $depth=MAD4B_Company_Reconcile_Depth_Audit_V1::evaluate($policy,$work,$scenarios);
  $policyInvalid=($depth['dependency_graph_valid']??false)!==true;
  $runtime=self::native_runtime_observation($company,$environmentInventory);
  $scopeTotals=array_count_values(array_column($work,'ownership_scope'));
  $stageTotals=array_count_values(array_column($work,'stage'));
  $p0=count(array_filter($work,static fn($x)=>($x['priority']??'')==='P0'));
  return [
   'contract'=>self::CONTRACT,
   'state'=>$policyInvalid?'SOURCE_POLICY_INTEGRITY_REVIEW_REQUIRED':
      ($invalidTargets?'IDENTITY_REGISTRY_REVIEW_REQUIRED':
      ($newCompany?'START_COMPANY_TARGET_PROFILE_PENDING':'READY_FOR_EVIDENCE_COLLECTION')),
   'company_code'=>$company,
   'registered_targets'=>$registered,
   'environment_inventory'=>$environmentInventory,
   'invalid_environment_records'=>$invalidTargets,
   'environments_are_independent_site_identities'=>true,
   'start_company_lane'=>$newCompany?'new_company_private_bootstrap':'existing_company_reconcile',
   'new_company_not_implicitly_enrolled'=>$newCompany,
   'legacy_report_bound_to_target'=>$bound,
   'legacy_report_matching_environment'=>$bound?$matchingEnvironments[0]:null,
   'work_plan_sha256'=>$sourceHash,
   'work_count'=>count($work),
   'work_items'=>$work,
   'scenario_count'=>count($scenarios),
   'incident_scenarios'=>$scenarios,
   'depth_audit'=>$depth,
   'depth_audit_is_non_authorizing'=>true,
   'stage_totals'=>$stageTotals,'ownership_totals'=>$scopeTotals,
   'bootstrap_priority_count'=>$p0,
   'observed_runtime'=>$runtime,
   'observed_runtime_not_signed_grant'=>true,
   'live_status_is_separate_from_policy_stage_hints'=>true,
   'all_work_items_still_require_independent_acceptance'=>true,
   'idempotent_noop_requires_exact_postcondition_readback'=>true,
   'next_action'=>$policyInvalid?'REPAIR_SOURCE_DEPENDENCY_POLICY_BEFORE_EXECUTION':
      ($invalidTargets?'VERIFY_ENVIRONMENT_TARGET_ORIGIN_AND_UUID':
      ($newCompany?'ENROLL_EXACT_TARGET_SITE_PROFILE_THEN_RUN_PRIVATE_BOOTSTRAP':
        (($runtime['state']??'')==='LOCAL_WORDPRESS_OBSERVED_UNSIGNED'
          ?'COLLECT_SIGNED_313_RECEIPTS_AND_BROWSER_ACCEPTANCE':
            'COLLECT_FRESH_TARGET_WORDPRESS_AND_ORIGINAL_RECEIPTS'))),
   'scenarios_are_advisory_until_verified'=>true,
   'required_target_probe_contract'=>'mad4b.company-reconcile.target-evidence.v1',
   'target_probe_is_advisory_not_an_original_receipt'=>true,
   'requires_semantic_source_target_diff'=>true,
   'requires_tenant_ownership_preservation'=>true,
   'stale_html_css_index_invalidation_is_target_local'=>true,
   'target_receipt_state'=>'UNVERIFIED',
   'execution_path'=>'Original 305/312/313 or explicitly enrolled native WP-CLI executor',
   'post_apply_readback'=>'separate exact_site manifest + locale/browser acceptance',
   'no_full_clone_of_tenant_owned_data'=>true,
   'authority_granted'=>false,
   'ready_to_publish'=>false,
   'mutation_performed'=>false,
  ];
 }
}
}

require_once __DIR__ . '/class-mad4b-company-repair-jobs.php';
