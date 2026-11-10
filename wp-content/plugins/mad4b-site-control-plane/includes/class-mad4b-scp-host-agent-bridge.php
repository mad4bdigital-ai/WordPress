<?php
defined('ABSPATH') || exit;

/**
 * Bound Host WP-CLI Agent. The host's private Ed25519 signer stays on the VPS.
 * Authentication is a fresh host signature, never a WordPress user or OAuth
 * token. A Host signature by itself does not authorize an asset review.
 */
final class MAD4B_SCP_Host_Agent_Bridge {
    const CONTRACT='mad4b.host-agent-execution.v1';
    const KEY_FILE='mad4b-host-agent-public.b64';
    const REVIEW='mad4b/context-ai-review';
    const SUBJECT='signed_host_executor';
    private static $active=false;
    private static $operation='';

    public static function public_key() {
        $path=__DIR__.'/'.self::KEY_FILE;
        if(!is_file($path) || is_link($path) || filesize($path)>128)return '';
        $bytes=base64_decode(trim((string)file_get_contents($path)),true);
        return is_string($bytes)&&strlen($bytes)===32?$bytes:'';
    }
    public static function site() {
        if(!class_exists('MAD4B_SCP_Site_Profile',false))return array();
        $s=MAD4B_SCP_Site_Profile::status();
        if(empty($s['configured'])||empty($s['authority_ready'])
           ||empty($s['deployment_binding_match'])
           ||empty($s['origin_match'])||empty($s['environment_match'])
           ||empty($s['same_origin_clone_protection'])
           ||!empty($s['mutation_pending_audit']))return array();
        return array(
            'site_uuid'=>(string)($s['site_uuid']??''),
            'origin'=>rtrim((string)home_url('/'),'/'),
            'environment'=>(string)($s['environment']??''),
            'database_sha256'=>defined('DB_NAME')?hash('sha256',DB_NAME):'',
        );
    }
    private static function cli_ready() {
        if(!defined('WP_CLI')||WP_CLI!==true||PHP_SAPI!=='cli'
           ||get_current_user_id()!==0
           ||!empty($_SERVER['HTTP_AUTHORIZATION'])
           ||!class_exists('MAD4B_SCP_CLI_Execution_Context',false))return false;
        $c=MAD4B_SCP_CLI_Execution_Context::resolve('runtime.status.read',false);
        return is_array($c)&&!empty($c['read_context_verified'])
            &&empty($c['blockers']);
    }
    private static function fingerprint() {
        $pub=self::public_key();$s=self::site();
        if(!$pub||!$s)return '';
        return hash('sha256',implode(chr(0),array(
            'mad4b.host-agent-identity.v1', $s['site_uuid'],$s['origin'],
            $s['environment'],base64_encode($pub))));
    }
    public static function agent() {
        if(!self::public_key()||!self::site()
           ||!class_exists('MAD4B_SCP_Agent_Registry',false))return null;
        $slug=sanitize_title((string)MAD4B_SCP_Site_Profile::agent_slug());
        $agent=$slug?MAD4B_SCP_Agent_Registry::get_agent_by_slug($slug):null;
        if(!is_array($agent) || ($agent['status']??'')!=='enabled'
            ||(int)($agent['wp_user_id']??0)!==0
            ||($agent['environment']??'')!==self::site()['environment'])return null;
        $fp=self::fingerprint();
        $binding=MAD4B_SCP_Agent_Registry::subject_binding(self::SUBJECT,$fp);
        if(!is_array($binding)||($binding['status']??'')!=='enabled'
            ||(int)($binding['agent_id']??0)!==(int)$agent['id'])return null;
        return $agent;
    }
    public static function binding_ready() {
        $agent=self::agent();
        if(!$agent)return false;
        $grant=MAD4B_SCP_Agent_Registry::exact_grant(
            (int)$agent['id'],'mad4b-write',self::REVIEW,'core');
        return is_array($grant)&&($grant['effect']??'')==='allow'
            &&($grant['environment']??'')===self::site()['environment'];
    }
    public static function active() {
        return self::$active && self::cli_ready() && self::binding_ready();
    }
    public static function active_for($ability) {
        if(!self::active() || !is_string($ability)) return false;
        if(in_array($ability,array('mad4b/content-job-create',
            'mad4b/content-job-transition','mad4b/artifact-append'),true)
            && self::$operation!=='media_intake'
            &&!(in_array(self::$operation,
                ['media_proposal','media_strategy','media_reconcile'],true)
                && $ability==='mad4b/artifact-append')
            && !(self::$operation==='media_close'
                && in_array($ability,[
                    'mad4b/content-job-transition',
                    'mad4b/artifact-append'],true)))return false;
        $agent=self::agent();
        $grant=$agent ? MAD4B_SCP_Agent_Registry::exact_grant(
            (int)$agent['id'],'mad4b-write',$ability,'core') : null;
        return is_array($grant) && ($grant['effect']??'')==='allow'
            && ($grant['environment']??'')===self::site()['environment'];
    }
    public static function status() {
        $s=self::site();$agent=self::agent();
        $route=class_exists('MAD4B_SCP_Context_Authority',false)
            ? MAD4B_SCP_Context_Authority::review_policy():array();
        return array(
            'contract'=>self::CONTRACT,
            'site_uuid'=>$s['site_uuid']??'',
            'environment'=>$s['environment']??'',
            'transport'=>'vps_signed_wpcli',
            'host_trust_configured'=>self::public_key()!=='',
            'host_site_binding_verified'=>(bool)$s,
            'agent_registered'=>(bool)$agent,
            'review_grant_ready'=>self::binding_ready(),
            'effective_review_mode'=>$route['mode']??'human_only',
            'host_identity_wp_user_id'=>0,
            'oauth_required'=>false,
            'automatic_approvals'=>false,
            'execution_authorized'=>false,
        );
    }

