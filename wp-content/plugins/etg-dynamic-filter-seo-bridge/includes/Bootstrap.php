<?php
namespace ETG\DynamicFilterSEOBridge;

use ETG\DynamicFilterSEOBridge\Content\ContentComposer;
use ETG\DynamicFilterSEOBridge\Content\GalleryComposer;
use ETG\DynamicFilterSEOBridge\Context\FilterContextBuilder;
use ETG\DynamicFilterSEOBridge\Elementor\Shortcodes;
use ETG\DynamicFilterSEOBridge\JetSmartFilters\FilterUrlParser;
use ETG\DynamicFilterSEOBridge\RankMath\MetadataAdapter;
use ETG\DynamicFilterSEOBridge\SEO\IndexingPolicy;
use ETG\DynamicFilterSEOBridge\Terms\TermMetaReader;
use ETG\DynamicFilterSEOBridge\WPML\LanguageResolver;

final class Bootstrap {
	private static $instance;
	private $builder;
	private $context;
	private $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) { return; }
		$this->booted = true;
		$compatibility = new Compatibility();
		$gallery = new GalleryComposer();
		$content = new ContentComposer( $gallery );
		$this->builder = new FilterContextBuilder( new FilterUrlParser(), new LanguageResolver(), new TermMetaReader(), $content );
		$provider = array( $this, 'context' );
		$shortcodes = new Shortcodes( $provider, $content, $gallery );
		add_action( 'init', array( $shortcodes, 'register' ), 20 );
		if ( $compatibility->rankMath() ) {
			( new MetadataAdapter( $provider, $content, $gallery, new IndexingPolicy() ) )->register();
		}
		do_action( 'etg_filter_seo_bridge_booted', $compatibility->report() );
	}

	public function context(): array {
		if ( null !== $this->context ) { return $this->context; }
		if ( ! $this->builder ) { return array(); }
		$this->context = $this->builder->build();
		return $this->context;
	}

	private function __construct() {}
}
