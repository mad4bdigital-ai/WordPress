<?php
/** Canonical early MU map verification. No mutation or provider authority. */
defined('ABSPATH') || exit;
final class MAD4B_MU_Map_Contract_V1 {
    const PATH = '/plugins/mad4b-platform/config/ordered-mu-map-contract.json';
    private static function is_sequential(array $value): bool {
        // Works on PHP 7.4 and 8.0 where array_is_list() does not exist.
        return array_keys($value) === array_keys(array_values($value));
    }
    public static function manifest(): array {
        $p = WP_CONTENT_DIR . self::PATH;
        if (!is_file($p) || !is_readable($p)) return [];
        $data = json_decode((string) file_get_contents($p), true);
        return is_array($data) ? $data : [];
    }
    public static function verify($entries, $manifest): bool {
        if (!is_array($entries) || !self::is_sequential($entries) || !is_array($manifest)
            || ($manifest['contract'] ?? '') !== 'mad4b.mu-ordered-map-integrity.v1'
            || !is_int($manifest['expected_count'] ?? null)
            || count($entries) !== $manifest['expected_count']
            || !is_array($manifest['ordered_entries'] ?? null)
            || $entries !== $manifest['ordered_entries']
            || !is_array($manifest['by_family'] ?? null)) return false;
        $seen=[]; $groups=[];
        foreach ($entries as $item) {
            if (!is_array($item) || !self::is_sequential($item) || count($item)!==2
                || !is_string($item[0]) || !is_string($item[1])
                || !preg_match('/^[a-zA-Z0-9._-]+\.php$/D',$item[0])
                || !preg_match('~^mad4b-(?:platform|commerce)/modules/[a-z-]+/[a-zA-Z0-9._-]+\.php$~D',$item[1])
                || isset($seen[$item[0]])) return false;
            $seen[$item[0]]=true;
            $family=explode('/',$item[1],2)[0];
            $groups[$family]=($groups[$family]??0)+1;
        }
        ksort($groups);
        $encoded=json_encode($entries,JSON_UNESCAPED_SLASHES);
        return is_string($encoded) && preg_match('/^[a-f0-9]{64}$/D',(string)($manifest['ordered_entries_sha256']??''))===1
            && hash_equals((string)$manifest['ordered_entries_sha256'],hash('sha256',$encoded))
            && $groups===$manifest['by_family'];
    }
    public static function current(array $entries): bool {
        return self::verify($entries,self::manifest());
    }
}
