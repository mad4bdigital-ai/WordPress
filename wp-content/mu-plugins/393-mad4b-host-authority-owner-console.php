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
        if (!is_admin()) return;
        add_action('admin_menu', array(__CLASS__, 'menu'), 35);
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
        }
        if ($out['anchor_revision']!==0
            ||$out['anchor_state']!=='MISSING_OR_INVALID') {
            $out['phase']='ANCHOR_STATE_REVIEW';
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
            esc_html__('Company administrator access required.','mad4b-site-control-plane'),'',array('response'=>403));
        $s=self::snapshot();
        echo '<div class="wrap" id="mad4b-host-authority-app" style="max-width:1000px">';
        echo '<h1>Host Authority</h1>';
        echo '<p class="description">MAD4B Master Admin / Control Plane — authenticated owner approval for the first Anchor.</p>';
        echo '<style>
        #mad4b-host-authority-app .mad4b-ha-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:18px 22px;margin:18px 0}
        #mad4b-host-authority-app .mad4b-ha-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(195px,1fr));gap:12px}
        #mad4b-host-authority-app .mad4b-ha-key{font-size:12px;color:#50575e;display:block;margin-bottom:4px}
        #mad4b-host-authority-app .mad4b-ha-value{font-size:15px;font-weight:600;overflow-wrap:anywhere}
        #mad4b-host-authority-app .mad4b-ha-step{border-top:1px solid #e2e4e7;margin-top:20px;padding-top:20px}
        #mad4b-host-authority-app #mad4b-ha-result{white-space:pre-wrap;overflow-wrap:anywhere}
        #mad4b-host-authority-app .mad4b-ha-notice{background:#f6f7f7;border-left:4px solid #2271b1;padding:12px 14px;margin-top:12px}
        #mad4b-host-authority-app input[type=text]{max-width:100%;width:490px}
        #mad4b-host-authority-app button[disabled]{cursor:not-allowed;opacity:.6}
        </style>';
        echo '<section class="mad4b-ha-card" aria-labelledby="mad4b-ha-heading">';
        echo '<h2 id="mad4b-ha-heading">Issue First Host Authority Anchor</h2>';
        echo '<div class="mad4b-ha-grid">';
        foreach (array(
            'Company'=>'All Royal Egypt',
            'Environment'=>'Staging',
            'Host'=>'hosting-2',
            'Original328'=>$s['original328_active']?'Active':'Unavailable',
            'Anchor state'=>$s['anchor_state'],
            'Revision'=>is_int($s['anchor_revision'])?(string)$s['anchor_revision']:'Unknown',
            'Owner session'=>$s['owner_verified']?'Verified prerequisites':'Not verified',
            'Workflow'=>$s['phase']
        ) as $label=>$value) {
            echo '<div><span class="mad4b-ha-key">'.esc_html($label).'</span>'
                .'<span class="mad4b-ha-value">'.esc_html($value).'</span></div>';
        }
        echo '</div><p class="mad4b-ha-notice"><strong>Scope:</strong> This page only stages a first-Anchor request via Original305. '
            .'It does not create the Host Anchor, enable Production, sign Original313 evidence, '
            .'or bypass the Original306/312/313 provider checks.</p>';
        if (!$s['owner_verified']) {
            echo '<div class="notice notice-warning inline"><p>Use the original enrolled WordPress owner account '
                .'or a separately verified Unified Administrator principal, with the active Original328 parent transport. '
                .'The <code>master_admin</code> company role alone is not an Original305 signing authority.</p></div>';
        } elseif ($s['phase']==='ANCHOR_STATE_REVIEW') {
            echo '<div class="notice notice-warning inline"><p>The signed Anchor state is no longer pristine Revision 0. '
                .'No first-Anchor request can be staged until independently reviewed.</p></div>';
        } else {
            echo '<div class="mad4b-ha-step"><h3>1. Approve owner Bootstrap Grant</h3>';
            echo '<p>Creates one operation-specific, non-executing Original305 Grant '
                .'against your existing Original328 transport. This does not issue an Anchor.</p>';
            echo '<p><label><input type="checkbox" id="mad4b-ha-owner-confirm"> '
                .'I approve a first Host Authority bootstrap request for All Royal Egypt / Staging only.</label></p>';
            echo '<p><button type="button" class="button button-primary" id="mad4b-ha-grant"'
                .(($s['grant_id']!=='')?' disabled':' disabled')
                .'>Approve Bootstrap Grant</button></p>';
            echo '<div class="mad4b-ha-step"><h3>2. Submit the independently prepared plan</h3>';
            echo '<p>An exact first-party Root plan SHA-256 is required. Do not invent or use a placeholder digest. '
                .'The pending Original312 signer must independently verify that plan after the real Owner Job is claimed.</p>';
            echo '<p><label for="mad4b-ha-plan">Verified Original312 plan SHA-256</label></p>';
            echo '<p><input type="text" id="mad4b-ha-plan" inputmode="latin"'
                .' minlength="64" maxlength="64" autocomplete="off" spellcheck="false"'
                .' pattern="[a-f0-9]{64}" aria-describedby="mad4b-ha-plan-help"></p>';
            echo '<p class="description" id="mad4b-ha-plan-help">Only a plan digest issued by the first-party Root plan preparer is acceptable. '
                .'If no plan exists yet, leave this field empty; your Bootstrap Grant can remain staged.</p>';
            echo '<p><button type="button" class="button button-secondary" '
                .'id="mad4b-ha-command" disabled>Queue Original305 Owner Request</button></p>';
            echo '<p><button type="button" class="button" id="mad4b-ha-refresh">Read Original305 Request Status</button></p>';
            echo '<p role="status" aria-live="polite" id="mad4b-ha-result" class="mad4b-ha-notice"> '
                .esc_html($s['request_id']!==''?'Original305 request already staged.':
                  ($s['grant_id']!==''?'Existing Bootstrap Grant detected. Plan submission remains a separate step.':
                      'Awaiting explicit owner approval.')).'</p>';
            echo '</div></div>';
        }
        echo '<p class="description">Last local request: '
            .esc_html($s['request_id']!==''?$s['request_status'].' (not an Anchor receipt)':'None')
            .'. Production remains unchanged.</p>';
        echo '</section></div>';
        self::render_owner_script($s);
    }
}
MAD4B_Host_Authority_Owner_Console_V106::boot();
