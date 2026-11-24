<?php
/**
 * Candidate contract for the existing Governor listing-render boundary.
 * No WordPress hooks, registry writes, certificate issuer or activation.
 * Only an original authorized adapter may supply a verified receipt and inputs.
 */
final class MAD4B_Governor_Navigation_Card_Contract_V1 {
    const GROUP='mad4b-rq-public-fragments-v1';
    const CONTRACT='mad4b.governor.navigation-card.v1';
    private const LISTINGS=[67125=>'destinations',67124=>'tour-types'];
    private static function sha($v):bool {
        return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;
    }
    public static function identity(array $c):?array {
        if(($c['environment']??'')!=='staging'||($c['anonymous']??null)!==true
            ||($c['native_navigation_scope']??null)!==true
            ||($c['original_governor_admitted']??null)!==true
            ||($c['market_attested']??null)!==true
            ||($c['route_kind']??'')!=='tour'
            ||($c['request_method']??'')!=='GET')return null;
        $id=$c['listing_id']??0;$term=$c['term_id']??0;
        if(!is_int($id)||!is_int($term)||$term<1
            ||!isset(self::LISTINGS[$id])||($c['taxonomy']??null)!==self::LISTINGS[$id])return null;
        if(!is_string($c['site_uuid']??null)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$c['site_uuid'])
            ||!is_string($c['origin']??null)||!preg_match('~^https://[a-z0-9.-]+/?$~D',$c['origin'])
            ||!preg_match('/^[a-z]{2,12}$/D',(string)($c['language']??''))
            ||!preg_match('/^[a-zA-Z_]{2,20}$/D',(string)($c['locale']??''))
            ||!in_array(($c['device']??''),['mobile','desktop'],true)
            ||!preg_match('/^[A-Z]{2}$/D',(string)($c['country']??''))
            ||!preg_match('/^[A-Z]{3}$/D',(string)($c['currency']??'')))return null;
        foreach(['template_sha','term_dependency_sha','runtime_dependency_sha','market_policy_sha','asset_manifest_sha'] as $k)
            if(!self::sha($c[$k]??null))return null;
        if(!is_string($c['generation']??null)||!preg_match('/^[a-zA-Z0-9:._-]{1,160}$/D',$c['generation']))return null;
        // Page identity is deliberately absent from the key, but its eligibility
        // is checked against the independent receipt before any cache operation.
        $keys=['site_uuid','origin','environment','route_kind','language','locale','device','country','currency',
            'listing_id','taxonomy','term_id','template_sha','term_dependency_sha','runtime_dependency_sha',
            'market_policy_sha','asset_manifest_sha','generation'];
        $out=['contract'=>self::CONTRACT];foreach($keys as $k)$out[$k]=$c[$k];
        return $out;
    }
    public static function key(array $identity):string {
        return 'nav-card-v1:'.hash('sha256',json_encode($identity,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    public static function admitted(array $c,array $receipt,int $now):?array {
        $i=self::identity($c);if(!$i)return null;
        if(($receipt['contract']??'')!==self::CONTRACT
            ||($receipt['authority_verified']??null)!==true
            ||($receipt['full_renderer_bytes_verified']??null)!==true
            ||($receipt['asset_parity_verified']??null)!==true
            ||($receipt['browser_parity_verified']??null)!==true
            ||($receipt['identity']??null)!==$i
            ||!self::sha($receipt['evidence_sha']??null)
            ||!self::sha($receipt['html_sha']??null)
            ||!is_int($receipt['expires_at']??null)
            ||$receipt['expires_at']<=$now)return null;
        $pages=$receipt['verified_page_ids']??null;$page=$c['page_id']??null;
        if(!is_array($pages)||count($pages)<2||count($pages)>128||!is_int($page)||$page<1)return null;
        foreach($pages as $p)if(!is_int($p)||$p<1)return null;
        if(count(array_unique($pages))<2||!in_array($page,$pages,true))return null;
        return $i;
    }
    public static function safe_html($html):bool {
        return is_string($html)&&strlen($html)>=60&&strlen($html)<=20000
            &&!preg_match('~<\s*(?:script|form|input|iframe)\b|nonce|aria-current|\be-current\b|checkout|payment|data-(?:price|rate|amount|session|user|cart)|elementor-dcss-[0-9]{10,22}(?![0-9])~i',$html);
    }
    public static function render(array $context,array $verifiedReceipt,int $now,callable $native,
        callable $read,callable $write,callable $assets,callable $freshContext) {
        $i=self::admitted($context,$verifiedReceipt,$now);
        if(!$i)return $native();
        $key=self::key($i);$sha=$verifiedReceipt['html_sha'];
        $current=static function()use($context,$verifiedReceipt,$freshContext,$i):bool{
            $fresh=$freshContext();
            return ($fresh['page_id']??null)===($context['page_id']??null)
                &&self::admitted($fresh,$verifiedReceipt,time())===$i;
        };
        $cached=$read($key,self::GROUP);
        if(is_string($cached)&&self::safe_html($cached)&&hash_equals($sha,hash('sha256',$cached))
            &&$current()
            &&$assets($verifiedReceipt)===true)return $cached;
        $html=$native();
        // Never promote evidence, bypass a renderer or extend an expired receipt.
        if(self::safe_html($html)&&hash_equals($sha,hash('sha256',$html))
            &&$current()){
            $ttl=min(600,$verifiedReceipt['expires_at']-time());
            if($ttl>0)$write($key,$html,self::GROUP,$ttl);
        }
        return $html;
    }
}
