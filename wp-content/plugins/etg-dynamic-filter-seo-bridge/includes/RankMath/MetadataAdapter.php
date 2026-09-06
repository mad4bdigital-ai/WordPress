<?php
namespace ETG\DynamicFilterSEOBridge\RankMath;

use ETG\DynamicFilterSEOBridge\Content\ContentComposer;
use ETG\DynamicFilterSEOBridge\Content\GalleryComposer;
use ETG\DynamicFilterSEOBridge\SEO\IndexingPolicy;

final class MetadataAdapter {
	private $contextProvider; private $content; private $gallery; private $indexing;
	public function __construct( callable $contextProvider, ContentComposer $content, GalleryComposer $gallery, IndexingPolicy $indexing ) {
		$this->contextProvider = $contextProvider; $this->content = $content; $this->gallery = $gallery; $this->indexing = $indexing;
	}

	public function register(): void {
		add_filter( 'rank_math/frontend/title', array( $this, 'title' ), 20 );
		add_filter( 'rank_math/frontend/description', array( $this, 'description' ), 20 );
		add_filter( 'rank_math/frontend/canonical', array( $this, 'canonical' ), 20 );
		add_filter( 'rank_math/frontend/robots', array( $this, 'robots' ), 20 );
		add_filter( 'rank_math/opengraph/facebook/image', array( $this, 'facebookImage' ), 20 );
	}

	public function title( $original ) {
		$context = $this->context();
		if ( ! $this->hasResolvedContext( $context ) ) { return $original; }
		$title = $this->content->metaTitle( $context );
		return '' !== $title ? $title : $original;
	}

	public function description( $original ) {
		$context = $this->context();
		if ( ! $this->hasResolvedContext( $context ) ) { return $original; }
		$description = $this->content->metaDescription( $context );
		return '' !== $description ? $description : $original;
	}

	public function canonical( $original ) {
		$context = $this->context();
		if ( ! $this->hasResolvedContext( $context ) || empty( $context['request_path'] ) ) { return $original; }
		return esc_url_raw( home_url( (string) $context['request_path'] ) );
	}

	public function robots( $robots ) {
		$decision = $this->indexing->decide( $this->context() );
		if ( null === $decision['index'] || ! is_array( $robots ) ) { return $robots; }
		$robots['index'] = $decision['index'] ? 'index' : 'noindex';
		$robots['follow'] = 'follow';
		return $robots;
	}

	public function facebookImage( $original ) {
		$context = $this->context();
		if ( ! $this->hasResolvedContext( $context ) ) { return $original; }
		$ids = $this->gallery->ids( $context, 'priority' );
		if ( ! $ids ) { return $original; }
		$url = wp_get_attachment_image_url( (int) reset( $ids ), 'full' );
		return $url ? $url : $original;
	}

	private function context(): array {
		$context = call_user_func( $this->contextProvider );
		return is_array( $context ) ? $context : array();
	}

	private function hasResolvedContext( array $context ): bool {
		return ! empty( $context['active'] ) && ! empty( $context['filters'] ) && empty( $context['unknown_filters'] )
			&& empty( $context['malformed'] ) && empty( $context['missing_terms'] ) && ! empty( $context['terms'] );
	}
}
