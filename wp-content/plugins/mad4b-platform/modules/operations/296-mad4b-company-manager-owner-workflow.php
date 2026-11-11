<?php
/**
 * Plugin Name: MAD4B Company Manager Browser Owner Workflow — Staging
 * Description: Owner-browser admin forms for exact company-manager delegation lifecycle.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Manager_Owner_UI_V1 {
 const SLUG='mad4b-company-manager-operations';
 const ACT='mad4b_company_manager_owner_ui';
 const MANAGER='wo-vacation';
 const COMPANY='wayfatours';
 private static function base() {
  return class_exists('MAD4B_Company_Portfolio_Manager_V1') &&
   MAD4B_Company_Portfolio_Manager_V1::staging();
 }
 private static function permit() {
  if(!self::base() || !current_user_can('manage_options') ||
    !current_user_can('activate_plugins') || !is_user_logged_in())
   return new WP_Error('manager_owner_browser_required','Staging owner browser session required',['status'=>403]);
  return true;
 }
 private static function record($code=self::MANAGER) {
  $catalog=MAD4B_Company_Portfolio_Manager_V1::list_managers();
  if(is_wp_error($catalog))return null;
  foreach((array)($catalog['items']??[]) as $r)if(($r['manager_code']??'')===$code)return $r;
  return null;
 }
 private static function assignment($manager_id,$company=self::COMPANY) {
  $v=MAD4B_Company_Portfolio_Manager_V1::status($manager_id,$company);
  return is_wp_error($v)?$v:$v;
 }
 private static function eligible_central_owner() {
  if(is_wp_error(self::permit()) || !class_exists('MAD4B_SCP_Site_Profile'))
   return false;
  if(!MAD4B_SCP_Site_Profile::origin_enrolled() ||
     !MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ||
     MAD4B_SCP_Site_Profile::current_environment()!=='staging')
   return false;
  $allowed=MAD4B_SCP_Site_Profile::oauth_user_ids();
  $uid=get_current_user_id();
  if($uid<1 || count($allowed)!==1 || (int)$allowed[0]!==$uid)
   return false;
  // A real WordPress logged-in browser session is required for this owner UI.
  // WP-CLI --user=1 must not be able to mint the user's confirmation.
  if(!defined('LOGGED_IN_COOKIE') || !isset($_COOKIE[LOGGED_IN_COOKIE]) ||
     (int)wp_validate_auth_cookie((string)$_COOKIE[LOGGED_IN_COOKIE],'logged_in')!==$uid)
   return false;
  if(!function_exists('wp_get_session_token') || wp_get_session_token()==='')
   return false;
  return true;
 }
 private static function route($args=[]) {
  return add_query_arg(array_merge(['page'=>self::SLUG],$args),admin_url('admin.php'));
 }
 private static function form_start($name,$values=[]) {
  echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post" style="margin:10px 0;padding:12px;border:1px solid #c3c4c7;max-width:850px">';
  wp_nonce_field('mad4b_manager_owner_'.$name);
  echo '<input type="hidden" name="action" value="'.esc_attr(self::ACT).'">';
  echo '<input type="hidden" name="transition" value="'.esc_attr($name).'">';
  foreach($values as $k=>$v) echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr((string)$v).'">';
 }
 private static function form_end($text) {
  submit_button($text,'primary','submit',false);
  echo '</form>';
 }
 public static function screen() {
  $p=self::permit();if(is_wp_error($p))wp_die(esc_html($p->get_error_message()),403);
  echo '<div class="wrap"><h1>Wo Vacation — Company Manager Lifecycle</h1>';
  echo '<p>Central Staging only. Manager identity is independent of Brand Context. No company assets, WordPress roles, remote writes or publishing rights transfer through this workflow.</p>';
  $msg=sanitize_key((string)($_GET['result']??''));
  if($msg!=='')echo '<div class="notice notice-info"><p>'.esc_html($msg).'</p></div>';
  $manager=self::record();
  if(!$manager){
   echo '<h2>Step 1: Register Manager</h2>';
   echo '<p>Register <strong>Wo Vacation</strong> as an independent manager identity, referencing the existing Brand Context only for identity evidence.</p>';
   self::form_start('create');self::form_end('Register Wo Vacation Manager');
   echo '</div>';return;
  }
  $id=$manager['manager_id'];
  echo '<p><strong>Manager:</strong> '.esc_html($manager['display_name']).' · <code>'.esc_html($id).'</code></p>';
  $bound=MAD4B_Company_Portfolio_Manager_V1::company_binding(self::COMPANY);
  if(is_wp_error($bound)){
   $cutover=class_exists('MAD4B_Company_Site_Replacement_V1',false)
    ? MAD4B_Company_Site_Replacement_V1::stage_state(self::COMPANY) : [];
   $known=!empty($cutover['new_site_uuid']) && wp_is_uuid((string)$cutover['new_site_uuid']);
   echo '<div class="notice notice-warning inline"><p><strong>Company Site Cutover:</strong> '.esc_html($known?($cutover['state']??'PENDING'):'REGISTRY_BINDING_REQUIRED').'.</p>';
   if($known)echo '<p>Replacement Site UUID: <code>'.esc_html($cutover['new_site_uuid']).'</code>. The new identity is visible; native 305 and the independent manager assignment remain pending. No old delegation is transferred.</p>';
   echo '<p>The original Site Profile and Company Registry must match before a new delegation can be proposed.</p></div></div>';return;
  }
  echo '<h2>Step 2: Verified Company Delegation</h2><p>Wayfatours · Company Site UUID: <code>'.esc_html($bound['company_site_uuid']).'</code></p>';
  $row=self::assignment($id);
  if(is_wp_error($row)){echo '<p>'.esc_html($row->get_error_message()).'</p></div>';return;}
  $state=$row['state']??'not_assigned';$rev=(int)($row['revision']??0);
  $historical=$row['prior_site_assignment_history']??null;
  if(is_array($historical) && wp_is_uuid((string)($historical['site_uuid']??''))){
   echo '<div class="notice notice-info inline"><p><strong>Historical site delegation:</strong> <code>'.esc_html((string)$historical['site_uuid']).'</code> · prior state: '.esc_html((string)($historical['state']??'unknown')).' · previous revision '.(int)($historical['revision']??0).'. It is retained for audit and confers no rights on this site.</p></div>';
  }
  $legacy=isset($row['company_site_uuid']) && (
   ($row['company_site_uuid']??'')!==($bound['company_site_uuid']??'')
   || ($row['binding_current']??true)===false);
  if($legacy){
   echo '<div class="notice notice-warning inline"><p><strong>Old-site delegation is inactive for this replacement.</strong> Previous Site UUID: <code>'.esc_html((string)$row['company_site_uuid']).'</code>; previous state: '.esc_html($state).' (revision '.(int)$rev.').</p><p>To delegate access to the new company site, submit a new proposal with new rights and an independent owner approval. Nothing transfers automatically.</p></div>';
   $state='not_assigned';
  }
  echo '<p><strong>Current delegation flow:</strong> '.esc_html($state).' · Revision '.(int)$rev.'</p>';
  $values=['manager_id'=>$id,'company_code'=>self::COMPANY,'expected_revision'=>$rev];
  if(in_array($state,['not_assigned','revoked'],true)){
   self::form_start('propose',$values);
   echo '<p><label><input type="checkbox" name="rights[]" value="view_company" checked disabled> View company (always included)</label></p>';
   foreach(['strategy_read'=>'Read Company Strategy','operations_read'=>'Read Operations','strategy_manage'=>'Manage Strategy','operations_manage'=>'Manage Operations','content_manage'=>'Manage Content','commerce_manage'=>'Manage Commerce'] as $k=>$label)
    echo '<p><label><input type="checkbox" name="rights[]" value="'.esc_attr($k).'" '.checked($k,'strategy_read',false).'> '.esc_html($label).'</label></p>';
   echo '<p><label>Owner evidence/reason <input type="text" size="65" maxlength="1200" name="reason" required placeholder="Exact business rationale for delegated company management"></label></p>';
   self::form_end('Propose Delegation');
  } else {
   echo '<p>Requested permissions: '.esc_html(implode(', ',(array)($row['rights']??[]))).'</p>';
   echo '<p>Proposal SHA-256: <code>'.esc_html($row['proposal_sha256']??'').'</code></p>';
   $actions=['proposed'=>['approve'=>'Approve Exact Proposal','revoke'=>'Revoke Proposal'],
     'approved'=>['activate'=>'Activate Approved Delegation','revoke'=>'Revoke Approved Delegation'],
     'active'=>['suspend'=>'Suspend Delegation','revoke'=>'Revoke Active Delegation'],
     'suspended'=>['revoke'=>'Revoke Suspended Delegation']];
   foreach($actions[$state]??[] as $action=>$label){
    $vals=$values;if($action==='approve')$vals['expected_proposal_sha256']=$row['proposal_sha256']??'';
    self::form_start($action,$vals);self::form_end($label);
   }
  }
  echo '<h2>Step 3: Central Administrator Membership</h2>';
  $owner=get_current_user_id();
  $member_key=$id.'|'.$owner;
  $all_members=get_option(MAD4B_Company_Portfolio_Manager_V1::MEMBERS,[]);
  $member=is_array($all_members)?($all_members[$member_key]??null):null;
  if(is_array($member)&&($member['state']??'')==='active'){
   echo '<p><strong>Manager membership:</strong> active · Revision '.(int)$member['revision'].'</p>';
   echo '<p>Wayfatours delegation for this account: '.(MAD4B_Company_Portfolio_Manager_V1::has_delegation($id,self::COMPANY,'view_company',$owner)?'ACTIVE':'DENIED').'</p>';
  } elseif($state==='active' && self::eligible_central_owner()){
   echo '<p>Enroll the currently signed-in, Site Profile-authorized Administrator as a Wo Vacation manager member. No new WordPress role, remote credential, or provider write authority is issued.</p>';
   self::form_start('member_enroll',[
    'manager_id'=>$id,'company_code'=>self::COMPANY,
    'expected_revision'=>(int)($member['revision']??0),
    'owner_user_id'=>$owner]);
   self::form_end('Enroll Current Administrator');
  } else {
   echo '<p>Membership not active. A logged-in, enrolled Central Administrator session and active Wayfatours delegation are required.</p>';
  }
  echo '<h2>Automatic Write Readiness</h2>';
  echo '<p>Company write rights derive automatically from the active assignment and enrolled manager user. The company uses its enrolled environment (including Production when it is the only one). The native provider must independently authorize exact company writes and verify the result.</p>';
  if(class_exists('MAD4B_Manager_Auto_Write_V1')){
   echo '<table class="widefat striped" style="max-width:880px"><thead><tr><th>Scope</th><th>Automatic entitlement</th><th>Native remote execution</th></tr></thead><tbody>';
   foreach(['content.write','strategy.write','operations.write','commerce.write'] as $intent){
    $check=MAD4B_Manager_Auto_Write_V1::entitlement($id,self::COMPANY,$intent,get_current_user_id());
    $scope=!empty($check['assignment_authorized'])?'ELIGIBLE':'DENIED';
    $remote=!empty($check['remote_write_ready'])?'READY':'BLOCKED';
    $env=(string)($check['execution_target_environment']??'unregistered');
    echo '<tr><td>'.esc_html($intent).' · '.esc_html($env).'</td><td>'.esc_html($scope).'</td><td>'.esc_html($remote);
    if(empty($check['remote_write_ready'])&&!empty($check['blockers']))
     echo ' · '.esc_html(implode(', ',array_slice($check['blockers'],0,3)));
    echo '</td></tr>';
   }
   echo '</tbody></table>';
  }else echo '<p>Automatic write policy is not loaded.</p>';
  echo '<h2>Governed Operation Admission</h2>';
  echo '<p>Read-only inspection is admitted independently from remote write execution. A delegated write is accepted by policy once the manager is active, then waits for an exact native executor.</p>';
  if(class_exists('MAD4B_Company_Operation_Admission_V1')){
   echo '<table class="widefat striped" style="max-width:880px"><thead><tr><th>Operation</th><th>Admission state</th></tr></thead><tbody>';
   foreach(['identity.inspect','environment.inspect','capability.inspect','provider.inspect','strategy.read','content.write','strategy.write','operations.write','commerce.write'] as $operation){
    $admission=MAD4B_Company_Operation_Admission_V1::assess(self::COMPANY,$operation);
    $state=is_wp_error($admission)?$admission->get_error_code():($admission['state']??'UNKNOWN');
    echo '<tr><td>'.esc_html($operation).'</td><td>'.esc_html($state).'</td></tr>';
   }
   echo '</tbody></table>';
  }
  $cutover=class_exists('MAD4B_Company_Site_Replacement_V1',false)
    ? MAD4B_Company_Site_Replacement_V1::stage_state(self::COMPANY) : [];
  if(in_array(($cutover['state']??''),['PENDING_NATIVE_305','PENDING_RECONCILE'],true)){
    echo '<h2>New Site Native Enrollment</h2><p>The new Site Profile is enrolled. Native 305, executor authority and publication require independent authorization and Reconcile acceptance. The legacy Bootstrap grant cannot be renewed as a grant for the replacement site.</p>';
    echo '<p><strong>Cutover state:</strong> '.esc_html($cutover['state']).'</p>';
  } else self::bootstrap_screen($id);
  echo '<h2>Access Verification</h2><p>Activating a company assignment alone grants no remote WordPress access: a separately enrolled manager user and each downstream provider authority are required.</p>';
  echo '</div>';
 }
 private static function bootstrap_screen($manager_id) {
  echo '<hr><section id="mad4b-wayfa-bootstrap"><h2>New-Site Bootstrap Authorization</h2>';
  echo '<p>Original Central issuer for the isolated Wayfa Tours rebuild. This page issues or revokes a site-bound grant only. It does not install WordPress, migrate content, or change DNS.</p>';
  if (!class_exists('MAD4B_Original_New_Site_Bootstrap_Issuer_V1', false)
      || !class_exists('MAD4B_Company_Environment_Registry_V2', false)) {
    echo '<div class="notice notice-error inline"><p>Original Bootstrap Issuer or Company Registry is unavailable.</p></div></section>';
    return;
  }
  $parent=MAD4B_Company_Environment_Registry_V2::target(self::COMPANY,'production');
  if (!is_array($parent) || ($parent['company_id']??'')!==self::COMPANY
      || ($parent['environment']??'')!=='production'
      || !wp_is_uuid((string)($parent['site_uuid']??''))
      || !preg_match('~^https://[a-z0-9.-]+$~D',(string)($parent['canonical_origin']??''))) {
    echo '<div class="notice notice-error inline"><p>Exact Original parent registry binding is not valid.</p></div></section>';
    return;
  }
  $target=array(
    'company'=>self::COMPANY,'environment'=>'production',
    'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
    'canonical_origin'=>'https://wayfatours.com',
    'container'=>'wayfa-rebuild-php','database'=>'wayfa_rebuild_wp',
    'parent_site_uuid'=>(string)$parent['site_uuid'],
    'parent_origin'=>(string)$parent['canonical_origin'],
  );
  $grant=MAD4B_Original_New_Site_Bootstrap_Issuer_V1::status(self::COMPANY,'production');
  $status=(string)($grant['state']??'UNKNOWN');
  echo '<table class="widefat striped" style="max-width:920px"><tbody>';
  foreach (array(
    'Company'=>self::COMPANY,
    'Target environment'=>'production',
    'New Site UUID'=>$target['site_uuid'],
    'New canonical origin'=>$target['canonical_origin'],
    'Target container'=>$target['container'],
    'Target database'=>$target['database'],
    'Original parent UUID'=>$target['parent_site_uuid'],
    'Original parent origin'=>$target['parent_origin'],
    'Issuer state'=>$status,
  ) as $label=>$value) {
    echo '<tr><th scope="row" style="width:230px">'.esc_html($label).'</th><td><code>'.esc_html($value).'</code></td></tr>';
  }
  echo '</tbody></table>';
  $actor=self::eligible_central_owner()
     && MAD4B_Company_Portfolio_Manager_V1::has_delegation(
       (string)$manager_id,self::COMPANY,'operations_manage',get_current_user_id());
  if (!$actor) {
    echo '<div class="notice notice-warning inline"><p>To issue or revoke: sign in using the enrolled Central Administrator browser session with active Wo Vacation operations_manage delegation. WP-CLI is read-only.</p></div></section>';
    return;
  }
  $url=rest_url('mad4b-central/v1/companies/'.self::COMPANY.'/new-site-bootstrap');
  $config=array(
    'endpoint'=>$url,
    'proofEndpoint'=>rest_url('mad4b-central/v1/companies/'.self::COMPANY.'/new-site-bootstrap/prepare-proof'),
    'nonce'=>wp_create_nonce('wp_rest'),
    'target'=>$target,
  );
  echo '<div style="max-width:920px;margin-top:15px;padding:16px;border:1px solid #c3c4c7;background:#fff">';
  echo '<p><strong>Current issuer readback:</strong> <code id="mad4b-bootstrap-status">'.esc_html($status).'</code></p>';
  echo '<p id="mad4b-bootstrap-detail" aria-live="polite">Check current state before issuing a grant.</p>';
  echo '<p><button type="button" class="button" id="mad4b-bootstrap-refresh">Refresh issuer status</button></p>';
  echo '<hr>';
  echo '<h3>Issue or renew new-site grant</h3>';
  echo '<p>Owner-approved Grant lifetime: at least 2 hours. Renewal is available after expiry or in the last 10 minutes, with a fresh signed host proof. Background host checks cannot impersonate the owner.</p>';
  echo '<p>The VPS Broker generates and signs the exact host proof automatically after you confirm the target identity. You do not need to paste any JSON or private key.</p>';
  echo '<textarea id="mad4b-bootstrap-host-proof" hidden aria-hidden="true" tabindex="-1"></textarea>';
  echo '<p id="mad4b-bootstrap-proof-state" aria-live="polite">Host proof will be prepared automatically.</p>';
  echo '<p><label><input type="checkbox" id="mad4b-bootstrap-keep-parent"> I confirm the exact new site identity above, and the original Wayfa site must remain operational.</label></p>';
  echo '<p><button type="button" class="button button-primary" id="mad4b-bootstrap-issue" disabled>Prepare Host Proof &amp; Issue / Renew Grant</button></p>';
  echo '<hr><h3>Revoke grant</h3>';
  echo '<p>Revocation requires the current exact grant digest. Revoking prevents this grant from authorizing any further operation and does not remove WordPress content.</p>';
  echo '<p><label for="mad4b-bootstrap-revoke-confirm">Type REVOKE to confirm</label> ';
  echo '<input type="text" id="mad4b-bootstrap-revoke-confirm" maxlength="6" autocomplete="off"></p>';
  echo '<p><button type="button" class="button" id="mad4b-bootstrap-revoke" disabled>Revoke current grant</button></p>';
  echo '<p id="mad4b-bootstrap-result" role="status" aria-live="polite"></p></div></section>';
  $javascript=<<<'JS'
(function() {
  'use strict';
  const cfg=window.mad4bWayfaBootstrapConfig;
  const byId=(id)=>document.getElementById('mad4b-bootstrap-'+id);
  const proofBox=byId('host-proof');
  const proofState=byId('proof-state');
  const issue=byId('issue');
  const revoke=byId('revoke');
  const keep=byId('keep-parent');
  const confirmBox=byId('revoke-confirm');
  const result=byId('result');
  const status=byId('status');
  const detail=byId('detail');
  let current=null, working=false;
  const required=['contract','company','environment','site_uuid','canonical_origin',
    'container','database','parent_site_uuid','parent_origin','issued','expires','nonce','signature'];
  function parseProof() {
    let p;
    try { p=JSON.parse(proofBox.value); } catch(e) { return null; }
    if (!p || Array.isArray(p) || typeof p!=='object' ||
        required.length!==Object.keys(p).length ||
        required.some(k=>!Object.prototype.hasOwnProperty.call(p,k)))return null;
    if (p.contract!=='mad4b.new-site-host-bootstrap-proof.v1' ||
      ['company','environment','site_uuid','canonical_origin','container','database','parent_site_uuid','parent_origin']
        .some(k=>p[k]!==cfg.target[k]) ||
      !Number.isInteger(p.issued) || !Number.isInteger(p.expires) ||
      p.expires<=Date.now()/1000+2 || p.expires>p.issued+60 ||
      typeof p.signature!=='string' || !p.signature)return null;
    return p;
  }
  function controls() {
    proofState.textContent=working ?
      'Preparing or validating signed host evidence from VPS...' :
      'VPS generates the proof automatically; no JSON or SSH command is needed.';
    const eligible=current && (
      current.state==='NOT_ISSUED' ||
      (current.renewal_eligible===true &&
        (current.state==='DENIED_OR_EXPIRED' ||
         current.state==='ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED')));
    issue.disabled=working || !keep.checked || !eligible;
    revoke.disabled=working || !current ||
      current.state!=='ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED' ||
      !/^[a-f0-9]{64}$/.test(current.grant_digest||'') ||
      confirmBox.value!=='REVOKE';
  }
  function message(s) { result.textContent=s; }
  async function call(method,body) {
    const url=method==='GET' ?
      cfg.endpoint+'?environment='+encodeURIComponent(cfg.target.environment) :
      cfg.endpoint;
    const res=await fetch(url,{
      method:method,credentials:'same-origin',cache:'no-store',
      headers:{'X-WP-Nonce':cfg.nonce,'Content-Type':'application/json'},
      body:body?JSON.stringify(body):undefined});
    const data=await res.json();
    if(!res.ok)throw new Error(data.code || ('HTTP '+res.status));
    return data;
  }
  async function callProof(method,body,requestId) {
    const url=cfg.proofEndpoint+(requestId?'?request_id='+encodeURIComponent(requestId):'');
    const response=await fetch(url,{
      method,credentials:'same-origin',cache:'no-store',
      headers:{'X-WP-Nonce':cfg.nonce,'Content-Type':'application/json'},
      body:body?JSON.stringify(body):undefined});
    const data=await response.json();
    if(!response.ok)throw new Error(data.code||('HTTP '+response.status));
    return data;
  }
  async function prepareSignedProof() {
    const t=cfg.target;
    const started=await callProof('POST',{
      operation:'new-site.wordpress.bootstrap',
      confirm_keep_parent:true,site_uuid:t.site_uuid,canonical_origin:t.canonical_origin
    });
    if(started.state!=='PENDING' || !/^[a-f0-9]{32}$/.test(started.request_id||''))
      throw new Error('ORIGINAL_HOST_PROOF_REQUEST_NOT_ADMITTED');
    for(let attempt=0;attempt<45;attempt++){
      message('VPS is validating and signing host evidence... attempt '+(attempt+1));
      const r=await callProof('GET',null,started.request_id);
      if(r.state==='SIGNED'){
        proofBox.value=JSON.stringify(r.proof);
        const proof=parseProof();
        if(!proof)throw new Error('SIGNED_HOST_PROOF_SCOPE_OR_EXPIRY_INVALID');
        return proof;
      }
      if(r.state==='FAILED')throw new Error(r.reason_code||'VPS_SIGNING_UNAVAILABLE');
      if(r.state!=='PENDING')throw new Error('HOST_PROOF_READBACK_INVALID');
      await new Promise(resolve=>setTimeout(resolve,1500));
    }
    throw new Error('VPS_HOST_PROOF_TIMEOUT_RETRY');
  }
  async function refresh() {
    working=true;controls();
    try {
      current=await call('GET');
      status.textContent=current.state||'UNKNOWN';
      detail.textContent='Grant present: '+String(current.grant_present===true)+
        ' | Revision: '+String(current.revision||0)+
        ' | Lifetime: '+String(current.grant_lifetime_seconds||7200)+' seconds'+
        ' | Owner renewal eligible: '+String(current.renewal_eligible===true)+
        (current.expires_epoch||current.previous_expires_epoch?
          ' | Grant expires / expired: '+new Date(
            (current.expires_epoch||current.previous_expires_epoch)*1000).toLocaleString():'');
      if(current.state==='DENIED_OR_EXPIRED' && current.renewal_eligible===true){
        message('Grant expired. Owner-authorized renewal is ready. Confirm the original site and click Issue / Renew Grant.');
      } else if(current.state==='ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED'
          && current.renewal_eligible===true){
        message('Grant will expire within 10 minutes. Renew with fresh host proof before execution stops.');
      } else {
        message('Issuer state refreshed from Original Core.');
      }
    } catch(e) {
      current=null;status.textContent='READBACK_FAILED';
      detail.textContent='No cached authorization is accepted.';
      message('Readback failed: '+e.message);
    } finally { working=false;controls(); }
  }
  byId('refresh').addEventListener('click',refresh);
  keep.addEventListener('change',controls);
  confirmBox.addEventListener('input',controls);
  issue.addEventListener('click',async function() {
    if(!current || !keep.checked || !(
      current.state==='NOT_ISSUED' ||
      (current.renewal_eligible===true && (
        current.state==='DENIED_OR_EXPIRED' ||
        current.state==='ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED'))))return;
    working=true;controls();message('Requesting signed host evidence from VPS...');
    try {
      const proof=await prepareSignedProof();
      message('Signed host evidence received. Submitting to Original Issuer...');
      const t=cfg.target;
      const body={environment:t.environment,site_uuid:t.site_uuid,
        canonical_origin:t.canonical_origin,container:t.container,database:t.database,
        parent_site_uuid:t.parent_site_uuid,bootstrap_mode:'isolated_candidate_only',
        keep_parent_operational:true,operation:'new-site.wordpress.bootstrap',
        expected_revision:current.revision||0,host_evidence:proof};
      if((current.revision||0)>0){
        if(!/^[a-f0-9]{64}$/.test(current.previous_grant_digest||'')){
          throw new Error('PREVIOUS_GRANT_DIGEST_UNAVAILABLE');
        }
        body.renew_previous_digest=current.previous_grant_digest;
      }
      const response=await call('POST',body);
      message('Original Issuer response: '+(response.state||'UNKNOWN')+
        '. Readback follows. This does not install WordPress.');
      proofBox.value='';keep.checked=false;
      await refresh();
      message('Grant issued and read back: '+(current?current.state:'UNKNOWN')+
       '. Native WordPress installation is separate.');
    } catch(e) {
      proofBox.value='';
      // Re-read original issuer; never assume a failed or timed-out POST is safe to replay.
      await refresh();
      message('Grant not confirmed: '+e.message+'. Current issuer state: '+
        (current?current.state:'READBACK_FAILED'));
    }
    finally { working=false;controls(); }
  });
  revoke.addEventListener('click',async function() {
    if(!current || current.state!=='ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED' ||
       confirmBox.value!=='REVOKE' || !/^[a-f0-9]{64}$/.test(current.grant_digest||''))return;
    if(!window.confirm('Revoke the exact Wayfa new-site Bootstrap Grant?'))return;
    working=true;controls();
    try {
      await call('DELETE',{environment:cfg.target.environment,
        operation:'new-site.wordpress.bootstrap',
        expected_state:'ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED',
        grant_digest:current.grant_digest});
      confirmBox.value='';
      await refresh();
      message('Revocation submitted. Original issuer readback: '+(current?current.state:'unavailable'));
    } catch(e) { message('Revocation not confirmed: '+e.message);await refresh(); }
    finally { working=false;controls(); }
  });
  controls();
  refresh();
  window.setInterval(function(){
    if(!working && document.visibilityState==='visible')refresh();
  },60000);
})();
JS;
  echo '<script>window.mad4bWayfaBootstrapConfig='.
    wp_json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).
    ";</script>\n<script>".$javascript."</script>";
 }
 public static function post() {
  $p=self::permit();if(is_wp_error($p))wp_die(esc_html($p->get_error_message()),403);
  $action=sanitize_key((string)wp_unslash($_POST['transition']??''));
  if(!in_array($action,['create','propose','approve','activate','suspend','revoke','member_enroll'],true))
   wp_die('Unsupported company manager owner operation',422);
  check_admin_referer('mad4b_manager_owner_'.$action);
  if($action==='create'){
   if(self::record())wp_die('Wo Vacation manager is already registered',409);
   if(!class_exists('MAD4B_SCP_Context_Authority'))wp_die('Brand reference authority unavailable',503);
   $profile=MAD4B_SCP_Context_Authority::profile();
   if(!is_array($profile)||($profile['brand_name']??'')!=='Wo Vacation'||
     !preg_match('/^[a-f0-9]{32}$/D',(string)($profile['brand_id']??''))||
     !wp_is_uuid((string)($profile['site_uuid']??'')))
     wp_die('Exact Wo Vacation identity reference not available',409);
   $result=MAD4B_Company_Portfolio_Manager_V1::create_manager([
     'manager_code'=>self::MANAGER,'display_name'=>'Wo Vacation',
     'reference_brand_id'=>$profile['brand_id'],'reference_site_uuid'=>$profile['site_uuid']]);
  } else {
   $mgr=self::record();
   if(!$mgr)wp_die('Manager record missing',404);
   $id=(string)wp_unslash($_POST['manager_id']??'');
   $code=sanitize_key((string)wp_unslash($_POST['company_code']??''));
   if($id!==$mgr['manager_id']||$code!==self::COMPANY)wp_die('Exact Manager/Company scope mismatch',403);
   $expected=filter_var(wp_unslash($_POST['expected_revision']??''),FILTER_VALIDATE_INT);
   if($expected===false || $expected<0)wp_die('Exact revision required',422);
   if($action==='member_enroll'){
    if(!self::eligible_central_owner() || (int)($_POST['owner_user_id']??0)!==get_current_user_id())
     wp_die('Exact authenticated Central Administrator browser session required',403);
    $assignment=self::assignment($id,$code);
    if(is_wp_error($assignment)||($assignment['state']??'')!=='active' ||
      ($assignment['binding_current']??true)===false)
     wp_die('Active, current Company Delegation required',409);
    $result=MAD4B_Company_Portfolio_Manager_V1::member_action([
     'manager_id'=>$id,'user_id'=>get_current_user_id(),
     'expected_revision'=>$expected,'action'=>'enroll']);
    if(!is_wp_error($result) &&
       (!is_array($result) || ($result['state']??'')!=='active' ||
        !MAD4B_Company_Portfolio_Manager_V1::has_delegation(
         $id,$code,'view_company',get_current_user_id())))
     $result=new WP_Error('mad4b_manager_member_readback_failed','Manager membership could not be verified',['status'=>503]);
   } elseif($action==='propose'){
    $raw=$_POST['rights']??[];
    if(!is_array($raw))wp_die('Rights array required',422);
    $rights=array_map('sanitize_key',array_map('wp_unslash',$raw));
    array_unshift($rights,'view_company');
    $result=MAD4B_Company_Portfolio_Manager_V1::propose([
     'manager_id'=>$id,'company_code'=>$code,'expected_revision'=>$expected,
     'rights'=>array_values(array_unique($rights)),
     'reason'=>sanitize_textarea_field(wp_unslash((string)($_POST['reason']??'')))]);
   } else {
    $result=MAD4B_Company_Portfolio_Manager_V1::change_assignment([
     'manager_id'=>$id,'company_code'=>$code,'expected_revision'=>$expected,
     'action'=>$action,
     'expected_proposal_sha256'=>sanitize_text_field(wp_unslash((string)($_POST['expected_proposal_sha256']??'')))]);
   }
  }
  $state=is_wp_error($result)?$result->get_error_code():'owner_action_'.$action.'_readback_saved';
  wp_safe_redirect(self::route(['result'=>$state]));exit;
 }
 public static function menu() {
  if(!self::base())return;
  add_submenu_page('mad4b-company-brand-readiness',
   'Wo Vacation Company Manager','Wo Vacation Manager','manage_options',self::SLUG,[__CLASS__,'screen']);
 }
 public static function boot(){
  add_action('admin_menu',[__CLASS__,'menu'],95);
  add_action('admin_post_'.self::ACT,[__CLASS__,'post']);
 }
}
MAD4B_Manager_Owner_UI_V1::boot();
$mad4b_reconcile_ui=__DIR__.'/296b-mad4b-company-reconcile-owner-actions-v1.php';
if(is_readable($mad4b_reconcile_ui))require_once $mad4b_reconcile_ui;

