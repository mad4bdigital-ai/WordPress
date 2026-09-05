<?php
namespace ETG\DynamicFilterSEOBridge\Context;

use ETG\DynamicFilterSEOBridge\Content\ContentComposer;
use ETG\DynamicFilterSEOBridge\JetSmartFilters\FilterUrlParser;
use ETG\DynamicFilterSEOBridge\Runtime\Readiness;
use ETG\DynamicFilterSEOBridge\Runtime\RequestScope;
use ETG\DynamicFilterSEOBridge\Runtime\PostTypeObserver;
use ETG\DynamicFilterSEOBridge\SEO\CombinationRegistry;
use ETG\DynamicFilterSEOBridge\SEO\ContentReadiness;
use ETG\DynamicFilterSEOBridge\SEO\ResultCountResolver;
use ETG\DynamicFilterSEOBridge\Terms\TermMetaReader;
use ETG\DynamicFilterSEOBridge\WPML\LanguageResolver;
use WP_Term;

final class FilterContextBuilder {
	private $parser; private $languages; private $meta; private $content; private $scope; private $resultCounts; private $readiness; private $combinations; private $contentReadiness; private $postTypes;
	public function __construct( FilterUrlParser $parser, LanguageResolver $languages, TermMetaReader $meta, ContentComposer $content, RequestScope $scope, ResultCountResolver $resultCounts, Readiness $readiness, CombinationRegistry $combinations, ContentReadiness $contentReadiness, PostTypeObserver $postTypes ) {
		$this->parser=$parser; $this->languages=$languages; $this->meta=$meta; $this->content=$content; $this->scope=$scope; $this->resultCounts=$resultCounts; $this->readiness=$readiness; $this->combinations=$combinations; $this->contentReadiness=$contentReadiness; $this->postTypes=$postTypes;
	}

	public function build( ?string $uri = null ): array {
		return $this->buildInternal( $uri, false, null );
	}

	/**
	 * Build the same real term/runtime context while bypassing only the Global kill
	 * switch. The result is explicitly non-authorizing and is used by the
	 * Publication planner before Production activation.
	 */
	public function buildEvidence( ?string $uri = null, ?string $language = null ): array {
		return $this->buildInternal( $uri, true, $language );
	}

	private function buildInternal( ?string $uri, bool $evidenceOnly, ?string $languageOverride ): array {
		$parsed = $this->parser->parse( $uri );
		$language = $languageOverride ? sanitize_key( $languageOverride ) : $this->languages->languageForUri( $uri );
		$scope = $evidenceOnly ? $this->scope->evaluateForEvidence( $parsed ) : $this->scope->evaluate( $parsed );
		$profile = (array) ( $scope['profile'] ?? array() );
		$readiness = $this->readiness->report();
		$runtimeReady = $evidenceOnly ? $this->evidenceRuntimeReady( $readiness ) : ( 'ready' === (string) ( $readiness['status'] ?? '' ) );
		$runtime = $this->currentProvider();
		$providerMatch = $evidenceOnly
			? true
			: ( ! $runtime['observed'] || ( $runtime['provider'] === (string) ( $parsed['provider'] ?? '' ) && $runtime['query_id'] === (string) ( $parsed['query_id'] ?? '' ) ) );
		$postTypeBinding = $this->postTypes->observe( $parsed, $profile );
		$context = array_merge( $parsed, array(
			'language'=>$language,
			'profile_id'=>(string)($scope['profile_id']??''),
			'profile'=>$profile,
			'taxonomy_roles'=>array(),
			'terms'=>array(),
			'missing_terms'=>array(),
			'translation_fallback'=>false,
			'jet_smart_filters_provider'=>$runtime,
			'provider_observation_matches_url'=>$providerMatch,
			'post_type_binding'=>$postTypeBinding,
			'post_type_observation'=>array( 'observed'=>(bool)($postTypeBinding['observed']??false), 'post_types'=>(array)($postTypeBinding['post_types']??array()), 'source'=>(string)($postTypeBinding['source']??'') ),
			'post_type_observation_matches_profile'=>(bool)($postTypeBinding['matches_profile']??false),
			'combo'=>array(),
			'scope'=>$scope,
			'in_scope'=>(bool)($scope['in_scope']??false),
			'scope_valid'=>(bool)($scope['scope_valid']??false),
			'readiness'=>$readiness,
			'runtime_ready'=>$runtimeReady,
			'evidence_runtime_ready'=>$evidenceOnly ? $runtimeReady : null,
			'result_count'=>null,
			'result_count_source'=>'unavailable',
			'result_count_authoritative'=>false,
			'result_count_detail'=>'',
			'combination_authority'=>array(),
			'content_readiness'=>array(),
			'evidence_only'=>$evidenceOnly,
			'authorizing'=>!$evidenceOnly && !empty($scope['authorizing']),
			'global_enabled'=>!empty($scope['global_enabled']),
		) );
		if ( empty( $parsed['active'] ) || empty( $context['in_scope'] ) ) { return $context; }
		if ( empty( $context['scope_valid'] ) ) { return $context; }
		foreach ( (array) $parsed['filters'] as $taxonomy=>$slug ) {
			$term=get_term_by('slug',$slug,$taxonomy);
			if(!$term instanceof WP_Term){$context['missing_terms'][$taxonomy]=$slug;continue;}
			$resolved=$this->languages->resolve($term,$taxonomy,$language);
			if($resolved['translation_fallback']){$context['translation_fallback']=true;}
			$role=$this->roleForTaxonomy($taxonomy,$profile);
			if(''===$role){$context['missing_terms'][$taxonomy]=$slug;continue;}
			$rule=(array)($profile['taxonomy_rules'][$taxonomy]??array());
			$termData=$this->meta->read($resolved['term'],(array)($rule['field_map']??array()));
			$termData['profile_meta']=$this->profileMeta($resolved['term'],$taxonomy,$profile);
			$termData['profile_role']=$role;
			$context['taxonomy_roles'][$taxonomy]=$role;
			$context['terms'][$role]=$termData;
		}
		$context=$this->content->decorate($context);
		$context['combination_authority']=$this->combinations->evaluate($context);
		$context['content_readiness']=$this->contentReadiness->evaluate($context);
		$result=$this->resultCounts->resolve($context);
		$context['result_count']=$result['count'];
		$context['result_count_source']=$result['source'];
		$context['result_count_authoritative']=$result['authoritative'];
		$context['result_count_detail']=(string)($result['detail']??'');
		return $context;
	}

