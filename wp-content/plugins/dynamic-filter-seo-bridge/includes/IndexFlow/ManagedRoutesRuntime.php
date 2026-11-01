<?php
namespace DynamicFilterSEOBridge\IndexFlow;

/** Staging-only vendor handoff. The storage Provider cannot activate this scope. */
final class ManagedRoutesRuntime {
    private static $active;
    private $service;
    private $routeAllowed;
    private $authority;
    private $seoFrontend;
    public function __construct(ManagedUrlArchitecture $service, callable $routeAllowed, callable $authority) {
        $this->service = $service; $this->routeAllowed = $routeAllowed; $this->authority = $authority;
    }

    public static function fromEnvironment($config, $profiles): ?self {
        if (!$config->enabled() || !function_exists('wp_get_environment_type') || 'staging' !== wp_get_environment_type()
            || !class_exists('MAD4B_SCP_Provider_Contracts') || !function_exists('jet_smart_filters')
            || !(new \DynamicFilterSEOBridge\Compatibility())->rankMath()) return null;
        $authority = static function (): bool {
            return true === \MAD4B_SCP_Provider_Contracts::mutation_guard('indexflow-route-runtime', true);
        };
        if (!$authority()) return null;
        $vendor = jet_smart_filters();
        if (!isset($vendor->settings) || 'permalink' !== $vendor->settings->get('url_structure_type')
            || filter_var($vendor->settings->get('use_url_aliases'), FILTER_VALIDATE_BOOLEAN)) return null;
        try {
            $snapshot = (new RouteDocumentStore())->snapshot();
            if (!$snapshot['exists']) return null;
            $service = RouteDocumentStore::architecture($snapshot['document']);
            $allowed = static function (array $row) use ($profiles, $service, $config): bool {
                if ('rank_math' !== $row['seo_owner']) return false;
                $parsed = (new \DynamicFilterSEOBridge\JetSmartFilters\FilterUrlParser($profiles->allowedTaxonomies()))->parse($service->technicalUrl($row['state']));
                $scope = $profiles->resolve($parsed);
                return !empty($scope['in_scope']) && !empty($scope['scope_valid']) && ($scope['profile_id'] ?? '') === $row['state']['profile_id']
                    && 'filtered' === ($scope['profile']['canonical_mode'] ?? $config->get('canonical_mode', 'filtered'))
                    && ('virtual' !== $row['source'] || !empty($scope['profile']['publication']['metadata']));
            };
            return new self($service, $allowed, $authority);
        } catch (\Throwable $e) { return null; }
    }

