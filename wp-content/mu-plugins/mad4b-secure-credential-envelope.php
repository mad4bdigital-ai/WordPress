<?php
/**
 * Plugin Name: MAD4B Secure Credential Envelope
 * Description: Recipient-bound, signed, one-time envelopes; vendor credential installation disabled without a certified native provider adapter.
 * Version: 0.1.0
 */
if(!defined('ABSPATH'))exit;
final class MAD4B_Secure_Credential_Envelope {
    const CONTRACT='mad4b.company-credential-envelope.v1';
    const SOURCE_DIR='/run/mad4b-crypto-keyring/company-credentials';
    const COMPANY_DIR='/var/www/.mad4b-private-host/company-credentials';
    const MAX_BODY=16000;
    const MAX_SECRET=4096;
    const LEDGER='mad4b_credential_envelope_receipts_v1';
    public static function boot():void {
        add_action('rest_api_init',[__CLASS__,'routes']);
        add_action('wp_abilities_api_init',[__CLASS__,'abilities'],60);
        add_filter('mad4b/configuration_jobs/discover',[__CLASS__,'configuration_diagnostics'],20,1);
        add_filter('mad4b/job_routing/exact_rules',[__CLASS__,'routing_rules'],20,1);
    }
    public static function routing_rules(array $rules):array {
        foreach(['company_credential_signer_missing','company_credential_recipient_missing',
                 'company_credential_issuer_trust_missing'] as $code){
            $rules[]=['origin'=>'configuration','provider'=>'mad4b-credentials',
              'code'=>$code,'family'=>'configuration','candidate_families'=>['configuration','security'],
              'confidence'=>'high'];
        }
        return $rules;
    }
    public static function configuration_diagnostics(array $signals):array {
        $status=self::public_status();
        $site=(string)(self::profile()['site_uuid']??'');
        $role=$status['role']??'recipient';
        if(($status['state']??'')!=='READY'){
            $signals[]=['provider'=>'mad4b-credentials',
                'code'=>$role==='issuer'?'company_credential_signer_missing':'company_credential_recipient_missing',
                'scope'=>sanitize_key($site).'-'.sanitize_key($role),
                'source'=>'probe','severity'=>'high',
                'label'=>'Company Credential signing or recipient identity unavailable',
                'guidance'=>'Check the site-local private key mounting and permission settings; use the trusted host enrollment workflow. Do not copy a private key through MCP or a Content Job.'];
        } elseif($role==='recipient' && empty($status['trusted_source_enrolled'])){
            $signals[]=['provider'=>'mad4b-credentials','code'=>'company_credential_issuer_trust_missing',
                'scope'=>sanitize_key($site).'-issuer-pin','source'=>'probe','severity'=>'high',
                'label'=>'Credential recipient has no trusted central issuer public key',
                'guidance'=>'Enroll only a verified central public-key fingerprint using the trusted host operator. Never accept an untrusted public key from an envelope.'];
        }
        return $signals;
    }
    private static function error(string $code,int $status=400):WP_Error {
        return new WP_Error('mad4b_envelope_'.$code,'Company credential envelope request was rejected.',
            ['status'=>$status,'code'=>$code]);
    }
    private static function profile():array {
        $p=get_option('mad4b_scp_site_profile_v2',[]);return is_array($p)?$p:[];
    }
    public static function source():bool {
        return class_exists('MAD4B_Company_Credential_Manager')&&MAD4B_Company_Credential_Manager::central()
            &&preg_match('/^[a-f0-9-]{36}$/D',(string)(self::profile()['site_uuid']??''))===1;
    }
    private static function key_dir():string {
        return self::source()?self::SOURCE_DIR:self::COMPANY_DIR;
    }
    private static function ensure_private_dir():bool {
        $parent=self::source()?dirname(self::SOURCE_DIR):dirname(self::COMPANY_DIR);
        if(!is_dir($parent)||!is_writable($parent)||is_link($parent))return false;
        $dir=self::key_dir();
        if(!is_dir($dir)&&!@mkdir($dir,0700,false))return false;
        return is_dir($dir)&&!is_link($dir)&&is_writable($dir)&&((fileperms($dir)&0777)===0700);
    }
    private static function seed(string $role,bool $create=false):string|WP_Error {
        if(!in_array($role,['recipient','issuer'],true))return self::error('invalid_key_role');
        if($role==='issuer'&&!self::source())return self::error('issuer_only_central',403);
        if($role==='recipient'&&self::source())return self::error('recipient_only_company',403);
        $path=self::key_dir().'/'.$role.'.seed';
        if($create){
            if(!self::trusted_cli())return self::error('key_initialization_requires_trusted_host',403);
            if(!self::ensure_private_dir())return self::error('private_directory_unavailable',503);
            if(is_link($path))return self::error('key_symlink_denied');
            if(!is_file($path)){
                try{$value=bin2hex(random_bytes(SODIUM_CRYPTO_BOX_SEEDBYTES));}
                catch(Throwable $e){return self::error('cryptographic_rng_unavailable');}
                $handle=@fopen($path,'x');
                if($handle===false){if(!is_file($path))return self::error('key_file_create_failed',503);}
                else{
                    @chmod($path,0600);
                    try{
                        if(fwrite($handle,$value)!==strlen($value)||!fflush($handle))return self::error('key_file_write_failed',503);
                    } finally {fclose($handle);}
                }
            }
        }
        if(!is_file($path)||is_link($path)||((fileperms($path)&0077)!==0)||!is_readable($path))return self::error('private_key_unavailable',503);
        $raw=@file_get_contents($path);
        if(!is_string($raw)||!preg_match('/^[a-f0-9]{64}$/D',$raw))return self::error('invalid_key_material',503);
        return hex2bin($raw);
    }
    public static function trusted_cli():bool {
        return defined('WP_CLI')&&WP_CLI===true&&PHP_SAPI==='cli';
    }
    public static function initialize():array|WP_Error {
        if(!self::trusted_cli())return self::error('initialization_not_authorized',403);
        if(!function_exists('sodium_crypto_box_seed_keypair')||!function_exists('sodium_crypto_sign_seed_keypair'))return self::error('sodium_missing',503);
        $role=self::source()?'issuer':'recipient';$seed=self::seed($role,true);
        if(is_wp_error($seed))return $seed;
        $public=self::public_from_seed($seed,$role);
        if(is_wp_error($public))return $public;
        return ['contract'=>self::CONTRACT,'site_uuid'=>(string)(self::profile()['site_uuid']??''),
            'role'=>$role,'state'=>'INITIALIZED','public_key_base64'=>base64_encode($public),
            'public_key_fingerprint'=>hash('sha256',$public),'secret_key_exposed'=>false,
            'storage'=>'host_private_mount_not_web_root'];
    }
    private static function public_from_seed(string $seed,string $role):string|WP_Error {
        try{
            if($role==='issuer'){
                $pair=sodium_crypto_sign_seed_keypair($seed);
                return sodium_crypto_sign_publickey($pair);
            }
            $pair=sodium_crypto_box_seed_keypair($seed);
            return sodium_crypto_box_publickey($pair);
        }catch(Throwable $e){return self::error('public_key_derivation_failed');}
    }
    public static function public_status():array {
        $role=self::source()?'issuer':'recipient';$seed=self::seed($role);
        $p=(array)self::profile();
        if(is_wp_error($seed))return ['contract'=>self::CONTRACT,'site_uuid'=>$p['site_uuid']??'',
            'state'=>'UNINITIALIZED','role'=>$role,'public_key_base64'=>null,'secret_key_exposed'=>false];
        $pub=self::public_from_seed($seed,$role);
        if(is_wp_error($pub))return ['contract'=>self::CONTRACT,'state'=>'ERROR','role'=>$role,'secret_key_exposed'=>false];
        $result=['contract'=>self::CONTRACT,'site_uuid'=>$p['site_uuid']??'',
            'origin'=>untrailingslashit(home_url()),'environment'=>wp_get_environment_type(),
            'state'=>'READY','role'=>$role,'public_key_base64'=>base64_encode($pub),
            'public_key_fingerprint'=>hash('sha256',$pub),'secret_key_exposed'=>false];
        if($role==='recipient'){
            $pinned=self::pinned();
            $result['trusted_source_enrolled']=!is_wp_error($pinned);
            if(!is_wp_error($pinned)){
                $result['trusted_source_site_uuid']=$pinned['site_uuid'];
                $result['trusted_source_public_fingerprint']=hash('sha256',$pinned['public_key']);
            }
        }
        return $result;
    }
    /** Initial enrollment only from an authenticated host operator, never from the encrypted payload. */
    public static function trust_source(string $issuerPublicB64,string $sourceUuid):array|WP_Error {
        if(self::source()||!self::trusted_cli())return self::error('trust_operation_unavailable',403);
        $pub=base64_decode($issuerPublicB64,true);
        if(!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||
            !preg_match('/^[a-f0-9-]{36}$/D',$sourceUuid)||
            hash_equals((string)(self::profile()['site_uuid']??''),$sourceUuid))
            return self::error('invalid_issuer_identity',422);
        if(!self::ensure_private_dir())return self::error('private_directory_unavailable',503);
        $file=self::key_dir().'/trusted-source-public.json';
        if(is_file($file)||is_link($file))return self::error('source_already_pinned_use_controlled_rotation',409);
        $data=wp_json_encode(['contract'=>self::CONTRACT,'source_site_uuid'=>$sourceUuid,
            'public_base64'=>base64_encode($pub),'fingerprint'=>hash('sha256',$pub)]);
        $h=@fopen($file,'x');if($h===false)return self::error('pin_file_create_failed',503);
        @chmod($file,0600);
        try{if(fwrite($h,$data)!==strlen($data)||!fflush($h))return self::error('pin_write_failed',503);}
        finally{fclose($h);}
        return ['status'=>'trusted','source_site_uuid'=>$sourceUuid,
            'fingerprint'=>hash('sha256',$pub),'private_key_exposed'=>false];
    }
    /** Rotation handshake: the CURRENT central signer must approve the NEXT
     * issuer public key for one exact recipient; no private key is transferred.
     * A prepared proposal NEVER activates or changes the current signing key.
     */
    public static function sign_rotation_proposal(string $nextPublicB64,string $targetUuid,int $expiresAt):array|WP_Error {
        if(!self::source()||!self::trusted_cli())return self::error('rotation_issuer_host_lane_required',403);
        $public=base64_decode($nextPublicB64,true);
        if(!is_string($public)||strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||
            !preg_match('/^[a-f0-9-]{36}$/D',$targetUuid)||
            $targetUuid===(string)(self::profile()['site_uuid']??''))return self::error('rotation_target_or_key_invalid',422);
        $enrolled=false;
        if(class_exists('MAD4B_Company_Credential_Manager')){
            foreach(MAD4B_Company_Credential_Manager::company_targets() as $target){
                if(($target['site_uuid']??'')===$targetUuid && !empty($target['site_verified'])){
                    $enrolled=true;break;
                }
            }
        }
        if(!$enrolled)return self::error('rotation_target_not_enrolled',409);
        $now=time();if($expiresAt<=$now||$expiresAt>$now+48*HOUR_IN_SECONDS)return self::error('rotation_expiry_invalid',422);
        $seed=self::seed('issuer');if(is_wp_error($seed))return $seed;
        $pair=sodium_crypto_sign_seed_keypair($seed);
        $body=['contract'=>'mad4b.company-credential-signer-rotation.v1',
            'purpose'=>'prepare_next_issuer_trust_without_cutover',
            'source_site_uuid'=>(string)(self::profile()['site_uuid']??''),
            'target_site_uuid'=>$targetUuid,
            'old_issuer_fingerprint'=>hash('sha256',sodium_crypto_sign_publickey($pair)),
            'next_issuer_public_base64'=>base64_encode($public),
            'next_issuer_fingerprint'=>hash('sha256',$public),
            'issued_at'=>$now,'expires_at'=>$expiresAt,
            'nonce'=>bin2hex(random_bytes(24))];
        $signed=sodium_crypto_sign_detached(wp_json_encode($body),sodium_crypto_sign_secretkey($pair));
        return ['body'=>$body,'signature_base64'=>base64_encode($signed),
            'private_key_exported'=>false,'active_key_switched'=>false];
    }
    public static function prepare_next_issuer(array $proposal):array|WP_Error {
        if(self::source()||!self::trusted_cli())return self::error('recipient_host_lane_required',403);
        if(array_diff(array_keys($proposal),['body','signature_base64'])||count($proposal)!==2||
            !is_array($proposal['body']??null)||!is_string($proposal['signature_base64']??null))
            return self::error('rotation_proposal_schema_invalid',422);
        $b=$proposal['body'];
        $fields=['contract','purpose','source_site_uuid','target_site_uuid',
            'old_issuer_fingerprint','next_issuer_public_base64','next_issuer_fingerprint',
            'issued_at','expires_at','nonce'];
        if(array_diff(array_keys($b),$fields)||array_diff($fields,array_keys($b))||
            ($b['contract']??'')!=='mad4b.company-credential-signer-rotation.v1'||
            ($b['purpose']??'')!=='prepare_next_issuer_trust_without_cutover'||
            !is_int($b['issued_at'])||!is_int($b['expires_at'])||
            $b['issued_at']>time()+30||$b['expires_at']<=time()||
            $b['expires_at']>$b['issued_at']+48*HOUR_IN_SECONDS||
            !is_string($b['nonce'])||!preg_match('/^[a-f0-9]{48}$/D',$b['nonce']))
            return self::error('rotation_payload_invalid',422);
        $trusted=self::pinned();if(is_wp_error($trusted))return $trusted;
        if(!hash_equals($trusted['site_uuid'],(string)$b['source_site_uuid'])||
            !hash_equals((string)(self::profile()['site_uuid']??''),(string)$b['target_site_uuid'])||
            !hash_equals(hash('sha256',$trusted['public_key']),(string)$b['old_issuer_fingerprint']))
            return self::error('rotation_existing_trust_mismatch',403);
        $next=base64_decode((string)$b['next_issuer_public_base64'],true);
        if(!is_string($next)||strlen($next)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||
            !hash_equals(hash('sha256',$next),(string)$b['next_issuer_fingerprint'])||
            hash_equals(hash('sha256',$trusted['public_key']),hash('sha256',$next)))
            return self::error('rotation_next_public_key_invalid',422);
        $sig=base64_decode($proposal['signature_base64'],true);
        if(!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES||
            !sodium_crypto_sign_verify_detached($sig,wp_json_encode($b),$trusted['public_key']))
            return self::error('rotation_proposal_signature_invalid',403);
        if(!self::ensure_private_dir())return self::error('private_directory_unavailable',503);
        $path=self::key_dir().'/trusted-source-next-public.json';
        if(is_file($path)||is_link($path))return self::error('rotation_proposal_already_prepared',409);
        $doc=wp_json_encode(['contract'=>$b['contract'],'state'=>'PREPARED_NOT_ACTIVE',
            'target_site_uuid'=>$b['target_site_uuid'],'source_site_uuid'=>$b['source_site_uuid'],
            'next_public_base64'=>$b['next_issuer_public_base64'],
            'current_fingerprint'=>$b['old_issuer_fingerprint'],
            'next_fingerprint'=>$b['next_issuer_fingerprint'],
            'expires_at'=>$b['expires_at'],'signed_proposal_sha256'=>hash('sha256',wp_json_encode($proposal))]);
        $f=@fopen($path,'x');if($f===false)return self::error('rotation_prepare_file_failed',503);
        @chmod($path,0600);$ok=false;
        try{$ok=fwrite($f,$doc)===strlen($doc)&&fflush($f);}
        finally{fclose($f);}
        if(!$ok){@unlink($path);return self::error('rotation_prepare_write_failed',503);}
        $r=json_decode((string)file_get_contents($path),true);
        if(($r['next_fingerprint']??'')!==$b['next_issuer_fingerprint'])return self::error('rotation_prepare_readback_failed',500);
        return ['state'=>'PREPARED_NOT_ACTIVE','next_issuer_fingerprint'=>$b['next_issuer_fingerprint'],
            'source_site_uuid'=>$b['source_site_uuid'],'target_site_uuid'=>$b['target_site_uuid'],
            'live_signing_key_changed'=>false,'live_recipient_key_changed'=>false];
    }
    public static function rotation_status():array {
        $p=self::key_dir().'/trusted-source-next-public.json';$doc=[];
        if(!self::source()&&is_file($p)&&!is_link($p))$doc=(array)json_decode((string)file_get_contents($p),true);
        $expires=(int)($doc['expires_at']??0);
        return ['contract'=>'mad4b.company-credential-signer-rotation.v1',
            'state'=>$doc?(($expires>time())?'PREPARED_NOT_ACTIVE':'EXPIRED_NOT_ACTIVE'):'NONE',
            'target_site_uuid'=>(string)(self::profile()['site_uuid']??''),
            'next_issuer_fingerprint'=>(string)($doc['next_fingerprint']??''),
            'source_site_uuid'=>(string)($doc['source_site_uuid']??''),
            'live_key_rotated'=>false,'private_key_exposed'=>false];
    }
    private static function pinned():array|WP_Error {
        $file=self::key_dir().'/trusted-source-public.json';
        if(!is_file($file)||is_link($file)||!is_readable($file))return self::error('source_not_enrolled',403);
        $data=json_decode((string)file_get_contents($file),true);
        if(!is_array($data)||($data['contract']??'')!==self::CONTRACT||
            !preg_match('/^[a-f0-9-]{36}$/D',(string)($data['source_site_uuid']??''))||
            hash_equals((string)(self::profile()['site_uuid']??''),(string)$data['source_site_uuid']))
            return self::error('source_trust_binding_invalid',403);
        $pub=base64_decode((string)($data['public_base64']??''),true);
        if(!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||
            !hash_equals((string)($data['fingerprint']??''),hash('sha256',$pub)))return self::error('source_public_key_invalid',403);
        return ['site_uuid'=>(string)$data['source_site_uuid'],'public_key'=>$pub];
    }
    private static function exact_header(array $h):bool {
        $fields=['contract','issuer_site_uuid','recipient_site_uuid','recipient_origin','recipient_environment',
            'provider_id','field_ref','grant_id','grant_version','mode','nonce','issued_at','expires_at',
            'recipient_key_sha256','test_only'];
        if(array_diff(array_keys($h),$fields)||array_diff($fields,array_keys($h)))return false;
        if($h['contract']!==self::CONTRACT||!is_string($h['issuer_site_uuid'])||
            !preg_match('/^[a-f0-9-]{36}$/D',$h['issuer_site_uuid']) ||
            !is_string($h['recipient_site_uuid'])||!preg_match('/^[a-f0-9-]{36}$/D',$h['recipient_site_uuid'])||
            !is_string($h['recipient_origin'])||!preg_match('~^https://[a-z0-9.-]+(?::[0-9]{1,5})?$~D',$h['recipient_origin'])||
            !in_array($h['recipient_environment'],['production','staging','development','local'],true)||
            !is_string($h['provider_id'])||!preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$h['provider_id'])||
            !is_string($h['field_ref'])||!preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$h['field_ref'])||
            !is_string($h['grant_id'])||strlen($h['grant_id'])>100||
            !is_int($h['grant_version'])||$h['grant_version']<1||
            !is_string($h['mode'])||!in_array($h['mode'],['materialized_secret','test_fixture'],true)||
            !is_string($h['nonce'])||!preg_match('/^[a-f0-9]{48}$/D',$h['nonce'])||
            !is_int($h['issued_at'])||!is_int($h['expires_at'])||
            !is_string($h['recipient_key_sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$h['recipient_key_sha256'])||
            !is_bool($h['test_only']))return false;
        return true;
    }
    /** Called inside a trusted native provider adapter, never exposed by REST/Abilities. */
    public static function seal(string $secret,array $header,string $targetPublicB64):array|WP_Error {
        if(!self::source()||!self::trusted_cli())return self::error('issuer_native_lane_required',403);
        if(!self::exact_header($header)||
            !hash_equals((string)(self::profile()['site_uuid']??''),$header['issuer_site_uuid'])||
            $header['issued_at']>time()+30||$header['expires_at']<time()||
            $header['expires_at']>$header['issued_at']+300||$header['expires_at']<=$header['issued_at'])
            return self::error('invalid_envelope_header',422);
        if($secret===''||strlen($secret)>self::MAX_SECRET)return self::error('secret_length_denied',422);
        $public=base64_decode($targetPublicB64,true);
        if(!is_string($public)||strlen($public)!==SODIUM_CRYPTO_BOX_PUBLICKEYBYTES||
            !hash_equals($header['recipient_key_sha256'],hash('sha256',$public)))return self::error('recipient_key_mismatch',422);
        if(($header['test_only']??false)!==true || $header['provider_id']!=='mad4b_test_fixture'||
            $header['mode']!=='test_fixture'||$header['grant_id']!=='test-synthetic-no-production-grant')
            return self::error('production_materialization_provider_adapter_not_enabled',409);
        if(!str_starts_with($secret,'MAD4B_TEST_ONLY_'))return self::error('test_fixture_value_required',422);
        $seed=self::seed('issuer');if(is_wp_error($seed))return $seed;
        $pair=sodium_crypto_sign_seed_keypair($seed);$payload=wp_json_encode(['header'=>$header,'value'=>$secret]);
        $cipher=sodium_crypto_box_seal($payload,$public);$encoded=base64_encode($cipher);
        $signature=sodium_crypto_sign_detached(wp_json_encode($header).'|'.$encoded,sodium_crypto_sign_secretkey($pair));
        return ['contract'=>self::CONTRACT,'header'=>$header,'ciphertext_base64'=>$encoded,
            'issuer_signature_base64'=>base64_encode($signature)];
    }
    /** Synthetic fixture only; verifies signature and target before decrypting. */
    public static function receive_fixture(array $envelope):array|WP_Error {
        if(!self::trusted_cli()||self::source())return self::error('recipient_native_lane_required',403);
        if(array_diff(array_keys($envelope),['contract','header','ciphertext_base64','issuer_signature_base64'])||
            count($envelope)!==4||($envelope['contract']??'')!==self::CONTRACT||
            !is_array($envelope['header']??null)||!is_string($envelope['ciphertext_base64']??null)||
            !is_string($envelope['issuer_signature_base64']??null))return self::error('envelope_schema_invalid',422);
        $header=$envelope['header'];
        if(!self::exact_header($header))return self::error('invalid_envelope_header',422);
        $p=self::profile();
        if(!hash_equals((string)($p['site_uuid']??''),$header['recipient_site_uuid'])||
            !hash_equals(untrailingslashit(home_url()),$header['recipient_origin'])||
            $header['recipient_environment']!==wp_get_environment_type())return self::error('recipient_site_binding_mismatch',409);
        if($header['issued_at']>time()+30||$header['expires_at']<=time()||
            $header['expires_at']>$header['issued_at']+300)return self::error('envelope_expired_or_invalid',409);
        if(!$header['test_only']||$header['provider_id']!=='mad4b_test_fixture'||
            $header['mode']!=='test_fixture'||$header['grant_id']!=='test-synthetic-no-production-grant')
            return self::error('production_provider_not_enabled',409);
        $trusted=self::pinned();if(is_wp_error($trusted))return $trusted;
        if(!hash_equals($trusted['site_uuid'],$header['issuer_site_uuid']))
            return self::error('issuer_site_binding_mismatch',403);
        $cipher=base64_decode($envelope['ciphertext_base64'],true);
        $sig=base64_decode($envelope['issuer_signature_base64'],true);
        if(!is_string($cipher)||strlen($cipher)>self::MAX_BODY||strlen($cipher)<64||
            !is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES||
            !sodium_crypto_sign_verify_detached($sig,wp_json_encode($header).'|'.$envelope['ciphertext_base64'],$trusted['public_key']))
            return self::error('signature_or_ciphertext_invalid',403);
        $seed=self::seed('recipient');if(is_wp_error($seed))return $seed;
        $pair=sodium_crypto_box_seed_keypair($seed);
        if(!hash_equals(hash('sha256',sodium_crypto_box_publickey($pair)),$header['recipient_key_sha256']))
            return self::error('recipient_key_rotation_mismatch',409);
        global $wpdb;$lock='mad4b_credential_fixture_receipt_'.get_current_blog_id();
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$lock))!==1)return self::error('receipt_ledger_busy',409);
        try{
            $receipts=(array)get_option(self::LEDGER,[]);
            $key=hash('sha256',$header['issuer_site_uuid'].'|'.$header['recipient_site_uuid'].'|'.$header['nonce']);
            if(isset($receipts[$key]))return self::error('envelope_replay_denied',409);
            $opened=sodium_crypto_box_seal_open($cipher,$pair);
            if(!is_string($opened))return self::error('recipient_decryption_failed',403);
            $body=json_decode($opened,true);
            if(!is_array($body)||!isset($body['header'],$body['value'])||$body['header']!==$header||
                !is_string($body['value'])||strlen($body['value'])>self::MAX_SECRET||
                !str_starts_with($body['value'],'MAD4B_TEST_ONLY_'))return self::error('decrypted_payload_invalid',403);
            if(count($receipts)>=150)return self::error('receipt_ledger_full',409);
            // A native provider adapter stages the decrypted TEST fixture into
            // a host-private encrypted vault. No live provider value admitted.
            $stage=null;
            if(class_exists('MAD4B_Credential_Provider_Adapters')){
                $stage=MAD4B_Credential_Provider_Adapters::stage_fixture($header,$body['value']);
                if(is_wp_error($stage))return $stage;
            }
            $receipts[$key]=['envelope_sha256'=>hash('sha256',$cipher),'state'=>'FIXTURE_VERIFIED',
                'issuer_site_uuid'=>$header['issuer_site_uuid'],'target_site_uuid'=>$header['recipient_site_uuid'],
                'provider_id'=>$header['provider_id'],'received_at'=>gmdate('c'),
                'fixture_value_digest_sha256'=>hash('sha256',$body['value']),
                'fixture_value_length'=>strlen($body['value']),'production_secret_stored'=>false,
                'vendor_setting_changed'=>false,
                'encrypted_fixture_stage_id'=>is_array($stage)?(string)($stage['stage_id']??''):null];
            update_option(self::LEDGER,$receipts,false);
            $readback=(array)get_option(self::LEDGER,[]);
            if(!isset($readback[$key])||$readback[$key]['state']!=='FIXTURE_VERIFIED')return self::error('receipt_write_not_verified',500);
            return ['contract'=>self::CONTRACT,'state'=>'FIXTURE_VERIFIED',
                'recipient_site_uuid'=>$header['recipient_site_uuid'],
                'fixture_length'=>strlen($body['value']),'fixture_digest_sha256'=>hash('sha256',$body['value']),
                'actual_vendor_secret_materialized'=>false,'plaintext_returned'=>false,
                'encrypted_fixture_stage_id'=>is_array($stage)?(string)($stage['stage_id']??''):null,
                'provider_settings_changed'=>false];
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public static function routes():void {
        register_rest_route('mad4b-company-credentials/v1','/recipient-public',[
            'methods'=>'GET','callback'=>static fn()=>rest_ensure_response(self::public_status()),
            'permission_callback'=>static fn()=>current_user_can('mad4b_manage_company_operations')||current_user_can('manage_options')]);
    }
    public static function abilities():void {
        if(!function_exists('wp_register_ability')||(function_exists('wp_has_ability')&&wp_has_ability('mad4b/company-credential-recipient-public')))return;
        wp_register_ability('mad4b/company-credential-recipient-public',[
            'label'=>'Company recipient public key','description'=>'Site-bound public key metadata only; private key never returned.',
            'category'=>'mad4b-read','execute_callback'=>static fn($input)=>self::public_status(),
            'permission_callback'=>static fn()=>current_user_can('mad4b_manage_company_operations')||current_user_can('manage_options'),
            'input_schema'=>['type'=>'object','properties'=>[],'required'=>[], 'additionalProperties'=>false],
            'output_schema'=>['type'=>'object','additionalProperties'=>true],
            'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
                     'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]],
        ]);
    }
}
MAD4B_Secure_Credential_Envelope::boot();
