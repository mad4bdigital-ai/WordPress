<?php
/**
 * Plugin Name: MAD4B Remote Operations & Authorization
 * Description: Owner-facing readback and exact Original305/306 operation workflow, no parallel signing authority.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Remote_Operations_Admin_V145 {
    const API = 'mad4b-remote-operations/v1';
    const PAGE = 'mad4b-remote-operations';
    const CONTRACT = 'mad4b.remote-operations-owner-console.v145';
    const GRANT_PREFIX = 'mad4b_central_ssh_grant_v1_';
    const TRANSPORT_PREFIX = 'mad4b_central_ssh_transport_v1_';
    const REQUEST_PREFIX = 'mad4b_central_ssh_request_v1_';
    const BROKER_PREFIX = 'mad4b_central_broker_job_v1_';
    const REGISTRY = 'mad4b_company_environment_registry_v2';
    const EXTERNAL_READBACK = 'mad4b_remote_external_host_readonly_v146';
    const EXTERNAL_CONTRACT = 'mad4b.host-v146.external-host-exact-site-ssh-readback.v1';
    const EXTERNAL_COMPANY_HOSTS = array(
        'tourism-almallahgroup'=>'hosting-1',
        'all-royal-egypt'=>'hosting-2',
        'egypt-tour-gates'=>'hosting-3',
    );

    public static function boot(): void {
        add_action('admin_menu', array(__CLASS__, 'menu'), 95);
        add_action('rest_api_init', array(__CLASS__, 'routes'), 98);
    }
    public static function authorized(): bool {
        return is_user_logged_in()
            && current_user_can('manage_options')
            && class_exists('MAD4B_Central_Dual_Lane_Registry_V1', false)
            && MAD4B_Central_Dual_Lane_Registry_V1::allowed()
            && class_exists('MAD4B_Host_V3_Owner_Console', false)
            && MAD4B_Host_V3_Owner_Console::site_binding_valid();
    }
    public static function menu(): void {
        if (!self::authorized()) return;
        $parent = isset($GLOBALS['admin_page_hooks']['mad4b-control-plane'])
            ? 'mad4b-control-plane' : 'tools.php';
        add_submenu_page($parent, 'Remote Operations & Authorization',
            'Remote Operations', 'manage_options', self::PAGE, array(__CLASS__, 'render'));
    }
    public static function routes(): void {
        register_rest_route(self::API, '/overview', array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'authorized'),
            'callback' => static fn($request) => rest_ensure_response(self::overview()),
        ));
    }
    private static function verified_companies(): array {
        $registry = get_option(self::REGISTRY, array());
        $companies = array();
        if (!is_array($registry) || !is_array($registry['companies'] ?? null)) return array();
        foreach ($registry['companies'] as $name => $details) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9-]{2,47}$/D', $name)
                || !is_array($details) || !is_array($details['environments'] ?? null)) continue;
            $envs = array();
            foreach ($details['environments'] as $env => $scope) {
                if (!in_array($env, array('staging','production','development'), true)
                    || !is_array($scope)) continue;
                $uuid = (string)($scope['site_uuid'] ?? '');
                $origin = (string)($scope['canonical_origin'] ?? '');
                if (!wp_is_uuid($uuid)
                    || !preg_match('~^https://[a-z0-9.-]+(?::[0-9]{1,5})?$~D', $origin)) continue;
                $envs[$env] = array('site_uuid' => $uuid, 'origin' => $origin);
            }
            if ($envs) $companies[$name] = $envs;
        }
        return $companies;
    }
    /** A separate staging company identity can never be inferred from an SSH host. */
    public static function project_record(string $kind, array $row, array $companies): ?array {
        $company = $row['company'] ?? '';
        $env = $row['environment'] ?? '';
        if (!is_string($company) || !is_string($env) || !isset($companies[$company][$env]))
            return null;
        $uuid = $companies[$company][$env]['site_uuid'];
        if (!is_string($row['site_uuid'] ?? null)
            || !hash_equals($uuid, $row['site_uuid'])) return null;
        $idField = array('grant'=>'grant_id','transport'=>'transport_id',
            'request'=>'request_id','broker'=>'job_id')[$kind] ?? null;
        if (!$idField || !is_string($row[$idField] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D', $row[$idField])) return null;

        $base = array(
            'id' => $row[$idField],
            'company' => $company,
            'environment' => $env,
            'site_uuid' => $uuid,
            'state' => substr((string)($row['state'] ?? $row['status'] ?? 'UNKNOWN'), 0, 90),
            'host' => substr((string)($row['target_host_id'] ?? $row['host_id'] ?? ''), 0, 80),
            'principal' => substr((string)($row['target_ssh_principal'] ?? $row['host_principal'] ?? ''), 0, 40),
            'operation' => substr((string)($row['operation'] ?? ''), 0, 100),
            'actor_id' => (int)($row['central_principal_id'] ?? 0),
            'expires_epoch' => isset($row['expires_epoch']) ? (int)$row['expires_epoch'] : null,
            'write_authorized' => false,
        );
        if ($kind === 'grant') {
            $base['mode'] = in_array($row['grant_mode'] ?? '', array('persistent','one_time'), true)
                ? $row['grant_mode'] : 'unknown';
            $base['transport_id'] = preg_match('/^[a-f0-9]{32}$/D',
                (string)($row['transport_id'] ?? '')) ? $row['transport_id'] : null;
            $base['independent_host_authority_verified'] =
                ($row['host_ssh_authority_verified'] ?? false) === true;
        } elseif ($kind === 'transport') {
            $base['mode'] = 'registered_ssh';
        } elseif ($kind === 'request') {
            $base['grant_id'] = preg_match('/^[a-f0-9]{32}$/D',
                (string)($row['grant_id'] ?? '')) ? $row['grant_id'] : null;
            $base['dispatch_candidate'] =
                $base['state'] === 'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
                && (int)($base['expires_epoch'] ?? 0) > time()
                && ($row['execution_performed'] ?? true) === false;
        } elseif ($kind === 'broker') {
            $base['request_id'] = preg_match('/^[a-f0-9]{32}$/D',
                (string)($row['request_id'] ?? '')) ? $row['request_id'] : null;
            $base['independent_readback_verified'] =
                ($row['independent_readback_verified'] ?? false) === true;
        }
        // Explicit allowlist: never serialize sealed_payload, credential_ref,
        // secret/key material, authority signature, or raw WP option data.
        return $base;
    }
    private static function records(string $kind, string $prefix, array $companies): array {
        global $wpdb;
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d",
            $wpdb->esc_like($prefix) . '%', 180
        ));
        $rows = array();
        foreach ((array)$names as $name) {
            if (count($rows) >= 65) break;
            if (!is_string($name) || strpos($name, $prefix) !== 0) continue;
            $row = get_option($name, false);
            if (!is_array($row)) continue;
            $safe = self::project_record($kind, $row, $companies);
            if ($safe && hash_equals($prefix . $safe['id'], $name)) $rows[] = $safe;
        }
        return $rows;
    }
    /** Read-only Root SSH evidence; never an authority record or fallback grant. */
    public static function external_host_evidence(array $companies): array {
        $source = get_option(self::EXTERNAL_READBACK, false);
        if (!is_array($source)
            || ($source['contract'] ?? null) !== self::EXTERNAL_CONTRACT
            || ($source['write_authorized'] ?? true) !== false
            || ($source['owner_stepup_v3_satisfied_by_ssh'] ?? true) !== false
            || ($source['ssh_identity_does_not_authorize_write'] ?? false) !== true
            || !is_array($source['sites'] ?? null)
            || count($source['sites']) > 5
            || count($source['sites']) < 1
        ) return array();
        $at = $source['observed_epoch'] ?? 0;
        if (!is_int($at) || $at < 1 || $at > time() + 60 || $at < time() - 7200)
            return array();
        $rows = array();
        $seen = array();
        foreach ($source['sites'] as $row) {
            if (!is_array($row)) continue;
            $company = $row['company'] ?? null;
            $env = $row['environment'] ?? null;
            if (!is_string($company) || !is_string($env)
                || !isset($companies[$company][$env])
                || !isset(self::EXTERNAL_COMPANY_HOSTS[$company])
                || ($row['host_id'] ?? null) !== self::EXTERNAL_COMPANY_HOSTS[$company]
                || ($row['site_uuid'] ?? null) !== $companies[$company][$env]['site_uuid']
                || ($row['canonical_origin'] ?? null) !== $companies[$company][$env]['origin'])
                continue;
            $id = $company . ':' . $env;
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $verified = ($row['identity_verified'] ?? false) === true
                && ($row['pinned_ssh_verified'] ?? false) === true
                && ($row['host_company_binding_readonly_verified'] ?? false) === true;
            $rows[] = array(
                'company'=>$company,'environment'=>$env,
                'site_uuid'=>$companies[$company][$env]['site_uuid'],
                'origin'=>$companies[$company][$env]['origin'],
                'host'=>$row['host_id'],
                'site_host_identity_verified'=>$verified,
                'source_observed_epoch'=>$at,
                'authorization_state'=>'ORIGINAL_SIGNED_GRANT_REQUIRED',
                'v3_signed_predecessor_verified'=>false,
                'host_write_authorized'=>false,
            );
        }
        return $rows;
    }
    public static function overview(): array {
        if (!self::authorized()) {
            return array('contract'=>self::CONTRACT,'authorized'=>false,
                'write_authorized'=>false,'companies'=>array(),'grants'=>array(),
                'transports'=>array(),'requests'=>array(),'jobs'=>array());
        }
        $companies = self::verified_companies();
        $grants = self::records('grant', self::GRANT_PREFIX, $companies);
        $transports = self::records('transport', self::TRANSPORT_PREFIX, $companies);
        $requests = self::records('request', self::REQUEST_PREFIX, $companies);
        $jobs = self::records('broker', self::BROKER_PREFIX, $companies);
        $external = self::external_host_evidence($companies);
        $v3 = class_exists('MAD4B_Host_V3_Owner_Console', false)
            ? MAD4B_Host_V3_Owner_Console::status_data() : array();
        $current = is_array($v3['current'] ?? null) ? $v3['current'] : array();
        return array(
            'contract'=>self::CONTRACT,
            'authorized'=>true,
            'owner_is_enrolled_for_central'=>true,
            'company_scope_source'=>'mad4b_company_environment_registry_v2',
            'companies'=>$companies,
            'external_hosts'=>$external,
            'grants'=>$grants,'transports'=>$transports,
            'requests'=>$requests,'jobs'=>$jobs,
            'v3'=>array(
                'state'=>substr((string)($current['state'] ?? 'UNAVAILABLE'),0,60),
                'write_authorized'=>($current['write_authorized'] ?? false) === true,
                'signature_verified'=>($current['signature_verified'] ?? false) === true,
                'readback_verified'=>($current['readback_verified'] ?? false) === true,
                'review_is_signature'=>false,
            ),
            'ssh_transport_auth_is_owner_approval'=>false,
            'existing_credential_grants_are_ssh_grants'=>false,
            'operation_submission'=>array(
                'grant_route'=>'mad4b/v1/central-execution-lanes/{company}/grants',
                'dispatch_route'=>'mad4b/v1/central-execution-lanes/{company}/broker-dispatch',
                'original_authority_always_rechecked'=>true,
                'new_write_authority_from_this_page'=>false,
                'ssh_company_binding_is_owner_signature'=>false,
                'no_raw_ssh_or_signer_endpoint'=>true,
            ),
        );
    }
    public static function render(): void {
        if (!self::authorized()) wp_die('Enrolled Central administrator required', 'Forbidden', array('response'=>403));
        $nonce = wp_create_nonce('wp_rest');
        $url = rest_url(self::API . '/overview');
        $rest = rest_url('mad4b/v1/central-execution-lanes/');
        echo '<div class="wrap mad4b-v145" id="mad4b-remote-operations-v145">';
        echo '<h1>Remote Operations &amp; Authorization</h1>';
        echo '<p>Approve an exact company operation, send an existing authorized request to the original VPS Broker, and track independent readback. No SSH password or private key is entered here.</p>';
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=mad4b-host-v3-owner-review')).'">Host Authority v3</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=mad4b-company-credentials')).'">Company Credentials (separate)</a></p>';
        echo '<style>
.mad4b-v145{max-width:1250px}.mad4b-v145 .m-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(310px,1fr));gap:14px;margin:15px 0}
.mad4b-v145 .m-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:17px}
.mad4b-v145 .m-card h2{margin:0 0 12px}.mad4b-v145 .m-card p{line-height:1.45}
.mad4b-v145 table{width:100%;border-collapse:collapse}.mad4b-v145 th,.mad4b-v145 td{padding:10px 8px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top;overflow-wrap:anywhere}
.mad4b-v145 .m-muted{color:#50575e}.mad4b-v145 .m-bad{color:#b32d2e;font-weight:600}.mad4b-v145 .m-good{color:#187c39;font-weight:600}
.mad4b-v145 select,.mad4b-v145 input,.mad4b-v145 textarea{max-width:100%;width:100%}.mad4b-v145 label{display:block;font-weight:600;margin:9px 0}
.mad4b-v145 .m-feedback{margin:10px 0;padding:10px;background:#f6f7f7;border-left:4px solid #2271b1}
.mad4b-v145 code{word-break:break-all}.mad4b-v145 button{margin:4px 0}
</style>';
        echo '<div id="m-status" class="m-feedback" role="status" aria-live="polite">Reading the original WordPress grants and Broker records…</div>';
        echo '<p><button type="button" class="button button-secondary" id="m-refresh">Refresh original readback</button></p>';
        echo '<div class="m-grid">';
        echo '<section class="m-card"><h2>1 · Authority and hosting</h2><div id="m-authority">Loading…</div></section>';
        echo '<section class="m-card"><h2>2 · Approve a scoped operation</h2>';
        echo '<p class="m-muted">This creates an Original305 scope record, not a signed host authorization. The original endpoint may reject missing identity or transport proofs.</p>';
        echo '<label for="m-transport">Registered target</label><select id="m-transport"></select>';
        echo '<label for="m-mode">Grant mode</label><select id="m-mode"><option value="one_time">One-time (5 minutes)</option><option value="persistent">Persistent scope (existing eligible SSH transport)</option></select>';
        echo '<label for="m-reason">Reason / change ticket</label><textarea id="m-reason" rows="2" placeholder="Describe the specific authorized maintenance operation (10–240 characters)"></textarea>';
        echo '<button type="button" id="m-grant" class="button button-primary" disabled>Request Original305 grant</button>';
        echo '<p id="m-grant-note" class="m-muted">A verified transport and enrolled administrator are required.</p></section>';
        echo '</div>';
        echo '<section class="m-card"><h2>External companies · Verified site and hosting binding</h2>';
        echo '<p class="m-muted">A live VPS SSH and WordPress identity readback is required for each external site. Company identity is not a Host Write grant.</p>';
        echo '<div id="m-hosts">Loading exact host bindings…</div></section>';
        echo '<div class="m-grid"><section class="m-card"><h2>3 · Existing operation grants</h2><div id="m-grants">Loading…</div></section>';
        echo '<section class="m-card"><h2>4 · Admitted requests / dispatch</h2><div id="m-requests">Loading…</div></section></div>';
        echo '<section class="m-card"><h2>5 · VPS Broker / independent readback</h2><div id="m-jobs">Loading…</div>';
        echo '<p class="m-muted">Unknown, stale and unsigned outcomes are not reported as success. Recovery requires the original signed sealed-payload process.</p></section>';
        echo '</div>';
        $config = array('overview'=>$url,'root'=>$rest,'nonce'=>$nonce,
            'actor'=>(int)get_current_user_id());
        echo '<script id="mad4b-remote-operations-v145-script">window.MAD4BRemoteOperationsV145=';
        echo wp_json_encode($config, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        echo ';';
        echo <<<'JS'
(function() {
"use strict";
const cfg=window.MAD4BRemoteOperationsV145;
const root=document.getElementById("mad4b-remote-operations-v145");
if(!root||!cfg)return;
const el=id=>root.querySelector("#"+id);
const t=(tag,value)=>{const n=document.createElement(tag);if(value!==undefined)n.textContent=String(value);return n;};
const state={data:null,busy:false};
const moment=e=>Number.isInteger(e)&&e>0?new Date(e*1000).toLocaleString():"Not time-limited by this record";
const isExpired=e=>Number.isInteger(e)&&e>0&&e<=Math.floor(Date.now()/1000);
const status=(message,bad=false)=>{const box=el("m-status");box.textContent=message;box.style.borderLeftColor=bad?"#b32d2e":"#2271b1";};
const permittedCompany=(name)=>!!(state.data&&Object.prototype.hasOwnProperty.call(state.data.companies,name));
function clear(node){while(node.firstChild)node.removeChild(node.firstChild);}
function row(parent,cells) {const tr=t("tr");for(const value of cells){const td=t("td",value);tr.append(td);}parent.append(tr);return tr;}
async function original(url,method="GET",payload) {
 const opts={method,credentials:"same-origin",headers:{"X-WP-Nonce":cfg.nonce}};
 if(method!=="GET"){opts.headers["Content-Type"]="application/json";opts.body=JSON.stringify(payload);}
 const response=await fetch(url,opts);
 const data=await response.json().catch(()=>({}));
 if(!response.ok){const code=data.code||("HTTP_"+response.status);throw Error(code+":"+(data.message||"Original authorization denied"));}
 return data;
}
function table(target,headers) {
 const host=el(target);clear(host);
 const table=t("table");const thead=t("thead");const headerRow=t("tr");
 headers.forEach(h=>headerRow.append(t("th",h)));thead.append(headerRow);
 const body=t("tbody");table.append(thead,body);host.append(table);return body;
}
function draw() {
 const d=state.data;
 const v=d.v3;
 const authority=el("m-authority");clear(authority);
 const p=t("p");
 p.append(t("strong","Host v3: "),t("span",v.state));authority.append(p);
 const w=t("p",v.write_authorized?"Signed Write: VERIFIED":"Persistent Write v3: NOT AUTHORIZED");w.className=v.write_authorized?"m-good":"m-bad";authority.append(w);
 authority.append(t("p","Signed current: "+(v.signature_verified&&v.readback_verified?"Verified":"Missing or incomplete")));
 authority.append(t("p","The company registry—not SSH login—defines company identity."));
 const existing=el("m-transport");clear(existing);
 const options=d.transports.filter(x=>
   permittedCompany(x.company)&&d.companies[x.company][x.environment]&&
   x.site_uuid===d.companies[x.company][x.environment].site_uuid&&
   (x.state==="PENDING_INDEPENDENT_SSH_VERIFICATION"||
    x.state==="REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED")&&
   (d.external_hosts||[]).some(site=>site.company===x.company&&
     site.environment===x.environment&&site.host===x.host&&
     site.site_uuid===x.site_uuid&&site.site_host_identity_verified===true));
 options.forEach(x=>{const o=t("option",x.company+" · "+x.environment+" · "+x.host+" · "+x.state);o.value=x.id;existing.append(o);});
 const empty=options.length===0;
 el("m-grant").disabled=empty;
 if(empty){const o=t("option","No enrolled transport available");o.value="";existing.append(o);}
 el("m-grant-note").textContent=empty?"Register a transport using Original305 before issuing a grant.":"Grant issuance uses the original endpoint; a grant does not itself authorize an SSH write.";
 const hosts=table("m-hosts",["Company / environment","Verified SSH host","Site identity","Write authority"]);
 (d.external_hosts||[]).forEach(x=>{
   const rowValue=row(hosts,[x.company+" / "+x.environment,x.host,
      x.site_host_identity_verified?"Site UUID and WordPress origin verified":"Verification missing",
      x.host_write_authorized?"SIGNED WRITE VERIFIED":"Independent signed grant still required"]);
   rowValue.lastChild.className=x.host_write_authorized?"m-good":"m-muted";
 });
 if(!(d.external_hosts||[]).length)row(hosts,["No current verified external-site evidence","—",
   "SSH identity and original registry readback required","No Write inferred"]);
 const grants=table("m-grants",["Company / environment","Operation","Grant state","Scope / expiry"]);
 d.grants.forEach(g=>{const tr=row(grants,[g.company+" / "+g.environment,g.operation||"Not specified",g.state,
  (g.mode||"unknown")+" · "+g.host+" · "+moment(g.expires_epoch)]);
   if(g.state==="CENTRAL_OPERATION_SCOPE_GRANTED")tr.lastChild.append(t("div","Host execution: "+(g.independent_host_authority_verified?"verified in record":"not verified in record")));
 });
 if(!d.grants.length)row(grants,["No grants recorded","","",""]);
 const req=table("m-requests",["Company","Original request","Status / expiry","Action"]);
 d.requests.forEach(x=>{
   const tr=row(req,[x.company+" / "+x.environment,x.id,x.state+" · "+moment(x.expires_epoch),""]);
   const eligible=x.dispatch_candidate&&x.actor_id===cfg.actor&&!isExpired(x.expires_epoch);
   if(eligible){const b=t("button","Dispatch via Original306");b.type="button";b.className="button";b.addEventListener("click",()=>dispatch(x,b));tr.lastChild.append(b);}
   else tr.lastChild.textContent="No dispatch allowed";
 });
 if(!d.requests.length)row(req,["No requests recorded","","",""]);
 const jobs=table("m-jobs",["Company","Broker job","State","Readback / recovery"]);
 d.jobs.forEach(x=>{
   const tr=row(jobs,[x.company+" / "+x.environment,x.id,x.state,
     x.independent_readback_verified?"Independently verified":"Not independently verified"]);
   if(x.actor_id===cfg.actor&&permittedCompany(x.company)){
      const inspect=t("button","Inspect original job");inspect.type="button";
      inspect.className="button button-small";
      inspect.addEventListener("click",()=>inspectJob(x,"job",inspect));
      const recovery=t("button","Check signed recovery");recovery.type="button";
      recovery.className="button button-small";
      recovery.addEventListener("click",()=>inspectJob(x,"recovery",recovery));
      tr.lastChild.append(t("div"),inspect,recovery);
   }
 });
 if(!d.jobs.length)row(jobs,["No broker jobs recorded","","",""]);
}
async function refresh(){
 if(state.busy)return;
 status("Reading original grants, existing requests and independent status…");
 try { const d=await original(cfg.overview);if(d.contract!=="mad4b.remote-operations-owner-console.v145"||d.authorized!==true)throw Error("Unexpected contract");
 state.data=d;draw();
 status("Verified external site bindings: "+(d.external_hosts||[]).filter(x=>x.site_host_identity_verified).length+
  " · "+d.grants.length+" grants · "+d.requests.length+" requests · "+d.jobs.length+
  " broker jobs. SSH identity does not grant Write.");
 }catch(e){status("Readback failed: "+e.message,true);}
}
async function submitGrant(){
 if(state.busy||!state.data)return;
 const d=state.data;
 const transport=d.transports.find(x=>x.id===el("m-transport").value);
 const mode=el("m-mode").value,reason=el("m-reason").value.trim();
 if(!transport||!permittedCompany(transport.company)||!["one_time","persistent"].includes(mode))return status("Invalid scoped transport",true);
 if(reason.length<10||reason.length>240)return status("Reason must contain 10–240 characters",true);
 if(mode==="persistent"&&transport.state!=="PENDING_INDEPENDENT_SSH_VERIFICATION")
   return status("Persistent grant requires an existing transport pending independent verification, per Original305.",true);
 if(!window.confirm("Submit a "+mode+" Original305 grant for "+transport.company+" / "+transport.environment+" on "+transport.host+"? This is NOT approval to execute a host command."))return;
 const payload={operation:"maintenance.shell",host_id:transport.host,host_principal:transport.principal,grant_mode:mode,reason};
 if(mode==="persistent")payload.transport_id=transport.id;
 else payload.ttl_seconds=300;
 state.busy=true;el("m-grant").disabled=true;
 try {
   const url=cfg.root+encodeURIComponent(transport.company)+"/grants";
   const result=await original(url,"POST",payload);
   status("Original305 response: "+(result.state||"submitted")+"; grant ID: "+(result.grant_id||"not returned")+". Host execution is still separate.");
 }catch(e){status("Original305 rejected the grant: "+e.message,true);}
 finally {state.busy=false;await refresh();}
}
async function dispatch(x,button){
 if(state.busy||!x.dispatch_candidate||x.actor_id!==cfg.actor||!permittedCompany(x.company))return;
 if(!window.confirm("Dispatch this EXISTING admitted request "+x.id+" through Original306? Only the original Broker may authorize or execute it."))return;
 state.busy=true;button.disabled=true;
 try {
   const url=cfg.root+encodeURIComponent(x.company)+"/broker-dispatch";
   const result=await original(url,"POST",{request_id:x.id});
   status("Original306 accepted/replied: "+(result.state||"pending independent Broker claim")+". Check the broker readback; dispatch is not execution proof.");
 }catch(e){status("Original306 denied dispatch: "+e.message,true);}
 finally {state.busy=false;await refresh();}
}
async function inspectJob(x,kind,button){
 if(state.busy||!permittedCompany(x.company)||x.actor_id!==cfg.actor||!(/^[a-f0-9]{32}$/).test(x.id))return;
 state.busy=true;button.disabled=true;
 try {
   const base=cfg.root+encodeURIComponent(x.company)+"/broker-jobs/"+x.id;
   const url=kind==="recovery"?base+"/recovery-evidence":base;
   const outcome=await original(url);
   // Never display the full job or its sealed payload even if the original
   // endpoint includes it. Only project whitelisted status fields.
   const label=kind==="recovery"?"Original recovery evidence":"Original broker job";
   const stateValue=String(outcome.state||"UNKNOWN").slice(0,80);
   const verified=outcome.independent_readback_verified===true;
   status(label+" · "+x.id+" · "+stateValue+
      " · independently verified readback: "+(verified?"YES":"not proven"));
 }catch(e){status("Original "+kind+" readback denied: "+e.message,true);}
 finally {state.busy=false;button.disabled=false;}
}
el("m-refresh").addEventListener("click",refresh);
el("m-grant").addEventListener("click",submitGrant);
refresh();
})();
JS;
        echo '</script>';
    }
}
MAD4B_Remote_Operations_Admin_V145::boot();
