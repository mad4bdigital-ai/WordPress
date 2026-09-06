<?php
namespace ETG\DynamicFilterSEOBridge;

use ETG\DynamicFilterSEOBridge\Admin\AdminAssets;
use ETG\DynamicFilterSEOBridge\Admin\OperationalPage;
use ETG\DynamicFilterSEOBridge\Admin\PublicationPage;
use ETG\DynamicFilterSEOBridge\Admin\DynamicContentPage;
use ETG\DynamicFilterSEOBridge\Admin\InventoryControlPage;
use ETG\DynamicFilterSEOBridge\Admin\UsageGuidePage;
use ETG\DynamicFilterSEOBridge\Admin\JetEngineInspectorPage;
use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Content\ContentComposer;
use ETG\DynamicFilterSEOBridge\Content\GalleryComposer;
use ETG\DynamicFilterSEOBridge\Context\FilterContextBuilder;
use ETG\DynamicFilterSEOBridge\Diagnostics\DecisionLogger;
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;
use ETG\DynamicFilterSEOBridge\Diagnostics\InventoryReconciler;
use ETG\DynamicFilterSEOBridge\Diagnostics\InventoryProfilePlanner;
use ETG\DynamicFilterSEOBridge\Diagnostics\PublicationEvidenceBundle;
use ETG\DynamicFilterSEOBridge\Elementor\Shortcodes;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTagRegistrar;
use ETG\DynamicFilterSEOBridge\JetEngine\QueryIdentityResolver;
use ETG\DynamicFilterSEOBridge\JetEngine\ValueNormalizer;
use ETG\DynamicFilterSEOBridge\JetEngine\ListingContextResolver;
use ETG\DynamicFilterSEOBridge\JetEngine\QueryRunner;
use ETG\DynamicFilterSEOBridge\JetEngine\RelationResolver;
use ETG\DynamicFilterSEOBridge\JetEngine\FieldDiscovery;
use ETG\DynamicFilterSEOBridge\JetEngine\ListingIntegration;
use ETG\DynamicFilterSEOBridge\JetSmartFilters\FilterUrlParser;
use ETG\DynamicFilterSEOBridge\JetSmartFilters\AjaxFilterStateParser;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use ETG\DynamicFilterSEOBridge\Presentation\InventoryContentCatalog;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSourceResolver;
use ETG\DynamicFilterSEOBridge\Presentation\PresentationResolver;
use ETG\DynamicFilterSEOBridge\Presentation\AjaxPresentationEndpoint;
use ETG\DynamicFilterSEOBridge\RankMath\MetadataAdapter;
use ETG\DynamicFilterSEOBridge\RankMath\PublicationSitemapRegistrar;
use ETG\DynamicFilterSEOBridge\Runtime\Readiness;
use ETG\DynamicFilterSEOBridge\Runtime\RequestScope;
use ETG\DynamicFilterSEOBridge\Runtime\PostTypeObserver;
use ETG\DynamicFilterSEOBridge\Runtime\RuntimeTopologyDiscoverer;
use ETG\DynamicFilterSEOBridge\Runtime\RuntimeQueryBindingResolver;
use ETG\DynamicFilterSEOBridge\SEO\CanonicalBuilder;
use ETG\DynamicFilterSEOBridge\SEO\CombinationRegistry;
use ETG\DynamicFilterSEOBridge\SEO\ContentReadiness;
use ETG\DynamicFilterSEOBridge\SEO\IndexingPolicy;
use ETG\DynamicFilterSEOBridge\SEO\JetEngineResultCountAdapter;
use ETG\DynamicFilterSEOBridge\SEO\PublicationCache;
use ETG\DynamicFilterSEOBridge\SEO\PublicationRegistry;
use ETG\DynamicFilterSEOBridge\SEO\PublicationResultCountProbe;
use ETG\DynamicFilterSEOBridge\SEO\ResultCountResolver;
use ETG\DynamicFilterSEOBridge\Simulation\ScenarioSimulator;
use ETG\DynamicFilterSEOBridge\Terms\TermMetaReader;
use ETG\DynamicFilterSEOBridge\WPML\LanguageResolver;

final class Bootstrap {
    private static $instance;private $builder;private $context;private $booted=false;private $readiness;private $policy;private $config;private $presentation;
    public static function instance():self{if(null===self::$instance){self::$instance=new self();}return self::$instance;}

