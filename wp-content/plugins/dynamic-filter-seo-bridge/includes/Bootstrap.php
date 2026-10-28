<?php
namespace DynamicFilterSEOBridge;

use DynamicFilterSEOBridge\Acceptance\BrowserAcceptanceProvider;
use DynamicFilterSEOBridge\Acceptance\CaseSelector;
use DynamicFilterSEOBridge\Acceptance\CanonicalStateNormalizer;
use DynamicFilterSEOBridge\Acceptance\LiveAcceptanceProvider;
use DynamicFilterSEOBridge\Acceptance\SemanticQueryEvaluator;
use DynamicFilterSEOBridge\Admin\AdminAssets;
use DynamicFilterSEOBridge\Admin\AdminDiscoveryController;
use DynamicFilterSEOBridge\Admin\OperationalPage;
use DynamicFilterSEOBridge\Admin\PublicationPage;
use DynamicFilterSEOBridge\Admin\DynamicContentPage;
use DynamicFilterSEOBridge\Admin\InventoryControlPage;
use DynamicFilterSEOBridge\Admin\UsageGuidePage;
use DynamicFilterSEOBridge\Admin\JetEngineInspectorPage;
use DynamicFilterSEOBridge\Admin\MediaLabPage;
use DynamicFilterSEOBridge\Config\Configuration;
use DynamicFilterSEOBridge\Config\ProfileRegistry;
use DynamicFilterSEOBridge\Content\ContentComposer;
use DynamicFilterSEOBridge\Content\GalleryComposer;
use DynamicFilterSEOBridge\Context\FilterContextBuilder;
use DynamicFilterSEOBridge\Diagnostics\DecisionLogger;
use DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;
use DynamicFilterSEOBridge\Diagnostics\InventoryReconciler;
use DynamicFilterSEOBridge\Diagnostics\InventoryProfilePlanner;
use DynamicFilterSEOBridge\Diagnostics\PublicationEvidenceBundle;
use DynamicFilterSEOBridge\Elementor\Shortcodes;
use DynamicFilterSEOBridge\Elementor\DynamicTagRegistrar;
use DynamicFilterSEOBridge\Elementor\ArchiveHeroPreset;
use DynamicFilterSEOBridge\Elementor\ContainerDynamicBackground;
use DynamicFilterSEOBridge\JetEngine\QueryIdentityResolver;
use DynamicFilterSEOBridge\JetEngine\ValueNormalizer;
use DynamicFilterSEOBridge\JetEngine\ListingContextResolver;
use DynamicFilterSEOBridge\JetEngine\QueryRunner;
use DynamicFilterSEOBridge\JetEngine\RelationResolver;
use DynamicFilterSEOBridge\JetEngine\FieldDiscovery;
use DynamicFilterSEOBridge\JetEngine\ListingIntegration;
use DynamicFilterSEOBridge\JetSmartFilters\FilterUrlParser;
use DynamicFilterSEOBridge\JetSmartFilters\AjaxFilterStateParser;
use DynamicFilterSEOBridge\Language\LocaleLanguageResolver;
use DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use DynamicFilterSEOBridge\Presentation\InventoryContentCatalog;
use DynamicFilterSEOBridge\Presentation\ContentSourceResolver;
use DynamicFilterSEOBridge\Presentation\PresentationResolver;
use DynamicFilterSEOBridge\Presentation\AjaxPresentationEndpoint;
use DynamicFilterSEOBridge\Presentation\MediaDiscoveryRegistry;
use DynamicFilterSEOBridge\Presentation\MediaInspector;
use DynamicFilterSEOBridge\RankMath\MetadataAdapter;
use DynamicFilterSEOBridge\RankMath\PublicationSitemapRegistrar;
use DynamicFilterSEOBridge\Runtime\BootGuard;
use DynamicFilterSEOBridge\Runtime\CapabilityRequirements;
use DynamicFilterSEOBridge\Runtime\Readiness;
use DynamicFilterSEOBridge\Runtime\RequestScope;
use DynamicFilterSEOBridge\Runtime\PostTypeObserver;
use DynamicFilterSEOBridge\Runtime\RuntimeTopologyDiscoverer;
use DynamicFilterSEOBridge\Runtime\RuntimeQueryBindingResolver;
use DynamicFilterSEOBridge\SEO\CanonicalBuilder;
use DynamicFilterSEOBridge\SEO\CombinationRegistry;
use DynamicFilterSEOBridge\SEO\ContentReadiness;
use DynamicFilterSEOBridge\SEO\IndexingPolicy;
use DynamicFilterSEOBridge\SEO\NativeFeaturePriority;
use DynamicFilterSEOBridge\SEO\JetEngineResultCountAdapter;
use DynamicFilterSEOBridge\SEO\PublicationCache;
use DynamicFilterSEOBridge\SEO\PublicationRegistry;
use DynamicFilterSEOBridge\SEO\PublicationResultCountProbe;
use DynamicFilterSEOBridge\SEO\ResultCountResolver;
use DynamicFilterSEOBridge\Simulation\ScenarioSimulator;
use DynamicFilterSEOBridge\Terms\TermMetaReader;
use DynamicFilterSEOBridge\WPML\HreflangAdapter;
use DynamicFilterSEOBridge\WPML\LanguageResolver;

