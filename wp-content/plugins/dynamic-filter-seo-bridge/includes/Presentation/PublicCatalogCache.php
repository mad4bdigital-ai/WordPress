<?php
namespace DynamicFilterSEOBridge\Presentation;

/** Public control metadata only. Diagnostics, authenticated and AJAX reads stay live. */
final class PublicCatalogCache {
    const GROUP='mad4b_dfsb_public_control_catalog';
    const TTL=120;
    private $memory=array();
    public function registerInvalidationHooks():void {
        foreach(array('clean_post_cache','clean_term_cache','added_term_meta','updated_term_meta','deleted_term_meta','added_post_meta','updated_post_meta','deleted_post_meta','switch_theme','activated_plugin','deactivated_plugin') as $hook){
            add_action($hook,array($this,'invalidate'),20,0);
        }
        foreach(array('added_option','updated_option','deleted_option') as $hook){add_action($hook,array($this,'optionChanged'),20,1);}
    }
    public function optionChanged(string $name):void {
        // Ignore ephemeral WordPress storage. Other options may define a vendor's schema.
        if(strpos($name,'_transient_')===0||strpos($name,'_site_transient_')===0)return;
        $this->invalidate();
    }
    public function invalidate():void {
        $this->memory=array();
        if(function_exists('wp_cache_set_last_changed'))wp_cache_set_last_changed(self::GROUP);
    }
    private function publicKey(array $profiles):string {
        if(!function_exists('wp_cache_get_last_changed')||!function_exists('wp_cache_set_last_changed')||!wp_using_ext_object_cache()||is_admin()||wp_doing_ajax()||is_user_logged_in()||(defined('REST_REQUEST')&&REST_REQUEST)||(defined('WP_CLI')&&WP_CLI)||!empty($_GET)||(function_exists('is_preview')&&is_preview()))return '';
        if(($_SERVER['REQUEST_METHOD']??'')!=='GET')return '';
        $uri=(string)($_SERVER['REQUEST_URI']??'');
        if($uri===''||$uri[0]!=='/'||strlen($uri)>4096||strpos($uri,'?')!==false)return '';
        $locale=apply_filters('wpml_current_language',null);if(!is_string($locale)||$locale==='')$locale=get_locale();
        $epoch=wp_cache_get_last_changed(self::GROUP);if(!$epoch)return '';
        $source=hash_file('sha256',__FILE__).hash_file('sha256',__DIR__.'/InventoryContentCatalog.php');
        // Registration is repeated on every WordPress request, so fingerprint
        // the current registry instead of invalidating every normal page load.
        $taxonomies=array_values(get_taxonomies());sort($taxonomies,SORT_STRING);
        $postTypes=array_values(get_post_types());sort($postTypes,SORT_STRING);
        $parts=array(self::GROUP,defined('DFSB_VERSION')?DFSB_VERSION:'', $source,home_url('/'),get_current_blog_id(),$locale,$uri,$epoch,$profiles,$taxonomies,$postTypes);
        $encoded=wp_json_encode($parts);return is_string($encoded)?hash('sha256',$encoded):'';
    }
    private function valid(array $value):bool {
        return ($value['contract']??'')===InventoryContentCatalog::CONTRACT&&($value['authorizing']??null)===false&&($value['read_only']??null)===true&&($value['profile_mutation']??null)===false&&is_array($value['tokens']??null)&&is_array($value['groups']??null)&&($value['token_count']??null)===count($value['tokens'])&&($value['group_count']??null)===count($value['groups'])&&preg_match('/^[a-f0-9]{64}$/D',(string)($value['snapshot_fingerprint']??''));
    }
    public function get(array $profiles,callable $snapshotProvider,InventoryContentCatalog $catalog):array {
        $key=$this->publicKey($profiles);
        if($key!==''&&isset($this->memory[$key]))return $this->memory[$key];
        if($key!==''){
            $found=false;$cached=wp_cache_get($key,self::GROUP,false,$found);
            if($found&&is_array($cached)&&$this->valid($cached))return $this->memory[$key]=$cached;
        }
        $snapshot=$snapshotProvider();$result=$catalog->build($snapshot,$profiles);
        // Never persist partial/unavailable evidence, or publish a result across an invalidation.
        if($key!==''&&($snapshot['evidence_complete']??false)===true&&$this->valid($result)&&$key===$this->publicKey($profiles)){
            wp_cache_set($key,$result,self::GROUP,self::TTL);$this->memory[$key]=$result;
        }
        return $result;
    }
}
