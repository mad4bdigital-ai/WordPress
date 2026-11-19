<?php
/**
 * Plugin Name: MAD4B Host Authority — Owner Bootstrap Console
 * Description: Owner-session-only UI for Original305 first Host Anchor request (All Royal Egypt Staging). Never issues host authority.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

/**
 * Display-only on GET; POSTs are made exclusively by the real owner's
 * browser to the already registered Original305 REST grants/commands.
 * This module never calls rest_do_request(), sets a current user, signs,
 * creates a plan or changes an Original312/313 provider.
 */
final class MAD4B_Host_Authority_Owner_Console_V106 {
    const PAGE = 'mad4b-host-authority';
    const COMPANY = 'all-royal-egypt';
    const ENV = 'staging';
    const OP = 'company.host-authority.anchor.issue';
    const PARENT = 'mad4b-control-plane';
    const COMPANY_PAGE = 'mad4b-company-host-authority';

    private static function can_view():bool {
        return current_user_can('manage_options')
            || current_user_can('mad4b_manage_company_operations');
    }
    public static function boot():void {
        // Owner-only GET; registered also for REST requests where is_admin()
        // is false. No mutation route or new owner approval mechanism.
        add_action('rest_api_init',array(__CLASS__,'register_read_route'),95);
        if (is_admin()) add_action('admin_menu',array(__CLASS__,'menu'),35);
    }
    public static function register_read_route():void {
        register_rest_route('mad4b/v1',
            '/central-execution-lanes/all-royal-egypt/host-authority-owner-state',
            array('methods'=>'GET',
                'permission_callback'=>array(__CLASS__,'read_permission'),
                'callback'=>array(__CLASS__,'read_state')));
    }
    public static function read_permission():bool {
        if (!self::can_view()) return false;
        $s=self::snapshot();
        return ($s['owner_verified']===true
            && $s['source_ready']===true);
    }
    public static function read_state(WP_REST_Request $request):WP_REST_Response {
        // Route permission_callback verifies the same Original328 owner
        // binding as the admin screen. No Grant, credential or nonce is emitted.
        if (!self::read_permission()) {
            return new WP_REST_Response(
                array('error'=>'owner_read_not_authorized'),403);
        }
        $s=self::snapshot();
        $body=array();
        foreach(array(
            'phase','owner_verified','original328_active',
            'anchor_state','anchor_revision','grant_id',
            'request_id','request_status','request_expires_epoch',
            'request_expired','broker_job_exists','broker_job_state',
            'broker_job_expires_epoch','signature_window_expired',
            'plan_sha256','plan_source'
        ) as $field) {
            // grant_id is a nonsecret identifier already on the owner page;
            // do not expose transport principal, signed payloads or credentials.
            $body[$field]=$s[$field];
        }
        $body['server_epoch']=time();
        $body['company']=self::COMPANY;
        $body['environment']=self::ENV;
        $response=new WP_REST_Response($body,200);
        $response->header('Cache-Control','private, no-store, max-age=0');
        $response->header('X-Content-Type-Options','nosniff');
        return $response;
    }
    public static function menu():void {
        add_submenu_page(self::PARENT,
            'Host Authority — All Royal Egypt / Staging',
            'Host Authority', 'manage_options',
            self::PAGE, array(__CLASS__, 'screen'));
        // The company master_admin role deliberately lacks WP super-admin
        // capabilities. Show a read-only entry under its native Company menu,
        // never grant authority merely because this submenu is visible.
        add_submenu_page('mad4b-company',
            'Host Authority — All Royal Egypt / Staging',
            'Host Authority', 'mad4b_manage_company_operations',
            self::COMPANY_PAGE, array(__CLASS__, 'screen'));
    }
    private static function source_ready():bool {
        return class_exists('MAD4B_Central_Dual_Lane_Registry_V1', false)
            && MAD4B_Central_Dual_Lane_Registry_V1::central()
            && class_exists('MAD4B_Central_SSH_Command_Intake_V1', false)
            && class_exists('MAD4B_Company_Native_WPCLI_Enrollment_V1', false)
            && class_exists('MAD4B_Original_Remote_Hostinger_328_V1', false)
            && class_exists('MAD4B_Original_Native_Operation_Providers_V1', false)
            && MAD4B_Original_Native_Operation_Providers_V1::
                first_anchor_bootstrap_intake_registered(self::OP, 'host','host.anchor.ref.v2');
    }
    private static function allowed_owner(int $user):bool {
        if ($user<=0 || !is_user_logged_in()) return false;
        // Mirror Original305's TWO allowed identities without promoting
        // master_admin, and always bind this browser actor to Original328.
        if (class_exists('MAD4B_Company_Manager_Unified_Administrator_Principal',false)
            && MAD4B_Company_Manager_Unified_Administrator_Principal::verified())
            return true;
        return current_user_can('manage_options')
            && current_user_can('activate_plugins')
            && class_exists('MAD4B_SCP_Site_Profile', false)
            && MAD4B_SCP_Site_Profile::user_is_enrolled($user);
    }
    private static function find_owned(string $prefix, int $user, string $transport_id, string $kind):?array {
        global $wpdb;
        if (!($wpdb instanceof wpdb) || !preg_match('/^[a-f0-9]{32}$/D',$transport_id))
            return null;
        // Bounded, read-only lookup; newest first. No Option writes, no secrets.
        $names=$wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 100",
            $wpdb->esc_like($prefix).'%'));
        if (!is_array($names)) return null;
        foreach ($names as $name) {
            if (!is_string($name) || !str_starts_with($name,$prefix)) continue;
            $row=get_option($name,false);
            if (!is_array($row)
                || ($row['company']??null)!==self::COMPANY
                || ($row['environment']??null)!==self::ENV
                || (int)($row['central_principal_id']??0)!==$user
                || ($row['transport_id']??null)!==$transport_id
                || ($row['operation']??null)!==self::OP
                || ($row['host_v2_bootstrap_only']??null)!==true) continue;
            if ($kind==='grant'
                && ($row['state']??'')==='CENTRAL_OPERATION_SCOPE_GRANTED'
                && preg_match('/^[a-f0-9]{32}$/D',(string)($row['grant_id']??'')))
                return $row;
            if ($kind==='request'
                && preg_match('/^[a-f0-9]{32}$/D',(string)($row['request_id']??'')))
                return $row;
        }
        return null;
    }
    /** Read-only, fails closed, never confers authority. */
    public static function snapshot():array {
        $u=get_current_user_id();
        $out=array(
            'company'=>self::COMPANY, 'environment'=>self::ENV,
            'operation'=>self::OP,
            'source_ready'=>self::source_ready(),
            'owner_verified'=>false,'original328_active'=>false,
            'anchor_state'=>'UNKNOWN', 'anchor_revision'=>null,
            'host_id'=>'','host_principal'=>'',
            'site_uuid'=>'','transport_id'=>'',
            'grant_id'=>'', 'request_id'=>'','request_status'=>'',
            'request_expires_epoch'=>0,'request_expired'=>false,
            'broker_job_exists'=>false,'broker_job_state'=>'',
            'broker_job_expires_epoch'=>0,'signature_window_expired'=>false,
            // From an exact previously submitted Original305 Root Plan
            // reference, not a new signed Original312/313 approval.
            'plan_sha256'=>'','plan_source'=>'',
            'phase'=>'CHECK_PREREQUISITES'
        );
        if (!$out['source_ready']) return $out;
        $slot=get_option(MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.
                         self::COMPANY.'_'.self::ENV,false);
        if (!is_array($slot)) return $out;
        $tid=(string)($slot['transport_id']??'');
        $pid=(string)($slot['grant_id']??'');
        if (!preg_match('/^[a-f0-9]{32}$/D',$tid)
            ||!preg_match('/^[a-f0-9]{32}$/D',$pid)) return $out;
        $grant=get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$pid,false);
        $transport=get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.$tid,false);
        if (!is_array($grant) || !is_array($transport)) return $out;
        $out['original328_active']=MAD4B_Original_Remote_Hostinger_328_V1::active(
            $transport,$grant);
        $out['host_id']=(string)($transport['host_id']??'');
        $out['host_principal']=(string)($transport['host_principal']??'');
        $out['site_uuid']=(string)($slot['site_uuid']??'');
        if (class_exists('MAD4B_Native_Host_Exact_Grant_V1',false)) {
            $anchor=MAD4B_Native_Host_Exact_Grant_V1::current_anchor_readback_v2(
                self::COMPANY,self::ENV);
            if (is_array($anchor)) {
                $out['anchor_state']=(string)($anchor['state']??'UNKNOWN');
                $out['anchor_revision']=is_int($anchor['revision']??null)
                    ?$anchor['revision']:null;
            }
        }
        $current_actor_matches=(int)($grant['central_principal_id']??0)===$u
            && (int)($transport['central_principal_id']??0)===$u;
        $out['owner_verified']=self::allowed_owner($u)
            && $current_actor_matches && $out['original328_active']
            && ($transport['company']??null)===self::COMPANY
            && ($transport['environment']??null)===self::ENV
            && ($transport['state']??null)==='REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED'
            && $out['host_id']==='hosting-2'
            && preg_match('/^u[0-9]{9}$/D',$out['host_principal'])
            && $out['site_uuid']!=='' && ($grant['site_uuid']??null)===$out['site_uuid'];
        if (!$out['owner_verified']) {
            $out['phase']='OWNER_SESSION_REQUIRED';
            return $out;
        }
        $out['transport_id']=$tid;
        $existing=self::find_owned(MAD4B_Central_SSH_Command_Intake_V1::PREFIX,
                                   $u,$tid,'grant');
        $request=self::find_owned(MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX,
                                  $u,$tid,'request');
        if ($existing) $out['grant_id']=(string)$existing['grant_id'];
        if ($request) {
            $out['request_id']=(string)$request['request_id'];
            $out['request_status']=(string)($request['status']??'UNKNOWN');
            $out['request_expires_epoch']=(int)($request['expires_epoch']??0);
            if (class_exists('MAD4B_Central_VPS_Broker_V1',false)
                && preg_match('/^[a-f0-9]{32}$/D',$out['request_id'])) {
                $broker=get_option(MAD4B_Central_VPS_Broker_V1::JOB.
                    $out['request_id'],false);
                $out['broker_job_exists']=is_array($broker)
                    &&($broker['job_id']??null)===$out['request_id'];
                $out['broker_job_state']=$out['broker_job_exists']
                    ?(string)($broker['state']??'UNKNOWN'):'';
                if ($out['broker_job_exists']) {
                    $out['broker_job_expires_epoch']=(int)($broker['expires_epoch']??0);
                    $out['signature_window_expired']=
                        $out['broker_job_expires_epoch']>0
                        &&($out['broker_job_expires_epoch']<=time()
                            ||($out['request_expires_epoch']>0
                               &&$out['request_expires_epoch']<=time()))
                        &&in_array($out['broker_job_state'],
                            array('BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
                                  'QUEUED_FOR_VPS_BROKER_CLAIM'),true)
                        &&$out['anchor_revision']===0
                        &&$out['anchor_state']==='MISSING_OR_INVALID';
                }
            }
            $out['request_expired']=$out['request_expires_epoch']>0
                &&$out['request_expires_epoch']<=time();
            if($out['request_expired']&&!$out['broker_job_exists'])
                $out['request_status']='EXPIRED_OWNER_REQUEST_RENEWAL_REQUIRED';
            // Reuse only the exact typed Root Plan reference from the
            // owner's previously admitted request. Expires affect authority,
            // not the immutable plan's identity. Independent root verification
            // is still mandatory; this is never signing evidence.
            $identity=$request['host_anchor_identity']??null;
            $candidate=is_array($identity)
                ?(string)($identity['plan_sha256']??''):'';
            if(is_array($identity)
                &&array_keys($identity)===array(
                    'authority_mode','operation_id','plan_sha256',
                    'site_uuid','target_host_id')
                &&preg_match('/^[a-f0-9]{64}$/D',$candidate)
                &&($identity['authority_mode']??null)==='bootstrap'
                &&($identity['operation_id']??null)===self::OP
                &&($identity['site_uuid']??null)===$out['site_uuid']
                &&($identity['target_host_id']??null)==='hosting-2'
                &&($request['grant_id']??null)===$out['grant_id']
                &&($request['transport_id']??null)===$tid
                &&($request['command_format']??null)==='host.anchor.ref.v2'
                &&($request['target_scope']??null)==='host'
                &&($request['status']??null)==='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
                &&($request['execution_performed']??null)===false
                &&(!$out['broker_job_exists']
                    ||($broker['plan_sha256']??null)===$candidate)) {
                $out['plan_sha256']=$candidate;
                $out['plan_source']='previous_owner_request_reference';
            }
        }
        if ($out['anchor_revision']!==0
            ||$out['anchor_state']!=='MISSING_OR_INVALID') {
            $out['phase']='ANCHOR_STATE_REVIEW';
        } elseif ($out['signature_window_expired']) {
            // A past Original306 Claim is not a usable Original312/313
            // admission. Do not advertise this as actively signing.
            $out['phase']='INDEPENDENT_SIGNATURE_WINDOW_EXPIRED';
        } elseif ($out['request_expired']&&!$out['broker_job_exists']) {
            $out['phase']='OWNER_REQUEST_EXPIRED';
        } elseif ($out['request_id']!=='') {
            $out['phase']='ORIGINAL305_REQUEST_STAGED';
        } elseif ($out['grant_id']!=='') {
            $out['phase']='BOOTSTRAP_GRANT_STAGED';
        } else {
            $out['phase']='AWAITING_OWNER_APPROVAL';
        }
        return $out;
    }

    /**
     * Render the reviewed owner script after the page controls. The v106
     * source-less footer handle did not reliably initialize in the actual
     * WordPress admin page: PHP kept all buttons disabled even after owner
     * confirmation. On this protected admin page, deliver the vetted source
     * with its JSON-encoded session binding at the point of use instead.
     *
     * Does not add any new REST route or perform an Owner action on GET.
     */
    private static function render_owner_script(array $status):void {
        if (true!==$status['owner_verified']
            ||$status['phase']==='ANCHOR_STATE_REVIEW') return;
        $file=__DIR__.'/mad4b-host-authority-owner-ui/host-authority.js';
        if (!is_file($file) || !is_readable($file)) {
            echo '<p class="notice notice-error">Host Authority controls unavailable: owner script missing.</p>';
            return;
        }
        $script=file_get_contents($file);
        if (!is_string($script) || strlen($script)>=32768
            ||!str_contains($script,'MAD4B Host Authority v106')
            ||stripos($script,'</script')!==false) {
            echo '<p class="notice notice-error">Host Authority controls unavailable: script validation failed.</p>';
            return;
        }
        $config=array(
            'owner'=>true,
            'nonce'=>wp_create_nonce('wp_rest'),
            'restBase'=>wp_make_link_relative(rest_url(
                 'mad4b/v1/central-execution-lanes/'.self::COMPANY.'/')),
            'transportId'=>$status['transport_id'],
            'grantId'=>$status['grant_id'],
            'requestId'=>$status['request_id'],
            'requestStatus'=>$status['request_status'],
            'planSha256'=>$status['plan_sha256'],
            'planSource'=>$status['plan_source'],
            'requestExpired'=>$status['request_expired'],
            'requestExpiresEpoch'=>$status['request_expires_epoch'],
            'brokerJobExists'=>$status['broker_job_exists'],
            'brokerJobState'=>$status['broker_job_state'],
            'signatureWindowExpired'=>$status['signature_window_expired'],
            'brokerJobExpiresEpoch'=>$status['broker_job_expires_epoch'],
            'hostId'=>$status['host_id'],
            'hostPrincipal'=>$status['host_principal'],
            'siteUuid'=>$status['site_uuid'],
            'operation'=>self::OP,
            'phase'=>$status['phase']
        );
        $json=wp_json_encode($config,
            JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        if (!is_string($json)) return;
        // The script follows the button markup; it is not tied to whether
        // other plugins print or defer wp_footer / WP_Scripts handles.
        echo '<script id="mad4b-host-v108-owner-controls">';
        echo 'window.MAD4BHostAuthorityV106=',$json,';',"
";
        echo $script;
        echo "
",'</script>';
    }

    public static function screen():void {
        if (!self::can_view()) wp_die(
            esc_html__('Company administrator access required.','mad4b-site-control-plane'),
            '',array('response'=>403));
        $s=self::snapshot();
        $owner=$s['owner_verified']===true;
        $grant=$owner && $s['grant_id']!=='';
        $expired=$owner && $s['phase']==='OWNER_REQUEST_EXPIRED';
        $signatureExpired=$owner &&
            $s['phase']==='INDEPENDENT_SIGNATURE_WINDOW_EXPIRED';
        $queued=$owner && $s['request_id']!=='' && !$expired;
        $review=$s['phase']==='ANCHOR_STATE_REVIEW';
        $headline=$review?'Independent Anchor review required':
            ($signatureExpired?'Original306 Claim expired — Original312/313 not issued':
            ($expired?'Owner Request expired — renewal required':
            ($queued?'Owner request accepted':($grant?'Bootstrap Grant completed':
            ($owner?'Owner approval required':'Owner verification required')))));
        $requestText=$s['request_status']==='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
            ? 'Accepted — awaiting independent Host verification'
            : (string)$s['request_status'];
        echo '<div class="wrap" id="mad4b-host-authority-app">';
        echo '<style>
        #mad4b-host-authority-app{max-width:1120px;margin-top:22px}
        #mad4b-host-authority-app [hidden]{display:none!important}
        #mad4b-host-authority-app .ha-top{display:flex;justify-content:space-between;gap:16px;align-items:start;margin-bottom:18px}
        #mad4b-host-authority-app .ha-top h1{margin:0 0 5px}
        #mad4b-host-authority-app .ha-muted{color:#50575e}
        #mad4b-host-authority-app .ha-pill{display:inline-block;background:#f3f6fa;color:#215783;border:1px solid #a3bada;padding:8px 12px;border-radius:4px;font-weight:600}
        #mad4b-host-authority-app .ha-panel{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px 24px;margin:0 0 16px}
        #mad4b-host-authority-app .ha-panel h2{font-size:18px;margin:0 0 9px;line-height:1.4}
        #mad4b-host-authority-app .ha-panel p{line-height:1.6}
        #mad4b-host-authority-app .ha-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-top:18px;border-top:1px solid #eee;padding-top:16px}
        #mad4b-host-authority-app .ha-key{display:block;color:#646970;font-size:12px;margin-bottom:4px}
        #mad4b-host-authority-app .ha-value{display:block;font-size:14px;font-weight:600;overflow-wrap:anywhere}
        #mad4b-host-authority-app .ha-progress{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;list-style:none;padding:0;margin:0 0 16px}
        #mad4b-host-authority-app .ha-progress li{border:1px solid #dcdcde;border-radius:5px;background:#fff;padding:13px;min-width:0}
        #mad4b-host-authority-app .ha-progress li.is-done{background:#f3faf5;border-color:#a3d1b0}
        #mad4b-host-authority-app .ha-progress li.is-current{border:2px solid #2271b1;padding:12px}
        #mad4b-host-authority-app .ha-progress strong{display:block;font-size:13px}
        #mad4b-host-authority-app .ha-progress small{display:block;color:#646970;margin-top:5px}
        #mad4b-host-authority-app .ha-consent{display:flex;gap:9px;align-items:start;padding:12px;background:#f6f7f7;border:1px solid #e2e4e7;border-radius:4px;margin:16px 0}
        #mad4b-host-authority-app .ha-consent input{margin-top:2px;flex-shrink:0}
        #mad4b-host-authority-app .ha-consent label{line-height:1.5}
        #mad4b-host-authority-app .ha-input{font-family:ui-monospace,Consolas,monospace;width:100%;max-width:670px;box-sizing:border-box;padding:8px 10px}
        #mad4b-host-authority-app .ha-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px}
        #mad4b-host-authority-app .ha-actions .button{min-height:36px}
        #mad4b-host-authority-app .ha-note{border-left:3px solid #2271b1;background:#f6f7f7;padding:11px 13px;white-space:pre-wrap;overflow-wrap:anywhere;margin:12px 0}
        #mad4b-host-authority-app .ha-note.is-error{border-color:#b32d2e;background:#fcf0f1}
        #mad4b-host-authority-app .ha-id{font:600 13px/1.5 ui-monospace,Consolas,monospace;word-break:break-all}
        #mad4b-host-authority-app .ha-next{border-top:1px solid #e2e4e7;padding-top:15px;margin-top:17px}
        #mad4b-host-authority-app .ha-next h3{font-size:14px;margin:0 0 8px}
        #mad4b-host-authority-app .ha-fine{font-size:12px;color:#646970}
        #mad4b-host-authority-app button:disabled{opacity:.6;cursor:not-allowed}
        @media(max-width:960px){#mad4b-host-authority-app .ha-grid,#mad4b-host-authority-app .ha-progress{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:500px){#mad4b-host-authority-app .ha-top{display:block}#mad4b-host-authority-app .ha-top .ha-pill{margin-top:12px}#mad4b-host-authority-app .ha-panel{padding:16px}#mad4b-host-authority-app .ha-progress{grid-template-columns:1fr}}
        </style>';
        echo '<header class="ha-top"><div><h1>Host Authority</h1>'
            .'<p class="ha-muted">Issue First Host Authority Anchor · All Royal Egypt / Staging</p></div>'
            .'<span class="ha-pill" id="mad4b-ha-overall-state">'.esc_html($headline).'</span></header>';
        echo '<section class="ha-panel" aria-labelledby="mad4b-ha-summary">';
        echo '<h2 id="mad4b-ha-summary">'.esc_html($headline).'</h2>';
        echo '<p class="ha-muted">'.esc_html($signatureExpired
            ? 'Original306 accepted the Claim, but the signing window elapsed before Original312/313 issuance. The independent First Anchor issuer has not been commissioned; do not resubmit another owner request until the signed workflow is ready.'
            :($expired
            ? 'The previous Owner Request expired before Original306 Broker Dispatch. The Bootstrap Grant remains valid. Submit the verified Root Plan again with a fresh owner confirmation; the page will dispatch it to the existing broker immediately.'
            :($queued
            ? ($s['broker_job_exists']
                ? 'Original306 Broker Job is queued. Independent verification must finish before a Host Anchor can be issued.'
                : 'Original305 accepted the request but Broker Dispatch is not confirmed. Dispatch the existing request before its window expires.')
            :($grant
                ? 'The non-executing Bootstrap Grant is complete. The next step is to submit the verified Root Plan.'
                :'Verify your owner session and active Original328 connection before proceeding.')))).'</p>';
        echo '<div class="ha-grid">';
        foreach(array(
            'Company'=>'All Royal Egypt','Environment'=>'Staging','Host'=>'hosting-2',
            'Original328'=>$s['original328_active']?'Active':'Unavailable',
            'Anchor state'=>$s['anchor_state'],
            'Revision'=>is_int($s['anchor_revision'])?(string)$s['anchor_revision']:'Unknown',
            'Owner session'=>$owner?'Verified':'Not verified',
            'Workflow'=>$s['phase']
        ) as $label=>$value) {
            echo '<div><span class="ha-key">'.esc_html($label).'</span>'
                .'<span class="ha-value">'.esc_html($value).'</span></div>';
        }
        echo '</div></section>';
        echo '<ol class="ha-progress" aria-label="Host Authority workflow">';
        foreach(array(
            array('connection','Connection verified',$owner?'Complete':'Verification needed',$owner,!$owner),
            array('grant','Bootstrap Grant',$grant?'Complete':'Owner approval',$grant,$owner&&!$grant),
            array('request','Owner Request',$expired?'Expired — renew':($queued?'Accepted':'Not submitted'),$queued,$grant&&!$queued),
            array('verify','Independent verification','Original306 / 312 / 313',false,$queued)
        ) as $stage) {
            echo '<li id="mad4b-ha-step-'.esc_html($stage[0]).'" class="'
                .($stage[3]?'is-done':($stage[4]?'is-current':'')).'">'
                .'<strong>'.esc_html($stage[1]).'</strong>'
                .'<small id="mad4b-ha-step-'.esc_html($stage[0]).'-status">'
                .esc_html($stage[2]).'</small></li>';
        }
        echo '</ol>';
        if (!$owner) {
            echo '<div class="notice notice-warning inline"><p>Use the original enrolled WordPress owner account '
                .'or a separately verified Unified Administrator principal. The <code>master_admin</code> role alone '
                .'cannot grant Original305 authority.</p></div>';
        } elseif($review) {
            echo '<div class="notice notice-warning inline"><p>The signed Anchor is no longer pristine Revision 0. '
                .'Independent readback is required before a new first-Anchor request.</p></div>';
        } else {
            echo '<section class="ha-panel" id="mad4b-ha-grant-panel"'
                .($grant?' hidden':'').'>';
            echo '<h2>1. Approve Bootstrap Grant</h2>'
                .'<p class="ha-muted">Create one operation-specific, non-executing Original305 Grant. This does not issue an Anchor.</p>';
            echo '<div class="ha-consent"><input type="checkbox" id="mad4b-ha-owner-confirm">'
                .'<label for="mad4b-ha-owner-confirm">I approve a first Host Authority Bootstrap Grant for All Royal Egypt / Staging only.</label></div>';
            echo '<div class="ha-actions"><button type="button" class="button button-primary"'
                .' id="mad4b-ha-grant" disabled>Approve Bootstrap Grant</button></div></section>';

            echo '<section class="ha-panel" id="mad4b-ha-command-panel"'
                .((!$grant||$queued)?' hidden':'').'>';
            echo '<h2>2. '.($expired?'Renew expired Owner Request':'Submit verified Root Plan').'</h2>'
                .'<p class="ha-muted">'.($expired
                ?'The previous request expired without Broker Dispatch. The Bootstrap Grant is still complete. Confirm a fresh Owner Request using the original verified Root Plan. No Anchor will be issued from this page.'
                :'Bootstrap is complete. Submit an independently prepared Root Plan SHA-256 to queue an Owner Job, not an Anchor. The page then dispatches the accepted request to Original306.').'</p>';
            echo '<p class="ha-muted" id="mad4b-ha-plan-summary" aria-live="polite">'
                .($s['plan_sha256']!==''
                  ?'Root Plan reference loaded automatically from the owner record. '
                   .'Independent Original312/313 verification is still required.'
                  :'No existing Root Plan reference was found. Enter the verified Root Plan SHA-256 manually.')
                .'</p>';
            echo '<div id="mad4b-ha-plan-editor"'
                .($s['plan_sha256']!==''?' hidden':'').'>';
            echo '<p><label for="mad4b-ha-plan"><strong>Root Plan SHA-256</strong></label></p>';
            echo '<input class="ha-input" type="text" id="mad4b-ha-plan" inputmode="latin"'
                .' minlength="64" maxlength="64" autocomplete="off" spellcheck="false"'
                .' pattern="[a-f0-9]{64}" aria-describedby="mad4b-ha-plan-help"'
                .' placeholder="64-character SHA-256" value="'.esc_attr($s['plan_sha256']).'">';
            echo '<p class="ha-fine" id="mad4b-ha-plan-help">A Root Plan reference is not an Original312/313 signature. Only the original first-party plan is eligible.</p></div>';
            echo '<p><button type="button" class="button" id="mad4b-ha-edit-plan"'
                .($s['plan_sha256']===''?' hidden':'').'>Use a different verified Root Plan</button></p>';
            echo '<div class="ha-consent"><input type="checkbox" id="mad4b-ha-command-confirm">'
                .'<label for="mad4b-ha-command-confirm">I authorize submitting this Root Plan as an Owner Request for All Royal Egypt / Staging only.</label></div>';
            echo '<div class="ha-actions"><button type="button" class="button button-primary"'
                .' id="mad4b-ha-command" aria-label="Queue Original305 Owner Request" disabled>'
                .($expired?'Renew and dispatch Owner Request':'Submit and dispatch Owner Request').'</button></div></section>';

            echo '<section class="ha-panel" id="mad4b-ha-tracker"'
                .(!$queued?' hidden':'').'>';
            echo '<h2 id="mad4b-ha-tracker-title" tabindex="-1">3. Owner Request accepted</h2>';
            echo '<p>Your owner action is complete. Original305 accepted the job but has not issued a Host Anchor.</p>';
            echo '<p class="ha-muted">Request ID</p><p class="ha-id" id="mad4b-ha-request-id">'
                .esc_html($queued?$s['request_id']:'').'</p>';
            echo '<p id="mad4b-ha-tracker-status"><strong>Current status:</strong> '
                .esc_html($signatureExpired
                    ?'Expired signing window. Original306 Claim is historical; no Original312/313 Enrollment or Anchor Revision 1 has been issued.'
                    :($queued?($s['broker_job_exists']
                    ?'Original306 Broker Job: '.$s['broker_job_state']
                    :$requestText.' · dispatch not yet confirmed'):'Not submitted')).'</p>';
            echo '<div class="ha-actions"><button type="button" class="button button-primary" id="mad4b-ha-refresh"'
                .(!$queued?' disabled':'').'>Check latest status</button>'
                .'<button type="button" class="button" id="mad4b-ha-copy"'
                .(!$queued?' disabled':'').'>Copy request ID</button>'
                .'<button type="button" class="button" id="mad4b-ha-dispatch"'
                .(!$queued||$s['broker_job_exists']?' hidden disabled':'')
                .'>Retry Original306 Broker Dispatch</button></div>';
            echo '<p class="ha-fine" id="mad4b-ha-checked-at" aria-live="polite">'
                .'Live status is loaded automatically while this tab is open.</p>';
            echo '<div class="ha-next"><h3>What happens next?</h3>'
                .'<p class="ha-muted">'.($signatureExpired
                    ?'The current Job cannot be signed or replayed. The first-party Original312/313 issuing worker must be commissioned and independently verified before a fresh owner-approved request is useful.'
                    :'Independent Original306 claim and Original312/313 verification are required. Anchor issuance must be checked separately using an authoritative readback.').'</p>'
                .'<p class="ha-fine">Checking status is read-only and does not execute, sign, or approve anything.</p></div></section>';
            if($expired) echo '<p class="ha-fine" id="mad4b-ha-expired-note">'
                .'Earlier Owner Request expired without Broker Dispatch. '
                .'Fresh owner consent is required for a new request. Previous Request ID: '
                .esc_html($s['request_id']).'</p>';
            echo '<p id="mad4b-ha-result" class="ha-note" role="status" aria-live="polite"'
                .($queued?' hidden':'').'>'
                .esc_html($grant?'Bootstrap Grant complete. Submit the verified Root Plan when authorized.':
                    'Owner controls ready. Check the approval box to enable Bootstrap Grant.')
                .'</p>';
        }
        echo '<p class="ha-fine">Staging only. Production remains unchanged. '
            .'This page cannot bypass Original306/312/313 or sign Host Anchor evidence.</p>';
        echo '</div>';
        self::render_owner_script($s);
    }
}
MAD4B_Host_Authority_Owner_Console_V106::boot();
