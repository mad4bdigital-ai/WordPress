<?php
declare(strict_types=1);
/**
 * Independent operational release authority. Git hosting, PRs and CI vendors are
 * not trusted issuers. Keys are injected from externally enrolled trust stores.
 * Pure policy core only: no DB writes, WordPress roles, signer key generation,
 * deployments or certificate publication occur in this class.
 */
final class MAD4B_Independent_Authority {
    public const CONTRACT = 'mad4b.operational-release-permit.v1';
    public const INTENT = 'mad4b.operation-intent.v1';
    public const POLICY_VERSION = 'mad4b.independent-authority.staging-policy.v1';
    public const IDENTITY = 'mad4b.owner-identity-attestation.v1';
    public const EVIDENCE = 'mad4b.qa-evidence.v1';
    public const RUNTIME = 'mad4b.runtime-readback.v1';
    public const CERTIFICATE = 'mad4b.operational-completion-certificate.v1';
    public const ACTIONS = ['enable_shared_media','publish_company_content','apply_company_release'];
    public const ROLES = ['company_owner','company_reviewer','platform_owner','security_reviewer'];
    public const APPROVERS = [
        'enable_shared_media'=>['platform_owner','company_owner','security_reviewer'],
        'publish_company_content'=>['company_owner','company_reviewer'],
        'apply_company_release'=>['platform_owner','company_owner','security_reviewer']
    ];
    public const REQUIRED_BASE = ['site_profile','artifact_integrity','runtime_isolation','rollback_verified','audit_chain'];
    public const REQUIRED_MEDIA = ['media_issuer_enrolled','media_rights_approved','immutable_mounts_readonly','two_tenant_boundary','signed_renewal','revocation_test','company_seo_review'];

