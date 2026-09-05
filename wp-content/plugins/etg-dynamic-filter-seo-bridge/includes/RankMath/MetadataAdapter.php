<?php
namespace ETG\DynamicFilterSEOBridge\RankMath;

use ETG\DynamicFilterSEOBridge\Content\ContentComposer;
use ETG\DynamicFilterSEOBridge\Content\GalleryComposer;
use ETG\DynamicFilterSEOBridge\SEO\CanonicalBuilder;
use ETG\DynamicFilterSEOBridge\SEO\IndexingPolicy;
use ETG\DynamicFilterSEOBridge\SEO\PublicationRegistry;

final class MetadataAdapter {
	private $contextProvider;
	private $content;
	private $gallery;
	private $indexing;
	private $canonical;
	private $publication;

	public function __construct( callable $contextProvider, ContentComposer $content, GalleryComposer $gallery, IndexingPolicy $indexing, CanonicalBuilder $canonical, PublicationRegistry $publication = null ) {
		$this->contextProvider = $contextProvider;
		$this->content = $content;
		$this->gallery = $gallery;
		$this->indexing = $indexing;
		$this->canonical = $canonical;
		$this->publication = $publication;
	}

	public function register(): void {
		add_filter( 'rank_math/frontend/title', array( $this, 'title' ), 20 );
		add_filter( 'rank_math/frontend/description', array( $this, 'description' ), 20 );
		add_filter( 'rank_math/frontend/canonical', array( $this, 'canonical' ), 20 );
		add_filter( 'rank_math/frontend/robots', array( $this, 'robots' ), 20 );
		add_filter( 'rank_math/opengraph/type', array( $this, 'openGraphType' ), 20 );
		add_filter( 'rank_math/opengraph/url', array( $this, 'openGraphUrl' ), 20 );
		add_filter( 'rank_math/opengraph/facebook/og_title', array( $this, 'socialTitle' ), 20 );
		add_filter( 'rank_math/opengraph/facebook/og_description', array( $this, 'socialDescription' ), 20 );
		add_filter( 'rank_math/opengraph/twitter/twitter_title', array( $this, 'socialTitle' ), 20 );
		add_filter( 'rank_math/opengraph/twitter/twitter_description', array( $this, 'socialDescription' ), 20 );
		add_filter( 'rank_math/opengraph/facebook/image', array( $this, 'socialImage' ), 20 );
		add_filter( 'rank_math/opengraph/twitter/image', array( $this, 'socialImage' ), 20 );
		add_filter( 'rank_math/opengraph/twitter/card_type', array( $this, 'twitterCardType' ), 20 );
		add_filter( 'rank_math/json_ld', array( $this, 'schema' ), 99, 2 );
		add_filter( 'wpml_hreflangs', array( $this, 'hreflangs' ), 99 );
	}

	public function title( $original ) { $context=$this->context(); if(!$this->seoMutationAllowed($context)){return $original;} $title=$this->content->metaTitle($context); return ''!==$title?$title:$original; }
	public function description( $original ) { $context=$this->context(); if(!$this->seoMutationAllowed($context)){return $original;} $description=$this->content->metaDescription($context); return ''!==$description?$description:$original; }
	public function canonical( $original ) { $context=$this->context(); if(!$this->seoMutationAllowed($context)){return $original;} return $this->canonical->build($context,$original); }
	public function robots( $robots ) { $decision=$this->indexing->decide($this->context()); if(!array_key_exists('index',$decision)||null===$decision['index']||!is_array($robots)){return $robots;} $robots['index']=$decision['index']?'index':'noindex'; $robots['follow']='follow'; return $robots; }
	public function openGraphType( $original ) { $context=$this->context(); return $this->socialAllowed($context)?'website':$original; }
	public function openGraphUrl( $original ) { $context=$this->context(); if(!$this->socialAllowed($context)){return $original;} $url=$this->canonical->build($context,(string)$original); return $url?:$original; }
	public function socialTitle( $original ) { $context=$this->context(); if(!$this->socialAllowed($context)){return $original;} $title=$this->content->metaTitle($context); return ''!==$title?$title:$original; }
	public function socialDescription( $original ) { $context=$this->context(); if(!$this->socialAllowed($context)){return $original;} $description=$this->content->metaDescription($context); return ''!==$description?$description:$original; }
	public function socialImage( $original ) { $context=$this->context(); if(!$this->socialAllowed($context)){return $original;} $ids=$this->gallery->ids($context,'priority'); if(!$ids){return $original;} $url=wp_get_attachment_image_url((int)reset($ids),'full'); return $url?$url:$original; }
	public function twitterCardType( $original ) { $context=$this->context(); if(!$this->socialAllowed($context)){return $original;} $ids=$this->gallery->ids($context,'priority'); return $ids?'summary_large_image':$original; }
	public function schema( $data, $jsonld = null ) { if(!is_array($data)||!$this->publication){return $data;} $context=$this->context(); if(!$this->hasResolvedContext($context)){return $data;} $decision=$this->indexing->decide($context); if(true!==($decision['index']??null)){return $data;} $schema=$this->publication->schemaForContext($context); if($schema){$data['ETGFilteredCollection']=$schema;} return $data; }
	public function hreflangs( $items ) { if(!$this->publication){return $items;} $context=$this->context(); if(!$this->hasResolvedContext($context)){return $items;} $alternates=$this->publication->alternatesForContext($context); return $alternates?:$items; }
	private function context(): array { $context=call_user_func($this->contextProvider); return is_array($context)?$context:array(); }
	private function socialAllowed( array $context ): bool { if(!$this->seoMutationAllowed($context)){return false;} $profile=(array)($context['profile']??array()); return !isset($profile['publication']['social'])||!empty($profile['publication']['social']); }
	private function seoMutationAllowed( array $context ): bool { if(!$this->hasResolvedContext($context)){return false;} $decision=$this->indexing->decide($context); return true===($decision['index']??null); }
	private function hasResolvedContext( array $context ): bool {
		if(empty($context['active'])||empty($context['in_scope'])||empty($context['runtime_ready'])||empty($context['filters'])){return false;}
		if(isset($context['scope_valid'])&&empty($context['scope_valid'])){return false;}
		$profile=(array)($context['profile']??array());
		$requireProvider=array_key_exists('require_provider_observation_for_index',$profile)?(bool)$profile['require_provider_observation_for_index']:true;
		if($requireProvider&&empty($context['provider_observed'])){return false;}
		if(!empty($context['provider_observed'])&&empty($context['provider_observation_matches_url'])){return false;}
		$binding=(array)($context['post_type_binding']??array());
		if(!empty($profile['require_post_type_binding'])&&(empty($binding['observed'])||empty($binding['matches_profile']))){return false;}
		return empty($context['unknown_filters'])&&empty($context['malformed'])&&empty($context['missing_terms'])&&empty($context['translation_fallback'])&&!empty($context['terms']);
	}
}
