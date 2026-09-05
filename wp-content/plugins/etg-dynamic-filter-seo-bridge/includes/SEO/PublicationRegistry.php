<?php
namespace ETG\DynamicFilterSEOBridge\SEO;

use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Content\ContentComposer;
use ETG\DynamicFilterSEOBridge\Content\GalleryComposer;
use ETG\DynamicFilterSEOBridge\Context\FilterContextBuilder;
use ETG\DynamicFilterSEOBridge\WPML\LanguageResolver;
use WP_Term;

final class PublicationRegistry {
	private const MAX_PUBLICATION_URLS = 5000;
	private $config;
	private $profiles;
	private $builder;
	private $policy;
	private $content;
	private $gallery;
	private $languages;
	private $countProbe;
	private $canonical;
	private $candidateCache = array();

	public function __construct(
		Configuration $config,
		ProfileRegistry $profiles,
		FilterContextBuilder $builder,
		IndexingPolicy $policy,
		ContentComposer $content,
		GalleryComposer $gallery,
		LanguageResolver $languages,
		PublicationResultCountProbe $countProbe,
		CanonicalBuilder $canonical
	) {
		$this->config=$config;
		$this->profiles=$profiles;
		$this->builder=$builder;
		$this->policy=$policy;
		$this->content=$content;
		$this->gallery=$gallery;
		$this->languages=$languages;
		$this->countProbe=$countProbe;
		$this->canonical=$canonical;
	}

	public function candidates( int $limit = 100 ): array {
		$limit=max(1,min(self::MAX_PUBLICATION_URLS,$limit));
		$out=array();
		foreach($this->profiles->all() as $profile){
			foreach((array)($profile['indexable_combinations']??array()) as $signature){
				if(count($out)>=$limit){break 2;}
				$out[]=$this->candidateForSignature((array)$profile,(string)$signature,false);
			}
		}
		return $out;
	}

	public function published( int $limit = self::MAX_PUBLICATION_URLS ): array {
		$limit=max(1,min(self::MAX_PUBLICATION_URLS,$limit));
		if(!$this->config->enabled()){return array();}
		$out=array();
		foreach($this->profiles->all() as $profile){
			if(empty($profile['enabled'])||empty($profile['publication']['sitemap'])){continue;}
			foreach((array)($profile['indexable_combinations']??array()) as $signature){
				if(count($out)>=$limit){break 2;}
				$candidate=$this->candidateForSignature((array)$profile,(string)$signature,true);
				if(!empty($candidate['sitemap_included'])){$out[]=$candidate;}
			}
		}
		return $out;
	}

	public function sitemapLinks( int $maxEntries, int $currentPage ): array {
		$maxEntries=max(1,min(1000,$maxEntries));
		$currentPage=max(1,$currentPage);
		$published=$this->published();
		$slice=array_slice($published,($currentPage-1)*$maxEntries,$maxEntries);
		$links=array();
		foreach($slice as $candidate){
			$link=array('loc'=>(string)$candidate['url']);
			if(!empty($candidate['images'])){$link['images']=$candidate['images'];}
			$links[]=$link;
		}
		return $links;
	}

	public function publicationSummary( int $limit = 100 ): array {
		$candidates=$this->candidates($limit);
		$included=0;$wouldIndex=0;$reasons=array();
		foreach($candidates as $candidate){
			if(!empty($candidate['would_index'])){$wouldIndex++;}
			if(!empty($candidate['sitemap_included'])){$included++;}
			foreach((array)($candidate['exclusion_reasons']??array()) as $reason){$reasons[$reason]=($reasons[$reason]??0)+1;}
		}
		ksort($reasons,SORT_STRING);
		return array(
			'contract'=>'etg.dfsb.publication-summary.v1',
			'authorizing'=>false,
			'global_enabled'=>$this->config->enabled(),
			'candidate_count'=>count($candidates),
			'would_index_count'=>$wouldIndex,
			'sitemap_included_count'=>$included,
			'exclusion_reasons'=>$reasons,
			'candidates'=>$candidates,
		);
	}

