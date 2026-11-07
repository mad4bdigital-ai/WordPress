<?php
/** Source-data invalidation for company-private catalog review receipts. */
defined('ABSPATH') || exit;
final class MAD4B_Catalog_Data_Epoch_V1 {
    public const STORE='mad4b_catalog_studio_data_epoch_v1';
    private static ?array $watch=null;
    private static array $dirty=[];
    private static function watch(): array {
        if (self::$watch!==null)return self::$watch;
        self::$watch=['post_type'=>[],'taxonomy'=>[]];
        if(!function_exists('mcfm_get_feeds'))return self::$watch;
        foreach(mcfm_get_feeds() as $feed){
            if(!is_array($feed) || !str_starts_with((string)($feed['slug']??''),'mad4b-managed-'))continue;
            $type=(string)($feed['object_type']??'');$key=(string)($feed['object_key']??'');
            if(isset(self::$watch[$type]) && $key!=='')self::$watch[$type][$key]=true;
        }
        return self::$watch;
    }
    private static function mark(string $kind,string $key): void {
        if($key!=='' && isset(self::watch()[$kind][$key]))self::$dirty[$kind.':'.$key]=true;
    }
    public static function post($id): void {
        $type=get_post_type((int)$id);
        if(is_string($type))self::mark('post_type',$type);
    }
    public static function post_meta($meta_id,$object_id): void {self::post((int)$object_id);}
    public static function term_meta($meta_id,$term_id): void {
        $term=get_term((int)$term_id);
        if($term instanceof WP_Term)self::mark('taxonomy',(string)$term->taxonomy);
    }
    public static function edited_term($term_id,$tt_id,$taxonomy): void {
        self::mark('taxonomy',(string)$taxonomy);
    }
    public static function object_terms($object_id,$terms,$tt_ids,$taxonomy): void {
        self::post((int)$object_id);
    }
    public static function epoch(string $type,string $key): string {
        $all=get_option(self::STORE,[]);
        $id=$type.':'.$key;
        return is_array($all)?(string)($all[$id]??'0'):'0';
    }
    public static function flush(): void {
        if(!self::$dirty)return;
        $values=get_option(self::STORE,[]);
        if(!is_array($values))$values=[];
        $stamp=sprintf('%.6f',microtime(true));
        foreach(array_keys(self::$dirty) as $key)$values[$key]=$stamp;
        update_option(self::STORE,$values,false);
        self::$dirty=[];
    }
    public static function boot(): void {
        add_action('save_post',[__CLASS__,'post'],50,1);
        foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook)
            add_action($hook,[__CLASS__,'post_meta'],50,2);
        foreach(['added_term_meta','updated_term_meta','deleted_term_meta'] as $hook)
            add_action($hook,[__CLASS__,'term_meta'],50,2);
        add_action('edited_term',[__CLASS__,'edited_term'],50,3);
        add_action('created_term',[__CLASS__,'edited_term'],50,3);
        add_action('delete_term',[__CLASS__,'edited_term'],50,3);
        add_action('set_object_terms',[__CLASS__,'object_terms'],50,4);
        add_action('shutdown',[__CLASS__,'flush'],1);
    }
}
MAD4B_Catalog_Data_Epoch_V1::boot();
