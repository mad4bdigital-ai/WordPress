<?php
/**
 * Plugin Name: MAD4B Company Environment WP-CLI (Staging)
 * Description: Bounded, exact-origin, owner-authorized read and reconcile commands.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI',false)) {
    final class MAD4B_Company_Environment_CLI_V1 {
        private static function guard():void {
            if(!class_exists('MAD4B_Company_Environment_Registry_V2',false) ||
               !class_exists('MAD4B_Company_Execution_Resolver_V1',false) ||
               !MAD4B_Company_Environment_Registry_V2::enabled() ||
               wp_get_environment_type()!=='staging' ||
               untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com') {
                WP_CLI::error('Exact Staging runtime and registry required',77);
            }
            if(!current_user_can('manage_options')) {
                WP_CLI::error('WordPress administrator context required (--user=<authorized-id>)',77);
            }
        }
        private static function view():array {
            $companies=MAD4B_Company_Environment_Registry_V2::discover();
            $summary=[];
            foreach($companies as $id=>$record) {
                foreach(($record['environments']??[]) as $env=>$target) {
                    $summary[$id.':'.$env]=[
                        'canonical_origin'=>$target['canonical_origin'],
                        'site_uuid'=>$target['site_uuid'],
                        'connection_state'=>$target['connection_state'],
                        'identity_candidate'=>$target['capabilities']['identity.read']['candidate_available'],
                        'content_write_authorized'=>$target['capabilities']['content.write']['execute_authorized'],
                        'plugin_install_authorized'=>$target['capabilities']['plugin.install']['execute_authorized'],
                    ];
                }
            }
            ksort($summary,SORT_STRING);
            return ['contract'=>'mad4b.company-environment-cli.v1',
                'environment'=>'staging','central_origin'=>'https://tours.dev.mad4b.com',
                'companies'=>count($companies),'targets'=>count($summary),
                'registrations'=>$summary,'mutation_performed'=>false,
                'production_mutation'=>false];
        }
        /** Read-only company and environment status.
         *
         * ## EXAMPLES
         *   wp mad4b-company-env status --user=1
         */
        public function status($args,$assoc_args):void {
            self::guard();
            WP_CLI::line(wp_json_encode(self::view(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        }
        /** Diagnose exact, independent Staging P-256 V4 prerequisites.
         *
         * This is an unauthenticated endpoint presence probe only; it
         * cannot grant trust or prove an authenticated signature.
         *
         * ## OPTIONS
         * [--company=<slug>]
         * : An enrolled company. Defaults to all-royal-egypt.
         *
         * ## EXAMPLES
         *   wp mad4b-company-env signed_v4_check --company=all-royal-egypt --user=1
         */
        public function signed_v4_check($args,$assoc_args):void {
            self::guard();
            $company=(string)($assoc_args['company']??'all-royal-egypt');
            if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company))
                WP_CLI::error('Invalid company slug',64);
            if(!class_exists('MAD4B_Environment_Signed_Identity_V4',false))
                WP_CLI::error('Environment P-256 V4 verifier missing',75);
            $status=MAD4B_Environment_Signed_Identity_V4::status($company,'staging');
            if(($status['state']??'')==='EXACT_ENVIRONMENT_TARGET_NOT_ENROLLED')
                WP_CLI::error('Exact Staging enrollment missing',77);
            $origin=(string)$status['canonical_origin'];
            $url=$origin.'/wp-json/mad4b-company/v1/signed-site-profile-v4';
            $response=wp_safe_remote_post($url,[
                'timeout'=>8,'redirection'=>0,'sslverify'=>true,
                'reject_unsafe_urls'=>true,'limit_response_size'=>4096,
                'headers'=>['Accept'=>'application/json']]);
            $http=is_wp_error($response)?null:wp_remote_retrieve_response_code($response);
            $endpoint='not_verified';
            if($http===404)$endpoint='rest_route_missing';
            elseif($http===403)$endpoint='route_present_rejects_unsigned_challenge';
            elseif($http===409)$endpoint='route_present_pending_profile_or_key_material';
            elseif($http===200)$endpoint='http_200_without_signature_not_authorizing';
            elseif($http===null)$endpoint='network_unavailable';
            $blockers=[];
            if(empty($status['central_trust_configured']))$blockers[]='exact_environment_public_key_trust_missing';
            if($http!==403 && $http!==409)$blockers[]='signed_v4_endpoint_not_verified';
            WP_CLI::line(wp_json_encode([
                'contract'=>'mad4b.company.signed-v4-preflight.v1',
                'company_id'=>$company,'environment'=>'staging',
                'site_uuid'=>$status['site_uuid'],
                'canonical_origin'=>$origin,
                'trust_configured'=>$status['central_trust_configured'],
                'signed_cryptographic_proof_current'=>$status['centrally_verified'],
                'endpoint_http'=>$http,'endpoint_state'=>$endpoint,
                'blockers'=>$blockers,'remote_write_authorized'=>false,
                'remote_mutation_performed'=>false,
                'production_mutation'=>false
            ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        }

        /** Register a separately owner-verified P-256 PUBLIC key for Staging.
         *
         * Requires an absolute, local key file, an independently checked
         * SHA-256 fingerprint of the normalized public PEM, and exact
         * origin/UUID. Existing trust may never be replaced here.
         *
         * ## OPTIONS
         * --company=<slug>
         * : Enrolled company.
         *
         * --public-key-file=<absolute-path>
         * : Local public PEM, never a private key.
         *
         * --expected-sha256=<sha256>
         * : SHA-256 of the canonical P-256 public PEM.
         *
         * --expected-site-uuid=<uuid>
         * : Exact site UUID independently attested by owner.
         *
         * --expected-origin=<origin>
         * : Exact Staging canonical origin.
         */
        public function signed_v4_trust_register($args,$assoc_args):void {
            self::guard();
            $id=(string)($assoc_args['company']??'');
            $path=(string)($assoc_args['public-key-file']??'');
            $fingerprint=strtolower((string)($assoc_args['expected-sha256']??''));
            $uuid=(string)($assoc_args['expected-site-uuid']??'');
            $origin=(string)($assoc_args['expected-origin']??'');
            if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$id)||
               !preg_match('/^[a-f0-9]{64}$/D',$fingerprint)||
               !wp_is_uuid($uuid)||
               $path===''||$path[0]!=='/'||!is_file($path)||
               is_link($path)||!is_readable($path)||filesize($path)>7000)
                WP_CLI::error('Exact public PEM and independent SHA/UUID required',77);
            if(!class_exists('MAD4B_Company_Environment_Registry_V2',false)||
               !class_exists('MAD4B_Environment_Signed_Identity_V4',false))
                WP_CLI::error('Environment registry or verifier unavailable',75);
            $target=MAD4B_Company_Environment_Registry_V2::target($id,'staging');
            if(!is_array($target)||$origin!==$target['canonical_origin']||
               $uuid!==$target['site_uuid'])
                WP_CLI::error('Exact independently verified Staging target mismatch',77);
            $trust_option=MAD4B_Environment_Signed_Identity_V4::trust_option($id,'staging');
            if($trust_option===''||get_option($trust_option,null)!==null)
                WP_CLI::error('Trust already exists or target is invalid; rotation requires separate governance',73);
            $pem=file_get_contents($path,false,null,0,7001);
            if(!is_string($pem)||strpos($pem,'PRIVATE KEY')!==false||
               substr_count($pem,'BEGIN PUBLIC KEY')!==1)
                WP_CLI::error('Only one P-256 public key PEM is accepted',77);
            $key=openssl_pkey_get_public($pem);
            $details=$key?openssl_pkey_get_details($key):false;
            if(!is_array($details)||($details['type']??null)!==OPENSSL_KEYTYPE_EC||
               ($details['ec']['curve_name']??'')!=='prime256v1')
                WP_CLI::error('Public key is not EC prime256v1',77);
            $canonical=(string)$details['key'];
            $actual=hash('sha256',$canonical);
            if(!hash_equals($fingerprint,$actual))
                WP_CLI::error('Independent key fingerprint mismatch',77);
            if(!add_option($trust_option,$canonical,'',false))
                WP_CLI::error('Immutable trust insertion failed',75);
            $readback=get_option($trust_option,'');
            if(!is_string($readback)||!hash_equals($actual,hash('sha256',$readback)))
                WP_CLI::error('Trust registration readback mismatch',75);
            $status=MAD4B_Environment_Signed_Identity_V4::status($id,'staging');
            WP_CLI::line(wp_json_encode([
                'state'=>'EXACT_STAGING_PUBLIC_TRUST_REGISTERED',
                'company_id'=>$id,'environment'=>'staging',
                'canonical_origin'=>$origin,'site_uuid'=>$uuid,
                'public_key_sha256'=>$actual,
                'central_trust_configured'=>$status['central_trust_configured'],
                'signed_cryptographic_proof_current'=>$status['centrally_verified'],
                'remote_write_authorized'=>false,
                'production_mutation'=>false
            ],JSON_UNESCAPED_SLASHES));
        }

        /** Preview or explicitly apply a bounded Staging snapshot reconciliation.
         *
         * Without --apply, this command is strictly read-only. Apply
         * writes only the central snapshot options on tours.dev.mad4b.com.
         *
         * ## OPTIONS
         * [--apply]
         * : Opt in to save the central registry and resolver snapshots.
         *
         * [--expected-origin=<origin>]
         * : Exact central origin. Mandatory with --apply.
         *
         * [--expected-environment=<environment>]
         * : Must be staging. Mandatory with --apply.
         *
         * ## EXAMPLES
         *   wp mad4b-company-env reconcile --user=1
         *   wp mad4b-company-env reconcile --user=1 --apply --expected-origin=https://tours.dev.mad4b.com --expected-environment=staging
         */
        public function reconcile($args,$assoc_args):void {
            self::guard();
            $apply=isset($assoc_args['apply']);
            if(!$apply) {
                $result=self::view();
                $result['action']='RECONCILE_DRY_RUN';
                // Provider preflight is observation-only; it never issues remote grants.
                $result['component_readiness']=apply_filters('mad4b_company_reconcile_component_readiness',[]);
                WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES));
                return;
            }
            $origin=(string)($assoc_args['expected-origin']??'');
            $env=(string)($assoc_args['expected-environment']??'');
            if($origin!=='https://tours.dev.mad4b.com'||$env!=='staging')
                WP_CLI::error('Missing or invalid exact Staging apply binding',77);
            $registry=MAD4B_Company_Environment_Registry_V2::reconcile();
            $resolver=MAD4B_Company_Execution_Resolver_V1::reconcile();
            $saved=get_option(MAD4B_Company_Environment_Registry_V2::OPTION,[]);
            $saved_resolver=get_option(MAD4B_Company_Execution_Resolver_V1::OPTION,[]);
            if(!is_array($saved)||!is_array($saved_resolver) ||
               ($registry['contract']??'')!==($saved['contract']??'')||
               ($registry['company_count']??-1)!==($saved['company_count']??-2)||
               ($resolver['target_count']??-1)!==($saved_resolver['target_count']??-2))
                WP_CLI::error('Snapshot readback failed',75);
            WP_CLI::line(wp_json_encode([
                'contract'=>'mad4b.company-environment-cli.v1',
                'state'=>'STAGING_SNAPSHOT_RECONCILED_AND_READBACK_VERIFIED',
                'company_count'=>$saved['company_count'],
                'environment_count'=>$saved['environment_count'],
                'resolver_targets'=>$saved_resolver['target_count'],
                'component_readiness'=>apply_filters('mad4b_company_reconcile_component_readiness',[]),
                'production_mutation'=>false,
                'external_write_performed'=>false],JSON_UNESCAPED_SLASHES));
        }
    }
    WP_CLI::add_command('mad4b-company-env','MAD4B_Company_Environment_CLI_V1');
}
