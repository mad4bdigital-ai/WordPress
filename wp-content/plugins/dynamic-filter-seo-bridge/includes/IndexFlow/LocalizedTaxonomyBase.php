<?php
namespace DynamicFilterSEOBridge\IndexFlow;
require_once __DIR__ . '/LocalizedRewriteBase.php';

/** Backward-compatible taxonomy convenience bridge. */
final class LocalizedTaxonomyBase {
    public const CONTRACT='dfsb.indexflow.wpml-taxonomy-url-base.v2';
    public static function inspect(string $taxonomy,string $locale): array {
        return LocalizedRewriteBase::inspect('taxonomy',$taxonomy,$locale);
    }
    public static function requireBase(string $taxonomy,string $locale): string {
        return LocalizedRewriteBase::requireLocalized('taxonomy',$taxonomy,$locale);
    }
}
