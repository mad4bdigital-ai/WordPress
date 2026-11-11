<?php
/** Dedicated, readback-driven admin UI for Company Site Operations. */
defined('ABSPATH') || exit;
final class MAD4B_Company_Site_Operations_Dashboard_V1 {
    private const PAGE='mad4b-company-site-operations';
    private const LIMIT=20;
    private const LABELS=[
        'site.binding.inspect'=>['Site binding','Verify the site UUID, hostname, environment and deployment binding.','Verify binding'],
        'site.runtime.inspect'=>['Runtime capabilities','Inspect site binding, Control Plane and Readiness capability without scanning images or content.','Inspect runtime'],
        'site.content.inspect'=>['Content & Brand Core','Check source-loop integrity, neutral demo policy, Brand Core approvals and publication readiness for this exact company/site.','Inspect content'],
        'site.readiness.run'=>['Content Readiness','Run the site-owned readiness workflow when due and authorized.','Request readiness'],
    ];
    private const STATES=[
        'COMPLETED'=>'Verified','QUEUED'=>'Queued','NOT_DUE'=>'Not due',
        'BLOCKED'=>'Needs attention','NOT_LOCALLY_BOUND'=>'Route missing',
        'FAILED'=>'Failed','EXPIRED'=>'Expired','ALREADY_QUEUED'=>'Already queued',
    ];
    private const FINDINGS=[
        'BINDING_VERIFIED'=>'Verified deployment identity',
        'BINDING_UNVERIFIED'=>'Site binding requires reconciliation',
        'PRESENTATION_VERIFIED'=>'Fresh presentation evidence available',
        'RUNTIME_INSPECTED'=>'Site operational capabilities verified without content scanning',
        'PRESENTATION_EVIDENCE_NOT_VERIFIED'=>'Remote Presentation Integrity evidence is unavailable or stale',
        'READINESS_COMPLETED'=>'Readiness scan completed',
        'READINESS_NOT_DUE'=>'Readiness has already run within its scheduling window',
        'ORIGINAL_SIGNED_REMOTE_WRITE_REQUIRED'=>'Signed native job authorization is required for remote execution',
        'REMOTE_SITE_POLICY_BLOCKED'=>'Target site readiness policy has outstanding blockers',
        'REMOTE_SITE_PROFILE_UNREADY'=>'Remote Site Profile is not ready',
        'REMOTE_ROUTE_UNAVAILABLE'=>'No enrolled remote route could be used',
        'EXECUTION_FAILED'=>'Host operation did not complete',
        'STATUS_UNVERIFIED'=>'Result could not be independently verified',
    ];
    private static function route(array $row):string {
        $diag=(array)($row['diagnostic']??[]);
        if(($diag['route']??'')==='vps_client_mcp_cross_ssh')return 'VPS Client MCP / Cross-SSH';
        if(($diag['route']??'')==='local_wpcli')return 'Local WP-CLI';
        if(($diag['route']??'')==='unavailable')return 'No verified route';
        $contract=(string)($row['result_contract']??'');
        if(str_starts_with($contract,'mad4b.company-site.remote-'))return 'VPS Client MCP / Cross-SSH';
        if(str_starts_with($contract,'mad4b.cli-readiness.')||$contract==='mad4b.presentation-browser-evidence.v1'
            ||$contract==='mad4b.company-site.binding-readback.v1')return 'Local WP-CLI';
        return 'Not confirmed';
    }
    private static function guide(array $row):string {
        if(($row['operation']??'')==='site.content.inspect' && ($row['state']??'')==='COMPLETED')
            return 'Exact site Content and Brand Core diagnostic received. Inspect its host receipt for Source Loop integrity, provenance, and pending review; readback is not publication authorization.';
        if(($row['operation']??'')==='site.presentation.inspect')
            return 'Legacy optional visual-quality evidence. It does not block Company Management or native Readiness.';
        $state=(string)($row['state']??'');
        $finding=(string)($row['diagnostic']['finding']??'');
        if($finding==='RUNTIME_INSPECTED'){
            $blockers=(int)($row['diagnostic']['policy_blockers_count']??0);
            return 'Runtime inspected successfully'.($blockers?' · Readiness has '.$blockers.' policy blocker(s)':' · no outstanding Readiness policy blockers').'.';
        }
        if($finding && isset(self::FINDINGS[$finding]))return self::FINDINGS[$finding].'.';
        $contract=(string)($row['result_contract']??'');
        if($state==='QUEUED')return 'Awaiting native worker processing. The request is not an execution receipt.';
        if($state==='COMPLETED')return 'Verified by the original site-owned readback. This is not an Original 313 receipt.';
        if($state==='NOT_DUE')return 'The site is not due for another readiness scan.';
        if($state==='EXPIRED')return 'Request expired without a final receipt. You can submit a fresh request.';
        if($state==='NOT_LOCALLY_BOUND')return 'Local host target was unavailable. Check the enrolled VPS Client MCP / Cross-SSH route.';
        if($state==='BLOCKED'&&$contract==='mad4b.company-site.remote-presentation-readback.v1')
            return 'Presentation Integrity evidence on the remote site was not verified; inspect the remote module and evidence freshness.';
        if($state==='BLOCKED'&&$contract==='mad4b.company-site.remote-readiness-plan.v1')
            return 'Remote readiness was not executed. Review site-policy blockers and the original signed remote-write path.';
        if($state==='BLOCKED')return 'Inspect the original host/site policy before retrying; SSH availability does not authorize an operation.';
        if($state==='FAILED')return 'Execution failed. Inspect the host receipt and source-owned workflow before retrying.';
        return 'No completed readback for this operation yet.';
    }
    private static function state(array $row):string {
        if(($row['state']??'')==='QUEUED' && (int)($row['expires_epoch']??0)<time())return 'EXPIRED';
        return (string)($row['state']??'UNKNOWN');
    }
    public static function overview(string $company,string $environment):array {
        if(!MAD4B_Company_Site_Operations_V1::owner())return ['error'=>'NOT_AUTHORIZED'];
        $company=sanitize_key($company);$environment=sanitize_key($environment);
        $plan=MAD4B_Company_Site_Operations_V1::plan($company,$environment,'site.binding.inspect');
        if(empty($plan['site_uuid']))return ['contract'=>'mad4b.company.site-operations.dashboard.v1',
            'company'=>$company,'environment'=>$environment,'target_verified'=>false,'rows'=>[],
            'latest'=>[],'counts'=>['queued'=>0,'completed'=>0,'attention'=>0]];
        $stored=get_option(MAD4B_Company_Site_Operations_V1::STORE,[]);
        if(!is_array($stored))$stored=[];
        $filtered=[];$latest=[];$counts=['queued'=>0,'completed'=>0,'attention'=>0];
        foreach($stored as $request_id=>$item){
            if(!is_array($item)||($item['company_code']??'')!==$company
                ||($item['environment']??'')!==$environment||($item['site_uuid']??'')!==$plan['site_uuid'])continue;
            $state=self::state($item);
            $record=[
                'request_id'=>$request_id,'operation'=>(string)($item['operation']??''),'state'=>$state,
                'state_label'=>self::STATES[$state]??$state,
                'requested_at'=>(string)($item['created_at']??''),
                'finished_at'=>(string)($item['finished_at']??''),
                'requested_epoch'=>(int)($item['requested_epoch']??0),
                'route'=>self::route($item),'guidance'=>self::guide($item),
                'evidence'=>(string)($item['evidence_sha256']??''),
                'result_contract'=>(string)($item['result_contract']??''),
                'diagnostic'=>array_intersect_key((array)($item['diagnostic']??[]),array_flip(
                    ['finding','site_binding_verified','presentation_fresh','policy_blockers_count'])),
            ];
            $filtered[]=$record;
        }
        usort($filtered,static fn($a,$b)=>($b['requested_epoch']<=>$a['requested_epoch'])
            ?:strcmp($b['request_id'],$a['request_id']));
        foreach($filtered as $row){
            $op=$row['operation'];
            if(isset(self::LABELS[$op])&&!isset($latest[$op]))$latest[$op]=$row;
            if($row['state']==='QUEUED')$counts['queued']++;
        }
        // KPI status refers to the latest operation per type, not obsolete
        // historical failures resolved by a newer verified host receipt.
        foreach($latest as $entry){
            if(in_array($entry['state'],['COMPLETED','NOT_DUE'],true))$counts['completed']++;
            elseif(in_array($entry['state'],['BLOCKED','FAILED','EXPIRED','NOT_LOCALLY_BOUND'],true))$counts['attention']++;
        }
        $last_binding=null;
        foreach($filtered as $row)if($row['operation']==='site.binding.inspect'&&$row['state']==='COMPLETED'){
            $last_binding=$row;break;
        }
        return [
            'contract'=>'mad4b.company.site-operations.dashboard.v1',
            'company'=>$company,'environment'=>$environment,'target_verified'=>true,
            'site_uuid'=>$plan['site_uuid'],'origin'=>$plan['canonical_origin'],
            'rows'=>array_slice($filtered,0,self::LIMIT),'latest'=>$latest,
            'counts'=>$counts,'last_binding'=>$last_binding,
            'updated_at'=>gmdate('c'),'readback_only'=>true,
        ];
    }
    private static function esc_date(string $value):string {
        if($value==='')return '—';
        $time=strtotime($value);
        return $time?esc_html(wp_date('M j, Y · H:i',$time)):'—';
    }
    private static function pill(string $state):string {
        $category=in_array($state,['COMPLETED'],true)?'ok':
            (in_array($state,['QUEUED','NOT_DUE'],true)?'pending':
                (in_array($state,['BLOCKED','NOT_LOCALLY_BOUND','FAILED','EXPIRED'],true)?'attention':'unknown'));
        $label=self::STATES[$state]??$state;
        return '<span class="mad4b-so-pill mad4b-so-pill--'.esc_attr($category).'">'
          .esc_html($label).'</span>';
    }
    public static function assets():void {
        if(sanitize_key((string)($_GET['page']??''))!==self::PAGE ||
            !MAD4B_Company_Site_Operations_V1::owner())return;
        $root=dirname(__DIR__);
        $css=$root.'/assets/company-site-operations.css';
        $js=$root.'/assets/company-site-operations.js';
        wp_enqueue_style('mad4b-company-site-ops',set_url_scheme(plugins_url('assets/company-site-operations.css',$root.'/mad4b-company-portfolio-manager.php'),'https'),[],
            (string)(is_file($css)?filemtime($css):1));
        wp_enqueue_script('mad4b-company-site-ops',set_url_scheme(plugins_url('assets/company-site-operations.js',$root.'/mad4b-company-portfolio-manager.php'),'https'),[],
            (string)(is_file($js)?filemtime($js):1),true);
    }
    public static function render():void {
        if(!MAD4B_Company_Site_Operations_V1::owner())wp_die('Company Management administrator required','',['response'=>403]);
        $enrolled=MAD4B_Company_Environment_Registry_V2::discover();
        $companies=[];
        foreach((array)$enrolled as $code=>$data){
            if(!is_array($data)||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',(string)$code))continue;
            $envs=array_values(array_intersect(['staging','production'],array_keys((array)($data['environments']??[]))));
            if(!$envs)continue;
            $companies[$code]=['name'=>(string)($data['company_name']??$code),'environments'=>$envs];
        }
        uasort($companies,static fn($a,$b)=>strcasecmp($a['name'],$b['name']));
        $default=array_key_first($companies);
        $company=sanitize_key((string)($_GET['company']??$default??''));
        if(!isset($companies[$company]))$company=(string)$default;
        $requested_env=sanitize_key((string)($_GET['environment']??''));
        $environments=$companies[$company]['environments']??[];
        $environment=in_array($requested_env,$environments,true)?$requested_env:($environments[0]??'staging');
        $view=self::overview($company,$environment);
        $valid=!empty($view['target_verified']);
        $site_title=$companies[$company]['name']??$company;
        $api=rest_url('mad4b-company-management/v1/site-operations/overview');
        $nonce=wp_create_nonce('wp_rest');
        echo '<div class="wrap mad4b-so" id="mad4b-company-site-operations" data-api="'.esc_url($api).'" data-nonce="'.esc_attr($nonce).'" data-company="'.esc_attr($company).'" data-environment="'.esc_attr($environment).'">';
        echo '<p class="mad4b-so-breadcrumb"><a href="'.esc_url(admin_url('admin.php?page=mad4b-company-brand-readiness')).'">Company Management</a> / Site Operations</p>';
        echo '<div class="mad4b-so-heading"><div><h1>Site Operations</h1><p>Run exact-target checks and follow native receipts across Local WP-CLI, VPS Client MCP and pinned Cross-SSH.</p></div>';
        echo '<span class="mad4b-so-version">Native operations · v1</span></div>';
        echo '<form id="mad4b-so-target-form" class="mad4b-so-target-form" method="get" action="'.esc_url(admin_url('admin.php')).'">';
        echo '<input type="hidden" name="page" value="'.esc_attr(self::PAGE).'" />';
        echo '<label for="mad4b-so-company">Company<select id="mad4b-so-company" name="company">';
        foreach($companies as $code=>$d)echo '<option value="'.esc_attr($code).'" data-environments="'.esc_attr(implode(',',$d['environments'])).'"'.selected($company,$code,false).'>'
            .esc_html($d['name']).'</option>';
        echo '</select></label><label for="mad4b-so-environment">Environment<select id="mad4b-so-environment" name="environment">';
        foreach(['staging','production'] as $env)echo '<option value="'.$env.'"'.selected($environment,$env,false).($environments&&!in_array($env,$environments,true)?' disabled':'').'>'.ucfirst($env).'</option>';
        echo '</select></label><button class="button button-primary" type="submit">Open site</button>';
        echo '</form>';
        $request_state=sanitize_text_field((string)($_GET['request_state']??''));
        $request_id=sanitize_text_field((string)($_GET['request_id']??''));
        if($request_state){
            echo '<p class="mad4b-so-flash" role="status">Request: <strong>'.esc_html(self::STATES[$request_state]??$request_state).'</strong>';
            if(wp_is_uuid($request_id))echo ' · <code>'.esc_html(substr($request_id,0,18)).'…</code>';
            echo '. Requests are verified asynchronously by the enrolled host operator.</p>';
        }
        if(!$valid){
            echo '<section class="mad4b-so-empty"><h2>Target not verified</h2><p>Company and environment must exist in the authoritative company registry before an operation can be queued.</p></section></div>';
            return;
        }
        $latest=$view['latest'];$counts=$view['counts'];$last_binding=$view['last_binding'];
        $last_route=$last_binding['route']??'No verified route yet';
        $fresh=$last_binding&&!empty($last_binding['finished_at'])&&time()-(strtotime($last_binding['finished_at'])?:0)<86400;
        echo '<section class="mad4b-so-site" aria-label="Selected site"><div><div class="mad4b-so-overline">Registered target</div><h2>'.esc_html($site_title).' <span>'.esc_html(ucfirst($environment)).'</span></h2>';
        echo '<a href="'.esc_url($view['origin']).'" target="_blank" rel="noopener noreferrer">'.esc_html($view['origin']).' <span aria-hidden="true">↗</span></a></div>';
        echo '<div class="mad4b-so-identity"><span>Site UUID</span><code>'.esc_html($view['site_uuid']).'</code>';
        echo '<button type="button" class="button button-small mad4b-so-copy" data-mad4b-copy="'.esc_attr($view['site_uuid']).'" aria-label="Copy site UUID">Copy UUID</button></div></section>';
        echo '<section class="mad4b-so-kpis" aria-label="Operation summary">';
        $blocks=[['Queued',$counts['queued'],'Current requests'],['Completed / not due',$counts['completed'],'Latest operation results'],
            ['Attention required',$counts['attention'],'Current operation findings'],['Latest verified route',$last_route,$fresh?'Verified within 24h':'Past readback · recheck to confirm']];
        foreach($blocks as $i=>$a)echo '<div class="mad4b-so-kpi"><span>'.esc_html((string)$a[0]).'</span><strong'.($i===3?' class="mad4b-so-kpi-route"':'').'>'.esc_html((string)$a[1]).'</strong><small>'.esc_html((string)$a[2]).'</small></div>';
        echo '</section>';
        echo '<div class="mad4b-so-main-head"><div><h2>Available operations</h2><p>Operations use the original Site Profile and execution policy. A queued request is never treated as proof of completion.</p></div>';
        echo '<div class="mad4b-so-actions"><button type="button" class="button" id="mad4b-so-refresh">Refresh status</button><span id="mad4b-so-refresh-status" role="status" aria-live="polite">'.($counts['queued']?'Automatic while queued':'Manual refresh').'</span></div></div>';
        echo '<div class="mad4b-so-operation-grid">';
        foreach(self::LABELS as $operation=>$labels){
            $plan=MAD4B_Company_Site_Operations_V1::plan($company,$environment,$operation);
            $row=$latest[$operation]??null;$status=$row['state']??'NOT_CHECKED';
            $queued=$status==='QUEUED';
            echo '<article class="mad4b-so-operation" data-operation="'.esc_attr($operation).'" data-last-request-id="'.esc_attr((string)($row['request_id']??'')).'">';
            echo '<header><h3>'.esc_html($labels[0]).'</h3><span class="mad4b-so-current-state">'.($row?self::pill($status):'<span class="mad4b-so-pill mad4b-so-pill--unknown">Not checked</span>').'</span></header>';
            echo '<p class="mad4b-so-operation-desc">'.esc_html($labels[1]).'</p>';
            echo '<p class="mad4b-so-guide">'.esc_html($row?$row['guidance']:'Choose this operation to request its first exact-target readback.').'</p>';
            echo '<div class="mad4b-so-operation-meta"><span>Last result</span><strong>'.($row?self::esc_date($row['finished_at']?:$row['requested_at']):'Not checked').'</strong>';
            echo '<span>Execution route</span><strong>'.esc_html($row['route']??'Verified when dispatched').'</strong></div>';
            echo '<form class="mad4b-so-run" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="mad4b_company_site_ops_request" />';
            foreach(['company'=>$company,'environment'=>$environment,'operation'=>$operation,'expected_plan'=>$plan['plan_sha256']] as $key=>$val)
                echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($val).'" />';
            wp_nonce_field('mad4b_company_site_ops_'.$plan['plan_sha256']);
            $action_label=$queued?'Already queued':
                ($status==='NOT_DUE'?'Recheck schedule':
                  (in_array($status,['BLOCKED','FAILED','EXPIRED','NOT_LOCALLY_BOUND'],true)?'Recheck':
                    ($status==='COMPLETED'&&$operation!=='site.readiness.run'?'Verify again':
                      ($row?'Run again':$labels[2]))));
            echo '<button type="submit" class="button'.($queued?'':' button-primary').'"'.($queued?' disabled aria-disabled="true"':'').'>'.esc_html($action_label).'</button>';
            echo '</form></article>';
        }
        echo '</div>';
        echo '<section class="mad4b-so-execution" aria-label="Execution routing"><div><h2>Execution routing</h2><p>The host worker chooses the first eligible route after verifying the exact company, environment, origin and site UUID.</p></div>';
        echo '<div class="mad4b-so-lanes"><span>Local WP-CLI</span><span>VPS Client MCP / pinned Cross-SSH</span><span>Native Host Runner</span></div>';
        echo '<p>Routes are candidates until an operation-specific receipt confirms the selected lane. Fallback is permitted only before dispatch; remote writes still require the original signed authorization.</p></section>';
        echo '<section class="mad4b-so-history" aria-label="Recent operation receipts"><div class="mad4b-so-main-head"><div><h2>Recent requests & receipts</h2><p>History for this selected company and environment, newest first.</p></div>';
        echo '<div class="mad4b-so-history-actions"><label for="mad4b-so-history-attention"><input type="checkbox" id="mad4b-so-history-attention" /> Needs attention only</label>';
        echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>self::PAGE,'company'=>$company,'environment'=>$environment],admin_url('admin.php'))).'">Reload history</a></div></div>';
        echo '<div class="mad4b-so-table-scroll"><table class="widefat striped"><thead><tr><th scope="col">Requested</th><th scope="col">Operation</th><th scope="col">Result</th><th scope="col">Route</th><th scope="col">Evidence</th></tr></thead><tbody id="mad4b-so-receipts">';
        if(!$view['rows'])echo '<tr><td colspan="5">No requests recorded for this site.</td></tr>';
        foreach($view['rows'] as $row){
            $name=self::LABELS[$row['operation']][0]??($row['operation']==='site.presentation.inspect'?'Optional visual QA (legacy)':$row['operation']);
            echo '<tr data-receipt-state="'.esc_attr($row['state']).'"><td>'.self::esc_date($row['requested_at']).'</td><td><strong>'.esc_html($name).'</strong><small>'.esc_html(substr($row['request_id'],0,8)).'</small></td><td>'.self::pill($row['state']).'<small>'.esc_html($row['guidance']).'</small></td><td>'.esc_html($row['route']).'</td><td>';
            if($row['evidence']){
                echo '<code title="'.esc_attr($row['evidence']).'">'.esc_html(substr($row['evidence'],0,12)).'…</code>';
                echo '<button type="button" class="button-link mad4b-so-copy" data-mad4b-copy="'.esc_attr($row['evidence']).'" aria-label="Copy evidence SHA-256 for '.esc_attr($row['operation']).'">Copy</button>';
            }
            else echo 'Pending';
            echo '</td></tr>';
        }
        echo '</tbody></table></div></section>';
        echo '<p class="mad4b-so-footnote">Receipts contain operation-specific readback evidence, not an Original 313 release approval. Secret material is never displayed.</p>';
        echo '</div>';
    }
}
