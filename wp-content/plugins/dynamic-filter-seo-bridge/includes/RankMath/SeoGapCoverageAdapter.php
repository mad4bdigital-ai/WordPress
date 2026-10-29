<?php
namespace DynamicFilterSEOBridge\RankMath;

use DynamicFilterSEOBridge\SEO\SiteSeoSources;
use DynamicFilterSEOBridge\SEO\ContextSeoSources;
use DynamicFilterSEOBridge\Context\PageContextResolver;

/** Scoped native-first Rank Math metadata gap repair, no indexing authority. */
final class SeoGapCoverageAdapter {
    // Same-request evidence that Rank Math will emit a BreadcrumbList;
    // don't suppress JetBlocks visual breadcrumb or JSON-LD on routes without
    // an observed Rank Math owner (including unrelated tenant templates).
    private static $rankMathBreadcrumbObserved=false;
    public static function register(): void {
        // Never bypass a held/unverified package or issue public SEO output
        // while the governed full bootstrap is prohibited.
        if(\DynamicFilterSEOBridge\Runtime\BootGuard::shouldHold())return;
        (new self())->hooks();
    }
    public function hooks(): void {
        add_filter('rank_math/frontend/title',array($this,'title'),99);
        add_filter('rank_math/frontend/description',array($this,'description'),99);
        add_filter('rank_math/opengraph/type',array($this,'type'),99);
        add_filter('rank_math/opengraph/facebook/og_title',array($this,'title'),99);
        add_filter('rank_math/opengraph/facebook/og_description',array($this,'description'),99);
        add_filter('rank_math/opengraph/twitter/twitter_title',array($this,'title'),99);
        add_filter('rank_math/opengraph/twitter/twitter_description',array($this,'description'),99);
        add_filter('rank_math/opengraph/facebook/og_site_name',array($this,'siteName'),99);
        add_filter('rank_math/opengraph/facebook/image',array($this,'image'),99);
        add_filter('rank_math/opengraph/twitter/image',array($this,'image'),99);
        // Rank Math only invokes the image URL filter after an image reaches
        // add_image(). Empty archive image collections need add_images instead.
        add_action('rank_math/opengraph/facebook/add_images',array($this,'addMissingImage'),90,1);
        add_action('rank_math/opengraph/twitter/add_images',array($this,'addMissingImage'),90,1);
        add_filter('rank_math/json_ld',array($this,'schema'),105,2);
        // JetBlocks owns the visual breadcrumbs; Rank Math owns the single
        // JSON-LD BreadcrumbList. This filter leaves the visual widget intact.
        add_filter('cx_breadcrumbs/json_ld_schema',array($this,'jetBreadcrumbs'),99,2);
    }

    /** Back-compatible archive helper; no hardcoded company/CPT allowlist. */
    public static function postType(): string {
        if(did_action('wp')){
            $context=PageContextResolver::fromQuery();
            return (string)($context['post_type']??'');
        }
        $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        foreach(PageContextResolver::catalog(150)['post_types'] as $type=>$spec){
            if(empty($spec['has_archive']))continue;
            $url=get_post_type_archive_link($type);
            $archive=(string)wp_parse_url((string)$url,PHP_URL_PATH);
            if($archive!=='' && (untrailingslashit($path)===untrailingslashit($archive)
                || str_starts_with($path,trailingslashit($archive).'jsf/')))return $type;
        }
        return '';
    }