final class Bootstrap {
    private static $instance;private $builder;private $context;private $booted=false;private $readiness;private $policy;private $config;private $presentation;
    public static function instance():self{if(null===self::$instance){self::$instance=new self();}return self::$instance;}

    public function boot():void{
        if($this->booted){return;}
        $this->booted=true;
        $this->config=new Configuration();
        $profiles=new ProfileRegistry($this->config);
        $compatibility=new Compatibility();
        $this->readiness=new Readiness($compatibility,$this->config,$profiles);

        $migration=$this->config->migrationStatus();
        $runtimeBlocked=in_array((string)($migration['state']??''),array('legacy_configuration_review_required','future_schema_unsupported'),true);
        // Distinct from publishing authority: prepared, disabled profiles
        // can still register read-only Elementor presentation on a certified
        // full boot. Safe Boot itself is enforced before Bootstrap is called.
        $previewRequirements=CapabilityRequirements::forProfiles($profiles->all(),false);
        $previewAdapters=(array)($previewRequirements['adapters']??array());
        $previewElementor=!$runtimeBlocked&&$compatibility->elementor()
            &&(in_array('elementor',$previewAdapters,true)||in_array('elementor_pro',$previewAdapters,true));
        $previewJetEngine=!$runtimeBlocked&&$compatibility->jetEngine()&&in_array('jet_engine',$previewAdapters,true);
        $previewJetSmartFilters=!$runtimeBlocked&&$compatibility->jetSmartFilters()&&in_array('jet_smart_filters',$previewAdapters,true);
        $requirements=CapabilityRequirements::forProfiles($profiles->all(),true);
        $requiredAdapters=(array)($requirements['adapters']??array());
        $semanticCapabilities=(array)($requirements['semantic_capabilities']??array());
        $needsJetEngine=!$runtimeBlocked&&in_array('jet_engine',$requiredAdapters,true);
        $needsJetSmartFilters=!$runtimeBlocked&&in_array('jet_smart_filters',$requiredAdapters,true);
        $needsElementor=!$runtimeBlocked&&(in_array('elementor',$requiredAdapters,true)||in_array('elementor_pro',$requiredAdapters,true));
        $needsRankMath=!$runtimeBlocked&&in_array('rank_math',$requiredAdapters,true);
        $needsWpml=!$runtimeBlocked&&in_array('wpml',$requiredAdapters,true);
        $globalRuntimeEnabled=!$runtimeBlocked&&$this->config->enabled();

        // Opt-in generic Elementor Shortcode Widget slots. These do not
        // install/modify any template or publish SEO while Safe Boot is held.
        add_action('init',array(new \DynamicFilterSEOBridge\Elementor\ContextPresentationShortcodes(),'register'),23);

        $mediaRegistry=new MediaDiscoveryRegistry();
        $gallery=new GalleryComposer();
        $content=new ContentComposer($gallery);
        $scope=new RequestScope($this->config,$profiles);
        $queryIdentityResolver=new QueryIdentityResolver();
        $topology=new RuntimeTopologyDiscoverer();
        if($needsJetEngine||$needsElementor){$topology->registerInvalidationHooks();}
        $queryBindingResolver=new RuntimeQueryBindingResolver($topology,$queryIdentityResolver);
        $adapter=($needsJetEngine||$previewJetEngine)?new JetEngineResultCountAdapter($queryBindingResolver):null;
        $resultCounts=new ResultCountResolver($this->config,$adapter);
        $languages=$needsWpml&&$compatibility->wpml()?new LanguageResolver():new LocaleLanguageResolver();

        $parserTaxonomies=array_values(array_unique(array_merge($profiles->allowedTaxonomies(),(array)$this->config->get('allowed_taxonomies',array()))));
        $managedRoutes=null;
        // Vendor settings initialize on init; do not inspect them prematurely
        // during plugins_loaded. The parser closure sees this same later value.
        add_action('init',function()use(&$managedRoutes,$profiles):void{
            $managedRoutes=\DynamicFilterSEOBridge\IndexFlow\ManagedRoutesRuntime::fromEnvironment($this->config,$profiles);
            if($managedRoutes){$managedRoutes->register();}
        },10);
        $managedResolver=static function(string$uri)use(&$managedRoutes):?string{return$managedRoutes?$managedRoutes->technicalUriFor($uri):null;};
        $parser=new FilterUrlParser($parserTaxonomies,(array)$this->config->get('allowed_query_params',array()),(array)$this->config->get('tracking_query_params',array()),$managedResolver);
        $ajaxParser=new AjaxFilterStateParser($parserTaxonomies);
        $combinations=new CombinationRegistry($this->config);
        $this->builder=new FilterContextBuilder($parser,$languages,new TermMetaReader($mediaRegistry),$content,$scope,$resultCounts,$this->readiness,$combinations,new ContentReadiness($this->config),new PostTypeObserver($queryBindingResolver),$ajaxParser);
        $this->policy=new IndexingPolicy($this->config);
        $canonical=new CanonicalBuilder($this->config);
        $publicationCache=new PublicationCache($this->config);
        $publication=new PublicationRegistry($this->config,$profiles,$this->builder,$this->policy,$content,$gallery,$languages,new PublicationResultCountProbe($queryBindingResolver,$languages),$canonical,$publicationCache);

        // Legacy Alpha13 CI marker only; diagnostics intentionally use the new non-persistent form instead of $topology->discover(false).
        // Diagnostics are read-only: bypass persistent topology cache for both
        // operational and catalog inventory surfaces.
        $runtimeInventory=new RuntimeInventory(null,null,function()use($topology){return$topology->discover(true,false);});
        $catalogInventory=new RuntimeInventory(null,null,function()use($topology){return$topology->discover(false,false);});
        $reconciler=new InventoryReconciler();
        $profilePlanner=new InventoryProfilePlanner();

        $acceptanceProvider=new LiveAcceptanceProvider(
            function()use($profiles):array{return$profiles->all();},
            function(string$uri):array{return$this->builder?$this->builder->buildEvidence($uri):array();},
            function(array$payload):array{return$this->builder?$this->builder->buildAjaxEvidence($payload):array();},
            new SemanticQueryEvaluator($queryBindingResolver),
            new CaseSelector(),
            new CanonicalStateNormalizer()
        );
        $acceptanceProvider->register();
        (new BrowserAcceptanceProvider($acceptanceProvider,function():array{return \DynamicFilterSEOBridge\Diagnostics\BuildIdentity::collect();}))->register();

        $provider=array($this,'context');
        $evidenceProvider=function():array{return$this->builder?$this->builder->buildEvidence():array();};
        $slots=new ContentSlotRegistry('alpha13'===(string)$this->config->get('compatibility_profile',''));
        $catalog=new InventoryContentCatalog();
        $normalizer=new ValueNormalizer();
        $listingContext=new ListingContextResolver($normalizer);
        $queryRunner=new QueryRunner();
        $relationResolver=new RelationResolver();
        $fieldDiscovery=new FieldDiscovery($normalizer,$listingContext);
        $sourceResolver=new ContentSourceResolver($listingContext,$queryRunner,$relationResolver,$normalizer,$fieldDiscovery);
        $mediaInspector=new MediaInspector($mediaRegistry);
        $this->presentation=new PresentationResolver($provider,$content,$gallery,$slots,$evidenceProvider,$sourceResolver);
        $catalogProvider=function()use($catalog,$catalogInventory,$profiles):array{static$cached=null;if(null!==$cached){return$cached;}$cached=$catalog->build($catalogInventory->collect(),$profiles->all());return$cached;};
        $previewContextProvider=function(string$previewUrl):array{return$this->previewEvidenceContext($previewUrl);};

        // Optional runtime adapters are registered only when an enabled profile
        // requires their semantic capability. Fresh inert installations register
        // no vendor runtime path.
        if($needsElementor||$previewElementor){
            $shortcodes=new Shortcodes($provider,$content,$gallery,$evidenceProvider,$this->presentation);add_action('init',array($shortcodes,'register'),20);
            (new DynamicTagRegistrar($this->presentation,$slots,$catalogProvider,$previewContextProvider))->registerHooks();
            (new ArchiveHeroPreset())->register();
            (new ContainerDynamicBackground($this->presentation,$slots))->register();
        }
        if($needsJetEngine||$previewJetEngine){(new ListingIntegration($this->presentation))->register();}
        // The read-only AJAX presentation endpoint must be discoverable even when all
        // profiles are disabled. Its handler remains fail-closed (rate limit,
        // scope, provider observations and publication policy are independent).
        // Frontend scripts stay strictly gated by profile adapter requirements.
        $ajaxPresentation=new AjaxPresentationEndpoint($this->builder,$this->presentation,$slots,$catalogProvider);
        add_action('rest_api_init',array($ajaxPresentation,'routes'));
        if($needsJetSmartFilters||$needsJetEngine||$previewJetSmartFilters||$previewJetEngine){add_action('wp_enqueue_scripts',array($ajaxPresentation,'assets'),30);}

        // Admin/read-only surfaces remain available while Global is OFF so an
        // operator can inspect, resolve migration state and prepare authority.
        (new AdminAssets())->register();
        (new AdminDiscoveryController($mediaInspector,$sourceResolver,$profiles,$slots))->register();
        (new DynamicContentPage($runtimeInventory,$catalog,$slots,$profiles,$sourceResolver))->register();
        (new MediaLabPage($mediaRegistry,$mediaInspector,$gallery,$previewContextProvider))->register();
        (new JetEngineInspectorPage($sourceResolver,$previewContextProvider))->register();
        (new UsageGuidePage($runtimeInventory,$catalog,$slots,$profiles))->register();
        (new InventoryControlPage($this->config,$profiles,$runtimeInventory,$profilePlanner))->register();
        (new \DynamicFilterSEOBridge\Admin\UrlArchitecturePage())->register();

        // Native JetSmartFilters has strict precedence for the overlapping
        // SEO features. DFSB stays a context/policy engine when JSF is loaded.
        // Only a missing JSF plugin can activate the Rank Math SEO fallback;
        // Safe Boot, global enable, capabilities and publication gates apply
        // independently and still prevent unauthorized output.
        if($globalRuntimeEnabled&&$needsRankMath&&$compatibility->rankMath()
            &&NativeFeaturePriority::bridgeSeoFallbackAllowed()){
            (new MetadataAdapter($provider,$content,$gallery,$this->policy,$canonical,$publication))->register();
            if(in_array('seo.sitemap',$semanticCapabilities,true)){(new PublicationSitemapRegistrar($publication))->register();}
        }
        if($globalRuntimeEnabled&&$needsWpml&&$compatibility->wpml()&&in_array('language.hreflang',$semanticCapabilities,true)){
            (new HreflangAdapter($provider,$publication))->register();
        }

        (new DecisionLogger($provider,$this->policy,$this->config))->register();
        $simulator=new ScenarioSimulator($this->config,$profiles,$combinations,$this->policy);
        $evidenceBundle=new PublicationEvidenceBundle($this->config,$profiles,$this->readiness,$runtimeInventory,$publication);
        (new OperationalPage($this->config,$profiles,$this->readiness,$this->builder,$this->policy,$simulator,$runtimeInventory,$reconciler))->register();
        (new PublicationPage($this->config,$profiles,$publication,$evidenceBundle))->register();
        if(defined('WP_CLI')&&WP_CLI&&class_exists('\\WP_CLI')){(new \DynamicFilterSEOBridge\CLI\Commands($runtimeInventory,$reconciler,$profiles))->register();}
        do_action('etg_filter_seo_bridge_booted',$this->readiness->report());
    }

