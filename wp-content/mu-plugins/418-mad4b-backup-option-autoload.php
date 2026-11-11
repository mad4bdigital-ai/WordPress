<?php
/**
 * Plugin Name: MAD4B Backup Options Autoload Policy
 * Description: Keep MAD4B migration/reconciliation backups persistent but not automatically loaded per frontend request.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Backup_Option_Autoload_V1 {
    private static bool $changing = false;
    public static function match(string $option):bool {
        return strlen($option)<=240 && str_starts_with($option,'mad4b_')
            && str_contains($option,'_backup_')
            && preg_match('/\A[a-zA-Z0-9_-]+\z/D',$option)===1;
    }
    public static function active():bool {
        $path=WPMU_PLUGIN_DIR.'/mad4b-backup-option-autoload.policy.json';
        if (!is_file($path) || is_link($path) || filesize($path)>2048)return false;
        $p=json_decode((string)file_get_contents($path),true);
        if (!is_array($p) || ($p['contract']??'')!=='mad4b.backup-autoload.v1'
            || ($p['mode']??'off')!=='active')return false;
        $host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        return in_array(($p['site']??null),['@current',$host],true);
    }
    public static function changed(string $option):void {
        if (self::$changing || !self::match($option) || !self::active())return;
        if (!function_exists('wp_set_option_autoload_values'))return;
        self::$changing=true;
        try {
            // Only the autoload flag changes. Option value is never read or replaced.
            wp_set_option_autoload_values([$option=>false]);
        } finally {self::$changing=false;}
    }
    public static function boot():void {
        add_action('added_option',static function ($name):void {self::changed((string)$name);},99,1);
        add_action('updated_option',static function ($name):void {self::changed((string)$name);},99,1);
    }
}
MAD4B_Backup_Option_Autoload_V1::boot();
