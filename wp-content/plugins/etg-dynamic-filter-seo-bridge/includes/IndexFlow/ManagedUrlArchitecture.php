<?php
namespace ETG\DynamicFilterSEOBridge\IndexFlow;

/**
 * Bounded publication registry and pure URL contracts. No persistence, query
 * execution, authority grants, redirects or SEO output takes place here.
 */
final class ManagedUrlArchitecture {
    public const CONTRACT = 'indexflow.managed-url-architecture.v1';
    private $normalizer;
    private $term;
    private $page;
    private $collision;
    private $native;
    private $origin;
    private $document;
    private $routes = array();

    /** Callbacks are source-owned adapters, never supplied by an admin payload. */
    public function __construct(array $document, string $origin, callable $term, callable $page, callable $collision, callable $native) {
        $this->term = $term;
        $this->page = $page;
        $this->collision = $collision;
        $this->native = $native;
        $this->normalizer = new NormalizedFilterState($term);
        $parts = parse_url($origin);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', array('http', 'https'), true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', array('', '/'), true)) {
            throw new \InvalidArgumentException('origin_only_required');
        }
        $this->origin = rtrim($origin, '/');
        if (array_diff(array_keys($document), array('version', 'profiles', 'routes'))
            || ($document['version'] ?? null) !== 1 || !is_array($document['profiles'] ?? null)
            || !is_array($document['routes'] ?? null) || count($document['routes']) > 500 || count($document['profiles']) > 100) {
            throw new \InvalidArgumentException('invalid_route_document');
        }
        $this->document = $document;
        foreach ($document['profiles'] as $profileId => $profile) {
            if (!is_string($profileId) || !preg_match('/^[a-z0-9_-]{1,80}$/D', $profileId) || !is_array($profile)
                || !is_array($profile['bases'] ?? null) || !$profile['bases'] || count($profile['bases']) > 50) throw new \InvalidArgumentException('invalid_route_profile');
            foreach ($profile['bases'] as $locale => $base) {
                if (!is_string($locale) || !preg_match('/^[a-z]{2,3}(?:-[a-zA-Z0-9]{2,8})?$/D', $locale) || !is_string($base)) throw new \InvalidArgumentException('invalid_locale_base');
                $this->profile($profileId, $locale);
                $this->cleanUrl($base);
            }
        }
        $paths = array();
        $identities = array();
        foreach ($document['routes'] as $row) {
            if (!is_array($row)) throw new \InvalidArgumentException('invalid_route_record');
            $state = $this->normalize((array)($row['state'] ?? array()));
            $id = $row['id'] ?? '';
            if (!is_string($id) || !preg_match('/^[a-z0-9_-]{1,80}$/D', $id) || isset($this->routes[$id])) throw new \InvalidArgumentException('invalid_route_id');
            if (!in_array($row['status'] ?? '', array('draft', 'approved', 'published', 'retired'), true)
                || !in_array($row['source'] ?? '', array('native_page', 'virtual'), true)
                || !in_array($row['seo_owner'] ?? '', array('rank_math', 'jet_smart_filters'), true)) {
                throw new \InvalidArgumentException('invalid_route_policy');
            }
            $url = $this->routeUrl($row, $state);
            $key = $state['identity'] . '|' . $state['locale'];
            // Retired entries are history only. They neither resolve nor emit SEO.
            if ('retired' !== $row['status']) {
                if (isset($paths[$url]) || isset($identities[$key])) throw new \InvalidArgumentException('route_identity_or_path_collision');
                $paths[$url] = $id;
                $identities[$key] = $id;
            }
            $row['state'] = $state;
            $row['url'] = $url;
            $row['identity'] = $state['identity'];
            $this->routes[$id] = $row;
        }
    }

    public function normalize(array $state): array {
        $normalized = $this->normalizer->normalize($state);
        $profile = $this->profile($normalized['profile_id'], $normalized['locale']);
        if (($profile['provider'] ?? '') !== $normalized['provider'] || ($profile['query_id'] ?? '') !== $normalized['query_id']) {
            throw new \InvalidArgumentException('provider_query_binding_mismatch');
        }
        $allowed = array();
        foreach ($profile['dimensions'] as $dimension) {
            $taxonomy = $dimension['taxonomy'];
            $ids = $normalized['filters'][$taxonomy] ?? array();
            // Initial published schema deliberately supports exact single-term AND
            // dimensions only. Never collapse OR/multi-select into a different URL.
            if (count($ids) > 1 || (!empty($dimension['required']) && 1 !== count($ids))) throw new \InvalidArgumentException('dimension_cardinality_violation');
            $allowed[] = $taxonomy;
        }
        if (array_diff(array_keys($normalized['filters']), $allowed)) throw new \InvalidArgumentException('unknown_route_dimension');
        return $normalized;
    }

    public function build(array $state, string $locale): string {
        if (($state['locale'] ?? '') !== $locale) throw new \InvalidArgumentException('localized_term_state_required');
        $state = $this->normalize($state);
        $profile = $this->profile($state['profile_id'], $locale);
        if ('published_seo' === $profile['strategy']) {
            $row = $this->lookup($state['identity'], $locale);
            if (!$row) throw new \InvalidArgumentException('published_route_required');
            return $row['url'];
        }
        $url = call_user_func($this->native, 'build', $state, $profile);
        if (!is_string($url) || !$this->sameOrigin($url)) throw new \InvalidArgumentException('native_url_unavailable');
        return $this->cleanUrl($url);
    }

    public function lookup(string $identity, string $locale): array {
        foreach ($this->routes as $row) {
            if ('published' === $row['status'] && $row['identity'] === $identity && $row['state']['locale'] === $locale) return $row;
        }
        return array();
    }

    public function technicalUrl(array $state): string {
        $state = $this->normalize($state);
        $profile = $this->profile($state['profile_id'], $state['locale']);
        $profile['strategy'] = 'native';
        $url = call_user_func($this->native, 'build', $state, $profile);
        if (!is_string($url)) throw new \InvalidArgumentException('native_url_unavailable');
        return $this->cleanUrl($url);
    }

    public function resolve(string $url): array {
        $clean = $this->cleanUrl($url);
        foreach ($this->routes as $row) {
            if ('published' === $row['status'] && $row['url'] === $clean) return $row;
        }
        $state = call_user_func($this->native, 'resolve', $url, $this->document['profiles']);
        if (!is_array($state) || !$state) throw new \InvalidArgumentException('route_not_found');
        $state = $this->normalize($state);
        return array('id' => '', 'state' => $state, 'identity' => $state['identity'], 'url' => $clean, 'source' => 'native_filter');
    }

    public function canonicalize(string $url): string {
        $row = $this->resolve($url);
        $published = $this->lookup($row['identity'], $row['state']['locale']);
        return $published ? $published['url'] : $this->build($row['state'], $row['state']['locale']);
    }

    public function preview(): array {
        $rows = array();
        foreach ($this->routes as $row) {
            $alternates = array();
            if ('published' === $row['status']) {
                foreach ($this->routes as $other) {
                    if ('published' === $other['status'] && $other['identity'] === $row['identity']) $alternates[$other['state']['locale']] = $other['url'];
                }
            }
            $rows[] = array('id' => $row['id'], 'identity' => $row['identity'], 'locale' => $row['state']['locale'],
                'status' => $row['status'], 'source' => $row['source'], 'url' => $row['url'],
                'canonical_candidate' => 'published' === $row['status'] ? $row['url'] : '', 'hreflang_candidates' => $alternates,
                'seo_owner' => $row['seo_owner'], 'indexing_authorized' => false, 'runtime_certified' => false);
        }
        return array('contract' => self::CONTRACT, 'read_only' => true, 'authorizing' => false,
            'runtime_enabled' => false, 'revision' => $this->revision(), 'profiles' => $this->document['profiles'], 'routes' => $rows);
    }

    /** Reduces externally observed evidence; it cannot issue a runtime certificate. */
    public function verify(array $route, array $observations): array {
        $registered = $this->routes[$route['id'] ?? ''] ?? array();
        if (!$registered || $registered !== $route || 'published' !== $registered['status']) throw new \InvalidArgumentException('registered_published_route_required');
        $reasons = array();
        $reference = null;
        foreach (array('direct', 'ajax', 'mixed', 'reload', 'back', 'forward') as $mode) {
            $seen = $observations[$mode] ?? array();
            $ids = $seen['result_ids'] ?? null;
            if (!is_array($ids) || !$ids || count($ids) > 10000 || ($seen['complete'] ?? false) !== true
                || ($seen['state_identity'] ?? '') !== $route['identity'] || ($seen['locale'] ?? '') !== $route['state']['locale']) {
                $reasons[] = $mode . '_complete_evidence_required'; continue;
            }
            $validIds = true;
            foreach ($ids as $id) if (!is_int($id) || $id < 1) $validIds = false;
            if (!$validIds) { $reasons[] = $mode . '_invalid_result_id'; continue; }
            if (count($ids) !== count(array_unique($ids))) $reasons[] = $mode . '_duplicate_result_ids';
            $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC);
            if (null === $reference) $reference = $ids;
            elseif ($reference !== $ids) $reasons[] = $mode . '_result_ids_differ';
            if (($seen['http_status'] ?? null) !== 200) $reasons[] = $mode . '_http_status';
            try {
                $widgetState = $this->normalize((array)($seen['widgets_state'] ?? array()));
                $requestState = $this->normalize((array)($seen['filter_state'] ?? array()));
                $urlState = $this->resolve((string)($seen['url'] ?? ''))['state'];
                if ($widgetState !== $route['state'] || $requestState !== $route['state'] || $urlState !== $route['state']) $reasons[] = $mode . '_filter_state_mismatch';
            } catch (\InvalidArgumentException $e) { $reasons[] = $mode . '_filter_state_unproven'; }
        }
        $seo = $observations['seo'] ?? array();
        if (($seo['canonical'] ?? '') !== $route['url'] || ($seo['canonical_count'] ?? 0) !== 1
            || ($seo['owner'] ?? '') !== $route['seo_owner'] || ($seo['robots'] ?? '') !== 'index,follow'
            || ($seo['sitemap_urls'] ?? array()) !== array($route['url'])) $reasons[] = 'seo_output_mismatch';
        $alternates = array();
        foreach ($this->routes as $other) if ('published' === $other['status'] && $other['identity'] === $route['identity']) $alternates[$other['state']['locale']] = $other['url'];
        $seenAlternates = $seo['hreflang'] ?? array();
        if (!is_array($seenAlternates)) $seenAlternates = array();
        ksort($alternates); ksort($seenAlternates);
        if ($seenAlternates !== $alternates) $reasons[] = 'hreflang_output_mismatch';
        $ownership = (new SeoOwnershipResolver())->inspect($route, (array)($observations['seo_ownership'] ?? array()));
        if (!$ownership['ready']) $reasons[] = 'seo_ownership_unproven';
        if (($observations['unknown_route_status'] ?? null) !== 404) $reasons[] = 'unknown_route_404_required';
        if (($observations['route_revision'] ?? '') !== $this->revision()) $reasons[] = 'stale_route_evidence';
        return array('contract' => 'indexflow.route-verification.v1', 'observed_pass' => !$reasons,
            'reasons' => array_values(array_unique($reasons)), 'authorizing' => false, 'runtime_certified' => false);
    }

    public function planMigration(self $new): array {
        $changes = array();
        foreach ($this->routes as $old) {
            if ('published' !== $old['status']) continue;
            $next = $new->lookup($old['identity'], $old['state']['locale']);
            if (!$next || $next['url'] !== $old['url']) {
                $changes[] = array('identity' => $old['identity'], 'locale' => $old['state']['locale'], 'from' => $old['url'],
                    'to' => $next['url'] ?? '', 'redirect_authorized' => false,
                    'required' => array('complete_result_id_equivalence', 'collision_check', 'owner_approval', 'verify', 'rollback'));
            }
        }
        return array('contract' => 'indexflow.route-migration-plan.v1', 'authorizing' => false, 'base_revision' => $this->revision(),
            'target_revision' => $new->revision(), 'migration_required' => $this->document['profiles'] !== $new->document['profiles'] || (bool)$changes,
            'changes' => $changes, 'apply_via' => 'MAD4B Plan -> Apply -> Verify -> mutation-undo', 'apply_available' => false);
    }

    public function revision(): string { return hash('sha256', json_encode(array($this->document, $this->routes))); }

    private function profile(string $id, string $locale): array {
        $profile = $this->document['profiles'][$id] ?? array();
        if (!is_array($profile) || !in_array($profile['strategy'] ?? '', array('native', 'aliased', 'published_seo'), true)
            || !is_array($profile['bases'] ?? null) || !isset($profile['bases'][$locale]) || !is_array($profile['dimensions'] ?? null)
            || !$profile['dimensions'] || count($profile['dimensions']) > 10) throw new \InvalidArgumentException('invalid_route_profile');
        $names = array(); $taxonomies = array();
        foreach ($profile['dimensions'] as $dimension) {
            if (!is_array($dimension) || !is_string($dimension['name'] ?? null) || !preg_match('/^[a-z0-9_-]{1,32}$/D', $dimension['name'])
                || !is_string($dimension['taxonomy'] ?? null) || !preg_match('/^[a-z0-9_-]{1,32}$/D', $dimension['taxonomy'])
                || !is_bool($dimension['required'] ?? null)
                || isset($names[$dimension['name']]) || isset($taxonomies[$dimension['taxonomy']])) throw new \InvalidArgumentException('ambiguous_dimension_schema');
            $names[$dimension['name']] = true; $taxonomies[$dimension['taxonomy']] = true;
        }
        return $profile;
    }

    private function routeUrl(array $row, array $state): string {
        $profile = $this->profile($state['profile_id'], $state['locale']);
        if ('published_seo' !== $profile['strategy']) throw new \InvalidArgumentException('published_strategy_required');
        $object = $row['object_id'] ?? null;
        if (!is_int($object) || $object < 1) throw new \InvalidArgumentException('native_page_or_template_required');
        $page = call_user_func($this->page, $object, $state['locale']);
        if (!is_array($page) || ($page['id'] ?? 0) !== $object || !is_string($page['url'] ?? null) || !$this->sameOrigin($page['url'])) {
            throw new \InvalidArgumentException('published_translated_page_required');
        }
        if ('native_page' === $row['source']) {
            $url = $this->cleanUrl($page['url']);
        } else {
            $base = $profile['bases'][$state['locale']];
            if (!is_string($base)) throw new \InvalidArgumentException('invalid_archive_base');
            $url = $this->cleanUrl($base);
            $slugs = array();
            foreach ($profile['dimensions'] as $dimension) {
                $taxonomy = $dimension['taxonomy'];
                foreach ($state['filters'][$taxonomy] ?? array() as $id) {
                    $term = call_user_func($this->term, $taxonomy, $id, $state['locale']);
                    $slug = $term['slug'] ?? '';
                    if (!is_string($slug) || '' === $slug || preg_match('~[/\\\\?#\s]~u', rawurldecode($slug))) throw new \InvalidArgumentException('invalid_translated_slug');
                    $slug = rawurlencode(rawurldecode($slug));
                    if (isset($slugs[$slug])) throw new \InvalidArgumentException('ambiguous_dimension_slug');
                    $slugs[$slug] = true;
                    // Include dimension labels to keep optional dimensions unambiguous.
                    $url .= rawurlencode($dimension['name']) . '/' . $slug . '/';
                }
            }
            $url = $this->cleanUrl($url);
            if ($url === $this->cleanUrl($page['url'])) throw new \InvalidArgumentException('virtual_template_path_collision');
        }
        $collisions = call_user_func($this->collision, $url, 'native_page' === $row['source'] ? $object : 0);
        if (!is_array($collisions) || $collisions) throw new \InvalidArgumentException('native_url_collision');
        return $url;
    }

    private function cleanUrl(string $url): string {
        if (strlen($url) > 2048 || !$this->sameOrigin($url)) throw new \InvalidArgumentException('foreign_or_invalid_url');
        $parts = parse_url($url);
        // Tracking parameters are discarded; pagination/sort/filter parameters
        // must never silently collapse into an indexable first-page canonical.
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach ($query as $key => $value) {
                if (!is_scalar($value) || !(0 === strpos($key, 'utm_') || in_array($key, array('gclid', 'fbclid'), true))) throw new \InvalidArgumentException('unsupported_route_query');
            }
        }
        $path = $parts['path'] ?? '/';
        if (preg_match('/%(?![0-9a-f]{2})/i', $path)) throw new \InvalidArgumentException('invalid_path_encoding');
        $segments = array();
        foreach (explode('/', trim($path, '/')) as $segment) {
            $segment = rawurldecode($segment);
            if ('' === $segment || '.' === $segment || '..' === $segment || preg_match('#[/\\\\?\x00-\x20\x7f%]#', $segment)) throw new \InvalidArgumentException('unsafe_route_path');
            $segments[] = rawurlencode($segment);
        }
        if (in_array(strtolower($segments[0] ?? ''), array('wp-admin', 'wp-json', 'wp-content', 'wp-includes', 'feed'), true)) throw new \InvalidArgumentException('reserved_route_path');
        return $this->origin . '/' . implode('/', $segments) . '/';
    }

    private function sameOrigin(string $url): bool {
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) return false;
        if (!isset($parts['host']) && !isset($parts['scheme'])) return 0 === strpos($url, '/') && 0 !== strpos($url, '//');
        $origin = parse_url($this->origin);
        return strtolower($parts['scheme'] ?? '') === strtolower($origin['scheme'])
            && strtolower($parts['host'] ?? '') === strtolower($origin['host'])
            && ($parts['port'] ?? ('https' === ($parts['scheme'] ?? '') ? 443 : 80)) === ($origin['port'] ?? ('https' === $origin['scheme'] ? 443 : 80));
    }
}
