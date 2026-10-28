<?php
namespace DynamicFilterSEOBridge\Diagnostics;

use DynamicFilterSEOBridge\Context\PageContextResolver;
use DynamicFilterSEOBridge\Context\ProviderContextResolver;
use DynamicFilterSEOBridge\Context\PresentationSlotPolicy;
use DynamicFilterSEOBridge\SEO\ContextSeoSources;
use DynamicFilterSEOBridge\Runtime\BootGuard;
use DynamicFilterSEOBridge\Presentation\SharedMediaSources;

/** Bounded non-authorizing evidence of the remaining integration gates. */
final class ContextCoverageMatrix {
    public const CONTRACT='dfsb.context-coverage-matrix.v1';
    private static function check(string $id,string $state,string $reason): array {
        return array('id'=>$id,'state'=>$state,'reason'=>$reason);
    }
    public static function forContext(array $ctx): array {
        $kind=(string)($ctx['page_kind']??'unresolved');
        $providers=ProviderContextResolver::forContext($ctx);
        $seo=ContextSeoSources::forContext($ctx,false);
        $presentation=PresentationSlotPolicy::forContext($ctx);
        $held=!empty(BootGuard::status()['effective_hold']);
        $unsupported=in_array($kind,array('unresolved','commerce_transaction','search_results',
            'author_archive','date_archive','not_found'),true);
        $checks=array();
        $checks[]=self::check('content_context',$unsupported?'out_of_scope':'resolved',
            $unsupported?'No automatic SEO publication for this kind':'Native registered content source');
        $checks[]=self::check('seo_title',$seo['status']==='resolved'&&$seo['title']!==''?'source_ready':'unresolved',
            'Rank Math authored metadata always has precedence');
        $quality=(string)($seo['description_source']??'none');
        $nativeChars=function_exists('mb_strlen')?mb_strlen((string)($ctx['description']??'')):
            strlen((string)($ctx['description']??''));
        $minChars=$kind==='singular'?80:120;
        $goodCopy=$quality==='native_content' && $nativeChars>=$minChars;
        $checks[]=self::check('unique_content',$goodCopy?'source_ready':'needs_editorial_review',
            'Source: '.$quality.'; native chars: '.$nativeChars.'; minimum review target: '.$minChars.
            '. Generic tagline never certifies SEO copy');
        $checks[]=self::check('image',$seo['image_url']!==''?'local_image_available':'requires_verified_media',
            'Only verified tenant attachment; remote shared media not implicitly promoted');
        $checks[]=self::check('pricing_provider',($providers['pricing']['status']??'')==='verified_read_only'?'presentation_binding_verified':'unresolved_or_owner_only',
            'Owner discovery never authorizes price, stock, quote or booking');
        $checks[]=self::check('availability_booking','not_authorized',
            'Native service availability and checkout certification required');
        $checks[]=self::check('filters',in_array($kind,array('posts_home','taxonomy','post_type_archive','shop_archive'),true)?'native_available_unbound':'not_applicable',
            'JetSmartFilters query-specific and URL-state verification still required');
        $checks[]=self::check('schema','needs_runtime_html_certification',
            'Rank Math/JetBlocks/WooCommerce owner and per-page graph must be verified');
        $checks[]=self::check('wpml_wcml','needs_language_currency_parity',
            'Source locale resolved; metadata, hreflang and currency output not yet certified');
        $checks[]=self::check('elementor_slots',$presentation['injects_content']?'registered':'defined_not_installed',
            'Elementor is display owner; slot placement is a non-mutating contract');
        $checks[]=self::check('faceted_publication','native_policy_unverified',
            'No automatic indexable combinations or canonical overrides');
        $checks[]=self::check('safe_boot',$held?'held':'requires_release_check',
            'Public output deliberately blocked while package is unverified');
        return array('contract'=>self::CONTRACT,'read_only'=>true,'authorizing'=>false,
            'page_kind'=>$kind,'post_type'=>(string)($ctx['post_type']??''),
            'language'=>(string)($ctx['language']??''),'checks'=>$checks,
            'provider'=>$providers,'seo_source'=>$seo['description_source'],
            'presentation'=>$presentation,'shared_media_enabled'=>SharedMediaSources::settings()['enabled'],
            'production_authorized'=>false);
    }
    public static function sampleCatalog(int $max=30): array {
        $catalog=PageContextResolver::catalog(max(1,min(100,$max)));
        $rows=array();
        foreach($catalog['post_types'] as $type=>$meta){
            $ctx=$type==='post'?PageContextResolver::forPostsHome():
                ($type==='product' && \DynamicFilterSEOBridge\Context\WooCommerceContextResolver::available()
                    ?\DynamicFilterSEOBridge\Context\WooCommerceContextResolver::shop()
                    :PageContextResolver::forArchive($type));
            if(($ctx['page_kind']??'')==='unresolved')continue;
            $coverage=self::forContext($ctx);
            $rows[]=array('type'=>$type,'kind'=>$ctx['page_kind'],
                'checks'=>array_column($coverage['checks'],'state','id'));
            if(count($rows)>=$max)break;
        }
        return array('contract'=>self::CONTRACT,'read_only'=>true,
            'truncated'=>$catalog['truncated']||count($rows)>=$max,'items'=>$rows);
    }
}
