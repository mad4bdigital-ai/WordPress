<?php
/**
 * Signed immutable Hub content verification, entirely read-only.
 * A signature is necessary but NOT sufficient to authorize publication,
 * importing, activating shadow posts, pricing or checkout.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Signed_Publication {
    const CONTRACT='mad4b.hub.content-publication.v1';
    const RUNTIME_KEY='/run/secrets/mad4b-hub-publisher-public';
    private static function reject($code){return new WP_Error('mad4b_hub_signature_'.$code,$code);}
    public static function verify($envelope,$trust,$target,$qa=false){
        if(!function_exists('sodium_crypto_sign_verify_detached')
          ||!is_array($envelope)||!is_array($trust)||!is_array($target)
          ||array_diff(array_keys($envelope),['payload_json','signature_hex','kid'])
          ||!is_string($envelope['payload_json']??null)
          ||strlen($envelope['payload_json'])>65536
          ||!is_string($envelope['signature_hex']??null)
          ||!preg_match('/^[a-f0-9]{128}$/D',$envelope['signature_hex'])
          ||!is_string($envelope['kid']??null))
            return self::reject('invalid_envelope');
        $hex=(string)($trust['public_key_hex']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$hex)
          ||($trust['kid']??'')!==$envelope['kid'])
            return self::reject('signer_untrusted');
        $payload=json_decode($envelope['payload_json'],true,32,JSON_BIGINT_AS_STRING);
        if(!is_array($payload)||json_last_error()!==JSON_ERROR_NONE
          ||($payload['contract']??'')!==self::CONTRACT)
            return self::reject('payload_invalid');
        if(!sodium_crypto_sign_verify_detached(hex2bin($envelope['signature_hex']),
             $envelope['payload_json'],hex2bin($hex)))
            return self::reject('signature_invalid');
        if(($payload['kid']??'')!==$envelope['kid']
          ||($payload['issuer_site_uuid']??'')!==($trust['issuer_site_uuid']??null)
          ||($payload['issuer_origin']??'')!==($trust['issuer_origin']??null)
          ||($payload['target_site_uuid']??'')!==($target['site_uuid']??null)
          ||($payload['target_environment']??'')!==($target['environment']??null)
          ||($payload['target_origin']??'')!==($target['origin']??null))
            return self::reject('issuer_or_target_mismatch');
        $now=time();$start=$payload['issued_at']??null;$end=$payload['expires_at']??null;
        if(!is_int($start)||!is_int($end)||$start>$now+60
          ||$start<$now-86400||$end<=$now||$end<=$start
          ||$end-$start>86400)return self::reject('publication_expired');
        $mode=(string)($payload['publication_state']??'');
        if($mode==='qa_signed') {
            if(!$qa||!MAD4B_Tenant_Operation_Authority::qa_draft()
              ||!defined('MAD4B_HUB_EPHEMERAL_QA')||MAD4B_HUB_EPHEMERAL_QA!==true)
                return self::reject('qa_publication_not_authorized');
        }elseif($mode==='approved'){
            if(!is_string($payload['approval_ref']??null)
                ||!preg_match('/^[a-zA-Z0-9_.:-]{12,128}$/D',$payload['approval_ref']))
                return self::reject('approval_receipt_missing');
        } else return self::reject('publication_not_approved');
        $id=(string)($payload['global_content_id']??'');
        $version=(string)($payload['source_version']??'');
        $lang=(string)($payload['language']??'');
        if(!preg_match('/^content-[a-f0-9]{32}$/D',$id)
          ||!preg_match('/^[a-f0-9]{64}$/D',$version)
          ||!preg_match('/^[a-z]{2,3}(?:-[a-z]{2,5})?$/D',$lang)
          ||!in_array(($payload['post_type']??''),['tours-and-activities','properties','transportations'],true))
            return self::reject('content_identity_invalid');
        $allow=$payload['allowed_tenants']??null;
        if(!is_array($allow)||count($allow)>100
           ||!in_array($target['site_uuid'],$allow,true))return self::reject('tenant_not_allowed');
        $defs=MAD4B_Tenant_Field_Registry::definitions($payload['post_type']);
        $fields=$payload['fields']??null;
        if(!is_array($fields)||count($fields)>100||array_diff(array_keys($fields),array_keys($defs)))
            return self::reject('unregistered_content_field');
        foreach($fields as $field=>$value)
            if($defs[$field]['default']==='local_only'
              ||!MAD4B_Tenant_Field_Registry::valid_value($field,$value,$target['site_uuid'],'source',$defs))
                return self::reject('invalid_source_data');
        return [
            'contract'=>self::CONTRACT.'.verified',
            'valid'=>true,'global_content_id'=>$id,'source_version'=>$version,
            'language'=>$lang,'post_type'=>$payload['post_type'],
            'issuer_site_uuid'=>$payload['issuer_site_uuid'],
            'issuer_origin'=>$payload['issuer_origin'],
            'target_site_uuid'=>$payload['target_site_uuid'],
            'source_fields'=>$fields,
            'envelope_sha256'=>hash('sha256',$envelope['payload_json'].'|'.$envelope['signature_hex']),
            'expires_at'=>$end,
            'publication_state'=>$mode,
            'qa_only'=>$mode==='qa_signed',
            'import_authorized'=>false,'shadow_create_authorized'=>false,
            'commerce_quote_authorized'=>false,'mutation_performed'=>false,
        ];
    }
    public static function verify_live($envelope) {
        if(!class_exists('MAD4B_Tenant_Sync'))return self::reject('missing_tenant_profile');
        $p=MAD4B_Tenant_Sync::profile();
        if(empty($p['ready'])||!is_readable(self::RUNTIME_KEY))return self::reject('publisher_not_enrolled');
        $key=trim((string)file_get_contents(self::RUNTIME_KEY));
        if(!preg_match('/^[a-f0-9]{64}$/D',$key))
            return self::reject('publisher_key_invalid');
        $issuer=get_option('mad4b_hub_issuer_enrollment_v1',null);
        if(!is_array($issuer)||($issuer['target_site_uuid']??'')!==$p['site_uuid']
            ||($issuer['environment']??'')!==$p['environment'])
            return self::reject('issuer_enrollment_absent');
        $trust=[
            'kid'=>$issuer['kid']??'',
            'public_key_hex'=>$key,'issuer_site_uuid'=>$issuer['issuer_site_uuid']??'',
            'issuer_origin'=>$issuer['issuer_origin']??'',
        ];
        return self::verify($envelope,$trust,[
            'site_uuid'=>$p['site_uuid'],'environment'=>$p['environment'],
            'origin'=>rtrim(home_url(),'/')
        ],false);
    }
}
