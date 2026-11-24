<?php
/**
 * Candidate adapter to the original Governor release-owned policy.
 * No hooks, writes, approval creation, or runtime installation.
 * Only the Governor's private policy reader and native audit may supply
 * inputs at the future integration boundary. Never use request data.
 */
final class MAD4B_Governor_Navigation_Card_Authority_V1 {
    private const POLICY='mad4b.render-query.public-fragments.v1';
    public static function resolve(array $policy,array $context,callable $nativeAudit,int $now):array {
        $deny=static fn($reason)=>['ready'=>false,'reason'=>$reason];
        if(($policy['contract']??'')!==self::POLICY
            ||($policy['status']??'')!=='active'
            ||($policy['environment']??'')!=='staging'
            ||($context['environment']??'')!=='staging'
            ||($policy['site_host']??'')!==parse_url((string)($context['origin']??''),PHP_URL_HOST)
            ||!is_int($policy['ttl_seconds']??null)||$policy['ttl_seconds']<15||$policy['ttl_seconds']>600)
            return $deny('native_policy_scope');
        $id=$context['listing_id']??0;
        if(!is_int($id)||!in_array($id,[67124,67125],true))return $deny('listing_scope');
        $cfg=$policy['templates'][$id]??null;
        if(!is_array($cfg)||($cfg['navigation_card_only']??null)!==true
            ||($cfg['tour_only']??null)!==true||($cfg['activation']??'')!=='active')
            return $deny('native_template_not_enrolled');
        if(($context['template_sha']??null)!==($cfg['elementor_sha256']??null)
            ||!in_array(($context['taxonomy']??''),(array)($cfg['taxonomies']??[]),true)
            ||$nativeAudit($id,$cfg)!==true)return $deny('native_template_audit');
        $context['original_governor_admitted']=true;
        $i=MAD4B_Governor_Navigation_Card_Contract_V1::identity($context);
        if(!$i)return $deny('context_identity');
        $key=MAD4B_Governor_Navigation_Card_Contract_V1::key($i);
        $entry=$policy['navigation_card_receipts'][$key]??null;
        if(!is_array($entry)||($entry['status']??'')!=='approved'
            ||!is_array($entry['receipt']??null))return $deny('native_receipt_missing');
        // Approval comes from the release-owned original manifest.
        // The entry must pin the exact independently reviewed receipt.
        $r=$entry['receipt'];
        $encoded=json_encode($r,JSON_UNESCAPED_SLASHES);
        $pin=$entry['receipt_sha256']??null;
        if(!is_string($encoded)||!is_string($pin)||!preg_match('/^[a-f0-9]{64}$/D',$pin)
            ||!hash_equals($pin,hash('sha256',$encoded)))return $deny('receipt_pin_mismatch');
        // A receipt must not grant its own authority. The consumer derives
        // this assertion only after the original manifest and audit passed.
        if(array_key_exists('authority_verified',$r))return $deny('self_asserted_authority');
        $r['authority_verified']=true;
        if(MAD4B_Governor_Navigation_Card_Contract_V1::admitted($context,$r,$now)!==$i)
            return $deny('receipt_ineligible');
        return ['ready'=>true,'reason'=>'native_policy_receipt','context'=>$context,'receipt'=>$r,
            'ttl_ceiling'=>$policy['ttl_seconds'],'receipt_sha256'=>$pin];
    }
}
