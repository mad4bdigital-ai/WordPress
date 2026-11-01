<?php
namespace DynamicFilterSEOBridge\IndexFlow;

/** Uses the vendor's own reversible alias implementation, not copied rules. */
final class VendorUrlAliases {
    private $vendor;
    public function __construct($vendor) { $this->vendor = $vendor; }
    public function dispatch(string $operation, string $url): string {
        if ('build' === $operation) return (string)$this->vendor->apply_aliases_to_url($url);
        if ('resolve' !== $operation) throw new \InvalidArgumentException('unknown_alias_operation');
        $parts = parse_url($url);
        if (!is_array($parts)) throw new \InvalidArgumentException('invalid_alias_url');
        $uri = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $previousUri = $_SERVER['REQUEST_URI'] ?? null;
        $previousRequest = $_REQUEST;
        $redirectPriority = has_action('template_redirect', 'redirect_canonical');
        try {
            $_SERVER['REQUEST_URI'] = $uri;
            $this->vendor->parse_aliases();
            $result = (string)$_SERVER['REQUEST_URI'];
        } finally {
            if (null === $previousUri) unset($_SERVER['REQUEST_URI']); else $_SERVER['REQUEST_URI'] = $previousUri;
            $_REQUEST = $previousRequest;
            if (false !== $redirectPriority && false === has_action('template_redirect', 'redirect_canonical')) {
                add_action('template_redirect', 'redirect_canonical', $redirectPriority);
            }
        }
        if (isset($parts['host'])) return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $result;
        return $result;
    }
}
