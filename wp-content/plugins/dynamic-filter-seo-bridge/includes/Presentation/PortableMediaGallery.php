<?php
namespace DynamicFilterSEOBridge\Presentation;

/**
 * WordPress-attachment-driven slideshow portability; no tenant hostname is
 * stored or trusted. Safe even when DFSB is held: no SEO runtime or writes.
 */
final class PortableMediaGallery {
    public function register(): void {
        // A native Elementor slideshow must remain portable in Safe Boot too.
        add_action('elementor/frontend/container/before_render', array($this, 'beforeRender'), 5, 1);
        // Tenant-neutral read-only metadata URL normalizer, independent of
        // Elementor rendering, SEO publication, and Safe Boot state.
        add_filter('get_term_metadata', array($this,'filterTermMediaUrls'), 100, 4);
        // Elementor may populate wrapper data-settings before dynamic meta
        // hydration. Re-sync the *native* slideshow settings after other
        // background handlers, without changing the saved Elementor template.
    }

    public function beforeRender($element): void {
        if (!is_object($element) || !method_exists($element, 'get_settings_for_display')
            || !method_exists($element, 'set_settings')) { return; }
        $settings=$element->get_settings_for_display();
        if (!is_array($settings) || ($settings['background_background']??'')!=='slideshow') { return; }
        // Meta-field galleries are exclusively rendered by the DFSB
        // ContainerDynamicBackground stage. Never try to replace Elementor's
        // native data-settings; doing both produces duplicate JSON and slides.
        $metaSource=($settings['dfsb_background_gallery_source']??'')==='meta_field';
        if($metaSource)return;
        $source=$settings['background_slideshow_gallery']??array();
        if (!is_array($source) || !$source) { return; }
        $repaired=self::resolve($source);
        if ($metaSource || $repaired !== $source) {
            $element->set_settings('background_slideshow_gallery',$repaired);
            // Elementor keeps a separate parsed_active_settings cache;
            // invalidate ONLY this gallery-enabled container after repairing
            // its source, otherwise the rendered native slideshow stays empty.
            $class=new \ReflectionObject($element);
            while($class){
                if($class->hasProperty('parsed_active_settings')){
                    foreach(array('parsed_dynamic_settings','parsed_active_settings')as$key){
                        if(!$class->hasProperty($key))continue;
                        try{
                            $property=$class->getProperty($key);
                            $property->setAccessible(true);
                            $property->setValue($element,null);
                        }catch(\Throwable $e){/* retain original cached presentation */}
                    }
                    break;
                }
                $class=$class->getParentClass();
            }
        }
    }

    /**
     * Resolve the configured tenant-owned WordPress/JetEngine meta field.
     * Metadata holds only attachment IDs, never original-company URLs.
     * With no active destination metadata, use the Elementor template post's
     * metadata. No implicit mutation and no hardcoded attachment identifiers.
     */
    public static function fromConfiguredMeta($element,array $settings): array {
        $key=sanitize_key((string)($settings['dfsb_background_gallery_meta_key']??'dfsb_background_gallery'));
        if(!preg_match('/^[a-z_][a-z0-9_]{1,79}$/',$key))return array();
        $scope=(string)($settings['dfsb_background_gallery_meta_scope']??'term_then_template');
        if(!in_array($scope,array('term_then_template','template','term'),true))$scope='term_then_template';
        $mode=(string)($settings['dfsb_background_gallery_media_policy']??'mixed');
        $mediaPolicy=array('mode'=>in_array($mode,array('mixed','local_only','shared_only'),true)?$mode:'mixed');
        $taxonomy=sanitize_key((string)($settings['dfsb_background_gallery_meta_taxonomy']??'destinations'));
        if(!preg_match('/^[a-z][a-z0-9_-]{2,79}$/',$taxonomy))return array();
        if($scope!=='template'){
            $term=self::activeTerm($taxonomy);
            if($term instanceof \WP_Term){
                $items=self::attachmentItems(get_term_meta($term->term_id,$key,true),$mediaPolicy);
                if($items)return $items;
            }
        }
        if($scope==='term')return array();
        $document=method_exists($element,'get_document')?$element->get_document():null;
        // Elementor Container does not implement get_document(); the
        // Documents Manager exposes the active Theme Builder document.
        if (!is_object($document) && class_exists('Elementor\Plugin')
            && isset(\Elementor\Plugin::$instance->documents)
            && method_exists(\Elementor\Plugin::$instance->documents,'get_current')) {
            $document=\Elementor\Plugin::$instance->documents->get_current();
        }
        $postId=is_object($document)&&method_exists($document,'get_main_id')?(int)$document->get_main_id():0;
        if($postId<=0 || get_post_type($postId)!=='elementor_library')return array();
        return self::attachmentItems(get_post_meta($postId,$key,true),$mediaPolicy);
    }