    public function context():array{if(null!==$this->context){return$this->context;}if(!$this->builder){return array();}$context=$this->builder->build();$stable=empty($context['active'])||empty($context['in_scope'])||!empty($context['result_count_authoritative'])||!$this->config||!$this->config->get('require_result_count_for_index',true);if($stable){$this->context=$context;}return$context;}
    public function readiness():array{$report=$this->readiness?$this->readiness->report():array();$report['safe_boot']=BootGuard::status();return$report;}
    public function indexingDecision():array{return$this->policy?$this->policy->decide($this->context()):array();}
    public function presentationValue(string$token,?array$context=null){return$this->presentation?$this->presentation->value($token,$context):'';}
    public function presentationSlot(string$slotId,?array$context=null):string{return$this->presentation?$this->presentation->slot($slotId,$context):'';}
    public function presentationResolver(){return$this->presentation;}

    private function previewEvidenceContext(string$previewUrl):array{
        if(!$this->builder){return array();}
        $previewUrl=trim($previewUrl);if(''===$previewUrl){return array();}
        $parts=function_exists('wp_parse_url')?wp_parse_url($previewUrl):parse_url($previewUrl);if(false===$parts||!is_array($parts)){return array();}
        if(isset($parts['user'])||isset($parts['pass'])||!$this->previewOriginAllowed($parts)){return array();}
        $path=(string)($parts['path']??'/');if(''===$path){$path='/';}if('/'!==substr($path,0,1)){$path='/'.$path;}
        $query=isset($parts['query'])?trim((string)$parts['query']):'';$uri=$path.(''!==$query?'?'.$query:'');
        return$this->builder->buildEvidence($uri);
    }

