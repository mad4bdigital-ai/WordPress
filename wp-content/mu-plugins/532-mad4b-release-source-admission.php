<?php
/**
 * Release source selection + signed host preflight admission.
 * This layer never grants a release or modifies a company/site runtime.
 * The original release, owner, authorization and rollback policies still apply.
 */
defined('ABSPATH') || exit;
final class MAD4B_Release_Source_Admission {
    const CONTRACT='mad4b.release-source-admission.v1';
    const OPTION='mad4b_release_source_request_v1';
    const ACTION='mad4b_release_source_request';
    const ABILITY='mad4b/release-source-preflight-status';
    const MAX_AGE=900;
    const EVIDENCE_SUBDIR='mad4b-source-preflight';

    public static function boot() {
        add_action('admin_post_'.self::ACTION,array(__CLASS__,'handle_request'));
        add_action('wp_abilities_api_init',array(__CLASS__,'register_ability'),69);
    }
    private static function dir() {return WP_CONTENT_DIR.'/mu-plugins/'.self::EVIDENCE_SUBDIR.'/';}
    public static function request() {
        $v=get_option(self::OPTION,array());
        return is_array($v)?$v:array();
    }
    public static function validate_selection($kind,$ref) {
        if(!in_array($kind,array('local_integration','live_toursdev','github_master','github_candidate'),true))
            return new WP_Error('mad4b_release_source_kind_invalid','Unknown allowlisted source kind.');
        if(in_array($kind,array('local_integration','github_candidate'),true)) {
            if(!is_string($ref)||strlen($ref)>120||!preg_match('#^[A-Za-z0-9_][A-Za-z0-9._/-]*$#D',$ref)
                ||strpos($ref,'..')!==false||strpos($ref,'//')!==false||substr($ref,-1)==='/'
                ||substr($ref,-1)==='.'||substr($ref,-5)==='.lock')
                return new WP_Error('mad4b_release_source_ref_invalid','A bounded, safe Git branch is required.');
        }elseif($ref!=='')return new WP_Error('mad4b_release_source_ref_unexpected','This source does not accept a branch reference.');
        return array('type'=>$kind,'reference'=>$ref);
    }
    public static function can_read() {return current_user_can('manage_options');}
    public static function handle_request() {
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!self::can_read() || !current_user_can('update_plugins')
           || !class_exists('MAD4B_SCP_Site_Profile')
           || !MAD4B_SCP_Site_Profile::origin_enrolled()
           || !MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id()))
            wp_die('Enrolled administrator and update capability required.','',array('response'=>403));
        check_admin_referer(self::ACTION);
        $type=isset($_POST['source_kind'])&&is_string($_POST['source_kind'])
            ?sanitize_key(wp_unslash($_POST['source_kind'])):'';
        $ref=isset($_POST['source_ref'])&&is_string($_POST['source_ref'])
            ?trim((string)wp_unslash($_POST['source_ref'])):'';
        if(!in_array($type,array('local_integration','github_candidate'),true))$ref='';
        $v=self::validate_selection($type,$ref);
        if(is_wp_error($v))wp_die(esc_html($v->get_error_message()),'',array('response'=>422));
        $v['site_uuid']=MAD4B_SCP_Site_Profile::site_uuid();
        $v['profile_digest']=MAD4B_SCP_Site_Profile::profile_digest();
        $v['requested_by']=(int)get_current_user_id();
        $v['requested_at']=time();
        $v['contract']='mad4b.release-source-request.v1';
        $v['authorizing']=false;
        update_option(self::OPTION,$v,false);
        wp_safe_redirect(admin_url('admin.php?page=mad4b-runtime-components&tab=maintenance#mad4b-release-source-selector'));
        exit;
    }
    /** Recompute the same sorted file->SHA-256 inventory as the host collector. */
    public static function current_site_inventory() {
        $root=WP_CONTENT_DIR.'/plugins/mad4b-site-control-plane';
        if(!is_dir($root)||is_link($root))return new WP_Error('mad4b_preflight_target_missing','Control Plane root missing');
        $items=array();$total=0;$limit=268435456;
        try {
            $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
            foreach($iterator as $file) {
                if($file->isLink())return new WP_Error('mad4b_preflight_target_symlink','Unexpected Control Plane symlink');
                if(!$file->isFile())continue;
                $path=$file->getPathname();
                $name='plugin/'.str_replace(DIRECTORY_SEPARATOR,'/',substr($path,strlen($root)+1));
                $size=$file->getSize();$total+=$size;
                if($total>$limit||count($items)>=6000||$size>$limit||!$file->isReadable())
                    return new WP_Error('mad4b_preflight_target_inventory_budget','Control Plane exceeds bounded verification');
                $hash=hash_file('sha256',$path);
                if($hash===false)return new WP_Error('mad4b_preflight_target_hash_failed','Unreadable Control Plane file');
                $items[$name]=$hash;
            }
            $mu=WP_CONTENT_DIR.'/mu-plugins';
            if(is_dir($mu)) {
                $list=scandir($mu);
                if(!is_array($list))return new WP_Error('mad4b_preflight_mu_listing_failed','Cannot inspect MU files');
                foreach($list as $name) {
                    if($name==='.'||$name==='..'||substr($name,-4)!=='.php'||stripos($name,'mad4b')===false)continue;
                    $file=$mu.'/'.$name;
                    if(is_link($file))return new WP_Error('mad4b_preflight_mu_symlink','Unexpected selected MU symlink');
                    if(!is_file($file))continue;
                    $size=filesize($file);$total+=$size;
                    if($total>$limit||count($items)>=6000||$size>$limit||!is_readable($file))
                        return new WP_Error('mad4b_preflight_mu_inventory_budget','Selected MU exceeds budget');
                    $hash=hash_file('sha256',$file);
                    if($hash===false)return new WP_Error('mad4b_preflight_mu_hash_failed','Unreadable MU file');
                    $items['mu/'.$name]=$hash;
                }
            }
        } catch(Throwable $e) {return new WP_Error('mad4b_preflight_inventory_exception','Cannot compute exact target inventory');}
        ksort($items,SORT_STRING);$lines='';
        foreach($items as $name=>$hash)$lines.=$name."\0".$hash."\n";
        return array('sha256'=>hash('sha256',$lines),'files'=>count($items));
    }
    private static function evidence_file() {return self::dir().'attestation.json';}
    /** A signed qualification is necessary, NEVER sufficient, for code deployment. */
    public static function check($expected_source_sha='') {
        $out=array('contract'=>self::CONTRACT,'preflight_ready'=>false,'authorizing'=>false,
                   'grant_created'=>false,'release_approved'=>false,'blockers'=>array(),
                   'source_sha'=>'','target_inventory_verified'=>false);
        $request=self::request();
        if(($request['contract']??'')!=='mad4b.release-source-request.v1'
           || !isset($request['type'],$request['reference']) || empty($request['site_uuid'])) {
            $out['blockers'][]='source_not_selected';return $out;
        }
        if(!class_exists('MAD4B_SCP_Site_Profile')
           || !MAD4B_SCP_Site_Profile::origin_enrolled()
           || !MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
           || (string)($request['site_uuid']??'')!==(string)MAD4B_SCP_Site_Profile::site_uuid()
           || !hash_equals((string)($request['profile_digest']??''),(string)MAD4B_SCP_Site_Profile::profile_digest())) {
            $out['blockers'][]='source_site_profile_changed';return $out;
        }
        $path=self::evidence_file();$public=self::dir().'release-public.pem';
        if(!is_file($path)||is_link($path)||!is_file($public)||is_link($public)) {
            $out['blockers'][]='signed_host_preflight_missing';return $out;
        }
        if(filesize($path)>16384 || filesize($public)>8192 || !function_exists('openssl_verify')) {
            $out['blockers'][]='signed_host_preflight_unavailable';return $out;
        }
        $envelope=json_decode((string)file_get_contents($path),true);
        if(!is_array($envelope)||array_keys($envelope)!==array('contract','payload_b64','signature_b64')
           ||($envelope['contract']??'')!=='mad4b.host-source-preflight-signature.v1') {
            $out['blockers'][]='signed_host_preflight_format_invalid';return $out;
        }
        $raw=base64_decode((string)$envelope['payload_b64'],true);
        $signature=base64_decode((string)$envelope['signature_b64'],true);
        $key=openssl_pkey_get_public((string)file_get_contents($public));
        if(!is_string($raw)||!is_string($signature)||$key===false
           ||openssl_verify($raw,$signature,$key,OPENSSL_ALGO_SHA256)!==1) {
            $out['blockers'][]='signed_host_preflight_signature_invalid';return $out;
        }
        $e=json_decode($raw,true);$now=time();
        if(!is_array($e)||($e['contract']??'')!=='mad4b.host-source-preflight.v1'
           ||!preg_match('/^[a-f0-9]{40}$/D',(string)($e['source_sha']??''))
           ||!preg_match('/^[a-f0-9]{64}$/D',(string)($e['target_inventory_sha256']??''))
           ||($e['reconciliation_ready']??false)!==true
           ||($e['source_manifest_ready']??false)!==true
           ||($e['authorizing']??null)!==false
           ||(int)($e['issued_at']??0)>$now+30
           ||(int)($e['expires_at']??0)<=$now
           ||(int)($e['expires_at']??0)-(int)($e['issued_at']??0)>self::MAX_AGE
           ||(string)($e['site_uuid']??'')!==(string)$request['site_uuid']
           ||(string)($e['profile_digest']??'')!==(string)$request['profile_digest']
           ||(string)($e['origin']??'')!==untrailingslashit(home_url())
           ||(string)($e['source_kind']??'')!==(string)$request['type']
           ||(string)($e['source_reference']??'')!==(string)$request['reference']
           ||(int)($e['issued_at']??0)<(int)($request['requested_at']??0)-30) {
            $out['blockers'][]='signed_host_preflight_binding_invalid';return $out;
        }
        $out['source_sha']=$e['source_sha'];
        if($expected_source_sha!==''&&!hash_equals((string)$expected_source_sha,(string)$e['source_sha'])) {
            $out['blockers'][]='selected_source_sha_mismatch';return $out;
        }
        $current=self::current_site_inventory();
        if(is_wp_error($current)||!hash_equals((string)$e['target_inventory_sha256'],(string)($current['sha256']??''))) {
            $out['blockers'][]='target_inventory_changed_after_preflight';return $out;
        }
        $out['target_inventory_verified']=true;
        $out['preflight_ready']=true;
        $out['receipt_sha256']=hash('sha256',$raw);
        $out['expires_at']=(int)$e['expires_at'];
        return $out;
    }
    public static function require_for_release($sha) {
        $result=self::check((string)$sha);
        return !empty($result['preflight_ready']) ? true
            : new WP_Error('mad4b_release_source_preflight_required',
                'A fresh, exact, signed Host reconciliation preflight must qualify the selected source and target before code release.',
                array('blockers'=>$result['blockers'],'source_sha'=>$result['source_sha']));
    }
    public static function register_ability() {
        if(!function_exists('wp_register_ability')||wp_has_ability(self::ABILITY))return;
        wp_register_ability(self::ABILITY,array(
            'label'=>'Release Source Preflight Status',
            'description'=>'Read signed Host reconciliation evidence without granting deploy authority.',
            'category'=>'mad4b-read','permission_callback'=>array(__CLASS__,'can_read'),
            'execute_callback'=>array(__CLASS__,'ability_status'),
            'input_schema'=>array('type'=>'object','additionalProperties'=>false),
            'output_schema'=>array('type'=>'object','additionalProperties'=>true),
            'meta'=>array('public'=>false,'show_in_rest'=>false,
                'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read','production_mutation_allowed'=>false),
                'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true)),
        ));
    }
    public static function ability_status($input=array()) {
        if(!self::can_read())return new WP_Error('mad4b_source_preflight_admin_required','Admin permission required');
        return array('selection'=>self::request(),'admission'=>self::check());
    }
    public static function host_report_status() {
        $path=self::dir().'status.json';
        if(!is_file($path)||is_link($path)||filesize($path)>10000)return array('state'=>'AWAITING_HOST_PREFLIGHT');
        $row=json_decode((string)file_get_contents($path),true);
        if(!is_array($row)||!in_array(($row['status']??''),array('NEEDS_RECONCILIATION','NEEDS_PACKAGE_PROVENANCE','READY_FOR_CERTIFICATION_REVIEW','BLOCKED'),true))
            return array('state'=>'AWAITING_HOST_PREFLIGHT');
        $req=self::request();
        if((int)($row['processed_at']??0)<(int)($req['requested_at']??0))
            return array('state'=>'AWAITING_HOST_PREFLIGHT');
        return array('state'=>$row['status'], 'conflicts'=>max(0,(int)($row['conflicts']??0)),
            'signed_qualification'=>!empty($row['signed']),
            'source_sha'=>preg_match('/^[a-f0-9]{40}$/D',(string)($row['source_sha']??'')) ? (string)$row['source_sha'] : '');
    }
    public static function render_selector() {
        if(!self::can_read())return;
        $r=self::request();$type=(string)($r['type']??'');$ref=(string)($r['reference']??'');
        echo '<section id="mad4b-release-source-selector" class="mad4b-scp-panel"><h3>Release Source &amp; Reconciliation Preflight</h3>';
        echo '<p>Choose the source to inspect. The host must independently reconcile and sign the exact source/target. Selection never installs code or issues a grant.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="'.esc_attr(self::ACTION).'">';
        wp_nonce_field(self::ACTION);
        echo '<p><label>Release source <select name="source_kind">';
        $types=array('local_integration'=>'Local Git branch / master','live_toursdev'=>'Live Tours.dev code','github_master'=>'GitHub master','github_candidate'=>'GitHub candidate branch');
        foreach($types as $key=>$title)echo '<option value="'.esc_attr($key).'" '.selected($type,$key,false).'>'.esc_html($title).'</option>';
        echo '</select></label></p><p><label>Branch (only for local/candidate) <input class="regular-text" name="source_ref" maxlength="120" value="'.esc_attr($ref).'" placeholder="integration/... or feature/..."></label></p>';
        if(current_user_can('update_plugins'))echo '<button type="submit" class="button button-secondary">Select &amp; request preflight</button>';
        echo '</form>';
        $status=self::check();$host=self::host_report_status();
        echo '<p><strong>Source status:</strong> '.esc_html($type?:'UNSELECTED').($ref?' / <code>'.esc_html($ref).'</code>':'').'</p>';
        echo '<p><strong>Host reconciliation:</strong> '.esc_html($host['state']).'</p>';
        if(isset($host['conflicts']))echo '<p><strong>Differences awaiting review:</strong> '.(int)$host['conflicts'].'</p>';
        echo '<p><strong>Signed Host preflight:</strong> '.esc_html(!empty($status['preflight_ready'])?'QUALIFIED FOR RELEASE REVIEW':'NOT QUALIFIED').'</p>';
        if(!empty($status['blockers']))echo '<p><small>Blockers: '.esc_html(implode(', ',$status['blockers'])).'</small></p>';
        echo '<p><em>This screen does not approve or execute a release. Original signed package, owner, grant and rollback gates remain mandatory.</em></p></section>';
    }
}
MAD4B_Release_Source_Admission::boot();