	public function metadataForContext( array $context ): array {
		if(!$this->resolved($context)){return array();}
		$title=$this->content->metaTitle($context);
		$description=$this->content->metaDescription($context);
		$canonical=$this->canonical->build($context,'');
		$ids=$this->gallery->ids($context,'priority');
		$image='';
		if($ids&&function_exists('wp_get_attachment_image_url')){$candidate=wp_get_attachment_image_url((int)reset($ids),'full');if(is_string($candidate)){$image=$candidate;}}
		return array(
			'title'=>$title,
			'description'=>$description,
			'canonical'=>$canonical,
			'robots'=>$this->policy->decide($context),
			'focus_keyword'=>$this->content->keyword($context),
			'og_title'=>$title,
			'og_description'=>$description,
			'og_image'=>$image,
			'twitter_title'=>$title,
			'twitter_description'=>$description,
			'twitter_image'=>$image,
			'schema'=>$this->schemaForContext($context,$canonical,$title,$description),
		);
	}

	public function alternatesForContext( array $context ): array {
		if(!$this->resolved($context)){return array();}
		$profile=(array)($context['profile']??array());
		if(empty($profile['publication']['hreflang'])){return array();}
		$decision=$this->policy->decide($context);
		if(true!==($decision['index']??null)){return array();}
		$profileId=sanitize_key((string)($profile['id']??''));
		$route=$this->singleRoute($profile);
		if(!$route){return array();}
		$approved=array_fill_keys(array_map('strtolower',(array)($profile['indexable_combinations']??array())),true);
		$languages=$this->languages->activeLanguages();
		if(!$languages){return array();}
		$out=array();
		foreach(array_keys($languages) as $language){
			$filters=$this->translatedFilters($context,$language);
			if(!$filters){continue;}
			$signature=$this->signature($profileId,$language,$filters);
			if(!isset($approved[strtolower($signature)])){continue;}
			$urlResult=$this->buildUrl($profile,$language,$filters,$route);
			if(empty($urlResult['url'])){continue;}
			$candidate=$this->candidateFromParts($profile,$signature,$language,$filters,$route,$urlResult['url'],true,false);
			if(!empty($candidate['would_index'])){$out[$language]=$candidate['url'];}
		}
		$default=function_exists('apply_filters')?sanitize_key((string)apply_filters('wpml_default_language',null)):'';
		if($default&&isset($out[$default])){$out['x-default']=$out[$default];}
		return $out;
	}

	public function schemaForContext( array $context, string $canonical = '', string $title = '', string $description = '' ): array {
		$profile=(array)($context['profile']??array());
		if(empty($profile['publication']['schema'])||!$this->resolved($context)){return array();}
		if(''===$canonical){$canonical=$this->canonical->build($context,'');}
		if(''===$title){$title=$this->content->metaTitle($context);}
		if(''===$description){$description=$this->content->metaDescription($context);}
		$about=array();
		foreach((array)($context['terms']??array()) as $term){if(is_array($term)&&!empty($term['name'])){$about[]=array('@type'=>'Thing','name'=>(string)$term['name']);}}
		$schema=array(
			'@type'=>'CollectionPage',
			'@id'=>$canonical?$canonical.'#etg-filtered-collection':'',
			'url'=>$canonical,
			'name'=>$title,
			'description'=>$description,
			'inLanguage'=>(string)($context['language']??''),
			'about'=>$about,
		);
		return array_filter($schema,static function($value){return !(null===$value||''===$value||array()===$value);});
	}