    public function register(): void {
        if (!$this->authorized()) return;
        self::$active = $this;
        add_action('init', array($this, 'rewrites'), 20);
        // Vendor render consumes jsf at priority 10; hand off before that.
        add_action('parse_request', array($this, 'parseRequest'), 1);
        add_filter('etg_filter_seo_canonical_url', array($this, 'contextCanonical'), 100, 3);
        add_filter('etg_filter_seo_publication_url', array($this, 'canonical'), 100);
        add_filter('jet-smart-filters/compatibility/rank_math/disable_integration', array($this, 'disableRankMath'), 100);
        add_action('jet-smart-filters/seo/frontend/init-rule', array($this, 'rememberSeoFrontend'), 100);
        add_action('wp', array($this, 'scopeSeoOwnership'), 100);
        add_action('wp_enqueue_scripts', array($this, 'enqueue'), 100);
        add_filter('redirect_canonical', array($this, 'redirectCanonical'), 100);
    }
    private function authorized(): bool { return true === call_user_func($this->authority); }
    private function allowed(array $row): bool {
        return $this->authorized() && 'published' === ($row['status'] ?? '') && true === call_user_func($this->routeAllowed, $row);
    }
    private function find(string $url): array {
        $row = $this->service->resolve($url);
        if (empty($row['id'])) $row = $this->service->lookup($row['identity'], $row['state']['locale']);
        return $row && $this->allowed($row) ? $row : array();
    }
    private function current(): array {
        try { return $this->find((string)($_SERVER['REQUEST_URI'] ?? '')); } catch (\InvalidArgumentException $e) { return array(); }
    }
    public function technicalUriFor(string $url): ?string {
        try { $row = $this->find($url); return $row ? $this->service->technicalUrl($row['state']) : null; }
        catch (\InvalidArgumentException $e) { return null; }
    }
    public static function reservedQueryVars(array $vars): array {
        if (!in_array('indexflow_route', $vars, true)) $vars[] = 'indexflow_route';
        return $vars;
    }
    public static function registerSafetyGuards(): void {
        add_filter('query_vars', array(self::class, 'reservedQueryVars'));
        add_action('parse_request', array(self::class, 'guardDormant'), 0);
    }
    public static function guardDormant($wp): void {
        if (!self::$active || !self::$active->authorized()) self::guardInactiveRequest($wp);
    }
    public static function guardInactiveRequest($wp): void {
        if (isset($wp->query_vars['indexflow_route'])) $wp->query_vars = array('error' => '404');
    }
    public function rewrites(): void {
        if (!$this->authorized()) return;
        $plan = (new WordPressRouteResolver($this->service, (string)parse_url((string)get_option('home'), PHP_URL_PATH)))->rewritePlan();
        foreach ($plan['rules'] as $rule) {
            $row = $this->service->resolve($this->urlForId($rule['route_id']));
            if ($this->allowed($row)) add_rewrite_rule($rule['regex'], $rule['query'], 'top');
        }
    }
    private function urlForId(string $id): string {
        foreach ($this->service->preview()['routes'] as $row) if ($id === $row['id']) return $row['url'];
        throw new \InvalidArgumentException('route_not_found');
    }
    public function parseRequest($wp): void {
        if (!$this->authorized()) { self::guardInactiveRequest($wp); return; }
        $row = $this->current();
        if (!$row || (!empty($wp->query_vars['indexflow_route']) && $wp->query_vars['indexflow_route'] !== $row['id'])) {
            if (isset($wp->query_vars['indexflow_route'])) $wp->query_vars = array('error' => '404');
            return;
        }
        $technical = rawurldecode((string)parse_url($this->service->technicalUrl($row['state']), PHP_URL_PATH));
        $offset = strpos($technical, '/jsf/');
        if (false === $offset) return;
        if ('virtual' === $row['source']) {
            // Exact registered-path fallback also handles an older persisted
            // rewrite cache, without flushing or writing options on a request.
            $wp->query_vars = array('page_id' => $row['object_id'], 'indexflow_route' => $row['id']);
        }
        // Feed the vendor's own store_queried_filter/restore_queried_filter_var
        // and apply_filters_from_permalink lifecycle; do not execute a new query.
        $wp->query_vars['jsf'] = substr($technical, $offset + 5);
    }
    public function canonical($url) {
        if (!is_string($url)) return $url;
        try { $row = $this->find($url); return $row ? $row['url'] : $url; }
        catch (\InvalidArgumentException $e) { return $url; }
    }
    public function contextCanonical($url, array $context, string $mode) {
        if ('filtered' !== $mode || empty($context['request_path'])) return $url;
        $path = (string)$context['request_path'];
        if (!empty($context['canonical_query_params'])) $path .= '?' . http_build_query($context['canonical_query_params']);
        try { $row = $this->find($path); return $row ? $row['url'] : $url; }
        catch (\InvalidArgumentException $e) { return $url; }
    }
    public function redirectCanonical($url) { return $this->current() ? false : $url; }
    public function disableRankMath($disable) { return $this->current() ? false : $disable; }
    public function rememberSeoFrontend($frontend): void {
        if ($this->current() && is_object($frontend)) $this->seoFrontend = $frontend;
    }
    public function scopeSeoOwnership(): void {
        if (!$this->current() || !$this->seoFrontend) return;
        // Remove only the exact JSF frontend instance's competing output for this
        // managed Rank Math request; never deactivate JSF SEO across the site.
        remove_filter('document_title', array($this->seoFrontend, 'modify_document_title'));
        remove_action('wp_head', array($this->seoFrontend, 'modify_document_description'), 1);
        remove_action('wp_head', array($this->seoFrontend, 'modify_canonical_url'), 1);
    }
    public function browserPlan(): array {
        $current = $this->current(); if (!$current) return array();
        $state = $current['state']; $routes = array();
        foreach ($this->service->preview()['routes'] as $preview) {
            if ('published' !== $preview['status'] || $preview['locale'] !== $state['locale']) continue;
            $row = $this->service->lookup($preview['identity'], $preview['locale']);
            if (!$this->allowed($row) || $row['state']['profile_id'] !== $state['profile_id']) continue;
            $routes[] = array('native_url' => $this->service->technicalUrl($row['state']), 'url' => $row['url']);
        }
        $technical = $this->service->technicalUrl($state);
        return array('provider' => $state['provider'], 'query_id' => $state['query_id'], 'revision' => $this->service->revision(),
            'archive_url' => substr($technical, 0, strpos($technical, '/jsf/')), 'routes' => $routes);
    }
    public function enqueue(): void {
        $plan = $this->browserPlan(); if (!$plan) return;
        wp_enqueue_script('indexflow-managed-routes', plugins_url('assets/js/indexflow-managed-routes.js', DFSB_MAIN_FILE), array(), DFSB_ASSET_VERSION, true);
        wp_add_inline_script('indexflow-managed-routes', 'window.IndexFlowManagedRoutes=' . wp_json_encode($plan, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
    }
}
