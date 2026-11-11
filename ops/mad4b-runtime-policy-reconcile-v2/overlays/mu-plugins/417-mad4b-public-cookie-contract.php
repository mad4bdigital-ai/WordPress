<?php
/**
 * Plugin Name: MAD4B Public Cookie Cache Contract v1
 * Description: Conservative allowlist for telemetry-only cookies, never personal session/market/favourites.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Public_Cookie_Cache_Contract_V1 {
    private const CONTRACT = 'mad4b.public-cookie-cache.v1';
    public static function telemetry_only(string $raw): bool {
        if ($raw === '') return true;
        if (strlen($raw) > 1024 || preg_match('/[\x00-\x1f\x7f]/', str_replace(["\t"], '', $raw))) return false;
        $name = '(?:_ga(?:_[A-Za-z0-9]+)?|_gid|_gat(?:_[A-Za-z0-9]+)?|_gcl_au)';
        $pair = $name.'=[A-Za-z0-9._%-]{1,160}';
        return preg_match('~\A'.$pair.'(?:;[ \t]*'.$pair.')*\z~D', $raw) === 1;
    }
    // Only stable home and published-tour shells. Archive, forms, booking and
    // contact endpoints deliberately remain outside this narrower contract.
    private static function public_tracking_path():array {
        if (!empty($_SERVER['QUERY_STRING']))return [false,''];
        $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH);
        $parts=array_values(array_filter(explode('/',trim($path,'/')),static fn($p)=>$p!==''));
        $lang='en';
        if (in_array($parts[0]??'',['ar','fr','de','es','it'],true)) {
            $lang=array_shift($parts);
        }
        if (count($parts)===0)return [true,$lang];
        if (count($parts)!==2 || $parts[0]!=='tours-and-activities'
            || !preg_match('~\\A[a-zA-Z0-9_%\\x80-\\xff-]{1,200}\\z~D',$parts[1]))return [false,''];
        return [true,$lang];
    }
    public static function safe_marketing_cookie(string $raw):bool {
        if ($raw==='' || strlen($raw)>4096 || preg_match('/[\\x00-\\x1f\\x7f]/',str_replace("\\t",'',$raw)))return false;
        [$allowed,$locale]=self::public_tracking_path();
        if (!$allowed)return false;
        $parts=explode(';',$raw);if(count($parts)>22)return false;
        $seen=[];$marketing=false;
        foreach($parts as $pair){
            $pair=trim($pair);
            if($pair==='' || !str_contains($pair,'='))return false;
            [$name,$value]=explode('=',$pair,2);
            if(isset($seen[$name]) || !preg_match('/\\A[A-Za-z0-9_.-]{1,80}\\z/D',$name)
               || !preg_match('#\\A[A-Za-z0-9._%$=:/+|@?!~-]{1,512}\\z#D',$value))return false;
            $seen[$name]=true;
            if($name==='wp-wpml_current_language') {
                if(!hash_equals($locale,rawurldecode($value)))return false;
                continue;
            }
            if(preg_match('/\\A(?:sbjs_(?:migrations|current_add|first_add|current|first|udata|session)|_ga(?:_[A-Za-z0-9]+)?|_gid|_gat(?:_[A-Za-z0-9]+)?|_gcl_(?:au|aw|dc|gb)|_fbp|_fbc|_clck|_clsk)\\z/D',$name)){
                $marketing=true;continue;
            }
            return false;
        }
        return $marketing;
    }
    public static function allow(string $raw): bool {
        if ($raw === '') return true;
        $policy=WPMU_PLUGIN_DIR.'/mad4b-public-cookie-cache.policy.json';
        if (!is_file($policy) || is_link($policy) || @filesize($policy)>2048) return false;
        $p=json_decode((string)file_get_contents($policy),true);
        if (!is_array($p) || ($p['contract']??'')!==self::CONTRACT) return false;
        $host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        if (!in_array(($p['site']??null),['@current',$host],true)) return false;
        if (($p['mode']??'off')==='active') {
            return self::telemetry_only($raw) || self::safe_marketing_cookie($raw);
        }
        // Canary never authorizes shared caching at the edge.
        return ($p['mode']??'off')==='canary'
            && self::telemetry_only($raw)
            && ($_SERVER['HTTP_X_MAD4B_ANALYTICS_COOKIE_CANARY']??'')==='1';
    }

}