    public static function isFaceted(): bool {
        $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        if(str_contains($path,'/jsf/'))return true;
        foreach(array('jet-smart-filters','jsf','filter','tax')as$key){if(isset($_GET[$key]))return true;}
        return false;
    }
    private function identityInfo(): array {
        if(is_admin() || wp_doing_ajax())return array();
        $context=PageContextResolver::fromQuery();
        // Existing isolated contract tests construct only the archive URL.
        // This fallback is never used in a WordPress frontend request.
        if(($context['page_kind']??'')==='unresolved' && !did_action('wp')){
            $type=self::postType();
            if($type!=='')$context=PageContextResolver::forArchive($type);
        }
        if(($context['page_kind']??'')==='unresolved')return array();
        return ContextSeoSources::forContext($context,true);
    }
    private function info(): array {
        // Unapproved JSF combinations never get a manufactured SEO title,
        // description, canonical, robots, social image or pricing claim.
        return self::isFaceted()?array():$this->identityInfo();
    }
    public function title($original) {
        $info=$this->info();
        return $info && SiteSeoSources::plain((string)$original)===''?$info['title']:$original;
    }
    public function description($original) {
        $info=$this->info();
        return $info && SiteSeoSources::plain((string)$original)===''?$info['description']:$original;
    }
    public function type($original) {
        // Open Graph content types for singular pages belong to Rank Math.
        // Never change a BlogPosting/Product/tour's OG type to website.
        $context=PageContextResolver::fromQuery();
        if(($context['page_kind']??'')==='unresolved' && !did_action('wp')){
            $type=self::postType();
            if($type!=='')$context=PageContextResolver::forArchive($type);
        }
        if(!in_array(($context['page_kind']??''),
            array('posts_home','post_type_archive','shop_archive','taxonomy'),true))return $original;
        if(self::isFaceted())return $original;
        return SiteSeoSources::plain((string)$original)===''?'website':$original;
    }
    public function siteName($original) {
        $info=$this->identityInfo();
        return $info?$info['identity']['name']:$original;
    }
    public function image($original) {
        $info=$this->info();
        if(!$info || (is_string($original)&&trim($original)!==''))return $original;
        return $info['image_url']!==''?$info['image_url']:$original;
    }
    /** Native-first OG archive image fallback through Rank Math Image API. */
    public function addMissingImage($images): void {
        // Rank Math already understands singular featured/social product media.
        // Inserting a logo before its native single image pipeline would hide
        // the authored picture. Only archive-type pages need this fallback.
        $ctx=PageContextResolver::fromQuery();
        if(did_action('wp') && !in_array(($ctx['page_kind']??''),
            array('post_type_archive','posts_home','shop_archive','taxonomy'),true))return;
        $info=$this->info();
        if(!$info || !is_object($images) || !method_exists($images,'has_images')
            || !method_exists($images,'add_image_by_id') || $images->has_images())return;
        $type=self::postType();
        if($type==='')return;
        // Prefer explicit Rank Math archive image where valid; its native
        // set_archive_image() runs after add_images() in this Rank Math build.
        $rank=(array)get_option('rank-math-options-titles',array());
        foreach(array('pt_'.$type.'_facebook_image_id','pt_'.$type.'_twitter_image_id','open_graph_image_id')as$key){
            $configured=absint($rank[$key]??0);
            if($configured>0 && wp_attachment_is_image($configured)
                && is_file((string)get_attached_file($configured))){
                $metadata=wp_get_attachment_metadata($configured);
                if(is_array($metadata) && (int)($metadata['width']??0)>=200
                    && (int)($metadata['height']??0)>=200)return;
            }
        }
        $fallbackId=absint($info['image_attachment_id']??0);
        if($fallbackId>0 && wp_attachment_is_image($fallbackId)
            && is_file((string)get_attached_file($fallbackId))){
            $images->add_image_by_id($fallbackId);
            return;
        }
        if(!empty($info['image_url']) && method_exists($images,'add_image_by_url')){
            $images->add_image_by_url($info['image_url']);
        }
    }
    public function jetBreadcrumbs($schema,$args=array()) {
        return self::$rankMathBreadcrumbObserved?array():$schema;
    }
    public function schema($data,$jsonld=null) {
        // Rank Math renders its graph inside wp_head, before the JetBlocks
        // breadcrumb widget is rendered in the Elementor body. De-duplicate
        // only after observing the actual graph, not based on plugin presence.
        if(is_array($data)){
            $candidates=isset($data['@graph']) && is_array($data['@graph'])?$data['@graph']:$data;
            foreach($candidates as $candidate){
                if(!is_array($candidate))continue;
                $types=(array)($candidate['@type']??array());
                if(in_array('BreadcrumbList',$types,true) && count((array)($candidate['itemListElement']??array()))>=2){
                    self::$rankMathBreadcrumbObserved=true;
                    break;
                }
            }
        }
        $info=$this->identityInfo();
        if(!$info || !is_array($data))return $data;
        $identity=$info['identity'];
        foreach($data as $key=>$node){
            if(!is_array($node))continue;
            $types=(array)($node['@type']??array());
            if(array_intersect($types,array('Organization','TravelAgency','TravelAgent','LocalBusiness'))){
                $node['name']=$identity['name'];
                $node['url']=$identity['url'];
                if($identity['logo_url']!==''){
                    $logo=is_array($node['logo']??null)?$node['logo']:array('@type'=>'ImageObject');
                    $logo['url']=$identity['logo_url'];
                    $logo['contentUrl']=$identity['logo_url'];
                    $logo['caption']=$identity['name'];
                    $node['logo']=$logo;
                }
            }
            if(in_array('WebSite',$types,true)){
                $node['name']=$identity['name'];
                $node['url']=untrailingslashit($identity['url']);
            }
            if(in_array('CollectionPage',$types,true)){
                if(!empty($node['name']))$node['name']=SiteSeoSources::plain((string)$node['name'],200);
                if(empty($node['description']) && !self::isFaceted()){
                    $node['description']=$info['description'];
                }
            }
            if(in_array('BreadcrumbList',$types,true)){
                foreach((array)($node['itemListElement']??array()) as $position=>$item){
                    if(!is_array($item) || (int)($item['position']??0)!==1)continue;
                    if(is_array($item['item']??null))$node['itemListElement'][$position]['item']['@id']=$identity['url'];
                    elseif(is_string($item['item']??null))$node['itemListElement'][$position]['item']=$identity['url'];
                }
            }
            $data[$key]=$node;
        }
        return $data;
    }
}
