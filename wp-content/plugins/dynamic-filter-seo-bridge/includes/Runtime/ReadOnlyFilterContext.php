<?php
namespace DynamicFilterSEOBridge\Runtime;

use DynamicFilterSEOBridge\Context\PageContextResolver;
use DynamicFilterSEOBridge\Context\ProviderContextResolver;

/** Read-only projection of the existing WordPress and native JSF context. */
final class ReadOnlyFilterContext {
    public static function register(): void {
        add_filter('dfsb/filter-context/read', array(__CLASS__, 'read'), 10, 1);
    }
    public static function read($previous = null): array {
        $result = array('contract'=>'dfsb.filter-context-read.v1', 'read_only'=>true, 'authorizing'=>false);
        if (!RuntimeLayers::canReadFilterContext()) return $result + array('status'=>'held');
        $context = PageContextResolver::fromQuery();
        if (in_array(($context['page_kind'] ?? ''), array('unresolved','commerce_transaction'), true))
            return $result + array('status'=>'unresolved');
        $provider = ProviderContextResolver::forContext($context);
        $filter = (array)($provider['filter'] ?? array());
        return $result + array('status'=>'available_read_only', 'page_kind'=>(string)($context['page_kind'] ?? ''),
            'language'=>(string)($context['language'] ?? ''),
            'filter_provider'=>(string)($filter['provider_id'] ?? 'unresolved'),
            'binding_status'=>(string)($filter['status'] ?? 'unresolved'));
    }
}
