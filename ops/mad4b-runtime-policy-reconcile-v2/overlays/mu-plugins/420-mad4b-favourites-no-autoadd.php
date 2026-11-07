<?php
/**
 * Plugin Name: MAD4B Favourites Opt-In Policy
 * Description: JetEngine Favourites changes only after the visitor explicitly clicks; visits never auto-favourite.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Favourites_OptIn_Policy_V1 {
    public static function normalize_args($args, $item) {
        if (!is_array($args) || !is_array($item)) return $args;
        if (($item['slug']??null)!=='favourites')return $args;
        if (($args['type']??null)!=='cookies')return $args;
        $args['store_on_view']=false;
        return $args;
    }
    public static function boot():void {
        add_filter('jet-engine/data-stores/settings/args-to-save',[self::class,'normalize_args'],999,2);
    }
}
MAD4B_Favourites_OptIn_Policy_V1::boot();