	private function candidateForSignature( array $profile, string $signature, bool $forPublication ): array {
		$key=sha1((string)($profile['id']??'').'|'.$signature.'|'.($forPublication?'1':'0'));
		if(isset($this->candidateCache[$key])){return $this->candidateCache[$key];}
		$parsed=$this->parseSignature($profile,$signature);
		if(!$parsed['valid']){
			$result=$this->baseCandidate($profile,$signature,$parsed['language'],$parsed['filters']);
			$result['exclusion_reasons'][]=$parsed['reason'];
			return $this->candidateCache[$key]=$result;
		}
		$route=$this->singleRoute($profile);
		if(!$route){
			$result=$this->baseCandidate($profile,$signature,$parsed['language'],$parsed['filters']);
			$result['exclusion_reasons'][]=count((array)($profile['routes']??array()))>1?'publication_route_ambiguous':'publication_route_missing';
			return $this->candidateCache[$key]=$result;
		}
		$urlResult=$this->buildUrl($profile,$parsed['language'],$parsed['filters'],$route);
		if(empty($urlResult['url'])){
			$result=$this->baseCandidate($profile,$signature,$parsed['language'],$parsed['filters']);
			$result['route']=$route;
			$result['exclusion_reasons'][]=(string)($urlResult['reason']??'publication_url_unavailable');
			return $this->candidateCache[$key]=$result;
		}
		$result=$this->candidateFromParts($profile,$signature,$parsed['language'],$parsed['filters'],$route,$urlResult['url'],$forPublication,true);
		return $this->candidateCache[$key]=$result;
	}

	private function candidateFromParts( array $profile, string $signature, string $language, array $filters, array $route, string $url, bool $forPublication, bool $includeImages ): array {
		$result=$this->baseCandidate($profile,$signature,$language,$filters);
		$result['route']=$route;
		$result['url']=$url;
		if(empty($profile['enabled'])){$result['exclusion_reasons'][]='profile_disabled';return $result;}
		$context=$this->builder->buildEvidence($url,$language);
		if(empty($context['result_count_authoritative'])){
			$count=$this->countProbe->resolve($context);
			if(!empty($count['authoritative'])){
				$context['result_count']=$count['count'];
				$context['result_count_source']=$count['source'];
				$context['result_count_authoritative']=true;
				$context['result_count_detail']=$count['detail'];
			}
		}
		$decision=$this->policy->decide($context);
		$metadata=$this->metadataForContext($context);
		$result['decision']=$decision;
		$result['would_index']=true===($decision['index']??null);
		$result['result_count']=$context['result_count']??null;
		$result['result_count_authoritative']=!empty($context['result_count_authoritative']);
		$result['result_count_source']=(string)($context['result_count_source']??'');
		$result['content_ready']=!empty($context['content_readiness']['ready']);
		$result['content_chars']=(int)($context['content_readiness']['content_chars']??0);
		$result['metadata']=$metadata;
		$result['elementor_content_verified']=!empty($profile['publication']['elementor_content_verified']);
		if(!$result['would_index']){$result['exclusion_reasons'][]=(string)($decision['reason']??'not_indexable');}
		if(empty($profile['publication']['sitemap'])){$result['exclusion_reasons'][]='profile_sitemap_disabled';}
		if(!$this->config->enabled()){$result['exclusion_reasons'][]='global_bridge_off';}
		$result['exclusion_reasons']=array_values(array_unique(array_filter($result['exclusion_reasons'],'strlen')));
		$result['sitemap_included']=$forPublication&&$this->config->enabled()&&!empty($profile['publication']['sitemap'])&&$result['would_index'];
		if($includeImages&&!empty($profile['publication']['include_images_in_sitemap'])){$result['images']=$this->imagesForContext($context);}
		return $result;
	}

	private function baseCandidate( array $profile, string $signature, string $language, array $filters ): array {
		return array(
			'contract'=>'etg.dfsb.publication-candidate.v1',
			'authorizing'=>false,
			'profile_id'=>(string)($profile['id']??''),
			'profile_enabled'=>!empty($profile['enabled']),
			'signature'=>$signature,
			'language'=>$language,
			'filters'=>$filters,
			'route'=>array(),
			'url'=>'',
			'would_index'=>false,
			'sitemap_included'=>false,
			'elementor_content_verified'=>!empty($profile['publication']['elementor_content_verified']),
			'result_count'=>null,
			'result_count_authoritative'=>false,
			'result_count_source'=>'',
			'content_ready'=>false,
			'content_chars'=>0,
			'metadata'=>array(),
			'images'=>array(),
			'decision'=>array(),
			'exclusion_reasons'=>array(),
		);
	}

