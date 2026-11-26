<?php
namespace DynamicFilterSEOBridge\Runtime;

/** Cache identity of a local asset, not release provenance or authorization. */
final class AssetVersion {
    private static $versions=array();
    public static function forPath(string $relative):string {
        $fallback=defined('DFSB_ASSET_VERSION')?(string)DFSB_ASSET_VERSION:(defined('DFSB_VERSION')?(string)DFSB_VERSION:'0');
        if(!preg_match('~\Aassets/(?:css|js)/[a-zA-Z0-9_-]+\.(?:css|js)\z~D',$relative))return $fallback;
        if(isset(self::$versions[$relative]))return self::$versions[$relative];
        $root=dirname(__DIR__,2);$path=$root.'/'.$relative;
        if(!is_file($path)||!is_readable($path))return $fallback;
        $hash=hash_file('sha256',$path);
        return is_string($hash)?(self::$versions[$relative]=$hash):$fallback;
    }
}
