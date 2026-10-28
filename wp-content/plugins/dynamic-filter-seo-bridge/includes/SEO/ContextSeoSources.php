<?php
namespace DynamicFilterSEOBridge\SEO;
use DynamicFilterSEOBridge\Context\PageContextResolver;
use DynamicFilterSEOBridge\Presentation\PortableMediaGallery;

/** Read-only SEO projection of native public WordPress content. */
final class ContextSeoSources {
    public const CONTRACT='dfsb.context-seo-sources.v1';
    public static function forContext(array $context,bool $withExtensions=false): array {
        $kind=(string)($context['page_kind']??'unresolved');
        $type=(string)($context['post_type']??'');
        $identity=SiteSeoSources::identity($withExtensions);
        $out=array('contract'=>self::CONTRACT,'status'=>'unresolved',
            'authorizing'=>false,'read_only'=>true,'identity'=>$identity,
            'title'=>'','description'=>'','description_source'=>'none',
            'image_attachment_id'=>0,'image_url'=>'');
        if(!in_array($kind,array('posts_home','post_type_archive','taxonomy','singular','shop_archive'),true)
            || ($type!==''&&!PageContextResolver::publicType($type)))return $out;
        $label=SiteSeoSources::plain((string)($context['title']??''),120);
        if($label==='')return $out;
        $desc=SiteSeoSources::plain((string)($context['description']??''),240);
        $source=$desc!==''?'native_content':'missing_source';
        if($desc===''){
            $desc=SiteSeoSources::plain((string)get_option('blogdescription',''),150);
            if($desc!=='')$source='site_tagline';
        }
        $out['status']='resolved';
        $out['title']=SiteSeoSources::plain($label.' | '.$identity['name'],160);
        $out['description']=$desc===''?'':($kind==='singular'
            ?SiteSeoSources::plain($desc,165)
            :SiteSeoSources::archiveFallbackDescription($label,$identity['name'],$desc,
                (string)($context['language']??get_locale())));
        $out['description_source']=$source;
        // Only explicitly tagged shared media may become a social image.
        // Local featured media stays preferred. No remote fetch or conversion.
        $explicitShared='';
        $ref=$context['image_shared_reference']??'';
        if(is_string($ref) && str_starts_with($ref,'shared:')){
            $explicitShared=\DynamicFilterSEOBridge\Presentation\SharedMediaSources::urlForShared(substr($ref,7));
        }
        $id=absint($context['image_attachment_id']??0);
        if($id<=0 && $explicitShared==='')$id=absint($identity['logo_id']??0);
        if($explicitShared!=='')$out['image_url']=$explicitShared;
        if($id>0 && wp_attachment_is_image($id) && is_file((string)get_attached_file($id))){
            $url=(string)PortableMediaGallery::attachmentUrl($id);
            if(str_starts_with($url,'/')){
                $p=wp_parse_url(home_url('/'));
                if(is_array($p)&&!empty($p['host']))$url=($p['scheme']??'https').'://'.$p['host'].$url;
            }
            if(preg_match('~^https?://~i',$url)){
                $out['image_attachment_id']=$id;
                $out['image_url']=$url;
            }
        }
        if($withExtensions){
            $extra=apply_filters('dfsb/seo/context_sources',$out,$context);
            if(is_array($extra))foreach(array('title','description')as $key){
                if(!empty($extra[$key])&&is_string($extra[$key])){
                    $out[$key]=SiteSeoSources::plain($extra[$key],$key==='title'?160:165);
                }
            }
        }
        return $out;
    }
}
