<?php
namespace DynamicFilterSEOBridge\Context;

use DynamicFilterSEOBridge\SEO\SiteSeoSources;
use DynamicFilterSEOBridge\SEO\ContextSeoSources;

/** One typed source for Elementor Dynamic Tags, shortcodes and REST preview. */
final class ContextValueResolver {
    public const CONTRACT='dfsb.context-value.v1';
    private const FIELDS=array('title','description','seo_title','seo_description',
        'image_url','page_kind','post_type','taxonomy','language','presentation_slot',
        'content_provider','filter_provider','pricing_provider','pricing_status',
        'availability_provider','booking_status');

    public static function fields(): array {return self::FIELDS;}
    public static function context(string $scope='current',int $id=0,string $type=''): array {
        switch($scope){
            case 'current':return PageContextResolver::fromQuery();
            case 'post':return PageContextResolver::forPost($id);
            case 'taxonomy':
                return $type!==''?PageContextResolver::forTaxonomy(sanitize_key($type),$id):array('page_kind'=>'unresolved');
            case 'archive':return PageContextResolver::forArchive(sanitize_key($type));
            case 'posts_home':return PageContextResolver::forPostsHome();
            case 'shop':return WooCommerceContextResolver::shop();
            default:return array('page_kind'=>'unresolved');
        }
    }
    public static function project(array $context): array {
        $seo=ContextSeoSources::forContext($context,false);
        $provider=ProviderContextResolver::forContext($context);
        $slots=PresentationSlotPolicy::forContext($context);
        $kind=(string)($context['page_kind']??'unresolved');
        $valid=($seo['status']??'')==='resolved';
        $values=array(
            'title'=>$valid?SiteSeoSources::plain((string)($context['title']??''),150):'',
            'description'=>$valid?SiteSeoSources::plain((string)($context['description']??''),1800):'',
            'seo_title'=>$valid?(string)($seo['title']??''):'',
            'seo_description'=>$valid?(string)($seo['description']??''):'',
            'image_url'=>$valid?(string)($seo['image_url']??''):'',
            'page_kind'=>$kind,
            'post_type'=>$valid?(string)($context['post_type']??''):'',
            'taxonomy'=>$valid?(string)($context['taxonomy']??''):'',
            'language'=>$valid?(string)($context['language']??''):'',
            'presentation_slot'=>$valid?(string)($slots['placement']??'none'):'none',
            'content_provider'=>$valid?(string)($provider['content']['provider_id']??'unresolved'):'unresolved',
            'filter_provider'=>$valid?(string)($provider['filter']['provider_id']??'unresolved'):'unresolved',
            'pricing_provider'=>$valid?(string)($provider['pricing']['provider_id']??'unresolved'):'unresolved',
            'pricing_status'=>$valid?(string)($provider['pricing']['status']??'not_evaluated'):'not_evaluated',
            'availability_provider'=>$valid?(string)($provider['availability']['provider_id']??'unresolved'):'unresolved',
            'booking_status'=>'not_authorized',
        );
        return array('contract'=>self::CONTRACT,'read_only'=>true,'authorizing'=>false,
            'resolved'=>$valid,'values'=>$values,'source'=>array(
                'seo_description'=>$seo['description_source']??'none',
                'language'=>$context['language']??'',
                'page_kind'=>$kind),
            'requires_editorial_review'=>($seo['description_source']??'none')!=='native_content');
    }
    /** Typed image for Elementor Image widgets or opt-in image shortcodes. */
    public static function image(array $ctx): array {
        $blank=array('id'=>0,'url'=>'');
        if(!in_array((string)($ctx['page_kind']??''),
            array('singular','taxonomy','post_type_archive','posts_home','shop_archive'),true))return $blank;
        $local=absint($ctx['image_attachment_id']??0);
        $explicitShared=is_string($ctx['image_shared_reference']??null)
            && str_starts_with((string)$ctx['image_shared_reference'],'shared:');
        // Site logo is a SEO fallback, not a content image for the template.
        if($local<=0 && !$explicitShared)return $blank;
        $seo=ContextSeoSources::forContext($ctx,false);
        if(($seo['status']??'')!=='resolved' || empty($seo['image_url']))return $blank;
        $id=$local>0 && (int)($seo['image_attachment_id']??0)===$local?$local:0;
        if($id<=0 && !$explicitShared)return $blank;
        return array('id'=>$id,'url'=>esc_url_raw((string)$seo['image_url']));
    }
    public static function value(array $ctx,string $field): string {
        if(!in_array($field,self::FIELDS,true))return '';
        return (string)(self::project($ctx)['values'][$field]??'');
    }
}