    public function boot():void{
        if($this->booted){return;}$this->booted=true;$this->config=new Configuration();$profiles=new ProfileRegistry($this->config);$compatibility=new Compatibility();$this->readiness=new Readiness($compatibility,$this->config,$profiles);
        $gallery=new GalleryComposer();$content=new ContentComposer($gallery);$scope=new RequestScope($this->config,$profiles);$queryIdentityResolver=new QueryIdentityResolver();$topology=new RuntimeTopologyDiscoverer();$topology->registerInvalidationHooks();$queryBindingResolver=new RuntimeQueryBindingResolver($topology,$queryIdentityResolver);$adapter=new JetEngineResultCountAdapter($queryBindingResolver);$resultCounts=new ResultCountResolver($this->config,$adapter);$languages=new LanguageResolver();
        $parserTaxonomies=array_values(array_unique(array_merge($profiles->allowedTaxonomies(),(array)$this->config->get('allowed_taxonomies',array()))));$parser=new FilterUrlParser($parserTaxonomies,(array)$this->config->get('allowed_query_params',array()),(array)$this->config->get('tracking_query_params',array()));$ajaxParser=new AjaxFilterStateParser($parserTaxonomies);$combinations=new CombinationRegistry($this->config);
        $this->builder=new FilterContextBuilder($parser,$languages,new TermMetaReader(),$content,$scope,$resultCounts,$this->readiness,$combinations,new ContentReadiness($this->config),new PostTypeObserver($queryBindingResolver),$ajaxParser);$this->policy=new IndexingPolicy($this->config);$canonical=new CanonicalBuilder($this->config);$publicationCache=new PublicationCache($this->config);$publication=new PublicationRegistry($this->config,$profiles,$this->builder,$this->policy,$content,$gallery,$languages,new PublicationResultCountProbe($queryBindingResolver),$canonical,$publicationCache);
        $runtimeInventory=new RuntimeInventory(null,null,function()use($topology){return$topology->discover(true);});$catalogInventory=new RuntimeInventory(null,null,function()use($topology){return$topology->discover(false);});$reconciler=new InventoryReconciler();$profilePlanner=new InventoryProfilePlanner();

        $provider=array($this,'context');$evidenceProvider=function():array{return$this->builder?$this->builder->buildEvidence():array();};$slots=new ContentSlotRegistry();$catalog=new InventoryContentCatalog();
        $normalizer=new ValueNormalizer();$listingContext=new ListingContextResolver($normalizer);$queryRunner=new QueryRunner();$relationResolver=new RelationResolver();$fieldDiscovery=new FieldDiscovery($normalizer,$listingContext);$sourceResolver=new ContentSourceResolver($listingContext,$queryRunner,$relationResolver,$normalizer,$fieldDiscovery);
        $this->presentation=new PresentationResolver($provider,$content,$gallery,$slots,$evidenceProvider,$sourceResolver);
        $shortcodes=new Shortcodes($provider,$content,$gallery,$evidenceProvider,$this->presentation);add_action('init',array($shortcodes,'register'),20);
        $catalogProvider=function()use($catalog,$catalogInventory,$profiles):array{static$cached=null;if(null!==$cached){return$cached;}$cached=$catalog->build($catalogInventory->collect(),$profiles->all());return$cached;};$previewContextProvider=function(string$previewUrl):array{return$this->previewEvidenceContext($previewUrl);};
        (new DynamicTagRegistrar($this->presentation,$slots,$catalogProvider,$previewContextProvider))->registerHooks();
        (new ListingIntegration($this->presentation))->register();
        (new AdminAssets())->register();
        (new DynamicContentPage($runtimeInventory,$catalog,$slots,$profiles,$sourceResolver))->register();
        (new JetEngineInspectorPage($sourceResolver,$previewContextProvider))->register();
        (new UsageGuidePage($runtimeInventory,$catalog,$slots,$profiles))->register();
        (new AjaxPresentationEndpoint($this->builder,$this->presentation,$slots,$catalogProvider))->register();
        (new InventoryControlPage($this->config,$profiles,$runtimeInventory,$profilePlanner))->register();

        if($compatibility->rankMath()){(new MetadataAdapter($provider,$content,$gallery,$this->policy,$canonical,$publication))->register();(new PublicationSitemapRegistrar($publication))->register();}
        (new DecisionLogger($provider,$this->policy,$this->config))->register();$simulator=new ScenarioSimulator($this->config,$profiles,$combinations,$this->policy);$evidenceBundle=new PublicationEvidenceBundle($this->config,$profiles,$this->readiness,$runtimeInventory,$publication);(new OperationalPage($this->config,$profiles,$this->readiness,$this->builder,$this->policy,$simulator,$runtimeInventory,$reconciler))->register();(new PublicationPage($this->config,$profiles,$publication,$evidenceBundle))->register();if(defined('WP_CLI')&&WP_CLI&&class_exists('\\WP_CLI')){(new \ETG\DynamicFilterSEOBridge\CLI\Commands($runtimeInventory,$reconciler,$profiles))->register();}do_action('etg_filter_seo_bridge_booted',$this->readiness->report());
    }

    public function context():array{if(null!==$this->context){return$this->context;}if(!$this->builder){return array();}$context=$this->builder->build();$stable=empty($context['active'])||empty($context['in_scope'])||!empty($context['result_count_authoritative'])||!$this->config||!$this->config->get('require_result_count_for_index',true);if($stable){$this->context=$context;}return$context;}
    public function readiness():array{return$this->readiness?$this->readiness->report():array();}
    public function indexingDecision():array{return$this->policy?$this->policy->decide($this->context()):array();}
    public function presentationValue(string$token,array$context=null){return$this->presentation?$this->presentation->value($token,$context):'';}
    public function presentationSlot(string$slotId,array$context=null):string{return$this->presentation?$this->presentation->slot($slotId,$context):'';}
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