    private static function canon(mixed $v):mixed {
        if(!is_array($v))return $v;
        if(!array_is_list($v))ksort($v,SORT_STRING);
        foreach($v as $k=>$x)$v[$k]=self::canon($x);
        return $v;
    }
    public static function json(array $data):string {
        return json_encode(self::canon($data),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    }
    public static function digest(array $data):string {return hash('sha256',self::json($data));}
    public static function activePolicyDigest():string {
        return self::digest(['version'=>self::POLICY_VERSION,'approvers'=>self::APPROVERS,
            'base_checks'=>self::REQUIRED_BASE,'media_checks'=>self::REQUIRED_MEDIA,
            'allowed_environment'=>'staging','permit_max_seconds'=>300]);
    }
    private static function hash(mixed $v):bool {return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;}
    private static function uuid(mixed $v):bool {return is_string($v)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$v)===1;}
    private static function host(mixed $v):bool {
        if(!is_string($v)||strlen($v)>255)return false;
        $p=parse_url($v);
        return is_array($p)&&($p['scheme']??'')==='https'&&!empty($p['host'])&&
            !filter_var($p['host'],FILTER_VALIDATE_IP)&&
            !isset($p['port'])&&!isset($p['user'])&&!isset($p['pass'])&&
            !isset($p['query'])&&!isset($p['fragment'])&&!isset($p['path'])&&
            preg_match('/^[a-z0-9.-]+$/D',$p['host'])===1&&
            $p['host']!=='localhost'&&!str_ends_with($p['host'],'.local')&&
            !str_ends_with($p['host'],'.internal')&&$v==='https://'.$p['host'];
    }
    private static function fields(array $data,array $required):bool {
        $keys=array_keys($data);sort($keys);sort($required);
        return $keys===$required;
    }
    private static function fail(string $code):array{return ['valid'=>false,'code'=>$code,'deployment_performed'=>false];}
    public static function checkIntent(array $intent,int $now):bool {
        if(!self::fields($intent,['contract','operation_id','action','site_uuid','environment',
                'origin','artifact_sha256','plan_sha256','rollback_sha256','rights_sha256',
                'created_at','expires_at','nonce','policy_sha256']))return false;
        return ($intent['contract']??'')===self::INTENT &&
            is_string($intent['operation_id'])&&preg_match('/^[a-zA-Z0-9_.:-]{12,120}$/D',$intent['operation_id'])===1&&
            in_array($intent['action'],self::ACTIONS,true)&&self::uuid($intent['site_uuid'])&&
            $intent['environment']==='staging'&&self::host($intent['origin'])&&
            self::hash($intent['artifact_sha256'])&&self::hash($intent['plan_sha256'])&&
            self::hash($intent['rollback_sha256'])&&self::hash($intent['rights_sha256'])&&
            hash_equals(self::activePolicyDigest(),$intent['policy_sha256'])&&
            is_string($intent['nonce'])&&preg_match('/^[a-f0-9]{32,64}$/D',$intent['nonce'])===1&&
            is_int($intent['created_at'])&&is_int($intent['expires_at'])&&
            $intent['created_at']<=$now+60&&$intent['created_at']>=$now-900&&
            $intent['expires_at']>$now&&$intent['expires_at']-$intent['created_at']<=900;
    }
    public static function verified(array $envelope,array $trustedKeys,string $expectedContract):array {
        if(!self::fields($envelope,['payload_json','signature_hex','kid']) ||
           !is_string($envelope['payload_json'])||strlen($envelope['payload_json'])>65536||
           !is_string($envelope['kid'])||!preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/D',$envelope['kid'])||
           !is_string($envelope['signature_hex'])||!preg_match('/^[a-f0-9]{128}$/D',$envelope['signature_hex']))
            return self::fail('bad_envelope');
        $key=$trustedKeys[$envelope['kid']]??null;
        if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))return self::fail('untrusted_key');
        if(!sodium_crypto_sign_verify_detached(hex2bin($envelope['signature_hex']),
            $envelope['payload_json'],hex2bin($key)))return self::fail('bad_signature');
        $claims=json_decode($envelope['payload_json'],true,32,JSON_BIGINT_AS_STRING);
        if(!is_array($claims)||json_last_error()!==JSON_ERROR_NONE||($claims['contract']??null)!==$expectedContract)
            return self::fail('wrong_claims_contract');
        return ['valid'=>true,'payload'=>$claims,'receipt_sha256'=>self::digest($envelope)];
    }
    private static function identity(array $envelope,array $trusted,int $now,string $intentHash,array $intent):array {
        $v=self::verified($envelope,$trusted,self::IDENTITY);
        if(!$v['valid'])return $v;
        $x=$v['payload'];
        if(!self::fields($x,['contract','actor_id','role','site_uuid','environment','intent_sha256',
             'session_sha256','authentication_method','authenticated_at','expires_at'])||
             !is_string($x['actor_id'])||!preg_match('/^[a-zA-Z0-9_.:@-]{4,128}$/D',$x['actor_id'])||
             !in_array($x['role'],self::ROLES,true)||$x['site_uuid']!==$intent['site_uuid']||
             $x['environment']!==$intent['environment']||$x['intent_sha256']!==$intentHash||
             !self::hash($x['session_sha256'])||
             !in_array($x['authentication_method'],['webauthn','mfa_step_up'],true)||
             !is_int($x['authenticated_at'])||!is_int($x['expires_at'])||
             $x['authenticated_at']>$now||$x['authenticated_at']<$now-300||
             $x['expires_at']<=$now||$x['expires_at']-$x['authenticated_at']>300)
            return self::fail('identity_scope_or_freshness');
        return $v;
    }
    private static function qa(array $envelope,array $trusted,int $now,string $intentHash,array $intent):array {
        $v=self::verified($envelope,$trusted,self::EVIDENCE);
        if(!$v['valid'])return $v;
        $x=$v['payload'];
        if(!self::fields($x,['contract','runner_id','site_uuid','environment','intent_sha256',
             'artifact_sha256','plan_sha256','observed_at','expires_at','results','evidence_sha256'])||
             !is_string($x['runner_id'])||strlen($x['runner_id'])>128||
             $x['site_uuid']!==$intent['site_uuid']||$x['environment']!==$intent['environment']||
             $x['intent_sha256']!==$intentHash||$x['artifact_sha256']!==$intent['artifact_sha256']||
             $x['plan_sha256']!==$intent['plan_sha256']||!self::hash($x['evidence_sha256'])||
             !is_int($x['observed_at'])||!is_int($x['expires_at'])||
             $x['observed_at']>$now||$x['observed_at']<$now-600||
             $x['expires_at']<=$now||$x['expires_at']-$x['observed_at']>600||
             !is_array($x['results'])||array_is_list($x['results']))return self::fail('qa_scope_or_freshness');
        $required=self::REQUIRED_BASE;
        if($intent['action']==='enable_shared_media')$required=array_merge($required,self::REQUIRED_MEDIA);
        foreach($required as $check)if(($x['results'][$check]??null)!==true)return self::fail('missing_qa_'.$check);
        return $v;
    }
    /**
     * A permit may ONLY be issued after exact signed QA and two independent,
     * freshly authenticated owner assertions. The caller supplies externally
     * trusted separate IdP/QA roots, never a GitHub status or PR.
     * The signing callback is an independently authenticated release authority.
     */
    public static function issue(array $intent,array $idpAssertions,array $qaAssertion,
        array $identityTrust,array $qaTrust,array $authorityTrust,callable $authoritySign,int $now):array {
        if(!self::checkIntent($intent,$now))return self::fail('intent_invalid');
        $hash=self::digest($intent);
        $e=self::qa($qaAssertion,$qaTrust,$now,$hash,$intent);
        if(!$e['valid'])return self::fail('qa_'.$e['code']);
        $requiredRoles=self::APPROVERS[$intent['action']];
        if(count($idpAssertions)!==count($requiredRoles))return self::fail('required_independent_approvals_missing');
        $actors=[];$roles=[];$sessions=[];$proofHashes=[];
        foreach($idpAssertions as $assertion){
            if(!is_array($assertion))return self::fail('identity_invalid');
            $v=self::identity($assertion,$identityTrust,$now,$hash,$intent);
            if(!$v['valid'])return self::fail('identity_'.$v['code']);
            $actors[]=$v['payload']['actor_id'];
            $roles[]=$v['payload']['role'];
            $sessions[]=$v['payload']['session_sha256'];
            $proofHashes[]=$v['receipt_sha256'];
        }
        if(count(array_unique($actors))!==count($requiredRoles)||
           count(array_unique($sessions))!==count($requiredRoles))
            return self::fail('same_person_or_session_two_approvals');
        if(in_array($e['payload']['runner_id'],$actors,true))return self::fail('qa_runner_cannot_approve_own_evidence');
        sort($roles,SORT_STRING);sort($requiredRoles,SORT_STRING);
        if($roles!==$requiredRoles)return self::fail('wrong_approver_roles');
        sort($proofHashes,SORT_STRING);
        $ttl=min($intent['expires_at'],$now+300,$e['payload']['expires_at']);
        foreach($idpAssertions as $assertion){
            $v=self::verified($assertion,$identityTrust,self::IDENTITY);
            $ttl=min($ttl,$v['payload']['expires_at']);
        }
        if($ttl<=$now)return self::fail('approval_expired');
        $permit=[
            'contract'=>self::CONTRACT,'issuer'=>'mad4b.local-operational-authority',
            'intent_sha256'=>$hash,'operation_id'=>$intent['operation_id'],
            'action'=>$intent['action'],'site_uuid'=>$intent['site_uuid'],
            'environment'=>$intent['environment'],'origin'=>$intent['origin'],
            'artifact_sha256'=>$intent['artifact_sha256'],'plan_sha256'=>$intent['plan_sha256'],
            'rollback_sha256'=>$intent['rollback_sha256'],'rights_sha256'=>$intent['rights_sha256'],
            'policy_sha256'=>$intent['policy_sha256'],'nonce'=>$intent['nonce'],
            'qa_receipt_sha256'=>$e['receipt_sha256'],'identity_receipt_hashes'=>$proofHashes,
            'issued_at'=>$now,'expires_at'=>$ttl
        ];
        $response=$authoritySign($permit);
        if(!is_array($response))return self::fail('authority_signer_missing');
        $verify=self::verified($response,$authorityTrust,self::CONTRACT);
        if(!$verify['valid']||self::digest($verify['payload'])!==self::digest($permit))
            return self::fail('authority_signature_or_payload_invalid');
        return ['valid'=>true,'permit_envelope'=>$response,'permit_payload'=>$permit,
            'status'=>'SIGNED_NOT_EXECUTED','executed'=>false];
    }
    /** A *post-deploy* certificate, independent of GitHub, requires separately
     * signed runtime readback from an enrolled observer. Its signer is an
     * independently operated CA, not the person who deployed the change. */
    public static function issueCompletionCertificate(array $permitEnvelope,array $permitTrust,
       array $runtimeEnvelope,array $runtimeTrust,array $certificateTrust,
       callable $authoritySign,int $now):array {
        $v=self::verified($permitEnvelope,$permitTrust,self::CONTRACT);
        if(!$v['valid'])return self::fail('permit_'.$v['code']);
        $p=$v['payload'];
        if(!is_int($p['issued_at']??null)||!is_int($p['expires_at']??null)||
           $p['issued_at']>$now||$p['expires_at']<=$now||
           ($p['environment']??null)!=='staging')return self::fail('permit_expired_or_wrong_environment');
        $r=self::verified($runtimeEnvelope,$runtimeTrust,self::RUNTIME);
        if(!$r['valid'])return self::fail('runtime_'.$r['code']);
        $x=$r['payload'];
        if(!self::fields($x,['contract','probe_id','site_uuid','environment','origin',
            'permit_sha256','artifact_sha256','plan_sha256','runtime_sha256','rollback_sha256',
            'healthy','observed_at','expires_at'])||
           !is_string($x['probe_id'])||strlen($x['probe_id'])<8||
           $x['site_uuid']!==$p['site_uuid']||$x['environment']!==$p['environment']||
           $x['origin']!==$p['origin']||$x['permit_sha256']!==$v['receipt_sha256']||
           $x['artifact_sha256']!==$p['artifact_sha256']||
           $x['plan_sha256']!==$p['plan_sha256']||
           $x['rollback_sha256']!==$p['rollback_sha256']||
           !self::hash($x['runtime_sha256'])||$x['healthy']!==true||
           !is_int($x['observed_at'])||!is_int($x['expires_at'])||
           $x['observed_at']>$now||$x['observed_at']<$now-180||
           $x['expires_at']<=$now||$x['expires_at']-$x['observed_at']>300)
           return self::fail('runtime_unhealthy_or_scope_changed');
        $cert=[
           'contract'=>self::CERTIFICATE,'issuer'=>'mad4b.local-operational-authority',
           'permit_sha256'=>$v['receipt_sha256'],'runtime_receipt_sha256'=>$r['receipt_sha256'],
           'site_uuid'=>$p['site_uuid'],'environment'=>$p['environment'],'origin'=>$p['origin'],
           'artifact_sha256'=>$p['artifact_sha256'],'runtime_sha256'=>$x['runtime_sha256'],
           'action'=>$p['action'],'result'=>'healthy_attested',
           'issued_at'=>$now,'expires_at'=>$now+3600
        ];
        $envelope=$authoritySign($cert);
        if(!is_array($envelope))return self::fail('certificate_signer_missing');
        $signed=self::verified($envelope,$certificateTrust,self::CERTIFICATE);
        if(!$signed['valid']||self::digest($signed['payload'])!==self::digest($cert))
            return self::fail('certificate_signature_invalid');
        return ['valid'=>true,'status'=>'SIGNED_COMPLETION_CERTIFICATE_NOT_PUBLICLY_INSTALLED',
            'certificate_envelope'=>$envelope,'certificate_payload'=>$cert,'deployment_performed'=>false];
    }
    /** Controller validates externally issued signed permit and durable replay/revocation state. */
    public static function verifyForExecution(array $envelope,array $authorityTrust,array $expectedIntent,
        callable $activePermitAndNonce,int $now):array {
        $v=self::verified($envelope,$authorityTrust,self::CONTRACT);
        if(!$v['valid'])return $v;
        if(!self::checkIntent($expectedIntent,$now))return self::fail('expected_intent_invalid');
        $p=$v['payload'];
        if(!self::fields($p,['contract','issuer','intent_sha256','operation_id','action',
             'site_uuid','environment','origin','artifact_sha256','plan_sha256','rollback_sha256',
             'rights_sha256','policy_sha256','nonce','qa_receipt_sha256','identity_receipt_hashes',
             'issued_at','expires_at'])||
             $p['issuer']!=='mad4b.local-operational-authority'||
             $p['intent_sha256']!==self::digest($expectedIntent)||
             $p['action']!==$expectedIntent['action']||
             $p['site_uuid']!==$expectedIntent['site_uuid']||
             $p['environment']!==$expectedIntent['environment']||
             $p['origin']!==$expectedIntent['origin']||
             $p['artifact_sha256']!==$expectedIntent['artifact_sha256']||
             $p['plan_sha256']!==$expectedIntent['plan_sha256']||
             $p['policy_sha256']!==$expectedIntent['policy_sha256']||
             $p['rollback_sha256']!==$expectedIntent['rollback_sha256']||
             $p['rights_sha256']!==$expectedIntent['rights_sha256']||
             $p['nonce']!==$expectedIntent['nonce']||
             !self::hash($p['qa_receipt_sha256'])||
             !is_array($p['identity_receipt_hashes'])||
             count($p['identity_receipt_hashes'])!==count(self::APPROVERS[$p['action']]??[])||
             count(array_filter($p['identity_receipt_hashes'],[self::class,'hash']))!==count($p['identity_receipt_hashes'])||
             count(array_unique($p['identity_receipt_hashes']))!==count($p['identity_receipt_hashes'])||
             !is_int($p['issued_at'])||!is_int($p['expires_at'])||
             $p['issued_at']>$now||$p['expires_at']<=$now||$p['expires_at']-$p['issued_at']>300)
             return self::fail('release_permit_scope_or_freshness');
        // MUST be backed by an independently authenticated transactional
        // nonce/grant store. A callback returning true alone is not a certificate
        // of replay safety; runtime integration tests are required.
        if($activePermitAndNonce($v['receipt_sha256'],$p['nonce'],$p['site_uuid'])!==true)
            return self::fail('revoked_or_nonce_consumed');
        return ['valid'=>true,'code'=>'VERIFIED_NOT_EXECUTED',
            'permit_sha256'=>$v['receipt_sha256'],'intent_sha256'=>$p['intent_sha256'],
            'executed'=>false,'requires_atomic_consume_and_apply'=>true];
    }
}