	private function evidenceRuntimeReady( array $readiness ): bool {
		/* `inactive` is expected while Global is OFF. For read-only evidence we
		 * evaluate the prerequisites directly, but we never convert that state into
		 * authorizing readiness. */
		if(!empty($readiness['missing_dependencies'])){return false;}
		if(!empty($readiness['missing_capabilities'])){return false;}
		if(!empty($readiness['configuration_errors'])){return false;}
		if(!empty($readiness['runtime_checks_pending'])){return false;}
		if(!empty($readiness['failed_runtime_checks'])){return false;}
		return true;
	}

	private function roleForTaxonomy( string $taxonomy, array $profile ): string {
		$rules=(array)($profile['taxonomy_rules']??array());
		if(isset($rules[$taxonomy]['role'])){return sanitize_key((string)$rules[$taxonomy]['role']);}
		$map=function_exists('apply_filters')?apply_filters('etg_filter_seo_taxonomy_role_map',array('location_jet'=>'location','tour-types_jet'=>'tour_type','tour-styles_jet'=>'style'),$profile):array();
		return isset($map[$taxonomy])?sanitize_key((string)$map[$taxonomy]):sanitize_key($taxonomy);
	}

	private function profileMeta( WP_Term $term, string $taxonomy, array $profile ): array {
		$rule=(array)($profile['taxonomy_rules'][$taxonomy]??array());
		$key=sanitize_key((string)($rule['required_meta_key']??''));
		if(''===$key){return array();}
		$value=function_exists('get_term_meta')?get_term_meta($term->term_id,$key,true):'';
		if($this->emptyValue($value)&&function_exists('get_field')){$value=get_field($key,$term->taxonomy.'_'.$term->term_id);}
		if(is_scalar($value)){return array($key=>trim((string)$value));}
		return array($key=>$value);
	}

	private function currentProvider(): array {
		$out=array('observed'=>false,'provider'=>'','query_id'=>'');
		if(!function_exists('jet_smart_filters')){return $out;}
		$instance=jet_smart_filters();
		if(!is_object($instance)||!isset($instance->query)||!is_object($instance->query)||!method_exists($instance->query,'get_current_provider')){return $out;}
		$provider=$instance->query->get_current_provider('provider');
		$queryId=$instance->query->get_current_provider('query_id');
		if(false===$provider&&false===$queryId){return $out;}
		return array('observed'=>true,'provider'=>sanitize_key((string)$provider),'query_id'=>sanitize_key((string)$queryId));
	}

	private function emptyValue( $value ): bool { return null===$value||false===$value||''===$value||array()===$value; }
}