	private function parseSignature( array $profile, string $signature ): array {
		$signature=strtolower(trim($signature));
		$parts=array_values(array_filter(explode('|',$signature),'strlen'));
		$profileId=sanitize_key((string)($profile['id']??''));
		if(count($parts)<3){return array('valid'=>false,'reason'=>'publication_signature_invalid','language'=>'','filters'=>array());}
		if(sanitize_key((string)$parts[0])!==$profileId){
			return array('valid'=>false,'reason'=>'profile_bound_publication_signature_required','language'=>sanitize_key((string)$parts[0]),'filters'=>array());
		}
		$language=sanitize_key((string)$parts[1]);
		$filters=array();
		foreach(array_slice($parts,2) as $part){
			if(false===strpos($part,'=')){return array('valid'=>false,'reason'=>'publication_signature_invalid','language'=>$language,'filters'=>$filters);}
			list($taxonomy,$slug)=explode('=',$part,2);
			$taxonomy=sanitize_key($taxonomy);$slug=sanitize_title($slug);
			if(''===$taxonomy||''===$slug||isset($filters[$taxonomy])){return array('valid'=>false,'reason'=>'publication_signature_invalid','language'=>$language,'filters'=>$filters);}
			$filters[$taxonomy]=$slug;
		}
		ksort($filters,SORT_STRING);
		if(''===$language||!$filters){return array('valid'=>false,'reason'=>'publication_signature_invalid','language'=>$language,'filters'=>$filters);}
		return array('valid'=>true,'reason'=>'','language'=>$language,'filters'=>$filters);
	}

	private function singleRoute( array $profile ): array {
		$routes=array_values((array)($profile['routes']??array()));
		if(1!==count($routes)){return array();}
		$route=(array)$routes[0];
		$provider=sanitize_key((string)($route['provider']??''));
		$queryId=sanitize_key((string)($route['query_id']??''));
		return $provider&&$queryId?array('provider'=>$provider,'query_id'=>$queryId):array();
	}

	private function buildUrl( array $profile, string $language, array $filters, array $route ): array {
		$paths=array_values(array_filter(array_map('strval',(array)($profile['archive_paths']??array())),'strlen'));
		if(!$paths){return array('url'=>'','reason'=>'publication_archive_path_missing');}
		$basePath=$this->archivePathForLanguage($paths,$language);
		$baseUrl=home_url($basePath);
		$translated=$baseUrl;
		$default=function_exists('apply_filters')?sanitize_key((string)apply_filters('wpml_default_language',null)):'';
		if(function_exists('has_filter')&&has_filter('wpml_permalink')){
			try{$candidate=apply_filters('wpml_permalink',$baseUrl,$language,true);if(is_string($candidate)&&''!==trim($candidate)){$translated=$candidate;}else{return array('url'=>'','reason'=>'wpml_permalink_invalid');}}catch(\Throwable $e){return array('url'=>'','reason'=>'wpml_permalink_exception');}
		}elseif($language&&$default&&$language!==$default&&!$this->pathHasLanguagePrefix($basePath,$language)){
			return array('url'=>'','reason'=>'wpml_permalink_unavailable');
		}
		$pairs=array();ksort($filters,SORT_STRING);
		foreach($filters as $taxonomy=>$slug){$pairs[]=sanitize_key((string)$taxonomy).':'.sanitize_title((string)$slug);}
		if(!$pairs){return array('url'=>'','reason'=>'publication_filters_empty');}
		$append='jsf/'.sanitize_key((string)$route['provider']).':'.sanitize_key((string)$route['query_id']).'/tax/'.implode(';',$pairs).'/';
		$url=$this->appendPath($translated,$append);
		return array('url'=>esc_url_raw($url),'reason'=>'');
	}

