<?php
/**
 * Plugin Name: MAD4B Central Scoped SSH Grant and Command Intake
 * Description: Revocable one-time and persistent scoped grants and registered SSH connection references; SSH host authorization remains independent.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Central_SSH_Command_Intake_V1 {
    const CONTRACT = 'mad4b.central-ssh-command-intake.v1';
    const PREFIX = 'mad4b_central_ssh_grant_v1_';
    const CLAIM_PREFIX = 'mad4b_central_ssh_claim_v1_';
    const REQUEST_PREFIX = 'mad4b_central_ssh_request_v1_';
    const TRANSPORT_PREFIX = 'mad4b_central_ssh_transport_v1_';
    const AUDIT = 'mad4b_central_ssh_intake_audit_v1';
    const TTL_MAX = 900;
    // PERMANENT NATIVE 313 LIFECYCLE: original persistent WP-CLI jobs need
    // enough time for the 5-minute dispatch cadence, a 320-second native
    // worker, independent readback, and bounded retries. This is only the
    // *initial* expiry of NEW 305 requests; it does not renew expired claims,
    // widen one-time grant TTL, or weaken 312/313 signatures/authority checks.
    // Backwards-compatible minimum; no fixed 1200-second admission policy.
    const PERSISTENT_WPCLI_313_JOB_WINDOW = 1200;
    const PERSISTENT_WPCLI_313_JOB_MAX_WINDOW = 3600;
    const COMMAND_MAX_BYTES = 4096;

    /**
     * PERMANENT DYNAMIC 305->313 LIFECYCLE POLICY
     *
     * Compute each NEW persistent WordPress job's time budget from current,
     * independently recorded 306 queue and recent broker-claim latency.
     * There is intentionally no fixed company/host/operation target here.
     *
     * The 60s service interval and 480s service budget are conservative
     * availability defaults, NOT authorizing metadata. The observed backlog
     * and delay are dynamic. This affects new original 305 requests only;
     * 312 signature, ownership, one-time grants, job IDs and 313 independent
     * proof remain unchanged. Old/expired receipts cannot be extended.
     */
    public static function wpcli_lifecycle_window( string $target_host = '' ): array {
        $tick = 60;
        $service_budget = 480;
        $newest_limit = 180;
        $now = time();
        $queued = 0;
        $claimed = 0;
        $recent_waits = array();
        if ( class_exists( 'MAD4B_Central_VPS_Broker_V1', false ) ) {
            global $wpdb;
            // One bounded read from original broker records, no new registry.
            $names = $wpdb->get_col( $wpdb->prepare(
                'SELECT option_name FROM ' . $wpdb->options
                    . ' WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d',
                $wpdb->esc_like( MAD4B_Central_VPS_Broker_V1::JOB ) . '%',
                $newest_limit
            ) );
            foreach ( (array) $names as $option_name ) {
                $job = get_option( (string) $option_name, false );
                if ( ! is_array( $job ) || ( $target_host !== ''
                    && (string) ( $job['target_host_id'] ?? '' ) !== $target_host ) ) continue;
                $state = (string) ( $job['state'] ?? '' );
                $expires = (int) ( $job['expires_epoch'] ?? 0 );
                $created = (int) ( $job['created_epoch'] ?? 0 );
                if ( $expires > $now && $state === 'QUEUED_FOR_VPS_BROKER_CLAIM' ) ++$queued;
                if ( $expires > $now && $state === 'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY' ) ++$claimed;
                $claim_key = (string) ( $job['job_id'] ?? '' );
                if ( $created <= 0 || $claim_key === '' || strlen( $claim_key ) !== 32 ) continue;
                $claim = get_option( MAD4B_Central_VPS_Broker_V1::CLAIM . $claim_key, false );
                $claim_epoch = is_array( $claim ) ? (int) ( $claim['claimed_epoch'] ?? 0 ) : 0;
                if ( $claim_epoch > $created && $claim_epoch <= $now
                    && $created > $now - DAY_IN_SECONDS ) {
                    $recent_waits[] = min( 900, $claim_epoch - $created );
                }
            }
        }
        sort( $recent_waits, SORT_NUMERIC );
        // P75 observed claim delay, capped to prevent a slow/stale job
        // inflating another company's authorization indefinitely.
        $p75 = count( $recent_waits )
            ? $recent_waits[(int) floor( (count( $recent_waits ) - 1) * 0.75 )] : 0;
        $queue_budget = min( 1500, $queued * $tick + $claimed * $service_budget );
        $suggested = $service_budget + 2 * $tick + max( $tick, $p75 )
            + $queue_budget + 120; // independent readback and bounded retry
        $seconds = max( self::PERSISTENT_WPCLI_313_JOB_WINDOW,
            min( self::PERSISTENT_WPCLI_313_JOB_MAX_WINDOW, $suggested ) );
        return array(
            'contract' => 'mad4b.original-305-native-313-adaptive-window.v1',
            'seconds' => $seconds,
            'queued' => $queued,
            'claimed' => $claimed,
            'claim_wait_p75_seconds' => $p75,
            'observations' => count( $recent_waits ),
            'queue_delay_budget_seconds' => $queue_budget,
            'window_bounded' => true,
            'authorization_changed' => false,
            'existing_jobs_changed' => false
        );
    }

    // Original 305 signed native CLI v1: no WP user override or CLI
    // impersonation. A registered original company native principal is
    // necessary but not sufficient without a fresh signed exact request.
    const NATIVE_CLI_CONTRACT='mad4b.original-305-signed-native-cli.v1';
    const NATIVE_CLI_NONCE='mad4b_original_305_native_cli_nonce_v1_';
    const NATIVE_CLI_FIELDS=['contract','company','environment','site_uuid',
        'canonical_origin','host_id','grant_id','actor_user_id','method',
        'route','body_sha256','issued','expires','nonce'];
    private static $native_cli_context=null;

    private static function actor_id():int {
        return (defined('WP_CLI') && WP_CLI &&
            is_array(self::$native_cli_context))
            ? (int)self::$native_cli_context['actor_user_id']
            : (int)get_current_user_id();
    }

    /**
     * Original 305 authenticated principal for this exact request object.
     * Never treats WP-CLI --user as a signed Native identity. The original
     * permission gate must run first and bind the request body's SHA.
     */
    public static function authenticated_actor_for(WP_REST_Request $request):int {
        if(defined('WP_CLI') && WP_CLI){
            $context=self::$native_cli_context;
            if(!is_array($context)
              ||($context['request_object']??null)!==$request
              ||($context['route']??null)!==(string)$request->get_route()
              ||($context['method']??null)!==(string)$request->get_method()
              ||($context['body_sha256']??null)!==hash('sha256',(string)$request->get_body()))
                return 0;
            return (int)($context['actor_user_id']??0);
        }
        return get_current_user_id();
    }
    /**
     * Original signed Native CLI may issue the 305 command and dispatch the
     * SAME original request to 306. No host shell, grant creation or other
     * REST endpoint is made native-accessible by this route switch.
     */
    public static function signed_native_route(string $method,string $route):?array {
        if(!in_array($method,['POST','GET'],true))return null;
        $prefix='~^/mad4b/v1/central-execution-lanes/';
        if(preg_match($prefix.'([a-z][a-z0-9-]{2,47})/commands(?:/([a-f0-9]{32}))?$~D',$route,$m)){
            if($method==='POST' && isset($m[2]))return null;
            if($method==='GET' && !isset($m[2]))return null;
            return ['company'=>$m[1],'kind'=>isset($m[2])?'inspect':'commands',
                'request_id'=>$m[2]??null];
        }
        if($method==='POST'
           &&preg_match($prefix.'([a-z][a-z0-9-]{2,47})/broker-dispatch$~D',$route,$m))
            return ['company'=>$m[1],'kind'=>'broker-dispatch','request_id'=>null];
        return null;
    }
    private static function native_cli_permitted(WP_REST_Request $request):bool {
        $method=(string)$request->get_method();
        $route=(string)$request->get_route();
        $body=(string)$request->get_body();
        // A fresh, signed proof is needed for every *different* request.
        // REST invokes permission_callback and callback for the same object.
        $context=self::$native_cli_context;
        if (is_array($context) &&
            ($context['request_object']??null)===$request &&
            ($context['route']??null)===$route &&
            ($context['method']??null)===$method &&
            ($context['body_sha256']??null)===hash('sha256',$body))
            return true;
        self::$native_cli_context=null;
        $route_info=self::signed_native_route($method,$route);
        if(!is_array($route_info))return false;
        if (!class_exists('MAD4B_Company_Native_WPCLI_Enrollment_V1',false)
            || !class_exists('MAD4B_Central_VPS_Broker_V1',false)
            || !class_exists('MAD4B_Central_SSH_Command_Intake_V1',false)
            || !class_exists('MAD4B_Company_Environment_Registry_V2',false)
            || !function_exists('sodium_crypto_sign_verify_detached'))
            return false;
        $proof_raw=(string)$request->get_header('X-MAD4B-Native-Proof');
        if ($proof_raw==='' || strlen($proof_raw)>4096)return false;
        $blob=base64_decode($proof_raw,true);
        if (!is_string($blob) || strlen($blob)>3000)return false;
        $proof=json_decode($blob,true);
        if (!is_array($proof) ||
            array_keys($proof)!==array_merge(self::NATIVE_CLI_FIELDS,['signature']))
            return false;
        $company=(string)$route_info['company'];
        $env=$proof['environment']??null;
        $issued=$proof['issued']??null;
        $expires=$proof['expires']??null;
        $now=time();
        if (($proof['contract']??null)!==self::NATIVE_CLI_CONTRACT
            || ($proof['company']??null)!==$company
            || !is_string($env) ||
                !preg_match('/^[a-z][a-z0-9_-]{1,31}$/D',$env)
            || ($proof['method']??null)!==$method
            || ($proof['route']??null)!==$route
            || !is_string($proof['body_sha256']??null)
            || !hash_equals($proof['body_sha256'],hash('sha256',$body))
            || ($proof['host_id']??null)!=='srv2030908'
            || !is_int($issued) || !is_int($expires)
            || $issued<$now-60 || $issued>$now+10
            || $expires<=$now || $expires>$issued+60
            || !is_string($proof['nonce']??null)
            || !preg_match('/^[a-f0-9]{32}$/D',$proof['nonce'])
            || !is_int($proof['actor_user_id']??null)
            || $proof['actor_user_id']<1)return false;
        $record=MAD4B_Company_Native_WPCLI_Enrollment_V1::readback(
            $company,$env);
        if (!is_array($record) ||
            ($record['state']??null)!=='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED'
            || ($record['full_wpcli_active']??null)!==true
            || ($record['host_authority_verified']??null)!==true)return false;
        $site=MAD4B_Company_Environment_Registry_V2::target($company,$env);
        if (!is_array($site) ||
            ($site['site_uuid']??null)!==($proof['site_uuid']??null) ||
            ($site['canonical_origin']??null)!==
                ($proof['canonical_origin']??null) ||
            ($site['environment']??null)!==$env)return false;
        $slot_key=MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.
            $company.'_'.$env;
        $slot=get_option($slot_key,false);
        $grant=is_array($slot)
            ? get_option(self::PREFIX.($slot['grant_id']??''),false)
            : false;
        // Original 305 signed Native proof attests the CENTRAL broker host.
        // The per-company Original 305 Grant can target local WP-CLI OR an
        // independently signed Original 328 Hostinger transport. Never turn
        // a local principal into remote authority by reusing its signature.
        $remoteGrant=is_array($grant)
            &&($grant['principal_type']??null)==='signed_hostinger_ssh_wpcli';
        $transport=$remoteGrant && is_array($slot)
            ? get_option(self::TRANSPORT_PREFIX.($slot['transport_id']??''),false)
            : false;
        if($remoteGrant && (
            !class_exists('MAD4B_Original_Remote_Hostinger_328_V1',false)
            ||!class_exists('MAD4B_Original_Verified_WPCLI_Transport_V1',false)
            ||!is_array($transport)
            ||!in_array(($grant['target_host_id']??null),['hosting-2','hosting-3'],true)
            ||($transport['host_id']??null)!==($grant['target_host_id']??null)
            ||($transport['host_principal']??null)!==($grant['target_ssh_principal']??null)
            ||($transport['transport_id']??null)!==($grant['transport_id']??null)
            ||($slot['transport_id']??null)!==($grant['transport_id']??null)
            ||($transport['principal_type']??null)!=='signed_hostinger_ssh_wpcli'
            ||!MAD4B_Original_Verified_WPCLI_Transport_V1::active($transport,$grant)
            ||!MAD4B_Original_Remote_Hostinger_328_V1::active($transport,$grant)))
            return false;
        if (!is_array($grant) ||
            ($grant['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED'
            || !($remoteGrant || (($grant['principal_type']??null)==='signed_vps_wpcli'
                &&($grant['target_host_id']??null)==='srv2030908'))
            || ($grant['operation']??null)!=='company.remote.wpcli.full'
            || ($grant['grant_mode']??null)!=='persistent'
            || ($grant['grant_id']??null)!==($proof['grant_id']??null)
            || ($grant['company']??null)!==$company
            || ($grant['environment']??null)!==$env
            || ($grant['site_uuid']??null)!==$proof['site_uuid']
            || ($grant['canonical_origin']??null)!==
                $proof['canonical_origin']
            || (int)($grant['central_principal_id']??0)!==
                $proof['actor_user_id'])return false;
        $pub=base64_decode((string)get_option(
            MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
        $sig=base64_decode((string)$proof['signature'],true);
        if (!is_string($pub) ||
            strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
            !is_string($sig) ||
            strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)return false;
        $values=[];
        foreach (self::NATIVE_CLI_FIELDS as $field) {
            $val=$proof[$field];
            if (!is_string($val) && !is_int($val))return false;
            $values[]=(string)$val;
        }
        if (!sodium_crypto_sign_verify_detached(
            $sig,implode("\n",$values),$pub))return false;
        // A signed Broker-dispatch is limited to an already admitted
        // ORIGINAL 305 request with this SAME company, site, grant and owner.
        // No client-selected request_id can change the target or grant.
        if($route_info['kind']==='broker-dispatch'){
            $decoded=json_decode($body,true);
            if(!is_array($decoded)||array_keys($decoded)!==['request_id']
               ||!preg_match('/^[a-f0-9]{32}$/D',(string)$decoded['request_id']))
                return false;
            $prior=get_option(self::REQUEST_PREFIX.$decoded['request_id'],false);
            if(!is_array($prior)
                ||($prior['request_id']??null)!==$decoded['request_id']
                ||($prior['company']??null)!==$company
                ||($prior['grant_id']??null)!==$proof['grant_id']
                ||($prior['central_principal_id']??null)!==$proof['actor_user_id']
                ||($prior['site_uuid']??null)!==$proof['site_uuid']
                ||($prior['environment']??null)!==$proof['environment']
                ||($prior['canonical_origin']??null)!==$proof['canonical_origin']
                ||($prior['target_scope']??null)!=='wordpress'
                ||($prior['command_format']??null)!=='wpcli.argv.json.v1'
                ||($prior['status']??null)!=='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
                ||($prior['expires_epoch']??0)<=time())
                return false;
        }
        // Only consume the nonce after cryptographic + original enrollment
        // verification. A second request with the same proof cannot proceed.
        if (!add_option(self::NATIVE_CLI_NONCE.$proof['nonce'],[
            'company'=>$company,'grant_id'=>$proof['grant_id'],
            'issued'=>$issued],'',false))return false;
        self::$native_cli_context=[
            'request_id'=>spl_object_id($request),
            'request_object'=>$request,
            'route'=>$route,
            'method'=>$method,
            'body_sha256'=>hash('sha256',$body),
            'actor_user_id'=>$proof['actor_user_id'],
        ];
        return true;
    }

    public static function permitted($request = null): bool {
        if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1', false)
            || !($request instanceof WP_REST_Request)) return false;
        if (defined('WP_CLI') && WP_CLI) {
            // REST uses the original logged-in session gate; the native
            // workload has a separately signed first-party identity.
            if (!MAD4B_Central_Dual_Lane_Registry_V1::central()
                || !class_exists('MAD4B_SCP_Site_Profile',false)
                || !MAD4B_SCP_Site_Profile::origin_enrolled()
                || !MAD4B_SCP_Site_Profile::site_urls_match_enrollment())
                return false;
            return self::native_cli_permitted($request);
        }
        if (!MAD4B_Central_Dual_Lane_Registry_V1::allowed())
            return false;
        $nonce = (string) $request->get_header('X-WP-Nonce');
        if ($nonce !== '') {
            // A supplied invalid REST nonce must fail closed. Only the
            // absence of a nonce permits the original separately verified
            // Unified Administrator identity route.
            return (bool) wp_verify_nonce($nonce, 'wp_rest')
                && self::actor_id() > 0;
        }
        // No REST-session proof: original Unified Administrator is an
        // alternative identity, not a fallback after failed verification.
        return class_exists('MAD4B_Company_Manager_Unified_Administrator_Principal', false)
            && MAD4B_Company_Manager_Unified_Administrator_Principal::verified();
    }

    private static function error(string $code, int $status = 403) {
        return new WP_Error('mad4b_ssh_intake_' . $code,
            'Central SSH intake rejected: ' . $code,
            array('status' => $status, 'contract' => self::CONTRACT));
    }

    private static function audit(string $action, string $company, string $id, string $state): void {
        $rows = get_option(self::AUDIT, array());
        if (!is_array($rows)) $rows = array();
        $rows[] = array(
            'time_utc' => gmdate('c'), 'actor_user_id' => self::actor_id(),
            'action' => $action, 'company' => $company, 'object_id' => $id,
            'state' => $state
        );
        update_option(self::AUDIT, array_slice($rows, -200), false);
    }

    private static function scope(string $company, string $operation,
                                  string $intent='operations.write') {
        // The Central VPS is a workload, never a customer company target.
        // The original enrolled WP owner admits only this local host scope;
        // the independent VPS operator must still prove its own authority.
        if ($company === 'tours-central') {
            $profile=class_exists('MAD4B_Original_Native_Operation_Providers_V1',false)
                ? MAD4B_Original_Native_Operation_Providers_V1::profile($operation) : null;
            $format=is_array($profile) && ($profile['authority_family']??'')==='original_305'
                && ($profile['target_scope']??'')==='host'
                ? (string)($profile['command_format']??'') : '';
            if (!class_exists('MAD4B_Original_Native_Operation_Providers_V1',false)
                || !in_array($format,['maintenance.shell.text.v1','legacy.unspecified.v1',
                    'host.source.ref.v1'],true)
                || !MAD4B_Original_Native_Operation_Providers_V1::legacy_admitted(
                    $operation,'host',$format==='legacy.unspecified.v1'
                        ? 'maintenance.shell.text.v1' : $format)
                || !class_exists('MAD4B_Central_Host_Scope_V1', false) ||
                !MAD4B_Central_Host_Scope_V1::current_owner())
                return self::error('central_host_owner_or_operation_required');
            $host=MAD4B_Central_Host_Scope_V1::inspect();
            if (($host['state']??'')!=='CENTRAL_HOST_IDENTITY_VERIFIED')
                return self::error('central_host_site_identity_required');
            return array('site_uuid'=>$host['site_uuid'],
                'canonical_origin'=>$host['canonical_origin'],
                'environment'=>$host['environment'],
                'assignment_revision'=>0);
        }
        if (!class_exists('MAD4B_Company_Operation_Admission_V1', false)
            || !class_exists('MAD4B_Manager_Auto_Write_V1', false)
            || !class_exists('MAD4B_Original_Native_Operation_Providers_V1',false)
            || !MAD4B_Original_Native_Operation_Providers_V1::original_305_operation_registered(
                $operation))
            return self::error('operation_not_registered', 422);
        // Original REST admission still requires the logged-in owner.
        // Signed native CLI uses the SAME original entitlement ledger,
        // bound to actor_user_id in its separately verified Broker Grant.
        $native = defined('WP_CLI') && WP_CLI &&
            is_array(self::$native_cli_context);
        if ($native) {
            $manager = MAD4B_Company_Operation_Admission_V1::manager_id();
            $rights = MAD4B_Manager_Auto_Write_V1::entitlement(
                $manager,$company,$intent,self::actor_id());
            $target = MAD4B_Manager_Auto_Write_V1::company_target($company);
            $admitted = [
                'policy_delegation_admitted'=>
                    !empty($rights['assignment_authorized']),
                'company_site_uuid'=>$target['site_uuid']??null,
                'environment'=>$target['environment']??null,
                'canonical_origin'=>$target['canonical_origin']??null,
            ];
        } else {
            $admitted=MAD4B_Company_Operation_Admission_V1::assess(
                $company,$intent);
        }
        if (is_wp_error($admitted) || empty($admitted['policy_delegation_admitted']))
            return self::error('active_company_operations_delegation_required');
        $target = MAD4B_Manager_Auto_Write_V1::company_target($company);
        if (($target['state'] ?? '') !== 'REGISTERED'
            || ($target['site_uuid'] ?? '') !== ($admitted['company_site_uuid'] ?? null))
            return self::error('exact_company_site_binding_required');
        $manager = MAD4B_Company_Operation_Admission_V1::manager_id();
        if ($manager === '') return self::error('manager_not_registered');
        $rights = MAD4B_Manager_Auto_Write_V1::entitlement(
            $manager, $company, $intent, self::actor_id());
        if (empty($rights['assignment_authorized'])) return self::error('assignment_inactive');
        return array('site_uuid' => $target['site_uuid'],
            'canonical_origin' => $target['canonical_origin'],
            'environment' => $target['environment'],
            'assignment_revision' => (int) ($rights['assignment_revision'] ?? 0));
    }


    /**
     * Native WordPress owner-session alternative to optional OAuth step-up.
     * Accept only the SAME REST request, nonce, logged-in enrolled owner,
     * exact company+Production environment and already-approved manager grant.
     * WP-CLI, stale/bad nonce, generic WordPress admin, and missing delegation
     * never qualify. Transport enrollment still requires explicit owner POST,
     * independently verified host and signed per-command 312/313 authority.
     */
    private static function verified_enrolled_owner_rest_session(
        WP_REST_Request $request, string $company): bool {
        if ((defined('WP_CLI') && WP_CLI)
            || (string)$request->get_method()!=='POST'
            || (string)$request->get_route()!==
                '/mad4b/v1/central-execution-lanes/'.$company.'/ssh-transports'
            || !is_user_logged_in() || get_current_user_id()<1
            || !current_user_can('manage_options')
            || !current_user_can('activate_plugins')
            || !class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
            || !MAD4B_Central_Dual_Lane_Registry_V1::allowed()
            || !class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
            || !class_exists('MAD4B_Company_Operation_Admission_V1',false)
            || !class_exists('MAD4B_SCP_Site_Profile',false)
            || !MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id()))
            return false;
        $nonce=(string)$request->get_header('X-WP-Nonce');
        if($nonce==='' || wp_verify_nonce($nonce,'wp_rest')===false)
            return false;
        $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
        if(!wp_is_uuid($manager)
            || !MAD4B_Company_Portfolio_Manager_V1::has_production_delegation(
                $manager,$company,'operations_manage',get_current_user_id()))
            return false;
        $target=MAD4B_Company_Portfolio_Manager_V1::production_assignment_status(
            $manager,$company);
        return is_array($target)
            && ($target['state']??'')==='active'
            && ($target['binding_current']??false)===true
            && ($target['environment']??'')==='production'
            && ($target['company_site_uuid']??'')!==''
            && wp_is_uuid($target['company_site_uuid']);
    }

    /**
     * Persist SSH connection configuration, not a live SSH session or secret.
     * The host operator must independently attest the host key and secret
     * reference through its authenticated transport before execution.
     */
    public static function register_transport(WP_REST_Request $request) {
        if (!self::permitted($request)) return self::error('owner_step_up_required');
        $company = (string) $request['company'];
        $input = $request->get_json_params();
        if (!is_array($input) || array_diff(array_keys($input),
                array('host_id','host_principal','host_key_fingerprint','credential_ref','reason','capabilities')))
            return self::error('invalid_transport_shape', 422);
        $host = (string) ($input['host_id'] ?? '');
        $principal = (string) ($input['host_principal'] ?? '');
        $fingerprint = (string) ($input['host_key_fingerprint'] ?? '');
        $reference = (string) ($input['credential_ref'] ?? '');
        $reason = (string) ($input['reason'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{2,79}$/D', $host)
            || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $principal)
            || !preg_match('/^SHA256:[A-Za-z0-9+\\/]{43}=?$/D', $fingerprint)
            || !preg_match('/^vault:[a-z0-9][a-z0-9_\\/-]{5,150}$/D', $reference)
            || strlen($reason) < 10 || strlen($reason) > 240)
            return self::error('invalid_ssh_transport_fields', 422);
        // Full WP-CLI must be explicitly enrolled by the original authenticated
        // company owner; a shell probe, WP-CLI user override or SSH handshake
        // alone cannot create a capability.
        $requested_capabilities = $input['capabilities'] ?? array();
        if (!is_array($requested_capabilities) || array_values($requested_capabilities) !== $requested_capabilities)
            return self::error('invalid_transport_capabilities', 422);
        $full_wpcli = $requested_capabilities === array('wpcli.full');
        if ($requested_capabilities !== array() && !$full_wpcli)
            return self::error('unknown_transport_capability', 422);
        // The owner can use a verified first-party OAuth principal OR a
        // native logged-in WordPress admin + valid REST nonce + exact active
        // Production delegation. Neither route accepts arbitrary WP-CLI --user.
        $oauth_owner=class_exists('MAD4B_Company_Manager_Unified_Administrator_Principal',false)
            && MAD4B_Company_Manager_Unified_Administrator_Principal::verified();
        if ($full_wpcli && !$oauth_owner
            && !self::verified_enrolled_owner_rest_session($request,$company))
            return self::error('verified_owner_enrollment_required');
        if ($company==='tours-central' &&
            ($host!=='srv2030908' || $principal!=='root'
             || $reference!=='vault:registry/local-srv2030908'
             || $requested_capabilities!==array()))
            return self::error('central_host_exact_local_transport_required', 422);
        $scope = self::scope($company, 'maintenance.shell');
        if (is_wp_error($scope)) return $scope;
        $id = bin2hex(random_bytes(16));
        $record = array(
            'contract' => self::CONTRACT, 'transport_id' => $id,
            'mode' => 'persistent_ssh_configuration',
            'company' => $company,
            'site_uuid' => $scope['site_uuid'],
            'canonical_origin' => $scope['canonical_origin'],
            'environment' => $scope['environment'],
            'assignment_revision' => $scope['assignment_revision'],
            'central_principal_id' => self::actor_id(),
            'host_id' => $host, 'host_principal' => $principal,
            'host_key_fingerprint' => $fingerprint,
            'credential_ref' => $reference,
            'created_epoch' => time(),
            'state' => 'PENDING_INDEPENDENT_SSH_VERIFICATION',
            // Persist intent in the ORIGINAL transport. Activation still
            // requires signed native 312/313 admission and exact host binding.
            'capabilities' => $requested_capabilities,
            'full_wpcli_pending_native_enrollment' => $full_wpcli,
            'full_wpcli_active' => false,
            'ssh_session_active' => false,
            'host_authority_verified' => false,
            'ssh_secret_stored_in_wordpress' => false,
            'reason' => sanitize_text_field($reason)
        );
        // Owner approves FULL WP-CLI at SSH-transport enrollment once.
        // Persist the original reusable grant, not a parallel grant ledger.
        // This is central enrollment ONLY: independent 312/313 and the
        // registered Native Executor must still prove execution authority.
        $grant_id = $full_wpcli ? bin2hex(random_bytes(16)) : null;
        if ($full_wpcli) $record['full_wpcli_grant_id'] = $grant_id;
        if (!add_option(self::TRANSPORT_PREFIX . $id, $record, '', false))
            return self::error('transport_persistence_failed', 503);
        if ($full_wpcli) {
            $grant = array(
                'contract' => self::CONTRACT, 'grant_id' => $grant_id,
                'company' => $company, 'operation' => 'company.remote.wpcli.full',
                'site_uuid' => $scope['site_uuid'],
                'canonical_origin' => $scope['canonical_origin'],
                'environment' => $scope['environment'],
                'assignment_revision' => $scope['assignment_revision'],
                'central_principal_id' => self::actor_id(),
                'target_host_id' => $host, 'target_ssh_principal' => $principal,
                'issued_epoch' => time(), 'expires_epoch' => null,
                'grant_mode' => 'persistent', 'transport_id' => $id,
                'requires_fresh_host_auth_for_each_command' => true,
                'state' => 'CENTRAL_OPERATION_SCOPE_GRANTED',
                'host_ssh_authority_verified' => false,
                'execution_authorized' => false, 'executed' => false,
                'reason' => sanitize_text_field($reason),
            );
            if (!add_option(self::PREFIX . $grant_id, $grant, '', false)) {
                delete_option(self::TRANSPORT_PREFIX . $id);
                return self::error('persistent_wpcli_grant_persistence_failed', 503);
            }
            // Do not set full_wpcli_active until the verified native host path
            // is certified; a credential probe cannot upgrade privileges.
            self::audit('full_wpcli_owner_enrolled', $company, $grant_id, 'CENTRAL_OPERATION_SCOPE_GRANTED');
        }
        self::audit('transport_registered', $company, $id, $record['state']);
        return new WP_REST_Response($record, 201);
    }

    /**
     * Revoking either a permanent grant or its connection registration
     * prevents new command claims. Host transport verifies the revocation
     * state again before dispatch, not solely when the command was accepted.
     */
    public static function revoke(WP_REST_Request $request) {
        if (!self::permitted($request)) return self::error('owner_step_up_required');
        $company = (string) $request['company'];
        $kind = (string) ($request->get_param('kind') ?: '');
        $id = (string) ($request['object_id'] ?? '');
        if (!in_array($kind, array('grant','transport'), true)
            || !preg_match('/^[a-f0-9]{32}$/D', $id))
            return self::error('invalid_revoke_scope', 422);
        $prefix = $kind === 'grant' ? self::PREFIX : self::TRANSPORT_PREFIX;
        $record = get_option($prefix . $id, null);
        $native_owner_revoke=is_array($record)
            && ($record['principal_type']??'')==='vps_native'
            && $company==='tours-central'
            && class_exists('MAD4B_Central_Host_Scope_V1',false)
            && MAD4B_Central_Host_Scope_V1::current_owner();
        if (!is_array($record) || ($record['company'] ?? '') !== $company
            || (!$native_owner_revoke
                && ($record['central_principal_id'] ?? null) !== self::actor_id()))
            return self::error('record_not_found', 404);
        if (($record['state'] ?? '') === 'REVOKED')
            return rest_ensure_response(array('state'=>'REVOKED','object_id'=>$id));
        // Revocation always remains available to the issuing enrolled owner,
        // even after the delegated company authority was suspended or removed.
        // A revoked assignment must not trap a persistent host grant as active.
        $record['state'] = 'REVOKED';
        $record['revoked_epoch'] = time();
        $record['revoked_by'] = self::actor_id();
        if (!update_option($prefix . $id, $record, false))
            return self::error('revoke_persistence_failed', 503);
        self::audit($kind . '_revoked', $company, $id, 'REVOKED');
        return rest_ensure_response(array('state'=>'REVOKED','object_id'=>$id));
    }

    /** Distinct Host v2 REST owner action, never a native root --user. */
    private static function host_anchor_v2_owner_session(
        WP_REST_Request $request,string $company
    ):bool {
        if(defined('WP_CLI')&&WP_CLI)return false;
        if(class_exists('MAD4B_Company_Manager_Unified_Administrator_Principal',false)
          && MAD4B_Company_Manager_Unified_Administrator_Principal::verified())
            return true;
        $id=get_current_user_id();
        return $id>0
            && is_user_logged_in()
            && current_user_can('manage_options')
            && current_user_can('activate_plugins')
            && class_exists('MAD4B_SCP_Site_Profile',false)
            && MAD4B_SCP_Site_Profile::user_is_enrolled($id)
            && (string)$request->get_method()==='POST'
            && str_starts_with((string)$request->get_route(),
                '/mad4b/v1/central-execution-lanes/'.$company.'/')
            && (string)$request->get_header('X-WP-Nonce')!==''
            && wp_verify_nonce((string)$request->get_header('X-WP-Nonce'),
                'wp_rest')!==false;
    }

    /** Check original independently active 305/328 transport, not a new Grant.
     * This is an identity prerequisite only: Host v2 owner must separately
     * issue an operation-specific Grant with its own original305 request.
     */
    private static function host_anchor_v2_parent_grant(
        string $company,string $environment,string $transport_id
    ):?array {
        if(!class_exists('MAD4B_Company_Native_WPCLI_Enrollment_V1',false)
            ||!class_exists('MAD4B_Original_Remote_Hostinger_328_V1',false))
            return null;
        $slot=get_option(MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.
            $company.'_'.$environment,false);
        if(!is_array($slot)
            ||($slot['transport_id']??null)!==$transport_id)return null;
        $id=(string)($slot['grant_id']??'');
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))return null;
        $parent=get_option(self::PREFIX.$id,false);
        $transport=get_option(self::TRANSPORT_PREFIX.$transport_id,false);
        if(!is_array($parent)||!is_array($transport)
            ||($parent['operation']??null)!=='company.remote.wpcli.full'
            ||($parent['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED'
            ||($parent['company']??null)!==$company
            ||($parent['environment']??null)!==$environment
            ||($parent['grant_mode']??null)!=='persistent'
            ||($parent['transport_id']??null)!==$transport_id
            ||!MAD4B_Original_Remote_Hostinger_328_V1::active($transport,$parent))
            return null;
        return $parent;
    }

    /**
     * The company_target() registry can already point to Production while a
     * distinct Original328 parent remains enrolled for Staging. For Host v2
     * ONLY, keep the freshly authorized company entitlement from scope(),
     * then bind the new non-executing intent to the exact already-enrolled
     * parent transport. Never rewrite either site's assignment or identity.
     */
    private static function host_anchor_v2_existing_parent_scope(
        string $company,string $operation,string $transport_id,array $scope
    ) {
        if(!in_array($operation,[
            'company.host-authority.anchor.issue',
            'company.host-authority.anchor.revoke'],true)
            ||!preg_match('/^[a-f0-9]{32}$/D',$transport_id)
            ||!class_exists('MAD4B_Company_Native_WPCLI_Enrollment_V1',false))
            return self::error('host_anchor_v2_parent_scope_invalid',403);
        $transport=get_option(self::TRANSPORT_PREFIX.$transport_id,false);
        if(!is_array($transport)
            ||($transport['state']??null)!=='REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED'
            ||!in_array(($transport['environment']??''),['staging','production'],true))
            return self::error('host_anchor_v2_parent_transport_unavailable',403);
        $environment=(string)$transport['environment'];
        $slot=get_option(MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.
                         $company.'_'.$environment,false);
        $parent=self::host_anchor_v2_parent_grant(
            $company,$environment,$transport_id);
        if(!is_array($slot)||!is_array($parent)
            ||($slot['company']??null)!==$company
            ||($slot['environment']??null)!==$environment
            ||($slot['transport_id']??null)!==$transport_id
            ||($slot['grant_id']??null)!==($parent['grant_id']??null)
            ||($slot['site_uuid']??null)!==($transport['site_uuid']??null)
            ||($parent['target_host_id']??null)!==($transport['host_id']??null)
            ||($parent['target_ssh_principal']??null)!==($transport['host_principal']??null)
            ||($transport['central_principal_id']??null)!==self::actor_id())
            return self::error('host_anchor_v2_parent_binding_changed',403);
        foreach(['company','environment','site_uuid','canonical_origin',
                 'assignment_revision','central_principal_id','transport_id'] as $field) {
            if(($parent[$field]??null)!==($transport[$field]??null))
                return self::error('host_anchor_v2_parent_binding_changed',403);
        }
        $bound=[
            'site_uuid'=>$transport['site_uuid'],
            'canonical_origin'=>$transport['canonical_origin'],
            'environment'=>$transport['environment'],
            'assignment_revision'=>$transport['assignment_revision']
        ];
        $matches=true;
        foreach($bound as $field=>$value) {
            if(($scope[$field]??null)!==$value){$matches=false;break;}
        }
        if($matches)return $scope;
        // Only the exact known Staging parent may coexist with a newer
        // Production binding. All other drift continues to fail closed.
        if($environment!=='staging'
            ||($scope['environment']??null)!=='production')
            return self::error('host_anchor_v2_unexpected_scope_drift',403);
        return $bound;
    }

    public static function issue(WP_REST_Request $request) {
        if (!self::permitted($request)) return self::error('owner_step_up_required');
        $company = (string) $request['company'];
        $input = $request->get_json_params();
        if (!is_array($input)
            || array_diff(array_keys($input),
                array('operation','host_id','host_principal','ttl_seconds','reason','grant_mode','transport_id')))
            return self::error('invalid_grant_shape', 422);
        $operation = (string) ($input['operation'] ?? '');
        // A reusable Full WP-CLI grant can ONLY originate at one owner-verified
        // connection enrollment, never via the ordinary generic grant endpoint.
        $profile=class_exists('MAD4B_Original_Native_Operation_Providers_V1',false)
            ? MAD4B_Original_Native_Operation_Providers_V1::profile($operation) : null;
        if (is_array($profile) && $profile['authority_family']==='original_305'
            && $profile['target_scope']==='wordpress')
            return self::error('use_owner_verified_wpcli_transport_enrollment', 403);
        $hostAnchorV2=is_array($profile)
            && ($profile['command_format']??'')==='host.anchor.ref.v2'
            && in_array($operation,[
                'company.host-authority.anchor.issue',
                'company.host-authority.anchor.revoke'],true);
        // Break first-Anchor enrollment circularity without opening a write
        // route: an original logged-in owner may STAGE only an issue/bootstrap
        // Grant against their ACTIVE enrolled Original328 parent. Full issuer
        // readiness is still required for revoke/rotate/operational writes.
        $hostAnchorReady=$hostAnchorV2
            &&MAD4B_Original_Native_Operation_Providers_V1::legacy_admitted(
                $operation,'host','host.anchor.ref.v2');
        $bootstrapOnly=$hostAnchorV2&&!$hostAnchorReady
            &&MAD4B_Original_Native_Operation_Providers_V1::
                first_anchor_bootstrap_intake_registered(
                    $operation,'host','host.anchor.ref.v2');
        if($hostAnchorV2 && (!self::host_anchor_v2_owner_session($request,$company)
            ||(!$hostAnchorReady&&!$bootstrapOnly)))
            return self::error('host_anchor_v2_separate_signed_issuer_and_owner_required',403);

        $host = (string) ($input['host_id'] ?? '');
        $host_principal = (string) ($input['host_principal'] ?? '');
        $reason = (string) ($input['reason'] ?? '');
        $mode = (string) ($input['grant_mode'] ?? 'one_time');
        $transport_id = (string) ($input['transport_id'] ?? '');
        $ttl = (int) ($input['ttl_seconds'] ?? 300);
        if (!in_array($mode, array('one_time','persistent'), true))
            return self::error('grant_mode_invalid', 422);
        if($hostAnchorV2 && $mode!=='persistent')
            return self::error('host_anchor_v2_separate_persistent_grant_required',422);
        if ($mode === 'persistent') {
            if (isset($input['ttl_seconds']) && $input['ttl_seconds'] !== null)
                return self::error('persistent_grant_does_not_expire_by_ttl', 422);
            if (!preg_match('/^[a-f0-9]{32}$/D', $transport_id))
                return self::error('persistent_transport_reference_required', 422);
            $transport = get_option(self::TRANSPORT_PREFIX . $transport_id, null);
            if (!is_array($transport)
                || (!$hostAnchorV2 && ($transport['state'] ?? '') !==
                    'PENDING_INDEPENDENT_SSH_VERIFICATION')
                || ($hostAnchorV2 && (
                    ($transport['state']??null)!=='REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED'
                    ||!in_array($host,['hosting-2','hosting-3'],true)
                    ||!class_exists('MAD4B_Original_Remote_Hostinger_328_V1',false)
                    ||!is_array(self::host_anchor_v2_parent_grant(
                        $company,(string)($transport['environment']??''),$transport_id)))))
                return self::error('persistent_transport_missing_or_revoked');
        } else {
            $transport = null;
            if ($transport_id !== '') return self::error('one_time_transport_reference_unsupported', 422);
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{2,79}$/D', $host)
            || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $host_principal)
            || ($mode === 'one_time' && ($ttl < 30 || $ttl > self::TTL_MAX))
            || strlen($reason) < 10 || strlen($reason) > 240)
            return self::error('grant_fields_invalid', 422);
        if ($company==='tours-central' &&
            (!is_array($profile) || ($profile['target_scope']??'')!=='host'
             || !in_array(($profile['command_format']??''),[
                  'legacy.unspecified.v1','maintenance.shell.text.v1','host.source.ref.v1'],true)
             || !MAD4B_Original_Native_Operation_Providers_V1::legacy_admitted(
                 $operation,'host',($profile['command_format']??'')==='legacy.unspecified.v1'
                    ? 'maintenance.shell.text.v1' : (string)$profile['command_format'])
             || $mode!=='persistent' || $host!=='srv2030908'
             || $host_principal!=='root' || $transport_id===''))
            return self::error('central_host_persistent_local_grant_required', 422);
        // The native-principal standing grant can only be created through
        // a valid Ed25519 VPS Client attestation. The owner-facing 305 API
        // may revoke it but may not mint a second grant on its transport.
        if ($mode==='persistent' && ($transport['principal_type']??'')==='vps_native')
            return self::error('native_grant_requires_signed_client',403);
        $scope = self::scope($company, $operation);
        if (is_wp_error($scope)) return $scope;
        if($hostAnchorV2) {
            $scope=self::host_anchor_v2_existing_parent_scope(
                $company,$operation,$transport_id,$scope);
            if(is_wp_error($scope))return $scope;
        }
        if ($mode === 'persistent' && (
            ($transport['company'] ?? '') !== $company
            || ($transport['host_id'] ?? '') !== $host
            || ($transport['host_principal'] ?? '') !== $host_principal
            || ($transport['central_principal_id'] ?? null) !== self::actor_id()
            || ($transport['site_uuid'] ?? null) !== $scope['site_uuid']
            || ($transport['canonical_origin'] ?? null) !== $scope['canonical_origin']
            || ($transport['environment'] ?? null) !== $scope['environment']
            || ($transport['assignment_revision'] ?? null) !== $scope['assignment_revision']))
            return self::error('persistent_transport_scope_mismatch');
        if($hostAnchorV2) {
            $parent=self::host_anchor_v2_parent_grant(
                $company,(string)$scope['environment'],$transport_id);
            if(!is_array($parent)
                ||($parent['site_uuid']??null)!==$scope['site_uuid']
                ||($parent['canonical_origin']??null)!==$scope['canonical_origin']
                ||(int)($parent['central_principal_id']??0)!==self::actor_id())
                return self::error('host_anchor_original328_parent_not_current',403);
        }
        // This is a central policy scope; host-level privilege is NOT minted.
        // The SSH transport must independently verify host principal/grants.
        $grant_id = bin2hex(random_bytes(16));
        $record = array(
            'contract' => self::CONTRACT, 'grant_id' => $grant_id,
            'company' => $company, 'operation' => $operation,
            'site_uuid' => $scope['site_uuid'],
            'canonical_origin' => $scope['canonical_origin'],
            'environment' => $scope['environment'],
            'assignment_revision' => $scope['assignment_revision'],
            'central_principal_id' => self::actor_id(),
            'target_host_id' => $host, 'target_ssh_principal' => $host_principal,
            'issued_epoch' => time(), 'expires_epoch' => $mode === 'persistent' ? null : time() + $ttl,
            'grant_mode' => $mode, 'transport_id' => $mode === 'persistent' ? $transport_id : null,
            'requires_fresh_host_auth_for_each_command' => true,
            'state' => 'CENTRAL_OPERATION_SCOPE_GRANTED',
            'host_ssh_authority_verified' => false,
            'execution_authorized' => false, 'executed' => false,
            'reason' => sanitize_text_field($reason)
        );
        if($hostAnchorV2) {
            // Owner creates a DISTINCT per-operation grant. The parent 328
            // proof is transport identity only, never inherited write scope.
            $record['principal_type']='signed_host_principal_v2';
            $record['original328_parent_grant_id']=$parent['grant_id'];
            $record['host_v2_execution_authorized']=false;
            // A staging Grant is distinct, never a runtime capability.
            // Even after future issuer commissioning it remains bootstrap-only.
            $record['host_v2_bootstrap_only']=$bootstrapOnly===true;
        }
        if (!add_option(self::PREFIX . $grant_id, $record, '', false))
            return self::error('grant_persistence_failed', 503);
        self::audit('grant_issued', $company, $grant_id, $record['state']);
        return new WP_REST_Response($record, 201);
    }

    /**
     * Derive one narrow tenant draft intent from the already authenticated
     * Original 305 signed-native request. NO second signature or certificate.
     * Returning null means the operation remains in the unchanged legacy lane.
     */
    /** This ID was signed by Original 305 as part of POST-body SHA-256. */
    public static function signed_private_draft_job_id(
        array $draft,string $correlation
    ):?string {
        if(!preg_match('/^[a-f0-9]{32}$/D',$correlation)
           ||!is_string($draft['resource_key']??null)
           ||!hash_equals(substr($draft['resource_key'],-32),$correlation))
            return null;
        return $correlation;
    }
    public static function private_domain_intent(array $argv,$verification,
                                                ?string $original_job=null):?array {
        // Exact unpublished draft meta. The signed correlation is the Job
        // ID; it is part of the derived resource witness, not a privilege.
        if(count($argv)===7 && ($argv[0]??null)==='post'
           &&($argv[1]??null)==='meta' &&($argv[2]??null)==='add') {
            $id=$argv[3]??null;
            $meta=$argv[4]??null;
            $value=$argv[5]??null;
            $intent=$meta==='_mad4b_content_draft_review'?'content.write':
                ($meta==='_mad4b_commerce_draft_policy'?'commerce.write':null);
            if($intent===null || !is_string($id) || !ctype_digit($id)
               ||(string)(int)$id!==$id ||(int)$id<1 ||(int)$id>2147483647
               ||!is_string($value)||$value===''||strlen($value)>1024
               ||($argv[6]??null)!=='--unique'
               ||!is_string($original_job)
               ||!preg_match('/^[a-f0-9]{32}$/D',$original_job)
               ||!is_array($verification)||array_keys($verification)!==[
                   'readback_argv','rollback_argv','readback_expected_sha256',
                   'rollback_expected_sha256'])
                return null;
            $before=hash('sha256','ABSENT'.chr(10));
            $after=hash('sha256',$value.chr(10));
            if(($verification['readback_argv']??null)!==
                   ['post','meta','get',$id,$meta]
               ||($verification['rollback_argv']??null)!==
                   ['post','meta','delete',$id,$meta]
               ||($verification['readback_expected_sha256']??null)!==$after
               ||($verification['rollback_expected_sha256']??null)!==$before)
                return null;
            return [
              'intent'=>$intent,
              'resource_key'=>'postmeta:'.$id.':'.$meta.':'.$original_job,
              'expected_before_sha256'=>$before,
              'expected_after_sha256'=>$after,
              'prestate_mode'=>'absent',
              'resource_type'=>'unpublished_draft_post_meta'
            ];
        }
        if(count($argv)!==5 || ($argv[0]??'')!=='option'
           ||($argv[1]??'')!=='add'
           ||($argv[4]??'')!=='--autoload=off'
           ||!is_string($argv[2]??null)||!is_string($argv[3]??null))
            return null;
        $key=$argv[2];$value=$argv[3];
        $intent=null;
        foreach([
          'strategy.write'=>'mad4b_strategy_draft_',
          'operations.write'=>'mad4b_operations_draft_'
        ] as $name=>$prefix){
            if(preg_match('/^'.preg_quote($prefix,'/').'[a-f0-9]{32}$/D',$key)){
                $intent=$name;break;
            }
        }
        if($intent===null || strlen($value)>1024 || $value==='')
            return null;
        if(!is_array($verification)||array_keys($verification)!==[
          'readback_argv','rollback_argv','readback_expected_sha256',
          'rollback_expected_sha256'])return null;
        $after=hash('sha256',$value.chr(10));
        $before=hash('sha256','ABSENT'.chr(10));
        if(($verification['readback_argv']??null)!==['option','get',$key]
          ||($verification['rollback_argv']??null)!==['option','delete',$key]
          ||($verification['readback_expected_sha256']??null)!==$after
          ||($verification['rollback_expected_sha256']??null)!==$before)
            return null;
        return [
          'intent'=>$intent,
          'resource_key'=>$key,
          'expected_before_sha256'=>$before,
          'expected_after_sha256'=>$after,
          'prestate_mode'=>'absent',
          'resource_type'=>'unpublished_draft_option',
        ];
    }
    /** Typed source-only Original305 Host Anchor v2 exact owner reference.
     * No Grant, Job, Claim or execution can be created by this pure verifier.
     * The Broker must separately resolve the immutable root Plan, authority
     * revision, signed owner source, original312 issuer and original313 reader.
     */
    public static function host_anchor_v2_exact_reference(
        $operation, $argv, $witness, $grant, $command, $target_scope
    ):?array {
        $action=$operation==='company.host-authority.anchor.issue'
            ? 'issue' : ($operation==='company.host-authority.anchor.revoke'
            ? 'revoke' : '');
        $required=['authority_mode','operation_id','plan_sha256',
                   'site_uuid','target_host_id'];
        if($action==='' || $target_scope!=='host' || $command!==null
            || !is_array($grant) || !is_array($argv) || !is_array($witness)
            || count($argv)!==4 || array_values($argv)!==$argv
            || array_keys($witness)!==$required
            || ($witness['operation_id']??null)!==$operation
            || ($witness['site_uuid']??null)!==($grant['site_uuid']??null)
            || ($witness['target_host_id']??null)!==($grant['target_host_id']??null)
            || !in_array(($grant['target_host_id']??null),['hosting-2','hosting-3'],true)
            || !in_array($witness['authority_mode']??null,
                         ['bootstrap','operational'],true)
            || ($witness['authority_mode']==='bootstrap'&&$action!=='issue')
            || !is_string($witness['plan_sha256']??null)
            || !preg_match('/^[a-f0-9]{64}$/D',$witness['plan_sha256'])
            || $argv!==['mad4b','host-anchor',$action,
                       '--plan_sha256='.$witness['plan_sha256']])
            return null;
        $canonical=wp_json_encode($argv,
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($canonical)
            || strlen($canonical)>self::COMMAND_MAX_BYTES)
            return null;
        return ['format'=>'host.anchor.ref.v2',
                'command_sha256'=>hash('sha256',$canonical),
                'command_byte_length'=>strlen($canonical)];
    }

    public static function accept_command(WP_REST_Request $request) {
        if (!self::permitted($request)) return self::error('owner_step_up_required');
        $company = (string) $request['company'];
        $input = $request->get_json_params();
        if (!is_array($input) || array_diff(array_keys($input),
                array('grant_id','command','argv','verification','summary','correlation_id','target_scope')))
            return self::error('invalid_request_shape', 422);
        $id = (string) ($input['grant_id'] ?? '');
        $command = $input['command'] ?? null;
        $argv = $input['argv'] ?? null;
        $summary = (string) ($input['summary'] ?? '');
        $correlation = (string) ($input['correlation_id'] ?? '');
        $target_scope = (string) ($input['target_scope'] ?? 'wordpress');
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)
            || strlen($summary) < 10 || strlen($summary) > 240
            || !preg_match('/^[a-zA-Z0-9_.-]{8,100}$/D', $correlation)
            || !in_array($target_scope, array('wordpress','host'), true))
            return self::error('command_fields_invalid', 422);
        $grant = get_option(self::PREFIX . $id, null);
        if (is_array($grant) && ($grant['principal_type']??'')==='vps_native')
            return self::error('native_command_requires_signed_client',403);
        if (!is_array($grant) || ($grant['contract'] ?? '') !== self::CONTRACT
            || ($grant['company'] ?? '') !== $company
            || ($grant['central_principal_id'] ?? 0) !== self::actor_id()
            || ($grant['state'] ?? '') !== 'CENTRAL_OPERATION_SCOPE_GRANTED'
            || (($grant['grant_mode'] ?? 'one_time') !== 'persistent'
                && (int) ($grant['expires_epoch'] ?? 0) <= time()))
            return self::error('grant_expired_or_mismatched');
        // WP-CLI uses the same canonical digest as the signed native argv
        // envelope; maintenance.shell preserves its legacy text digest.
        $profile=class_exists('MAD4B_Original_Native_Operation_Providers_V1',false)
            ? MAD4B_Original_Native_Operation_Providers_V1::profile(
                (string)($grant['operation']??'')) : null;
        if (!is_array($profile) || $profile['authority_family']!=='original_305')
            return self::error('source_registered_operation_provider_required',422);
        $wpcli=$profile['target_scope']==='wordpress';
        $hostSource=null;
        if ($wpcli) {
            if ($command !== null || $target_scope !== 'wordpress'
                || !is_array($argv) || array_values($argv) !== $argv
                || count($argv) < 1 || count($argv) > 256)
                return self::error('wpcli_argv_shape_invalid', 422);
            foreach ($argv as $part) {
                if (!is_string($part) || strlen($part) > 8192
                    || preg_match('/[\x00\x0a\x0d]/', $part)
                    || preg_match('/^--(?:path|url|ssh|http)(?:=|$)/i', $part))
                    return self::error('wpcli_argv_target_override_or_invalid', 422);
            }
            $canonical = wp_json_encode($argv, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($canonical) || strlen($canonical) > self::COMMAND_MAX_BYTES)
                return self::error('wpcli_argv_bytes_invalid', 422);
            $command_digest = hash('sha256', $canonical);
            $command_byte_length = strlen($canonical);
            $command_format = 'wpcli.argv.json.v1';
            if (array_key_exists('verification',$input)
                && (!class_exists('MAD4B_Original_WPCLI_Sealed_Command_V2',false)
                    || !MAD4B_Original_WPCLI_Sealed_Command_V2::witness_valid($input['verification'])))
                return self::error('original_signed_wpcli_verification_invalid',422);
        } elseif ($profile['target_scope']==='host'
            && ($profile['command_format']??'')==='host.anchor.ref.v2') {
            // Distinct first-party original305 owner-signed Host v2 intent.
            $hostAnchor=$input['verification']??null;
            $approved=self::host_anchor_v2_exact_reference(
                (string)($grant['operation']??''),$argv,$hostAnchor,
                $grant,$command,$target_scope);
            if(!is_array($approved))
                return self::error('host_anchor_original305_exact_plan_required',422);
            $bootstrapGrant=($grant['host_v2_bootstrap_only']??null)===true;
            $bootstrapWitness=($hostAnchor['authority_mode']??null)==='bootstrap'
                &&($grant['operation']??null)==='company.host-authority.anchor.issue';
            if($bootstrapGrant && (!$bootstrapWitness
                ||!MAD4B_Original_Native_Operation_Providers_V1::
                   first_anchor_bootstrap_intake_registered(
                     (string)$grant['operation'],'host','host.anchor.ref.v2')))
                return self::error('host_anchor_bootstrap_only_grant_mismatch',403);
            if(!$bootstrapGrant && !MAD4B_Original_Native_Operation_Providers_V1::
               legacy_admitted((string)$grant['operation'],'host','host.anchor.ref.v2'))
                return self::error('host_anchor_signed_issuer_not_commissioned',403);
            $command_digest=$approved['command_sha256'];
            $command_byte_length=$approved['command_byte_length'];
            $command_format=$approved['format'];
        } elseif ($profile['target_scope']==='host'
            && ($profile['command_format']??'')==='host.source.ref.v1') {
            // Typed, immutable Git resource; this is NOT a shell command.
            // Original312 must later check the same digest against the
            // root-enrolled operation manifest and the real owner catalog.
            $hostSource=$input['verification']??null;
            $expected=['host_scope','owner_catalog_sha256','owner_id','plan_sha256'];
            if ($target_scope!=='host' || $command!==null
                || !is_array($argv) || count($argv)<2 || count($argv)>12
                || array_values($argv)!==$argv || !is_array($hostSource))
                return self::error('host_source_typed_contract_required',422);
            $keys=array_keys($hostSource);sort($keys,SORT_STRING);
            if ($keys!==$expected
                || !preg_match('/^host:[a-zA-Z0-9][a-zA-Z0-9_.-]{2,80}$/D',(string)$hostSource['host_scope'])
                || !preg_match('/^[a-z][a-z0-9.-]{2,90}$/D',(string)$hostSource['owner_id'])
                || !preg_match('/^[a-f0-9]{64}$/D',(string)$hostSource['owner_catalog_sha256'])
                || !preg_match('/^[a-f0-9]{64}$/D',(string)$hostSource['plan_sha256']))
                return self::error('host_source_resource_binding_invalid',422);
            foreach ($argv as $part) {
                if (!is_string($part) || strlen($part)>240 || !preg_match('/^[a-zA-Z0-9._:\/=+-]{1,240}$/D',$part))
                    return self::error('host_source_argv_not_exact_reference',422);
            }
            if (!str_starts_with(end($argv),'--plan_sha256=')
                || substr(end($argv),14)!==$hostSource['plan_sha256'])
                return self::error('host_source_plan_command_mismatch',422);
            $canonical=wp_json_encode($argv,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if (!is_string($canonical) || strlen($canonical)>self::COMMAND_MAX_BYTES)
                return self::error('host_source_reference_too_large',422);
            $command_digest=hash('sha256',$canonical);
            $command_byte_length=strlen($canonical);
            $command_format='host.source.ref.v1';
        } else {
            if ($profile['target_scope']!=='host' || $target_scope!=='host')
                return self::error('exact_host_operation_scope_required',422);
            if ($argv !== null || array_key_exists('verification',$input)
                || !is_string($command) || trim($command) === ''
                || strlen($command) > self::COMMAND_MAX_BYTES
                || strpos($command, "\0") !== false)
                return self::error('command_text_invalid', 422);
            $command_digest = hash('sha256', $command);
            $command_byte_length = strlen($command);
            $command_format = 'maintenance.shell.text.v1';
        }
        if ($company==='tours-central' &&
            ($target_scope!=='host' ||
             ($grant['target_host_id']??'')!=='srv2030908' ||
             ($grant['target_ssh_principal']??'')!=='root'))
            return self::error('central_host_command_target_scope_invalid', 422);
        $privateDraft=null;
        if($wpcli && is_array($argv)){
            $key=(string)($argv[2]??'');
            $recognized=(($argv[0]??'')==='option'
                &&(str_starts_with($key,'mad4b_strategy_draft_')
                  ||str_starts_with($key,'mad4b_operations_draft_')))
              ||(($argv[0]??'')==='post'
                &&($argv[1]??'')==='meta'
                &&($argv[2]??'')==='add'
                &&in_array(($argv[4]??''),[
                    '_mad4b_content_draft_review',
                    '_mad4b_commerce_draft_policy'],true));
            if($recognized){
                $privateDraft=self::private_domain_intent(
                    $argv,$input['verification']??null,$correlation);
                if($privateDraft===null)
                    return self::error('original_domain_draft_witness_invalid',422);
                // The Original Signed Native CLI body binds correlation_id
                // to this exact request. Reuse its 128-bit ID as the Job
                // ID for typed first-touch only; otherwise the new private
                // resource would not be owned by the actual original Job.
                if(self::signed_private_draft_job_id(
                    $privateDraft,$correlation)===null)
                    return self::error('original_draft_job_id_not_signed_and_bound',422);
                $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
                $rights=MAD4B_Manager_Auto_Write_V1::entitlement(
                    $manager,$company,$privateDraft['intent'],self::actor_id());
                if(!is_array($rights)
                    ||($rights['assignment_authorized']??false)!==true
                    ||($rights['manager_membership_active']??false)!==true
                    ||($rights['company_binding_verified']??false)!==true)
                    return self::error('specific_domain_intent_delegation_required',403);
            }
        }
        $scope = self::scope($company, (string) $grant['operation'],
            is_array($privateDraft)?$privateDraft['intent']:'operations.write');
        if (is_wp_error($scope)) return $scope;
        if(($command_format??null)==='host.anchor.ref.v2') {
            $scope=self::host_anchor_v2_existing_parent_scope(
                $company,(string)$grant['operation'],
                (string)($grant['transport_id']??''),$scope);
            if(is_wp_error($scope))return $scope;
        }
        foreach (array('site_uuid','environment','canonical_origin','assignment_revision') as $field) {
            if (($scope[$field] ?? null) !== ($grant[$field] ?? null))
                return self::error('delegation_or_site_changed');
        }
        $mode = (string) ($grant['grant_mode'] ?? 'one_time');
        $transport_id = (string) ($grant['transport_id'] ?? '');
        if ($mode === 'persistent') {
            $transport = get_option(self::TRANSPORT_PREFIX . $transport_id, null);
            $isAnchor=(($command_format??null)==='host.anchor.ref.v2');
            $anchorParent=$isAnchor ? self::host_anchor_v2_parent_grant(
                $company,(string)($scope['environment']??''),$transport_id) : null;
            $anchorValid=$isAnchor
                && is_array($anchorParent)
                && ($grant['operation']??null)===($hostAnchor['operation_id']??null)
                && ($grant['original328_parent_grant_id']??null)===
                    ($anchorParent['grant_id']??null)
                && ($grant['principal_type']??null)==='signed_host_principal_v2'
                && self::host_anchor_v2_owner_session($request,$company)
                && ($bootstrapGrant
                    ? ($bootstrapWitness && MAD4B_Original_Native_Operation_Providers_V1::
                        first_anchor_bootstrap_intake_registered(
                          (string)$grant['operation'],'host','host.anchor.ref.v2'))
                    : MAD4B_Original_Native_Operation_Providers_V1::legacy_admitted(
                        (string)$grant['operation'],'host','host.anchor.ref.v2'))
                && ($anchorParent['central_principal_id']??null)===self::actor_id();
            if (!is_array($transport)
                || (!$anchorValid && ($isAnchor ||
                    !class_exists('MAD4B_Original_Verified_WPCLI_Transport_V1',false)
                    || !MAD4B_Original_Verified_WPCLI_Transport_V1::permitted($transport,$grant)))
                || ($transport['company'] ?? null) !== $company
                || ($transport['site_uuid'] ?? null) !== $scope['site_uuid']
                || ($transport['canonical_origin'] ?? null) !== $scope['canonical_origin']
                || ($transport['environment'] ?? null) !== $scope['environment']
                || ($transport['assignment_revision'] ?? null) !== $scope['assignment_revision']
                || ($transport['host_id'] ?? null) !== $grant['target_host_id']
                || ($transport['host_principal'] ?? null) !== $grant['target_ssh_principal'])
                return self::error('persistent_transport_revoked_or_changed');
        } elseif ($mode !== 'one_time') return self::error('grant_mode_unknown');
        $request_id = is_array($privateDraft)
            ? self::signed_private_draft_job_id($privateDraft,$correlation)
            : bin2hex(random_bytes(16));
        // Persistent grants are reusable, but each correlation is single use.
        // For one-time grants preserve the original one-time consumption.
        $claim_key = self::CLAIM_PREFIX . $id;
        if ($mode === 'persistent')
            $claim_key .= '_' . hash('sha256', $correlation);
        if (!add_option($claim_key, $request_id, '', false))
            return self::error($mode === 'persistent'
                ? 'correlation_already_claimed' : 'one_time_grant_already_claimed', 409);
        $receipt = array(
            'contract' => self::CONTRACT, 'request_id' => $request_id,
            'grant_id' => $id, 'company' => $company,
            'operation' => $grant['operation'], 'site_uuid' => $scope['site_uuid'],
            'canonical_origin' => $scope['canonical_origin'],
            'environment' => $scope['environment'], 'central_principal_id' => self::actor_id(),
            'target_host_id' => $grant['target_host_id'],
            'target_ssh_principal' => $grant['target_ssh_principal'],
            'target_scope' => $target_scope, 'summary' => sanitize_text_field($summary),
            'correlation_id' => $correlation,
            'command_sha256' => $command_digest,
            'command_format' => $command_format,
            'command_byte_length' => $command_byte_length,
            'grant_mode' => $mode,
            'transport_id' => $mode === 'persistent' ? $transport_id : null,
            // Exact command bytes must come from the authenticated operator
            // session at dispatch. Never store arbitrary shell text in WP options.
            'command_bytes_persisted' => false,
            'created_epoch' => time(), 'expires_epoch' => $mode === 'persistent'
                ? time() + ($wpcli
                    ? self::wpcli_lifecycle_window( (string) $grant['target_host_id'] )['seconds']
                    : 300)
                : $grant['expires_epoch'],
            'status' => 'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY',
            'host_ssh_grant_verified' => false, 'transport_registered' => false,
            'execution_performed' => false
        );
        if(is_array($privateDraft)){
            // The digest is derived, not caller-controlled. The Original
            // native CLI signature already covers the exact POST body.
            // The original sealed X25519 record carries those argv bytes.
            $typed=[
              'contract'=>'mad4b.original305.typed-private-draft.v1',
              'company'=>$company,
              'environment'=>$scope['environment'],
              'site_uuid'=>$scope['site_uuid'],
              'grant_id'=>$id,
              'transport_id'=>$transport_id,
              'domain_intent'=>$privateDraft['intent'],
              'resource_key'=>$privateDraft['resource_key'],
              'command_sha256'=>$command_digest,
              'expected_before_sha256'=>$privateDraft['expected_before_sha256'],
              'expected_after_sha256'=>$privateDraft['expected_after_sha256'],
            ];
            $raw=wp_json_encode($typed,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if(!is_string($raw))
                return self::error('typed_original_305_canonical_invalid',503);
            $receipt['domain_intent']=$privateDraft['intent'];
            $receipt['domain_witness_sha256']=hash('sha256',$raw);
            $receipt['domain_resource_key']=$privateDraft['resource_key'];
        }
        if ($wpcli) {
            if (!class_exists('MAD4B_Original_WPCLI_Sealed_Command_V2',false))
                return self::error('original_sealed_wpcli_module_unavailable',503);
            $sealed=MAD4B_Original_WPCLI_Sealed_Command_V2::seal(
                $receipt,$argv,$input['verification']??null);
            if (is_wp_error($sealed)) return $sealed;
            if (!is_string($sealed)||!preg_match('/^[a-f0-9]{64}$/D',$sealed))
                return self::error('original_wpcli_argv_seal_unknown',503);
            $receipt['sealed_argv_sha256']=$sealed;
            $receipt['sealed_argv_transport']='host_bound_x25519_original_305';
        }
        if (is_array($hostSource)) $receipt['host_source_identity']=$hostSource;
        if (isset($hostAnchor) && is_array($hostAnchor)) {
            $receipt['host_anchor_identity']=$hostAnchor;
            $receipt['host_v2_bootstrap_only']=($grant['host_v2_bootstrap_only']??null)===true;
        }
        if (!add_option(self::REQUEST_PREFIX . $request_id, $receipt, '', false)) {
            self::audit('request_persistence_failed', $company, $request_id, 'FAILED');
            return self::error('request_persistence_failed', 503);
        }
        self::audit('command_intake', $company, $request_id, $receipt['status']);
        return new WP_REST_Response($receipt, 202);
    }

    public static function inspect(WP_REST_Request $request) {
        if (!self::permitted($request)) return self::error('owner_step_up_required');
        $id = (string) $request['request_id'];
        if (!preg_match('/^[a-f0-9]{32}$/D', $id))
            return self::error('invalid_request_id', 422);
        $row = get_option(self::REQUEST_PREFIX . $id, null);
        if (!is_array($row) || ($row['company'] ?? '') !== (string) $request['company']
            || ($row['central_principal_id'] ?? 0) !== self::actor_id())
            return self::error('request_not_found', 404);
        return rest_ensure_response($row);
    }

    // Original 305 intake owns the rebind; it does not invent an actor or grant.
    public static function rebindOriginalSealedCommandOnce(string $id,array $input,array $proof) {
        return MAD4B_Original_Sealed_Command_Rebind_V1::execute($id,$input,$proof);
    }

    public static function routes(): void {
        if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1', false)
            || !MAD4B_Central_Dual_Lane_Registry_V1::central()) return;
        $base = '/central-execution-lanes/(?P<company>[a-z][a-z0-9-]{2,47})';
        register_rest_route('mad4b/v1', $base . '/ssh-transports', array(
            'methods' => 'POST', 'permission_callback' => array(__CLASS__, 'permitted'),
            'callback' => array(__CLASS__, 'register_transport')
        ));
        register_rest_route('mad4b/v1', $base . '/(?P<kind>grant|transport)/(?P<object_id>[a-f0-9]{32})/revoke', array(
            'methods' => 'POST', 'permission_callback' => array(__CLASS__, 'permitted'),
            'callback' => array(__CLASS__, 'revoke')
        ));
        register_rest_route('mad4b/v1', $base . '/grants', array(
            'methods' => 'POST', 'permission_callback' => array(__CLASS__, 'permitted'),
            'callback' => array(__CLASS__, 'issue')
        ));
        register_rest_route('mad4b/v1', $base . '/commands', array(
            'methods' => 'POST', 'permission_callback' => array(__CLASS__, 'permitted'),
            'callback' => array(__CLASS__, 'accept_command')
        ));
        register_rest_route('mad4b/v1', $base . '/commands/(?P<request_id>[a-f0-9]{32})', array(
            'methods' => 'GET', 'permission_callback' => array(__CLASS__, 'permitted'),
            'callback' => array(__CLASS__, 'inspect')
        ));
    }
    public static function boot(): void {
        add_action('rest_api_init', array(__CLASS__, 'routes'), 90);
    }
}
MAD4B_Central_SSH_Command_Intake_V1::boot();
// The verified 377-entry MU manifest loads 305-mad4b-original-sealed-rebind.php at its
// ordered position. Do not require a forward module while the router is
// iterating; otherwise public HTML needlessly depends on central commands.
// Outside the modular router, preserve the historical fail-closed requirement.
if (!defined('MAD4B_MU_MODULAR_LOADER_BOOTED')) {
    require_once MAD4B_MU_File_Resolver_V1::path('305-mad4b-original-sealed-rebind.php');
}
