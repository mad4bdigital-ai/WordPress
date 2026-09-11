<?php
namespace ETG\DynamicFilterSEOBridge;

use ETG\DynamicFilterSEOBridge\Admin\OperationalPage;
use ETG\DynamicFilterSEOBridge\Admin\PublicationPage;
use ETG\DynamicFilterSEOBridge\Admin\MaintenancePage;
use ETG\DynamicFilterSEOBridge\Admin\RuntimeValidationPage;
use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Content\ContentComposer;
use ETG\DynamicFilterSEOBridge\Content\GalleryComposer;
use ETG\DynamicFilterSEOBridge\Context\FilterContextBuilder;
use ETG\DynamicFilterSEOBridge\Diagnostics\DecisionLogger;
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;
use ETG\DynamicFilterSEOBridge\Diagnostics\InventoryReconciler;
use ETG\DynamicFilterSEOBridge\Diagnostics\LiveRuntimeProbe;
use ETG\DynamicFilterSEOBridge\Diagnostics\PublicationEvidenceBundle;
use ETG\DynamicFilterSEOBridge\Elementor\Shortcodes;
use ETG\DynamicFilterSEOBridge\JetSmartFilters\FilterUrlParser;
use ETG\DynamicFilterSEOBridge\RankMath\MetadataAdapter;
use ETG\DynamicFilterSEOBridge\RankMath\PublicationSitemapRegistrar;
use ETG\DynamicFilterSEOBridge\Runtime\Readiness;
use ETG\DynamicFilterSEOBridge\Runtime\RequestScope;
use ETG\DynamicFilterSEOBridge\Runtime\PostTypeObserver;
use ETG\DynamicFilterSEOBridge\Runtime\ReleaseIdentity;
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
	private static $instance; private $builder; private $context; private $booted=false; private $readiness; private $policy; private $config;
	public static function instance():self{if(null===self::$instance){self::$instance=new self();}return self::$instance;}
	public function boot():void{
		if($this->booted){return;}$this->booted=true;$this->config=new Configuration();$profiles=new ProfileRegistry($this->config);$compatibility=new Compatibility();$this->readiness=new Readiness($compatibility,$this->config,$profiles);$gallery=new GalleryComposer();$content=new ContentComposer($gallery);$scope=new RequestScope($this->config,$profiles);$adapter=new JetEngineResultCountAdapter();$resultCounts=new ResultCountResolver($this->config,$adapter);$languages=new LanguageResolver();$parserTaxonomies=array_values(array_unique(array_merge($profiles->allowedTaxonomies(),(array)$this->config->get('allowed_taxonomies',array()))));$parser=new FilterUrlParser($parserTaxonomies,(array)$this->config->get('allowed_query_params',array()),(array)$this->config->get('tracking_query_params',array()));$combinations=new CombinationRegistry($this->config);$this->builder=new FilterContextBuilder($parser,$languages,new TermMetaReader(),$content,$scope,$resultCounts,$this->readiness,$combinations,new ContentReadiness($this->config),new PostTypeObserver());$this->policy=new IndexingPolicy($this->config);$canonical=new CanonicalBuilder($this->config);$publicationCache=new PublicationCache($this->config);$publication=new PublicationRegistry($this->config,$profiles,$this->builder,$this->policy,$content,$gallery,$languages,new PublicationResultCountProbe(),$canonical,$publicationCache);
		$provider=array($this,'context');$evidenceProvider=function():array{return $this->builder?$this->builder->buildEvidence():array();};$shortcodes=new Shortcodes($provider,$content,$gallery,$evidenceProvider);add_action('init',array($shortcodes,'register'),20);
		if($compatibility->rankMath()){(new MetadataAdapter($provider,$content,$gallery,$this->policy,$canonical,$publication))->register();(new PublicationSitemapRegistrar($publication))->register();}
		(new DecisionLogger($provider,$this->policy,$this->config))->register();$simulator=new ScenarioSimulator($this->config,$profiles,$combinations,$this->policy);$runtimeInventory=new RuntimeInventory();$reconciler=new InventoryReconciler();$releaseIdentity=new ReleaseIdentity();$liveProbe=new LiveRuntimeProbe($this->config,$this->builder,$this->policy);$liveProbe->register();$evidenceBundle=new PublicationEvidenceBundle($this->config,$profiles,$this->readiness,$runtimeInventory,$publication,$releaseIdentity,$liveProbe);(new OperationalPage($this->config,$profiles,$this->readiness,$this->builder,$this->policy,$simulator,$runtimeInventory,$reconciler))->register();(new PublicationPage($this->config,$profiles,$publication,$evidenceBundle,$liveProbe))->register();(new RuntimeValidationPage($this->config,$profiles,$liveProbe))->register();(new MaintenancePage($this->config,$releaseIdentity))->register();if(defined('WP_CLI')&&WP_CLI&&class_exists('\\WP_CLI')){(new \ETG\DynamicFilterSEOBridge\CLI\Commands($runtimeInventory,$reconciler,$profiles))->register();}do_action('etg_filter_seo_bridge_booted',$this->readiness->report());
	}
	public function context():array{if(null!==$this->context){return $this->context;}if(!$this->builder){return array();}$context=$this->builder->build();$stable=empty($context['active'])||empty($context['in_scope'])||!empty($context['result_count_authoritative'])||!$this->config||!$this->config->get('require_result_count_for_index',true);if($stable){$this->context=$context;}return $context;}
	public function readiness():array{return $this->readiness?$this->readiness->report():array();}
	public function indexingDecision():array{return $this->policy?$this->policy->decide($this->context()):array();}
	private function __construct(){}
}