    public static function activeTerm(string $taxonomy) {
        if(!taxonomy_exists($taxonomy))return null;
        $term=function_exists('get_queried_object')?get_queried_object():null;
        if($term instanceof \WP_Term && $term->taxonomy===$taxonomy)return $term;
        $path=wp_parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH);
        if(!is_string($path)||strlen($path)>800)return null;
        // This recognizes only signed-shape native tour archive URLs. It
        // does not authorize a request or affect the provider query itself.
        if(!preg_match('~^/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/((?:[a-z-]{2,60}:[a-z0-9_-]{1,80})(?:;[a-z-]{2,60}:[a-z0-9_-]{1,80}){0,2})/$~D',$path,$m))return null;
        foreach(explode(';',$m[1]) as $item){
            $pieces=explode(':',$item,2);
            if(count($pieces)===2&&$pieces[0]===$taxonomy){
                $found=get_term_by('slug',$pieces[1],$taxonomy);
                return $found instanceof \WP_Term?$found:null;
            }
        }
        return null;
    }

    /** Mixed galleries: local attachment IDs + explicit shared relative paths. */
    public static function attachmentItems($value,array $policy=array()): array {
        if(is_string($value) && function_exists('maybe_unserialize'))$value=maybe_unserialize($value);
        $refs=array();
        $walk=static function($data) use (&$walk,&$refs): void {
            if(count($refs)>=30)return;
            if(is_array($data)){
                if(isset($data['source']) || isset($data['library'])){
                    $refs[]=$data;return;
                }
                foreach(array('id','attachment_id','value')as$key){
                    if(isset($data[$key])&&is_scalar($data[$key])){
                        $refs[]=$data[$key];return;
                    }
                }
                if(array_is_list($data)){
                    foreach($data as$item)$walk($item);
                }else{
                    foreach(array('gallery','images','items','ids','attachments')as$key){
                        if(isset($data[$key]))$walk($data[$key]);
                    }
                }
                return;
            }
            if(!is_scalar($data))return;
            $txt=trim((string)$data);
            if($txt==='')return;
            if(str_starts_with($txt,'[')||str_starts_with($txt,'{')){
                $decoded=json_decode($txt,true);
                if(is_array($decoded)){$walk($decoded);return;}
            }
            if(str_contains($txt,',') && strlen($txt)<3000){
                foreach(explode(',',$txt)as$ref)$walk(trim($ref));return;
            }
            $refs[]=$txt;
        };
        $walk($value);
        $out=array();$seen=array();
        foreach($refs as $ref){
            $item=SharedMediaSources::reference($ref,$policy);
            if(!$item)continue;
            $key=$item['source']==='local'?'local:'.$item['id']:'shared:'.$item['path'];
            if(isset($seen[$key]))continue;
            $seen[$key]=true;$out[]=$item;
            if(count($out)>=30)break;
        }
        return $out;
    }

    public static function resolve(array $items, int $limit=30): array {
        $out=array();$seen=array();
        foreach(array_slice($items,0,max(1,min(30,$limit))) as $item){
            if(!is_array($item))continue;
            $id=isset($item['id'])&&is_numeric($item['id'])?(int)$item['id']:0;
            if($id>0){
                $url=self::attachmentUrl($id);
                // Never emit an outdated foreign/local host or a missing file.
                if(''===$url || isset($seen['id:'.$id]))continue;
                $seen['id:'.$id]=true;
                $out[]=array('id'=>$id,'url'=>$url);
                continue;
            }
            // Explicit URL-only items (CDN/external collections) are not
            // attachments. Keep vetted HTTPS URLs, never remap to a tenant.
            $url=isset($item['url'])&&is_string($item['url'])?trim($item['url']):'';
            if(!preg_match('~^https://[^\s<>{}]+$~i',$url))continue;
            $url=function_exists('esc_url_raw')?esc_url_raw($url):$url;
            if(''===$url||isset($seen['url:'.$url]))continue;
            $seen['url:'.$url]=true;
            $out[]=array('id'=>0,'url'=>$url);
        }
        return $out;
    }

    /**
     * Rebase an old site upload URL using WordPress General Settings only.
     * Leave URLs unmodified when the referenced file is absent, external, or
     * outside the actual local uploads tree; never modify database values.
     */
    public static function rebaseLocalUploadUrl($source) {
        if(!is_string($source)||strlen($source)>4096)return $source;
        $url=trim($source);
        if(!preg_match('~^https?://~i',$url))return $source;
        $parts=wp_parse_url($url);
        if(!is_array($parts)||empty($parts['host'])||empty($parts['path'])||isset($parts['user'])||isset($parts['pass']))return $source;
        $uploads=wp_get_upload_dir();
        if(!is_array($uploads)||!empty($uploads['error']))return $source;
        $base=(string)($uploads['baseurl']??'');
        $basedir=(string)($uploads['basedir']??'');
        $uploadPath=(string)wp_parse_url($base,PHP_URL_PATH);
        if($uploadPath===''||$basedir===''||!str_starts_with((string)$parts['path'],rtrim($uploadPath,'/').'/'))return $source;
        $tail=substr((string)$parts['path'],strlen(rtrim($uploadPath,'/'))+1);
        if($tail===''||strlen($tail)>2000)return $source;
        $decoded=rawurldecode($tail);
        if(str_contains($decoded,"\0")||str_contains($decoded,'\\')||preg_match('~(^|/)\.{1,2}(/|$)~',$decoded))return $source;
        $root=realpath($basedir);
        $file=realpath(rtrim($basedir,'/').'/'.$decoded);
        if(!$root||!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||!is_file($file))return $source;
        // Explicit custom upload_url_path (including a media CDN) is owned by
        // WordPress; otherwise siteurl is the new tenant's canonical origin.
        if(!get_option('upload_url_path')){
            $site=(string)get_option('siteurl');
            $siteParts=wp_parse_url($site);
            if(is_array($siteParts)&&in_array(strtolower((string)($siteParts['scheme']??'')),array('http','https'),true)
                && !empty($siteParts['host'])&&!isset($siteParts['user'])&&!isset($siteParts['pass'])){
                $origin=strtolower((string)$siteParts['scheme']).'://'.$siteParts['host'];
                if(isset($siteParts['port']))$origin.=':'.(int)$siteParts['port'];
                $base=$origin.$uploadPath;
            }
        }
        $canonical=rtrim($base,'/').'/'.$tail;
        if(!empty($parts['query']))$canonical.='?'.$parts['query'];
        return esc_url_raw($canonical);
    }

    /** Existing JetEngine term galleries, including legacy image_gellery. */
    public function filterTermMediaUrls($override,$termId,$metaKey,$single) {
        if(null!==$override)return $override;
        $allowed=(array)apply_filters('dfsb_portable_media_fields',array('image_gellery','image_gallery','gallery','cover_image','banner_image'));
        if(!in_array((string)$metaKey,$allowed,true))return $override;
        $term=get_term((int)$termId);
        if(!$term || is_wp_error($term))return $override;
        $cache=wp_cache_get((int)$termId,'term_meta');
        if(false===$cache){$loaded=update_meta_cache('term',array((int)$termId));$cache=$loaded[(int)$termId]??array();}
        if(!is_array($cache)||!isset($cache[$metaKey])||!is_array($cache[$metaKey]))return $override;
        $result=array();$modified=false;
        foreach($cache[$metaKey] as $item){
            $value=maybe_unserialize($item);
            if(!is_string($value)){$result[]=$value;continue;}
            $parts=explode(',',$value);
            foreach($parts as &$part){
                $fixed=self::rebaseLocalUploadUrl($part);
                if($fixed!==$part)$modified=true;
                $part=$fixed;
            }unset($part);
            $result[]=implode(',',$parts);
        }
        return $modified ? ($single?($result[0]??''):$result) : $override;
    }

    public static function attachmentUrl(int $id): string {
        if($id<=0 || !function_exists('wp_get_attachment_url') || !function_exists('get_attached_file'))return '';
        $file=get_attached_file($id);
        if(!is_string($file) || !is_file($file) || (function_exists('wp_attachment_is_image')&&!wp_attachment_is_image($id)))return '';
        $url=wp_get_attachment_url($id);
        if(!is_string($url)||!preg_match('~^https?://~i',$url))return '';
        $parsed=wp_parse_url($url);
        if(!is_array($parsed)||empty($parsed['host'])||empty($parsed['path']))return '';
        // Locally stored WordPress uploads are portable as relative paths;
        // remote offload/CDN URLs remain absolute to avoid broken media.
        $base=wp_upload_dir(null,false);
        $baseHost=wp_parse_url((string)($base['baseurl']??''),PHP_URL_HOST);
        $basePath=(string)wp_parse_url((string)($base['baseurl']??''),PHP_URL_PATH);
        // For local uploads, store URL paths independently of any site's
        // hostname. WordPress may cache the uploads base during an in-request
        // domain change; its base host must still match the original asset.
        // Explicit CDN/offload paths remain absolute and retain their origin.
        if(!get_option('upload_url_path') && is_string($baseHost)
            && strcasecmp($baseHost,(string)$parsed['host'])===0
            && $basePath!=='' && str_starts_with((string)$parsed['path'],rtrim($basePath,'/').'/')){
            $relative=(string)$parsed['path'];
            if(!empty($parsed['query']))$relative.='?'.$parsed['query'];
            return function_exists('esc_url_raw')?esc_url_raw($relative):$relative;
        }
        return function_exists('esc_url_raw')?esc_url_raw($url):$url;
    }
}