    private function previewOriginAllowed(array$parts):bool{
        $hasHost=!empty($parts['host']);$hasScheme=!empty($parts['scheme']);
        if(!$hasHost&&!$hasScheme&&!isset($parts['port'])){return true;}
        if(!$hasHost||!$hasScheme){return false;}
        $scheme=strtolower((string)$parts['scheme']);if(!in_array($scheme,array('http','https'),true)){return false;}
        $home=function_exists('home_url')?home_url('/'):'';$homeParts=function_exists('wp_parse_url')?wp_parse_url($home):parse_url($home);
        if(false===$homeParts||!is_array($homeParts)||empty($homeParts['scheme'])||empty($homeParts['host'])){return false;}
        $homeScheme=strtolower((string)$homeParts['scheme']);$host=strtolower((string)$parts['host']);$homeHost=strtolower((string)$homeParts['host']);
        if($scheme!==$homeScheme||$host!==$homeHost){return false;}
        return$this->effectivePort($parts,$scheme)===$this->effectivePort($homeParts,$homeScheme);
    }

    private function effectivePort(array$parts,string$scheme):int{
        if(isset($parts['port'])&&is_numeric($parts['port'])){return(int)$parts['port'];}
        return'https'===strtolower($scheme)?443:80;
    }

    private function __construct(){}
}
