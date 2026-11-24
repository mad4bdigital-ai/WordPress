<?php
/**
 * Isolated integration candidate. Original Governor supplies server-internal
 * callbacks. No hook registration, issuance, activation or option writes.
 */
final class MAD4B_Governor_Navigation_CSS_Adapter_V1 {
    public static function resolve(callable $nativePolicy,callable $nativeSnapshot,callable $nativeAudit):array {
        $s=$nativeSnapshot();
        if(!is_array($s)||!is_array($s['context']??null)||!is_array($s['css_sources']??null))
            return ['ready'=>false,'reason'=>'native_asset_snapshot_missing'];
        $i=MAD4B_Navigation_CSS_Dependency_V1::identity($s['css_sources'],(string)($s['css_runtime_sha']??''));
        $other=(string)($s['other_assets_sha']??'');
        if(!$i||!($sha=MAD4B_Navigation_CSS_Dependency_V1::bind($i,$other)))
            return ['ready'=>false,'reason'=>'native_asset_identity_missing'];
        $ctx=$s['context'];
        if(($ctx['listing_id']??null)!==67125)return ['ready'=>false,'reason'=>'unobserved_listing'];
        $ctx['asset_manifest_sha']=$sha;
        $p=$nativePolicy();
        if(!is_array($p))return ['ready'=>false,'reason'=>'native_policy_missing'];
        $result=MAD4B_Governor_Navigation_Card_Authority_V1::resolve($p,$ctx,$nativeAudit,time());
        if(!$result['ready'])return $result;
        $result['css_identity']=$i;$result['other_assets_sha']=$other;
        return $result;
    }
    public static function render(callable $nativePolicy,callable $nativeSnapshot,callable $nativeAudit,
        callable $native,callable $read,callable $write,callable $nativeAssets) {
        $r=self::resolve($nativePolicy,$nativeSnapshot,$nativeAudit);
        if(!$r['ready'])return $native();
        $fresh=static function()use($nativePolicy,$nativeSnapshot,$nativeAudit,$r):array{
            $n=self::resolve($nativePolicy,$nativeSnapshot,$nativeAudit);
            if(!$n['ready']||!hash_equals($r['receipt_sha256'],$n['receipt_sha256']))return [];
            return $n['context'];
        };
        $assets=static function($receipt)use($nativeAssets,$fresh,$r):bool{
            // The native replay adapter must return the actual ordered CSS
            // bytes and independently verified non-segment asset digest.
            $a=$nativeAssets($receipt);
            if(!is_array($a)||!is_string($a['segment_bytes']??null)
                ||($a['other_assets_sha']??null)!==$r['other_assets_sha']
                ||!MAD4B_Navigation_CSS_Dependency_V1::segment_matches($r['css_identity'],$a['segment_bytes']))return false;
            return $fresh()===$r['context'];
        };
        $bounded=static function($key,$html,$group,$ttl)use($write,$r){
            $ttl=min($ttl,$r['ttl_ceiling']);
            if($ttl>0)$write($key,$html,$group,$ttl);
        };
        return MAD4B_Governor_Navigation_Card_Contract_V1::render($r['context'],$r['receipt'],time(),
            $native,$read,$bounded,$assets,$fresh);
    }
}
