<?php
namespace ETG\DynamicFilterSEOBridge\Context;

require_once dirname( __DIR__ ) . '/Identifiers/QueryId.php';

use ETG\DynamicFilterSEOBridge\Content\ContentComposer;
use ETG\DynamicFilterSEOBridge\Identifiers\QueryId;
use ETG\DynamicFilterSEOBridge\JetSmartFilters\AjaxFilterStateParser;
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
    private $parser; private $ajaxParser; private $languages; private $meta; private $content; private $scope; private $resultCounts; private $readiness; private $combinations; private $contentReadiness; private $postTypes;
    public function __construct( FilterUrlParser $parser, LanguageResolver $languages, TermMetaReader $meta, ContentComposer $content, RequestScope $scope, ResultCountResolver $resultCounts, Readiness $readiness, CombinationRegistry $combinations, ContentReadiness $contentReadiness, PostTypeObserver $postTypes, AjaxFilterStateParser $ajaxParser = null ) {
        $this->parser=$parser;$this->languages=$languages;$this->meta=$meta;$this->content=$content;$this->scope=$scope;$this->resultCounts=$resultCounts;$this->readiness=$readiness;$this->combinations=$combinations;$this->contentReadiness=$contentReadiness;$this->postTypes=$postTypes;$this->ajaxParser=$ajaxParser ?: new AjaxFilterStateParser();
    }
    public function build( ?string $uri = null ): array { return $this->buildInternal( $uri, false, null, 'authorizing_request' ); }
    public function buildEvidence( ?string $uri = null, ?string $language = null ): array {
        return $this->buildInternal( $uri, true, $language, null === $uri ? 'live_request' : 'synthetic_uri' );
    }
    public function buildAjaxEvidence( array $payload, ?string $language = null ): array {
        $parsed = $this->ajaxParser->parse( $payload );
        $uri = (string) ( $parsed['archive_path'] ?? '/' );
        return $this->buildParsed( $parsed, true, $language, $uri, 'live_ajax' );
    }
    private function buildInternal( ?string $uri, bool $evidenceOnly, ?string $languageOverride, string $evidenceOrigin ): array {
        $parsed=$this->parser->parse($uri);
        return $this->buildParsed( $parsed, $evidenceOnly, $languageOverride, $uri, $evidenceOrigin );
    }
    private function buildParsed( array $parsed, bool $evidenceOnly, ?string $languageOverride, ?string $languageUri, string $evidenceOrigin ): array {
        $language=$languageOverride?sanitize_key($languageOverride):$this->languages->languageForUri($languageUri);
        $scope=$evidenceOnly?$this->scope->evaluateForEvidence($parsed):$this->scope->evaluate($parsed);
        $profile=(array)($scope['profile']??array());$publication=(array)($profile['publication']??array());$readiness=$this->readiness->report();$runtimeReady=$evidenceOnly?$this->evidenceRuntimeReady($readiness):('ready'===(string)($readiness['status']??''));
        $ajax='ajax'===(string)($parsed['state_transport']??'');
        $clientProviderObserved=$ajax&&''!==(string)($parsed['provider']??'')&&''!==(string)($parsed['query_id']??'');
        $clientProviderMatchesState=$clientProviderObserved;
        $runtime=$ajax?array('observed'=>false,'provider'=>'','query_id'=>'','source'=>'not_server_observed'):$this->currentProvider();
        $providerMatchUrl=$ajax?false:($evidenceOnly?true:(!empty($runtime['observed'])&&$runtime['provider']===(string)($parsed['provider']??'')&&$runtime['query_id']===(string)($parsed['query_id']??'')));
        $providerMatchState=$ajax?$clientProviderMatchesState:false;
        $postTypeBinding=$this->postTypes->observe($parsed,$profile);
        $explicitDarkRender=!empty($publication['elementor_render_when_global_off']);
        $liveEvidence=$evidenceOnly&&in_array($evidenceOrigin,array('live_request','live_ajax'),true);
        $safeLiveDarkRender=$liveEvidence
            && empty($scope['global_enabled'])
            && !empty($parsed['active'])
            && !empty($scope['in_scope'])
            && !empty($scope['scope_valid'])
            && empty($profile['enabled'])
            && !empty($publication['require_elementor_content']);
        $darkPresentationAllowed=$explicitDarkRender||$safeLiveDarkRender;
        $darkPresentationSource=$explicitDarkRender?'explicit_profile_flag':($safeLiveDarkRender?'safe_live_evidence':'blocked');
        $context=array_merge($parsed,array(
            'language'=>$language,'profile_id'=>(string)($scope['profile_id']??''),'profile'=>$profile,'taxonomy_roles'=>array(),'terms'=>array(),'term_sets'=>array(),'missing_terms'=>array(),'translation_fallback'=>false,
            'jet_smart_filters_provider'=>$runtime,'provider_observed'=>!empty($runtime['observed']),'provider_server_observed'=>!empty($runtime['observed']),'provider_client_observed'=>$clientProviderObserved,'provider_client_source'=>$ajax?'jet_smart_filters_browser_event':'','provider_client_matches_state'=>$clientProviderMatchesState,'provider_observation_matches_url'=>$providerMatchUrl,'provider_observation_matches_state'=>$providerMatchState,
            'provider_observation_verified_for_publication'=>!empty($publication['provider_observation_verified']),'provider_observation_evidence_id'=>(string)($publication['provider_observation_evidence_id']??''),
            'post_type_binding'=>$postTypeBinding,'post_type_observation'=>array('observed'=>(bool)($postTypeBinding['observed']??false),'post_types'=>(array)($postTypeBinding['post_types']??array()),'source'=>(string)($postTypeBinding['source']??'')),'post_type_observation_matches_profile'=>(bool)($postTypeBinding['matches_profile']??false),
            'combo'=>array(),'scope'=>$scope,'in_scope'=>(bool)($scope['in_scope']??false),'scope_valid'=>(bool)($scope['scope_valid']??false),'readiness'=>$readiness,'runtime_ready'=>$runtimeReady,'evidence_runtime_ready'=>$evidenceOnly?$runtimeReady:null,
            'result_count'=>null,'result_count_source'=>'unavailable','result_count_authoritative'=>false,'result_count_detail'=>'','combination_authority'=>array(),'content_readiness'=>array(),'evidence_only'=>$evidenceOnly,'evidence_origin'=>$evidenceOrigin,'synthetic'=>$evidenceOnly&&'synthetic_uri'===$evidenceOrigin,'authorizing'=>!$evidenceOnly&&!empty($scope['authorizing']),'global_enabled'=>!empty($scope['global_enabled']),
            'dark_presentation_allowed'=>$darkPresentationAllowed,'dark_presentation_source'=>$darkPresentationSource,
            'url_authority'=>$ajax?false:true,'ajax_only'=>$ajax,
        ));
        if(empty($parsed['active'])||empty($context['in_scope'])||empty($context['scope_valid'])){return $context;}
        $filterValues=(array)($parsed['filter_values']??array());
        if(!$filterValues){foreach((array)($parsed['filters']??array()) as $taxonomy=>$slug){$filterValues[$taxonomy]=array($slug);}}
        foreach($filterValues as $taxonomy=>$slugs){
            $taxonomy=sanitize_key((string)$taxonomy);$role=$this->roleForTaxonomy($taxonomy,$profile);if(''===$role){$context['missing_terms'][$taxonomy]='role_unavailable';continue;}
            $rule=(array)($profile['taxonomy_rules'][$taxonomy]??array());$resolvedSet=array();
            foreach(array_slice((array)$slugs,0,20) as $slug){$slug=sanitize_title((string)$slug);if(''===$slug){continue;}$term=get_term_by('slug',$slug,$taxonomy);if(!$term instanceof WP_Term){$context['missing_terms'][$taxonomy]=$slug;continue;}$resolved=$this->languages->resolve($term,$taxonomy,$language);if($resolved['translation_fallback']){$context['translation_fallback']=true;}$termData=$this->meta->read($resolved['term'],(array)($rule['field_map']??array()));$termData['profile_meta']=$this->profileMeta($resolved['term'],$taxonomy,$profile);$termData['profile_role']=$role;$resolvedSet[]=$termData;}
            if($resolvedSet){$context['taxonomy_roles'][$taxonomy]=$role;$context['term_sets'][$role]=$resolvedSet;$context['terms'][$role]=$resolvedSet[0];}
        }
        $context=$this->content->decorate($context);
        $context['combination_authority']=$this->combinations->evaluate($context);
        $context['content_readiness']=$this->contentReadiness->evaluate($context);
        if($ajax&&(empty($parsed['filtered_query_complete'])||!empty($parsed['unsupported_filter_props'])||!empty($parsed['unknown_filters'])||!empty($parsed['malformed']))){$result=array('count'=>null,'source'=>'blocked_incomplete_ajax_query','authoritative'=>false,'detail'=>'filtered_query_incomplete');}else{$result=$ajax?$this->resultCounts->resolveFilteredQuery($context,(array)($parsed['filtered_query']??array())):$this->resultCounts->resolve($context);}
        $context['result_count']=$result['count'];$context['result_count_source']=$result['source'];$context['result_count_authoritative']=$result['authoritative'];$context['result_count_detail']=(string)($result['detail']??'');
        return $context;
    }
    private function evidenceRuntimeReady(array $readiness):bool{if(!empty($readiness['missing_dependencies'])||!empty($readiness['missing_capabilities'])||!empty($readiness['configuration_errors'])||!empty($readiness['runtime_checks_pending'])||!empty($readiness['failed_runtime_checks'])){return false;}return true;}
    private function roleForTaxonomy(string $taxonomy,array $profile):string{$rules=(array)($profile['taxonomy_rules']??array());if(isset($rules[$taxonomy]['role'])){return sanitize_key((string)$rules[$taxonomy]['role']);}$map=function_exists('apply_filters')?apply_filters('etg_filter_seo_taxonomy_role_map',array('location_jet'=>'location','tour-types_jet'=>'tour_type','tour-styles_jet'=>'style'),$profile):array();return isset($map[$taxonomy])?sanitize_key((string)$map[$taxonomy]):sanitize_key($taxonomy);}
    private function profileMeta(WP_Term $term,string $taxonomy,array $profile):array{$rule=(array)($profile['taxonomy_rules'][$taxonomy]??array());$key=sanitize_key((string)($rule['required_meta_key']??''));if(''===$key){return array();}$value=function_exists('get_term_meta')?get_term_meta($term->term_id,$key,true):'';if($this->emptyValue($value)&&function_exists('get_field')){$value=get_field($key,$term->taxonomy.'_'.$term->term_id);}if(is_scalar($value)){return array($key=>trim((string)$value));}return array($key=>$value);}
    private function currentProvider():array{$out=array('observed'=>false,'provider'=>'','query_id'=>'','source'=>'runtime');if(!function_exists('jet_smart_filters')){return $out;}$instance=jet_smart_filters();if(!is_object($instance)||!isset($instance->query)||!is_object($instance->query)||!method_exists($instance->query,'get_current_provider')){return $out;}$provider=$instance->query->get_current_provider('provider');$queryId=$instance->query->get_current_provider('query_id');if(false===$provider&&false===$queryId){return $out;}$provider=sanitize_key((string)$provider);$queryId=QueryId::normalize($queryId);if(''===$provider||''===$queryId){return $out;}return array('observed'=>true,'provider'=>$provider,'query_id'=>$queryId,'source'=>'runtime');}
    private function emptyValue($value):bool{return null===$value||false===$value||''===$value||array()===$value;}
}
