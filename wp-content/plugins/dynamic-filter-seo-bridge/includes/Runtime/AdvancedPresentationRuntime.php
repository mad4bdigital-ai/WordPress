<?php
namespace DynamicFilterSEOBridge\Runtime;

use DynamicFilterSEOBridge\Compatibility;
use DynamicFilterSEOBridge\Config\Configuration;
use DynamicFilterSEOBridge\Config\ProfileRegistry;
use DynamicFilterSEOBridge\Content\ContentComposer;
use DynamicFilterSEOBridge\Content\GalleryComposer;
use DynamicFilterSEOBridge\Context\FilterContextBuilder;
use DynamicFilterSEOBridge\Elementor\DynamicTagRegistrar;
use DynamicFilterSEOBridge\JetEngine\FieldDiscovery;
use DynamicFilterSEOBridge\JetEngine\ListingContextResolver;
use DynamicFilterSEOBridge\JetEngine\ListingIntegration;
use DynamicFilterSEOBridge\JetEngine\QueryIdentityResolver;
use DynamicFilterSEOBridge\JetEngine\QueryRunner;
use DynamicFilterSEOBridge\JetEngine\RelationResolver;
use DynamicFilterSEOBridge\JetEngine\ValueNormalizer;
use DynamicFilterSEOBridge\JetSmartFilters\AjaxFilterStateParser;
use DynamicFilterSEOBridge\JetSmartFilters\FilterUrlParser;
use DynamicFilterSEOBridge\Language\LocaleLanguageResolver;
use DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use DynamicFilterSEOBridge\Presentation\ContentSourceResolver;
use DynamicFilterSEOBridge\Presentation\InventoryContentCatalog;
use DynamicFilterSEOBridge\Presentation\MediaDiscoveryRegistry;
use DynamicFilterSEOBridge\Presentation\PresentationResolver;
use DynamicFilterSEOBridge\Runtime\Readiness;
use DynamicFilterSEOBridge\SEO\CombinationRegistry;
use DynamicFilterSEOBridge\SEO\ContentReadiness;
use DynamicFilterSEOBridge\SEO\ResultCountResolver;
use DynamicFilterSEOBridge\Terms\TermMetaReader;
use DynamicFilterSEOBridge\WPML\LanguageResolver;

/**
 * Isolated, evidence-only Elementor/JetEngine presenter while SEO Full Boot is held.
 * Never invokes Bootstrap::boot(), SEO publishers, acceptance providers,
 * managed rewrite routes, authorizing FilterContextBuilder::build(), or REST.
 */
final class AdvancedPresentationRuntime {
    private static $registered=false;

    public static function registerWhenHeld(): bool {
        if(self::$registered || !BootGuard::shouldHold()
            || !RuntimeLayers::canPresent())return false;

        $compat=new Compatibility();
        if(!$compat->elementor() && !$compat->jetEngine())return false;

        try {
            $config=new Configuration();
            $profiles=new ProfileRegistry($config);
            $gallery=new GalleryComposer();
            $content=new ContentComposer($gallery);
            $media=new MediaDiscoveryRegistry();
            $slots=new ContentSlotRegistry(
                'alpha13'===(string)$config->get('compatibility_profile','')
            );
            $topology=new RuntimeTopologyDiscoverer();
            $queryIds=new QueryIdentityResolver();
            $bindings=new RuntimeQueryBindingResolver($topology,$queryIds);
            $language=$compat->wpml()?new LanguageResolver():new LocaleLanguageResolver();
            $taxonomies=array_values(array_unique(array_merge(
                $profiles->allowedTaxonomies(),
                (array)$config->get('allowed_taxonomies',array())
            )));
            $builder=new FilterContextBuilder(
                new FilterUrlParser(
                    $taxonomies,
                    (array)$config->get('allowed_query_params',array()),
                    (array)$config->get('tracking_query_params',array())
                ),
                $language,
                new TermMetaReader($media),
                $content,
                new RequestScope($config,$profiles),
                new ResultCountResolver($config),
                new Readiness($compat,$config,$profiles),
                new CombinationRegistry($config),
                new ContentReadiness($config),
                new PostTypeObserver($bindings),
                new AjaxFilterStateParser($taxonomies)
            );
            $readEvidence=static function()use($builder):array{
                if(!RuntimeLayers::canPresent())return array();
                $e=$builder->buildEvidence();
                // In no case can this context authorize SEO, prices, or booking.
                $e['authorizing']=false;
                $e['url_authority']=false;
                return $e;
            };
            $normalizer=new ValueNormalizer();
            $listing=new ListingContextResolver($normalizer);
            $source=new ContentSourceResolver(
                $listing,new QueryRunner(),new RelationResolver(),
                $normalizer,new FieldDiscovery($normalizer,$listing)
            );
            $resolver=new PresentationResolver(
                $readEvidence,$content,$gallery,$slots,$readEvidence,$source
            );
            $catalog=static function()use($profiles):array{
                // No runtime/topology discovery or executable provider query here.
                return (new InventoryContentCatalog())->build(array(),$profiles->all());
            };
            if($compat->elementor()){
                (new DynamicTagRegistrar($resolver,$slots,$catalog,null))->registerHooks();
            }
            if($compat->jetEngine()){
                (new ListingIntegration($resolver))->register();
            }
            self::$registered=true;
            return true;
        }catch(\Throwable $error){
            // No SEO boot escalation, persistence or public diagnostics leakage.
            return false;
        }
    }
}