	private function archivePathForLanguage( array $paths, string $language ): string {
		foreach($paths as $path){if($this->pathHasLanguagePrefix($path,$language)){return $path;}}
		return (string)reset($paths);
	}

	private function pathHasLanguagePrefix( string $path, string $language ): bool {
		$bits=array_values(array_filter(explode('/',trim($path,'/')),'strlen'));
		return $language&&$bits&&sanitize_key((string)$bits[0])===sanitize_key($language);
	}

	private function appendPath( string $url, string $append ): string {
		$parts=parse_url($url);
		if(!is_array($parts)){return rtrim($url,'/').'/'.ltrim($append,'/');}
		$scheme=(string)($parts['scheme']??'');$host=(string)($parts['host']??'');$port=isset($parts['port'])?':'.(int)$parts['port']:'';
		$user=(string)($parts['user']??'');$pass=(string)($parts['pass']??'');$auth=$user?($user.($pass?':'.$pass:'').'@'):'';
		$origin=$scheme&&$host?$scheme.'://'.$auth.$host.$port:'';
		$path='/' . trim((string)($parts['path']??''),'/') . '/';
		if('//==='===substr($path,0,6)){$path='/';}
		$path=rtrim($path,'/').'/'.ltrim($append,'/');
		$query=isset($parts['query'])&&''!==(string)$parts['query']?'?'.$parts['query']:'';
		return ($origin?:'').$path.$query;
	}

	private function translatedFilters( array $context, string $language ): array {
		$filters=array();
		foreach((array)($context['taxonomy_roles']??array()) as $taxonomy=>$role){
			$termData=(array)($context['terms'][$role]??array());
			$termId=(int)($termData['term_id']??0);
			$taxonomy=sanitize_key((string)$taxonomy);
			if(!$termId||!$taxonomy){return array();}
			$term=get_term($termId,$taxonomy);
			if(!$term instanceof WP_Term){return array();}
			$resolved=$this->languages->resolve($term,$taxonomy,$language);
			if(!empty($resolved['translation_fallback'])||empty($resolved['term'])||!$resolved['term'] instanceof WP_Term){return array();}
			$filters[$taxonomy]=sanitize_title((string)$resolved['term']->slug);
		}
		ksort($filters,SORT_STRING);
		return $filters;
	}

	private function signature( string $profileId, string $language, array $filters ): string {
		$parts=array(sanitize_key($profileId),sanitize_key($language));ksort($filters,SORT_STRING);
		foreach($filters as $taxonomy=>$slug){$parts[]=sanitize_key((string)$taxonomy).'='.sanitize_title((string)$slug);}
		return implode('|',array_filter($parts,'strlen'));
	}

	private function imagesForContext( array $context ): array {
		$ids=array_slice($this->gallery->ids($context,'priority'),0,5);$images=array();
		foreach($ids as $id){$src=function_exists('wp_get_attachment_image_url')?wp_get_attachment_image_url((int)$id,'full'):false;if(!$src){continue;}$title=function_exists('get_the_title')?get_the_title((int)$id):'';$row=array('src'=>$src);if($title){$row['title']=$title;}$images[]=$row;}
		return $images;
	}

	private function resolved( array $context ): bool {
		if(empty($context['active'])||empty($context['in_scope'])||empty($context['runtime_ready'])||empty($context['filters'])){return false;}
		if(isset($context['scope_valid'])&&empty($context['scope_valid'])){return false;}
		if(isset($context['provider_observation_matches_url'])&&empty($context['provider_observation_matches_url'])){return false;}
		$profile=(array)($context['profile']??array());$binding=(array)($context['post_type_binding']??array());
		if(!empty($profile['require_post_type_binding'])&&(empty($binding['observed'])||empty($binding['matches_profile']))){return false;}
		return empty($context['unknown_filters'])&&empty($context['malformed'])&&empty($context['missing_terms'])&&empty($context['translation_fallback'])&&!empty($context['terms']);
	}
}
