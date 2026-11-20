<?php
/**
 * MAD4B Company Reconcile — Original Owner Action Surface.
 * Dynamic company/environment selection, exact scope review and authorization
 * readback. A review acknowledgement NEVER creates 305 Jobs or 313 receipts.
 * Source-only; no tenant data or deployment writes from this screen.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Reconcile_Owner_Actions_V1 {
    public const SLUG='mad4b-company-reconcile';
    public const REVIEW_ACTION='mad4b_company_reconcile_review_v1';
    public const REVIEWS='mad4b_company_reconcile_scope_reviews_v1';
    public const INVENTORY='mad4b_company_reconcile_asset_inventory_v1';
    public const ORIGINAL_305_OWNER_SUBMIT='mad4b_company_reconcile_submit_305_v1';
    public const ORIGINAL_JOBS='mad4b_company_reconcile_exact_305_jobs_v1';
    public const CONTRACT='mad4b.company-reconcile-owner-actions.v1';
    private static function admitted():bool {
        // Native company owner capability is shared by administrator and
        // master_admin; neither generic WordPress Settings nor plugin
        // activation rights are a prerequisite for a read/review screen.
        return class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
            && MAD4B_Company_Portfolio_Manager_V1::staging()
            && current_user_can('mad4b_manage_company_operations');
    }
    private static function browser_owner():bool {
        if(!self::admitted() || !is_user_logged_in() || !defined('LOGGED_IN_COOKIE'))return false;
        $cookie=(string)($_COOKIE[LOGGED_IN_COOKIE]??'');
        return $cookie!=='' && wp_validate_auth_cookie($cookie,'logged_in')===get_current_user_id()
            && function_exists('wp_get_session_token') && wp_get_session_token()!=='';
    }
    public static function targets():array {
        if(!self::admitted() || !class_exists('MAD4B_Company_Environment_Registry_V2',false))return [];
        $all=MAD4B_Company_Environment_Registry_V2::discover();
        $out=[];
        foreach($all as $code=>$company){
            if(!is_string($code)||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code))continue;
            foreach((array)($company['environments']??[]) as $env=>$target){
                if(!in_array($env,['production','staging'],true) || !is_array($target))continue;
                if(!wp_is_uuid((string)($target['site_uuid']??'')))continue;
                $origin=(string)($target['canonical_origin']??'');
                if(wp_parse_url($origin,PHP_URL_SCHEME)!=='https')continue;
                $out[$code.':'.$env]=[
                    'company_code'=>$code,'environment'=>$env,
                    'name'=>(string)($company['company_name']??$code),
                    'origin'=>$origin,'site_uuid'=>(string)$target['site_uuid']];
            }
        }
        ksort($out,SORT_STRING);
        return $out;
    }
    private static function selected():?array {
        $all=self::targets();
        $code=sanitize_key((string)wp_unslash($_GET['company']??''));
        $env=sanitize_key((string)wp_unslash($_GET['environment']??''));
        $scope=sanitize_text_field((string)wp_unslash($_GET['scope']??''));
        if($scope!=='' && $code==='' && $env===''){
            $parts=explode(':',$scope);
            if(count($parts)===2){$code=$parts[0];$env=$parts[1];}
        }
        $key=$code.':'.$env;
        if(isset($all[$key]))return $all[$key];
        // Show a selection, never silently reinterpret a named company as another.
        if($code!=='' || $env!=='')return null;
        return $all?reset($all):null;
    }
    /** Read the authoritative original 305/306 Job, never trust cached UI state. */
    private static function job_live(array $target):array {
        $key=$target['company_code'].':'.$target['environment'];
        $rows=get_option(self::ORIGINAL_JOBS,[]);
        $last=is_array($rows)?($rows[$key]??null):null;
        if(!is_array($last))return ['state'=>'NOT_SUBMITTED','retryable'=>true,'record'=>null];
        $id=(string)($last['original_305_job_id']??'');
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)
          ||($last['site_uuid']??'')!==$target['site_uuid'])
            return ['state'=>'UNTRUSTED_STORED_JOB','retryable'=>false,'record'=>$last];
        $c='MAD4B_Central_SSH_Command_Intake_V1';
        if(!class_exists($c,false)||!class_exists('MAD4B_Central_VPS_Broker_V1',false))
            return ['state'=>'ORIGINAL_PROVIDER_UNAVAILABLE','retryable'=>false,'record'=>$last];
        $request=get_option($c::REQUEST_PREFIX.$id,false);
        $broker=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
        if(!is_array($request)||!is_array($broker)
          ||($request['request_id']??'')!==$id
          ||($broker['job_id']??'')!==$id
          ||($request['company']??'')!==$target['company_code']
          ||($broker['company']??'')!==$target['company_code']
          ||($broker['environment']??'')!==$target['environment']
          ||($broker['site_uuid']??'')!==$target['site_uuid']
          ||($broker['canonical_origin']??'')!==$target['origin']
          ||($request['command_sha256']??'')!==($broker['command_sha256']??'')
          ||($last['command_sha256']??'')!==($broker['command_sha256']??''))
            return ['state'=>'ORIGINAL_JOB_IDENTITY_DRIFT','retryable'=>false,'record'=>$last];
        $state=(string)($broker['state']??'UNKNOWN');
        $expires=min((int)($request['expires_epoch']??0),(int)($broker['expires_epoch']??0));
        if($state==='QUEUED_FOR_VPS_BROKER_CLAIM' && $expires>0 && $expires<=time())
            return ['state'=>'EXPIRED_UNCLAIMED','retryable'=>true,'expires'=>$expires,'record'=>$last];
        return ['state'=>$state,'retryable'=>false,'expires'=>$expires,'record'=>$last];
    }
    /** Content-addressed owner review for one exact site and file-diff request.
     * A re-scan of identical bytes does not require another owner review;
     * changed paths, source/target bytes, request, or site identity do.
     */
    public static function scope_manifest_digest(array $target,$inventory):?string {
        if(!is_array($inventory)
          ||($inventory['contract']??'')!=='mad4b.company-reconcile-asset-inventory.v1'
          ||($inventory['company_code']??'')!==($target['company_code']??null)
          ||($inventory['environment']??'')!==($target['environment']??null)
          ||($inventory['site_uuid']??'')!==($target['site_uuid']??null)
          ||($inventory['origin']??'')!==($target['origin']??null))return null;
        $request=$inventory['exact_original_request_sha256']??null;
        $items=$inventory['items']??null;
        if(!is_string($request)||!preg_match('/^[a-f0-9]{64}$/D',$request)
          ||!is_array($items)||!array_is_list($items)||!$items||count($items)>40)return null;
        $rows=[];$different=0;
        foreach($items as $item){
            if(!is_array($item))return null;
            $path=$item['path']??null;
            $source=$item['source_sha256']??null;
            $dest=$item['target_sha256']??null;
            $needs=$item['needs_sync']??null;
            if(!is_string($path)||!preg_match('~^wp-content/(?:plugins/mad4b-commerce/modules/presentation/[a-zA-Z0-9_./-]+|maintenance\.php)$~D',$path)
              ||str_contains($path,'..')||isset($rows[$path])
              ||!is_string($source)||!preg_match('/^[a-f0-9]{64}$/D',$source)
              ||($dest!==null&&(!is_string($dest)||!preg_match('/^[a-f0-9]{64}$/D',$dest)))
              ||!is_bool($needs)||$needs!==($source!==$dest))return null;
            $different+=(int)$needs;
            $rows[$path]=['source_sha256'=>$source,'target_sha256'=>$dest];
        }
        if(!$different)return null;
        ksort($rows,SORT_STRING);
        return hash('sha256',wp_json_encode(['scope'=>[
            $target['company_code'],$target['environment'],$target['site_uuid'],$target['origin']],
            'original305_request_sha256'=>$request,'assets'=>$rows],JSON_UNESCAPED_SLASHES));
    }
    /** A scan older than an hour or unexpectedly from the future cannot submit. */
    public static function inventory_fresh($inventory):bool {
        if(!is_array($inventory))return false;
        $epoch=$inventory['observed_epoch']??null;
        return is_int($epoch)&&$epoch>0&&$epoch<=time()+60&&time()-$epoch<3600;
    }
    /** Read-only no-change determination, never Original313 write authority. */
    public static function inventory_in_sync(array $target,$inventory):bool {
        if(!self::inventory_fresh($inventory) || !is_array($inventory)
           || ($inventory['contract']??null)!=='mad4b.company-reconcile-asset-inventory.v1'
           || ($inventory['company_code']??null)!==($target['company_code']??null)
           || ($inventory['environment']??null)!==($target['environment']??null)
           || ($inventory['site_uuid']??null)!==($target['site_uuid']??null)
           || ($inventory['origin']??null)!==($target['origin']??null))return false;
        $items=$inventory['items']??null;
        if(!is_array($items)||!array_is_list($items)||!$items||count($items)>40)return false;
        $seen=[];
        foreach($items as $item){
            if(!is_array($item))return false;
            $path=$item['path']??null;
            $source=$item['source_sha256']??null;
            $dest=$item['target_sha256']??null;
            if(!is_string($path)||!preg_match('~^wp-content/(?:plugins/mad4b-commerce/modules/presentation/[a-zA-Z0-9_./-]+|maintenance\.php)$~D',$path)
               ||str_contains($path,'..')||isset($seen[$path])
               ||!is_string($source)||!preg_match('/^[a-f0-9]{64}$/D',$source)
               ||!is_string($dest)||!hash_equals($source,$dest)
               ||($item['needs_sync']??null)!==false)return false;
            $seen[$path]=true;
        }
        return true;
    }
    public static function review_matches_inventory(array $target,$inventory,$review,?int $actor=null):bool {
        if(!is_array($review)
          ||($review['state']??'')!=='OWNER_SCOPE_REVIEWED_NOT_NATIVE_WRITE_AUTHORIZED'
          ||($review['company_code']??'')!==($target['company_code']??null)
          ||($review['environment']??'')!==($target['environment']??null)
          ||($review['site_uuid']??'')!==($target['site_uuid']??null)
          ||($review['origin']??'')!==($target['origin']??null)
          ||!is_int($review['reviewed_epoch']??null)
          ||!is_int($review['actor_id']??null)
          ||(int)$review['reviewed_epoch']<=0
          ||(int)$review['reviewed_epoch']>time()+60
          ||($actor!==null && (int)($review['actor_id']??0)!==$actor))return false;
        $current=self::scope_manifest_digest($target,$inventory);
        if($current===null)return false;
        $old=$review['scope_digest_sha256']??null;
        return is_string($old)&&preg_match('/^[a-f0-9]{64}$/D',$old)
          &&hash_equals($old,$current)
          &&($review['request_sha256']??null)===($inventory['exact_original_request_sha256']??null);
    }
    private static function details(array $target):array {
        $company=$target['company_code'];$environment=$target['environment'];
        $plan=class_exists('MAD4B_Company_Execution_Resolver_V1',false)
            ?MAD4B_Company_Execution_Resolver_V1::plan($company,$environment,'content.write'):[];
        $preflight=class_exists('MAD4B_Company_Native_Write_Executor_V1',false)
            ?MAD4B_Company_Native_Write_Executor_V1::preflight_status($company,$environment,'content.write'):[];
        $proof=class_exists('MAD4B_Company_Native_Write_Executor_V1',false)
            ?MAD4B_Company_Native_Write_Executor_V1::certified_status($company,$environment,'content.write'):[];
        $valid=is_array($plan) && ($plan['site_uuid']??null)===$target['site_uuid']
            && ($plan['canonical_origin']??null)===$target['origin'];
        $ready=$valid && ($plan['state']??'')==='READY_FOR_EXACT_NATIVE_WRITE'
            && ($plan['selected_channel']??'')==='native_company_wordpress_write'
            && ($plan['execution_allowed']??false)===true
            && ($plan['remote_write_permitted']??false)===true;
        $rows=get_option(self::REVIEWS,[]);if(!is_array($rows))$rows=[];
        $review=$rows[$company.':'.$environment]??null;
        $currentInventory=self::inventory($target);
        $staleReview=is_array($review)&&!self::review_matches_inventory($target,$currentInventory,$review);
        if($staleReview)$review=null;
        return ['plan'=>is_array($plan)?$plan:[],
            'preflight'=>is_array($preflight)?$preflight:[],
            'proof'=>is_array($proof)?$proof:[],
            'ready'=>$ready,'review'=>$review,'stale_review_record_present'=>$staleReview,
            'identity_bound'=>$valid];
    }
    /** Publish only sanitized live host scan metadata, never a grant or job. */
    public static function import_plan_snapshot(array $scan):array {
        if(!defined('WP_CLI') || !WP_CLI)
            return ['state'=>'DENIED','reason'=>'ROOT_HOST_SCANNER_WPCLI_ONLY'];
        if(!class_exists('MAD4B_Company_Environment_Registry_V2',false))
            return ['state'=>'DENIED','reason'=>'REGISTRY_UNAVAILABLE'];
        $company=(string)($scan['company']??'');
        $env=(string)($scan['environment']??'');
        $target=MAD4B_Company_Environment_Registry_V2::target($company,$env);
        if(!is_array($target)||!wp_is_uuid((string)($target['site_uuid']??''))
            ||($scan['target_uuid']??null)!==$target['site_uuid']
            ||($scan['target']??null)!==$target['canonical_origin'])
            return ['state'=>'DENIED','reason'=>'EXACT_SITE_IDENTITY_MISMATCH'];
        $sha=$scan['source_asset_hashes']??null;
        if(!is_array($sha)||count($sha)>40)return ['state'=>'DENIED','reason'=>'ASSET_DIGEST_SET_INVALID'];
        $items=[];
        foreach($sha as $path=>$pair){
            if(!is_string($path)||!preg_match('~^wp-content/(?:plugins/mad4b-commerce/modules/presentation/[a-zA-Z0-9_./-]+|maintenance\.php)$~D',$path)
                ||str_contains($path,'..')||!is_array($pair))continue;
            $want=(string)($pair['source_sha256']??'');
            $have=$pair['target_sha256']??null;
            if(!preg_match('/^[a-f0-9]{64}$/D',$want)
                ||($have!==null&&!preg_match('/^[a-f0-9]{64}$/D',(string)$have)))continue;
            $items[]=['path'=>$path,'source_sha256'=>$want,'target_sha256'=>$have,
                'needs_sync'=>$want!==$have];
        }
        if(!$items)return ['state'=>'DENIED','reason'=>'NO_VALID_ASSETS'];
        $rows=get_option(self::INVENTORY,[]);if(!is_array($rows))$rows=[];
        $op=$scan['exact_operation_request']??null;
        $exact=is_array($op) && ($op['file_count']??null)===count(array_filter($items,static fn($x)=>$x['needs_sync']))
            && preg_match('/^[a-f0-9]{64}$/D',(string)($op['request_sha256']??''))
            ?(string)$op['request_sha256']:null;
        $rows[$company.':'.$env]=[
            'contract'=>'mad4b.company-reconcile-asset-inventory.v1',
            'exact_original_request_sha256'=>$exact,
            'company_code'=>$company,'environment'=>$env,'site_uuid'=>$target['site_uuid'],
            'origin'=>$target['canonical_origin'],'observed_epoch'=>time(),
            'scan_state'=>(string)($scan['state']??'UNKNOWN'),
            'items'=>$items,
            'write_executor_capable'=>(($scan['native_write_executor']['write_capable']??false)===true),
            'write_executor_status'=>(string)($scan['native_write_executor']['status']??'UNKNOWN'),
            'write_authorized'=>false,'native_execution_receipt'=>false];
        if(count($rows)>80)$rows=array_slice($rows,-80,null,true);
        update_option(self::INVENTORY,$rows,false);
        return ['state'=>'ADVISORY_INVENTORY_PUBLISHED','company'=>$company,'environment'=>$env,
            'total'=>count($items),'different'=>count(array_filter($items,static fn($i)=>$i['needs_sync'])),
            'conveys_execution_authority'=>false];
    }
    private static function inventory(array $target):?array {
        $rows=get_option(self::INVENTORY,[]);
        if(!is_array($rows))return null;
        $row=$rows[$target['company_code'].':'.$target['environment']]??null;
        if(!is_array($row)||($row['site_uuid']??'')!==$target['site_uuid']
            ||($row['origin']??'')!==$target['origin'])return null;
        return $row;
    }
    private static function url(array $target=[]):string {
        $query=['page'=>self::SLUG];
        if($target){$query['company']=$target['company_code'];$query['environment']=$target['environment'];}
        return add_query_arg($query,admin_url('admin.php'));
    }
    public static function menu():void {
        if(!self::admitted())return;
        add_submenu_page('mad4b-company-brand-readiness',
            'Company Reconcile &amp; Deploy','Reconcile &amp; Deploy','mad4b_manage_company_operations',
            self::SLUG,[__CLASS__,'screen']);
        // A visible entry also appears under the main Company area.
        add_submenu_page('mad4b-company',
            'Company Reconcile &amp; Deploy','Reconcile &amp; Deploy','mad4b_manage_company_operations',
            self::SLUG,[__CLASS__,'screen']);
    }
    public static function shortcut():void {
        if(!self::admitted())return;
        $page=sanitize_key((string)($_GET['page']??''));
        if(!in_array($page,['mad4b-company-manager-operations','mad4b-company-brand-readiness'],true))return;
        echo '<div class="notice notice-info"><p><strong>Company Reconcile:</strong> ';
        echo esc_html__('Review a company deployment scope, check native write authorization, and see the exact next step.','mad4b-platform');
        echo ' <a class="button button-secondary" href="'.esc_url(self::url()).'">Open Reconcile &amp; Deploy</a></p></div>';
    }
    private static function state($value):string {
        $text=(string)$value;
        return $text!==''?$text:'NOT_AVAILABLE';
    }
    public static function screen():void {
        if(!self::admitted())wp_die('Central Company Administrator required',403);
        $targets=self::targets();$target=self::selected();
        echo '<div class="wrap mad4b-company-reconcile"><h1>Company Reconcile &amp; Deploy</h1>';
        // Post/Redirect/Get: an older browser tab must not trap an owner
        // on admin-post.php when the current original job is still active.
        if(($_GET['notice']??'')==='original305_already_active')
            echo '<div class="notice notice-info" role="status"><p>The Original 305 Job is already active. No duplicate request was created. Its live status is shown below.</p></div>';
        echo '<p>Choose an enrolled company and environment. Review the operation scope and native authorization separately. A screen review is not a signed 305 Job or a 313 execution receipt.</p>';
        if(!$targets){echo '<div class="notice notice-warning"><p>No verified enrolled company environments. Nothing can be selected or deployed.</p></div></div>';return;}
        echo '<form method="get" class="postbox" style="padding:16px;max-width:1050px">';
        echo '<input type="hidden" name="page" value="'.esc_attr(self::SLUG).'">';
        echo '<label for="mad4b-reconcile-target"><strong>Company / Environment</strong></label> ';
        echo '<select id="mad4b-reconcile-target" name="scope" style="min-width:min(100%,280px)">';
        foreach($targets as $key=>$item){
            echo '<option value="'.esc_attr($key).'" '.selected($target && $item===$target,true,false).'>'.esc_html($item['name'].' — '.$item['environment']).'</option>';
        }
        echo '</select> <button type="submit" class="button">Inspect selected company</button></form>';
        // scope is read-only selection syntax, not a trusted target identity.
        if(!$target){echo '<p role="alert">The requested company/environment is not enrolled. Choose an available entry.</p></div>';return;}
        $data=self::details($target);$plan=$data['plan'];$preflight=$data['preflight'];$proof=$data['proof'];
        $review=$data['review'];$status=$data['ready']?'Native write channel admitted':'Native write not authorized';
        echo '<div class="postbox" style="padding:16px;max-width:1050px"><h2 class="hndle">Selected target</h2>';
        echo '<p><strong>Company:</strong> '.esc_html($target['name']).' &nbsp; <strong>Environment:</strong> '.esc_html($target['environment']).'</p>';
        echo '<p><strong>Registered origin:</strong> '.esc_html($target['origin']).' &nbsp; <strong>Site UUID:</strong> <code>'.esc_html($target['site_uuid']).'</code></p>';
        echo '<table class="widefat striped"><thead><tr><th>Check</th><th>Live result</th></tr></thead><tbody>';
        $checks=[
            'Company identity'=>($data['identity_bound']?'Exact origin / Site UUID matched':'Not independently matched'),
            'Scope preflight'=>self::state($preflight['state']??null),
            'Dynamic skill preflight'=>!empty($plan['company_dynamic_skill_preflight_verified'])?'PASS':'NOT VERIFIED',
            'Native execution authority'=>self::state($plan['state']??null),
            '313 write &amp; rollback evidence'=>self::state($proof['state']??null),
            'Execution decision'=>$status,
        ];
        foreach($checks as $label=>$value)echo '<tr><th scope="row">'.wp_kses_post($label).'</th><td><code>'.esc_html($value).'</code></td></tr>';
        echo '</tbody></table></div>';
        // Plugin package installation is a SEPARATE original Native operation.
        // The Admin Experience assets below use content.write and cannot
        // authorize or verify plugin.install, even if their scan is fresh.
        $installer=class_exists('MAD4B_Company_Native_Write_Executor_V1',false)
            ?MAD4B_Company_Native_Write_Executor_V1::preflight_status(
                $target['company_code'],$target['environment'],'plugin.install'):[];
        $installer_identity=is_array($installer)
            &&($installer['company_code']??'')===$target['company_code']
            &&($installer['environment']??'')===$target['environment']
            &&($installer['site_uuid']??'')===$target['site_uuid']
            &&($installer['canonical_origin']??'')===$target['origin'];
        $owner='MAD4B_Company_Experience_Install_Owner_V1';
        $installed=class_exists($owner,false)
            &&($target['company_code']??null)===$owner::COMPANY
            &&($target['environment']??null)===$owner::ENV
            &&($target['site_uuid']??null)===$owner::SITE
            ?$owner::terminal_status():[];
        echo '<div class="postbox" style="padding:16px;max-width:1050px"><h2 class="hndle">Plugin package installation — separate Native operation</h2>';
        echo '<p>This panel reads <code>plugin.install</code>; the file-diff panel below reads <code>content.write</code>. An asset review or file scan cannot authorize plugin installation.</p>';
        if(!$installer_identity){
            echo '<p role="alert">Exact plugin installer scope/identity could not be verified. No plugin deployment is allowed.</p>';
        }else{
            echo '<table class="widefat striped"><tbody>';
            $plugin_checks=[
              'Provider preflight'=>$installed?'INSTALLED_HISTORICALLY_RECONCILED':self::state($installer['state']??null),
              'Original transport'=>!empty($installer['original_transport_verified'])?'VERIFIED':'NOT VERIFIED',
              'Exact operation provider'=>!empty($installer['operation_provider_registered'])?'REGISTERED':'NOT REGISTERED',
              'Handler 312 evidence'=>$installed?'NOT REQUIRED (ALREADY INSTALLED)':(!empty($installer['operation_provider_ready'])?'CERTIFIED':'PENDING'),
              'Original 305 job'=>$installed?'HISTORICALLY RECONCILED':(!empty($installer['original_native_job_submitted'])?'RECORDED':'NOT SUBMITTED'),
              'Production install allowed'=>$installed?'NOT NEEDED':(!empty($installer['execution_allowed'])?'AUTHORIZED':'HOLD')
            ];
            foreach($plugin_checks as $label=>$value)
                echo '<tr><th scope="row">'.esc_html($label).'</th><td><code>'.esc_html($value).'</code></td></tr>';
            echo '</tbody></table>';
            if(!$installed&&empty($installer['execution_allowed']))
                echo '<p role="status">'.esc_html((string)($installer['reason']??'Independent original 305/312/313 verification required')).'. No plugin install Job or receipt is created by this page.</p>';
        }
        if(class_exists('MAD4B_Company_Experience_Install_Owner_V1',false))
            MAD4B_Company_Experience_Install_Owner_V1::render_panel($target,$installer);
        echo '</div>';
        echo '<div class="postbox" style="padding:16px;max-width:1050px"><h2 class="hndle">Admin Experience — operation scope</h2>';
        echo '<p>Source-owned presentation files only: styles, theme switcher, contrast, WordPress notices, tables and UI auto-scan. No users, passwords, tour content, payments, orders, or database cloning.</p>';
        $inventory=self::inventory($target);
        $in_sync=self::inventory_in_sync($target,$inventory);
        if($inventory){
            $files=(array)($inventory['items']??[]);$pending=array_filter($files,static fn($f)=>!empty($f['needs_sync']));
            echo '<p><strong>Latest source/target scan:</strong> '.esc_html(gmdate('Y-m-d H:i', (int)$inventory['observed_epoch'])).' UTC · '.count($files).' supported files · '.count($pending).' different.</p>';
            echo '<div style="max-height:330px;overflow:auto"><table class="widefat striped"><thead><tr><th scope="col">Exact source file</th><th scope="col">Comparison</th></tr></thead><tbody>';
            foreach($files as $f){
                echo '<tr><td><code style="overflow-wrap:anywhere">'.esc_html($f['path']).'</code></td><td>'.(!empty($f['needs_sync'])?'Requires sync':'Matching').'</td></tr>';
            }
            echo '</tbody></table></div>';
            echo '<p><small>The inventory is a previous host readback, not an authorization receipt; the execution preflight above is evaluated live.</small></p>';
        }else echo '<p><em>No host file-diff snapshot has been published for this selection. The native authorization results above are live.</em></p>';
        if($in_sync)
            echo '<div class="notice notice-success inline" role="status"><p><strong>Source parity verified:</strong> all scanned Admin Experience files match their current source SHA256. No new Original305 job, 312 write, or 313 certificate is required to eliminate file drift. Historical jobs are retained for audit; this read-only comparison does not certify a past manual deployment.</p></div>';
        else echo '<p><strong>Pending execution:</strong> '.($data['ready']?'Provider is authorized; an original exact job is still required.':'A signed, exact original 305/306/312 write job and independent 313 executor are required.').'</p>';
        if(!$in_sync){
        if(!empty($data['stale_review_record_present']))
            echo '<div class="notice notice-warning inline" role="alert"><p>Previous scope review is not valid for this exact file inventory. Re-review after a fresh host scan; no prior acknowledgement grants a changed operation.</p></div>';
        if($review)echo '<div class="notice notice-success inline"><p>Scope review recorded at '.esc_html(gmdate('Y-m-d H:i:s', (int)($review['reviewed_epoch']??0))).' UTC. This is not execution authorization.</p></div>';
        else echo '<p><em>No browser owner scope review recorded for this exact site identity.</em></p>';
        if(self::browser_owner()){
            $reviewForMe=$review&&($review['actor_id']??null)===get_current_user_id();
            $can_review=$reviewForMe||(
                self::scope_manifest_digest($target,$inventory)!==null
                &&self::inventory_fresh($inventory));
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:12px 0">';
            wp_nonce_field('mad4b_reconcile_review_'.$target['company_code'].'_'.$target['environment'].'_'.$target['site_uuid']);
            echo '<input type="hidden" name="action" value="'.esc_attr(self::REVIEW_ACTION).'">';
            echo '<input type="hidden" name="company" value="'.esc_attr($target['company_code']).'">';
            echo '<input type="hidden" name="environment" value="'.esc_attr($target['environment']).'">';
            echo '<input type="hidden" name="site_uuid" value="'.esc_attr($target['site_uuid']).'">';
            echo '<input type="hidden" name="review_action" value="'.($reviewForMe?'withdraw':'confirm').'">';
            echo '<button type="submit" class="button '.($reviewForMe?'button-secondary':'button-primary').'" '
                .disabled(!$can_review,true,false).'>'.($reviewForMe?'Withdraw scope review':'Confirm reviewed operation scope').'</button>';
            if(!$can_review)echo '<p role="status">A fresh, exact host file inventory and Original 305 request hash are required before confirming a reviewed scope.</p>';
            echo '</form>';
        }else echo '<p>Scope confirmation requires an active, authenticated WordPress owner browser session. WP-CLI user override cannot confirm it.</p>';
        // State machine readback. Never confuse owner scope review with signed
        // ORIGINAL 305 acceptance or signed independent 313 write evidence.
        $reviewed=$review && ($review['state']??'')==='OWNER_SCOPE_REVIEWED_NOT_NATIVE_WRITE_AUTHORIZED'
            &&($review['actor_id']??null)===get_current_user_id();
        $submission=($plan['original_305_313_submission_preflight_passed']??false)===true;
        $signed_write=$data['ready'];
        echo '<div class="postbox" style="padding:16px;max-width:1050px"><h2 class="hndle">What happens next?</h2>';
        echo '<ol style="margin-left:24px">';
        echo '<li><strong>Review exact target scope:</strong> '.($reviewed?'Completed':'Not confirmed').' — owner review only.</li>';
        $joblive=self::job_live($target);
        $last=$joblive['record'];
        $created=!($joblive['retryable']??false);
        $jobstate=(string)$joblive['state'];
        echo '<li><strong>Original 305/306 exact job:</strong> '.($last?esc_html($jobstate).' · '.esc_html($last['original_305_job_id']??''):($submission?'Preflight eligible, not yet issued':'Preflight not eligible')).'.</li>';
        echo '<li><strong>Native write provider:</strong> '.($signed_write?'Authorized by original provider':'Unavailable until an independent, write-capable executor and grant are verified').'.</li>';
        echo '<li><strong>Independent 313 proof:</strong> '.esc_html(self::state($proof['state']??null)).'. Emitted only after successful apply, readback and rollback verification.</li>';
        echo '</ol>';
        $request_hash=is_array($inventory)?(string)($inventory['exact_original_request_sha256']??''):'';
        $valid_request=self::scope_manifest_digest($target,$inventory)!==null;
        $fresh_inventory=self::inventory_fresh($inventory);
        $write_capable=is_array($inventory)&&($inventory['write_executor_capable']??false)===true;
        if(!$created){
            $owner_session=self::browser_owner();
            $can_submit=$owner_session&&$reviewed&&($review['actor_id']??null)===get_current_user_id()
                &&$submission&&$valid_request&&$fresh_inventory&&$write_capable;
            $age=is_array($inventory)?max(0,time()-(int)($inventory['observed_epoch']??0)):null;
            $blocked_reasons=[];
            if(!$owner_session)$blocked_reasons[]='Sign in with the original owner browser session';
            if(!$reviewed || ($review['actor_id']??null)!==get_current_user_id())
                $blocked_reasons[]='Complete the exact current-file scope review using this owner session';
            if(!$submission)$blocked_reasons[]='Native 305/306 scope preflight is unavailable';
            if(!$valid_request)$blocked_reasons[]='Exact 305 request manifest is missing';
            if(!$fresh_inventory)$blocked_reasons[]='File inventory is outdated; a new host-side file scan is required';
            if(!$write_capable)$blocked_reasons[]='Native file executor supports verification only; an authorized write + rollback + 313 handler is not yet installed';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:14px 0">';
            wp_nonce_field('mad4b_original305_'.$target['company_code'].'_'.$target['environment'].'_'.$request_hash);
            echo '<input type="hidden" name="action" value="'.esc_attr(self::ORIGINAL_305_OWNER_SUBMIT).'">';
            foreach(['company'=>$target['company_code'],'environment'=>$target['environment'],
                     'site_uuid'=>$target['site_uuid'],'request_sha256'=>$request_hash] as $name=>$value)
                echo '<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr($value).'">';
            echo '<button class="button button-primary" type="submit" '.disabled(!$can_submit,true,false).'>Submit exact Original 305 write Job</button>';
            echo '<p><small>Owner-authorized single Job for the exact file diff. This is not direct Production apply.</small></p>';
            if($jobstate==='EXPIRED_UNCLAIMED')echo '<p role="status">The previous Job expired unclaimed. A new original 305 submission is required; the expired Job will not be executed.</p>';
            if($age!==null)echo '<p><strong>Inventory age:</strong> '.esc_html((string)floor($age/60)).' minutes. Maximum: 60 minutes.</p>';
            if(!$can_submit){
                echo '<div class="notice notice-warning inline" role="status"><p><strong>Submission unavailable:</strong> '.esc_html(implode('; ',$blocked_reasons)).'.</p>';
                if(!$fresh_inventory)echo '<p>The host-side inventory refresh runs automatically. Reload this page after its next successful scan. Reloading this page does not refresh the host file hashes.</p>';
                echo '</div>';
            }
            echo '</form>';
        }elseif($created){
            echo '<p><strong>Original 305 Job:</strong> <code>'.esc_html($last['original_305_job_id']).'</code> · '.esc_html($jobstate).'. No deployment has been inferred from submission.</p>';
        }
        if(!$signed_write){
            $next_action=($created && $jobstate==='QUEUED_FOR_VPS_BROKER_CLAIM')
              ?'Original 305 is queued, but a matching native write executor is still required. Do not submit a duplicate Job.'
              :($write_capable?'The signed 305 Job and subsequent independent 313 receipt are required before Production apply.'
                :'The native host file component currently verifies source and target only; no certified write/rollback/313 handler is active. Repeated 305 submissions cannot deploy the files.');
            echo '<div class="notice notice-warning inline"><p><strong>Production apply is disabled.</strong> '.esc_html($next_action).'</p></div>';
            echo '<p><button type="button" class="button button-primary" disabled aria-describedby="mad4b-native-write-disabled-reason">Apply exact operation to Production</button> <span id="mad4b-native-write-disabled-reason">Disabled: original Native Write authorization missing.</span></p>';
        }
        echo '</div>';
        echo '<p><a class="button button-secondary" href="'.esc_url(self::url($target)).'">Refresh live preflight</a> ';
        echo '<a class="button button-secondary" href="'.esc_url(admin_url('tools.php?page=mad4b-native-313')).'">Native 313 read-only diagnostics</a></p>';
        } else {
            echo '<p role="status">This scope is currently reconciled. No owner review, new signed job, or production write action is needed. Any expired Original305 job remains historical evidence only.</p>';
        }
        echo '</div></div>';
    }
    public static function submit_original_305():void {
        if(!self::browser_owner())wp_die('Original owner browser authentication required',403);
        $company=sanitize_key((string)wp_unslash($_POST['company']??''));
        $env=sanitize_key((string)wp_unslash($_POST['environment']??''));
        $uuid=sanitize_text_field((string)wp_unslash($_POST['site_uuid']??''));
        $sha=sanitize_text_field((string)wp_unslash($_POST['request_sha256']??''));
        $targets=self::targets();$target=$targets[$company.':'.$env]??null;
        if(!$target||$uuid!==$target['site_uuid']||!preg_match('/^[a-f0-9]{64}$/D',$sha))
            wp_die('Exact company identity or request missing',409);
        check_admin_referer('mad4b_original305_'.$company.'_'.$env.'_'.$sha);
        // Stale browser tabs are common. Check current ORIGINAL Broker Job
        // before inventory validity and any further write-authorization work;
        // an active pending job must return its live status, never a 409 page.
        $existing=self::job_live($target);
        if($existing['record'] && !($existing['retryable']??false)){
            wp_safe_redirect(add_query_arg('notice','original305_already_active',self::url($target)),303);
            exit;
        }
        $reviews=get_option(self::REVIEWS,[]);$review=is_array($reviews)?($reviews[$company.':'.$env]??null):null;
        $inventory=self::inventory($target);
        if(!self::review_matches_inventory($target,$inventory,$review,get_current_user_id())
          ||!self::inventory_fresh($inventory)
          ||($inventory['exact_original_request_sha256']??null)!==$sha)
            wp_die('Original owner review or exact recent file inventory required',409);
        if(($inventory['write_executor_capable']??false)!==true)
            wp_die('Native write and rollback executor is not ready. No Original 305 Job created.',409);
        $details=self::details($target);
        if(($details['plan']['original_305_313_submission_preflight_passed']??false)!==true)
            wp_die('Native scope preflight did not pass',409);
        $slot=get_option('mad4b_company_native_wpcli_slot_v1_'.$company.'_'.$env,false);
        $c='MAD4B_Central_SSH_Command_Intake_V1';
        $grant=is_array($slot)?get_option($c::PREFIX.($slot['grant_id']??''),false):false;
        $transport=is_array($slot)?get_option($c::TRANSPORT_PREFIX.($slot['transport_id']??''),false):false;
        if(!is_array($grant)||!is_array($transport)
          ||($grant['operation']??null)!=='company.remote.wpcli.full'
          ||($grant['central_principal_id']??null)!==get_current_user_id()
          ||($slot['site_uuid']??null)!==$uuid
          ||!MAD4B_Original_Verified_WPCLI_Transport_V1::active($transport,$grant))
            wp_die('Active original owner-enrolled WP-CLI grant required',403);
        $jobs=get_option(self::ORIGINAL_JOBS,[]);if(!is_array($jobs))$jobs=[];
        $prior=self::job_live($target);
        if($prior['record'] && !($prior['retryable']??false)){
            wp_safe_redirect(add_query_arg('notice','original305_already_active',self::url($target)),303);
            exit;
        }
        // Atomic per-target submission lock also prevents simultaneous POSTs
        // from multiple owner tabs. It is not an authorization grant.
        $lockkey='mad4b_company_reconcile_submit305_lock_'.md5($company.':'.$env.':'.$uuid);
        $lock=get_option($lockkey,false);
        if(is_array($lock) && (int)($lock['until']??0)>time()){
            wp_safe_redirect(add_query_arg('notice','original305_already_active',self::url($target)),303);
            exit;
        }
        if($lock!==false)delete_option($lockkey);
        $token=bin2hex(random_bytes(12));
        if(!add_option($lockkey,['until'=>time()+100,'site_uuid'=>$uuid,'token'=>$token],'',false)){
            wp_safe_redirect(add_query_arg('notice','original305_already_active',self::url($target)),303);
            exit;
        }
        // Even if the REST provider returns wp_die, clean the lock. An old
        // request must never release another request's later lock.
        register_shutdown_function(static function()use($lockkey,$token){
            $current=get_option($lockkey,false);
            if(is_array($current) && ($current['token']??'')===$token)
                delete_option($lockkey);
        });
        // Re-read the original Broker under the submission lock, not just the
        // page render snapshot. Do not touch or reissue a live original Job.
        $locked_prior=self::job_live($target);
        if($locked_prior['record'] && !($locked_prior['retryable']??false)){
            delete_option($lockkey);
            wp_safe_redirect(add_query_arg('notice','original305_already_active',self::url($target)),303);
            exit;
        }
        // Exactly one whitelisted, source-owned native operation; no PHP eval,
        // shell, arbitrary path, password, or target overrides in the argv.
        $argv=['mad4b','company-appearance','apply','--request-sha256='.$sha];
        $body=['grant_id'=>(string)$slot['grant_id'],'command'=>null,'argv'=>$argv,
            'summary'=>'Company Admin Experience exact source parity for '.$company.' '.$env,
            'correlation_id'=>'appearance.'.substr($sha,0,24).'.'.bin2hex(random_bytes(4)),
            'target_scope'=>'wordpress'];
        $nonce=wp_create_nonce('wp_rest');
        $req=new WP_REST_Request('POST','/mad4b/v1/central-execution-lanes/'.$company.'/commands');
        $req->set_param('company',$company);
        $req->set_header('X-WP-Nonce',$nonce);
        $req->set_header('Content-Type','application/json');
        $req->set_body(wp_json_encode($body));
        $admit=MAD4B_Central_SSH_Command_Intake_V1::accept_command($req);
        if(is_wp_error($admit))wp_die('Original 305 admission rejected: '.esc_html($admit->get_error_code()),403);
        $receipt=$admit instanceof WP_REST_Response?$admit->get_data():[];
        $id=(string)($receipt['request_id']??'');
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)
          ||($receipt['status']??null)!=='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY')
            wp_die('Original 305 command not independently admitted',409);
        $dispatch=new WP_REST_Request('POST','/mad4b/v1/central-execution-lanes/'.$company.'/broker-dispatch');
        $dispatch->set_param('company',$company);
        $dispatch->set_header('X-WP-Nonce',$nonce);
        $dispatch->set_header('Content-Type','application/json');
        $dispatch->set_body(wp_json_encode(['request_id'=>$id]));
        $queued=MAD4B_Central_VPS_Broker_V1::dispatch($dispatch);
        if(is_wp_error($queued))wp_die('Original 305 admitted but Broker dispatch pending: '.esc_html($queued->get_error_code()),409);
        $job=$queued instanceof WP_REST_Response?$queued->get_data():[];
        if(($job['job_id']??null)!==$id||($job['state']??null)!=='QUEUED_FOR_VPS_BROKER_CLAIM')
            wp_die('Original Broker did not certify queued job',409);
        $history=is_array($prior['record'])?(array)($prior['record']['history']??[]):[];
        if(is_array($prior['record'])&&!empty($prior['record']['original_305_job_id']))
            $history[]=[$prior['record']['original_305_job_id'],$prior['state']];
        $history=array_slice($history,-10);
        $jobs[$company.':'.$env]=['contract'=>'mad4b.company-reconcile-original305.v1',
          'history'=>$history,
          'company_code'=>$company,'environment'=>$env,'site_uuid'=>$uuid,
          'request_sha256'=>$sha,'original_305_job_id'=>$id,
          'command_sha256'=>(string)($receipt['command_sha256']??''),
          'actor_id'=>get_current_user_id(),'created_epoch'=>time(),
          'state'=>'QUEUED_FOR_VPS_BROKER_CLAIM','execution_performed'=>false];
        if(count($jobs)>100)$jobs=array_slice($jobs,-100,null,true);
        update_option(self::ORIGINAL_JOBS,$jobs,false);
        delete_option($lockkey);
        wp_safe_redirect(self::url($target),303);exit;
    }
    public static function post():void {
        if(!self::browser_owner())wp_die('Authenticated central owner browser session required',403);
        $company=sanitize_key((string)wp_unslash($_POST['company']??''));
        $env=sanitize_key((string)wp_unslash($_POST['environment']??''));
        $uuid=sanitize_text_field((string)wp_unslash($_POST['site_uuid']??''));
        $action=sanitize_key((string)wp_unslash($_POST['review_action']??''));
        $all=self::targets();$target=$all[$company.':'.$env]??null;
        if(!in_array($action,['confirm','withdraw'],true)||!$target||$uuid!==$target['site_uuid'])
            wp_die('Exact enrolled company, environment and site identity required',409);
        check_admin_referer('mad4b_reconcile_review_'.$company.'_'.$env.'_'.$uuid);
        $rows=get_option(self::REVIEWS,[]);if(!is_array($rows))$rows=[];
        $key=$company.':'.$env;
        if($action==='withdraw')unset($rows[$key]);
        else {
            $inventory=self::inventory($target);
            $digest=self::scope_manifest_digest($target,$inventory);
            if($digest===null || !self::inventory_fresh($inventory))
                wp_die('Fresh exact Original 305 file scope must be inspected before owner review',409);
            $rows[$key]=['contract'=>self::CONTRACT,'company_code'=>$company,'environment'=>$env,
            'site_uuid'=>$uuid,'origin'=>$target['origin'],'component'=>'admin-experience',
            'request_sha256'=>$inventory['exact_original_request_sha256'],
            'scope_digest_sha256'=>$digest,'reviewed_file_count'=>count($inventory['items']),
            'reviewed_epoch'=>time(),
            'actor_id'=>get_current_user_id(),'state'=>'OWNER_SCOPE_REVIEWED_NOT_NATIVE_WRITE_AUTHORIZED',
            'original_305_job_created'=>false,'execution_authorized'=>false,'production_mutated'=>false];
        }
        if(count($rows)>100)$rows=array_slice($rows,-100,null,true);
        update_option(self::REVIEWS,$rows,false);
        wp_safe_redirect(self::url($target));exit;
    }
    public static function boot():void {
        add_action('admin_menu',[__CLASS__,'menu'],98);
        add_action('admin_notices',[__CLASS__,'shortcut'],75);
        add_action('admin_post_'.self::REVIEW_ACTION,[__CLASS__,'post']);
        add_action('admin_post_'.self::ORIGINAL_305_OWNER_SUBMIT,[__CLASS__,'submit_original_305']);
    }
}
MAD4B_Company_Reconcile_Owner_Actions_V1::boot();