    private static function verify(array $envelope) {
        if(!self::cli_ready())return new WP_Error('mad4b_host_cli_transport_required','Verified host WP-CLI transport required.');
        $pub=self::public_key();$site=self::site();
        if(!$pub||!$site)return new WP_Error('mad4b_host_trust_missing','Pinned host trust or site binding unavailable.');
        $p=$envelope['payload']??null;$body=$envelope['body']??null;
        $sig=base64_decode((string)($envelope['signature_b64']??''),true);
        if(!is_array($p)||!is_array($body)||!is_string($sig)||strlen($sig)!==64
           ||count($envelope)!==4||($envelope['contract']??'')!==self::CONTRACT)
           return new WP_Error('mad4b_host_envelope_invalid','Invalid host envelope.');
        $expected_fields=['contract','site_uuid','origin','environment','database_sha256',
          'operation','body_sha256','nonce','issued_at','expires_at'];
        $actual=array_keys($p);sort($actual);sort($expected_fields);
        if($actual!==$expected_fields ||($p['contract']??'')!==self::CONTRACT)
            return new WP_Error('mad4b_host_payload_invalid','Host payload schema mismatch.');
        foreach($site as $k=>$v)
            if(!isset($p[$k])||!is_string($p[$k])||!hash_equals($v,$p[$k]))
                return new WP_Error('mad4b_host_site_drift','Host signature is for a different site or database.');
        if(!in_array($p['operation'],['bind','review','policy','media_intake','media_proposal','media_strategy','media_reconcile','media_close','media_associate','media_apply'],true)
           ||!is_string($p['nonce'])||!preg_match('/^[a-f0-9]{32}$/D',$p['nonce'])
           ||!is_int($p['issued_at'])||!is_int($p['expires_at'])
           ||$p['issued_at']>time()+5||$p['issued_at']<time()-55
           ||$p['expires_at']<=time()||$p['expires_at']>$p['issued_at']+60)
           return new WP_Error('mad4b_host_attestation_expired','Host attestation expired or operation invalid.');
        $body_json=($p['operation']==='bind' && !$body) ? '{}' : wp_json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($body_json)||!hash_equals(hash('sha256',$body_json),(string)$p['body_sha256']))
           return new WP_Error('mad4b_host_body_drift','Signed host operation body mismatch.');
        ksort($p,SORT_STRING);
        $json=wp_json_encode($p,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($json)||!sodium_crypto_sign_verify_detached($sig,$json,$pub))
           return new WP_Error('mad4b_host_signature_invalid','Host signature verification failed.');
        // Atomic, one-time anti-replay. An old proof never becomes a grant.
        $nonce_key='mad4b_host_nonce_'.hash('sha256',$p['nonce'].$site['site_uuid']);
        if(!add_option($nonce_key,$p['expires_at'],'','no'))
            return new WP_Error('mad4b_host_proof_replayed','Host attestation replayed.');
        return $p;
    }
    private static function bind() {
        if(!class_exists('MAD4B_SCP_Schema',false)||!MAD4B_SCP_Schema::is_ready()
           ||!class_exists('MAD4B_SCP_Audit',false)
           ||empty(MAD4B_SCP_Audit::storage_status()['ready']))
            return new WP_Error('mad4b_host_registry_not_ready','Registry or append-only audit unavailable.');
        $site=self::site();
        $slug=sanitize_title((string)MAD4B_SCP_Site_Profile::agent_slug());
        if(!$slug || !function_exists('wp_has_ability') || !wp_has_ability(self::REVIEW))
            return new WP_Error('mad4b_host_review_ability_missing','Exact canonical review ability unavailable.');
        $lock=MAD4B_SCP_Distributed_Lock::catalog_name('host-agent-bind:'.$site['site_uuid']);
        $held=MAD4B_SCP_Distributed_Lock::acquire($lock);
        if(is_wp_error($held))return $held;
        try{
            $agent=MAD4B_SCP_Agent_Registry::get_agent_by_slug($slug);
            if($agent && ((int)($agent['wp_user_id']??0)!==0
                ||($agent['environment']??'')!==$site['environment']))
                return new WP_Error('mad4b_host_agent_conflict','Canonical agent belongs to another principal or environment.');
            if(!$agent){
                $agent=MAD4B_SCP_Agent_Registry::create_agent(array(
                    'slug'=>$slug,'label'=>'ChatGPT via signed MAD4B Host WP-CLI',
                    'status'=>'enabled','environment'=>$site['environment'],'wp_user_id'=>0));
                if(is_wp_error($agent))return $agent;
            } elseif(($agent['status']??'')!=='enabled'){
                $agent=MAD4B_SCP_Agent_Registry::update_agent(
                    $agent['public_id'],['status'=>'enabled'],(int)$agent['revision']);
                if(is_wp_error($agent))return $agent;
            }
            $fp=self::fingerprint();
            $bound=MAD4B_SCP_Agent_Registry::bind_subject($agent['public_id'],
                self::SUBJECT,$fp,'Attested MAD4B VPS Host Executor');
            if(is_wp_error($bound))return $bound;
            $grant=MAD4B_SCP_Agent_Registry::exact_grant(
                (int)$agent['id'],'mad4b-write',self::REVIEW,'core');
            if(is_wp_error($grant)&&$grant->get_error_code()!=='mad4b_nhi_grant_missing')return $grant;
            if(is_wp_error($grant)){
                $created=MAD4B_SCP_Agent_Registry::grant_ability($agent['public_id'],
                    'mad4b-write',self::REVIEW,'core',[],'allow',$site['environment']);
                if(is_wp_error($created))return $created;
            } elseif(($grant['effect']??'')!=='allow')
                return new WP_Error('mad4b_host_review_grant_denied','Exact review grant is denied.');
            // Scoped ContentJob intake. No publish or live-post mutation grant.
            foreach(['mad4b/content-job-create','mad4b/content-job-transition','mad4b/artifact-append'] as $ability){
                if(!function_exists('wp_has_ability') || !wp_has_ability($ability))
                    return new WP_Error('mad4b_host_content_ability_missing',
                        'Original MAD4B ContentJob ability is unavailable.');
                $g=MAD4B_SCP_Agent_Registry::exact_grant(
                    (int)$agent['id'],'mad4b-write',$ability,'core');
                if(is_wp_error($g)){
                    if($g->get_error_code()!=='mad4b_nhi_grant_missing')return $g;
                    $g=MAD4B_SCP_Agent_Registry::grant_ability(
                        $agent['public_id'],'mad4b-write',$ability,
                        'core',[],'allow',$site['environment']);
                    if(is_wp_error($g))return $g;
                }elseif(($g['effect']??'')!=='allow'
                    ||($g['environment']??'')!==$site['environment'])
                    return new WP_Error('mad4b_host_content_grant_conflict',
                        'Existing explicit ContentJob grant differs from Host scope.');
            }
            if(!self::binding_ready())
                return new WP_Error('mad4b_host_binding_readback_failed','Host binding was not confirmed.');
            $audit=MAD4B_SCP_Audit::record('mad4b/host-agent-exact-bind',[
                'site_uuid'=>$site['site_uuid'],'environment'=>$site['environment'],
                'agent_public_id'=>$agent['public_id'],'subject_type'=>self::SUBJECT,
                'subject_fingerprint_sha256'=>hash('sha256',$fp),
                'granted_ability'=>self::REVIEW,'wp_user_id'=>0,
                'connection_transport'=>'signed_wpcli','oauth_used'=>false,
                'review_mode_changed'=>false,'review_decisions_created'=>0]);
            if(is_wp_error($audit))return $audit;
            return ['status'=>'bound','agent_public_id'=>$agent['public_id']]
                   +self::status();
        }finally{MAD4B_SCP_Distributed_Lock::release($lock);}
    }
    public static function change_review_policy(array $body) {
        if(!self::active())return new WP_Error('mad4b_host_policy_not_attested',
            'Signed Host Agent required before review policy changes.');
        if(count($body)!==2 || !array_key_exists('mode',$body)
            || !array_key_exists('confirmation',$body)
            ||($body['confirmation']??'')!=='CONFIRM HOST AI REVIEW MODE')
            return new WP_Error('mad4b_host_policy_explicit_intent_required',
                'Exact host review-mode confirmation required.');
        $mode=sanitize_key((string)($body['mode']??''));
        if(!in_array($mode,['human_only','human_and_ai','ai_only'],true))
            return new WP_Error('mad4b_host_policy_mode_invalid','Unknown review mode.');
        $agent=self::agent();
        $id=$mode==='human_only'?'':(string)$agent['public_id'];
        $result=MAD4B_SCP_Context_Authority::set_review_policy($mode,$id,true);
        if(is_wp_error($result))return $result;
        $readback=MAD4B_SCP_Context_Authority::review_policy();
        if($readback['mode']!==$mode ||(string)$readback['ai_agent_public_id']!==$id)
            return new WP_Error('mad4b_host_policy_readback_failed',
                'Review policy did not persist as expected.');
        $event=MAD4B_SCP_Audit::record('mad4b/host-review-policy-signed-change',[
            'mode'=>$mode,'agent_public_id'=>$id,
            'site_uuid'=>self::site()['site_uuid'],
            'identity_method'=>'signed_host_wpcli',
            'wp_user_id'=>0,'grant_escalation'=>false]);
        if(is_wp_error($event))return $event;
        return ['state'=>'policy_applied','mode'=>$mode,
            'agent_public_id'=>$id,'readback_verified'=>true,
            'oauth_used'=>false,'wp_user_id'=>0];
    }

    public static function review(array $body) {
        if(!self::active())return new WP_Error('mad4b_host_review_not_attested','Fresh signed Host execution required.');
        if(!class_exists('MAD4B_SCP_Context_Authority',false))
            return new WP_Error('mad4b_host_context_unavailable','Context Authority unavailable.');
        return MAD4B_SCP_Context_Authority::review_asset_by_host_agent($body,self::agent(),self::fingerprint());
    }
    public static function execute(array $envelope) {
        $proof=self::verify($envelope);
        if(is_wp_error($proof))return $proof;
        self::$active=true;
        self::$operation=(string)$proof['operation'];
        try{
            if($proof['operation']==='bind') {
                if($envelope['body']!==[])return new WP_Error('mad4b_host_bind_body_invalid','Bind has no user-defined parameters.');
                return self::bind();
            }
            if($proof['operation']==='policy')
                return self::change_review_policy((array)$envelope['body']);
            if($proof['operation']==='media_intake'){
                require_once __DIR__.'/class-mad4b-scp-host-media-intake.php';
                return MAD4B_SCP_Host_Media_Intake::execute((array)$envelope['body']);
            }
            if($proof['operation']==='media_proposal'){
                require_once __DIR__.'/class-mad4b-scp-host-media-intake.php';
                return MAD4B_SCP_Host_Media_Intake::propose_localized_alt(
                    (array)$envelope['body']);
            }
            if($proof['operation']==='media_strategy'){
                require_once __DIR__.'/class-mad4b-scp-host-visual-strategy-proposal.php';
                return MAD4B_SCP_Host_Visual_Strategy_Proposal::execute(
                    (array)$envelope['body']);
            }
            if($proof['operation']==='media_reconcile'){
                require_once __DIR__.'/class-mad4b-scp-host-media-reconcile-proposal.php';
                return MAD4B_SCP_Host_Media_Reconcile_Proposal::execute(
                    (array)$envelope['body']);
            }
            if($proof['operation']==='media_apply'){
                if(!class_exists('MAD4B_Universal_Media_Apply_V1',false))
                    return new WP_Error('mad4b_unified_media_module_missing','Universal media router not installed.');
                return MAD4B_Universal_Media_Apply_V1::dispatch((array)$envelope['body']);
            }
            if($proof['operation']==='media_associate'){
                if(!class_exists('MAD4B_Tour_Media_Association_V1',false))
                    return new WP_Error('mad4b_media_associate_module_missing','Media Association module not installed.');
                return MAD4B_Tour_Media_Association_V1::execute((array)$envelope['body']);
            }
            if($proof['operation']==='media_close'){
                require_once __DIR__.'/class-mad4b-scp-host-media-closure.php';
                return MAD4B_SCP_Host_Media_Closure::execute((array)$envelope['body']);
            }
            return self::review((array)$envelope['body']);
        }finally{self::$active=false;self::$operation='';}
    }
    public static function command($args,$assoc) {
        if(!self::cli_ready() || $assoc)WP_CLI::error('Use exact, non-impersonating registered host WP-CLI.');
        $cmd=$args[0]??'';
        if($cmd==='status'){
            if(count($args)!==1)WP_CLI::error('Status accepts no arguments.');
            WP_CLI::line(wp_json_encode(self::status(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
            return;
        }
        if($cmd!=='execute'||count($args)!==1)WP_CLI::error('Only status/execute supported.');
        $input=stream_get_contents(STDIN,65537);
        if(!is_string($input)||strlen($input)>65536)WP_CLI::error('Host input exceeds bounded limit.');
        $envelope=json_decode($input,true);
        if(!is_array($envelope))WP_CLI::error('Invalid host request.');
        $result=self::execute($envelope);
        if(is_wp_error($result))WP_CLI::error($result->get_error_code());
        WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES));
    }
}
if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b host-agent',[MAD4B_SCP_Host_Agent_Bridge::class,'command']);
