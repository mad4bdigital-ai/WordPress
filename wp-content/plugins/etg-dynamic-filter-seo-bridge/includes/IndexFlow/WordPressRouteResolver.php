<?php
namespace ETG\DynamicFilterSEOBridge\IndexFlow;

/** Exact rewrite projection, prepared for separately certified activation. */
final class WordPressRouteResolver {
    private $architecture;
    private $sitePath;
    public function __construct(ManagedUrlArchitecture $architecture, string $sitePath = '/') {
        $this->architecture = $architecture;
        $this->sitePath = trim(rawurldecode($sitePath), '/');
    }

    public function queryVars(array $vars): array {
        if (!in_array('indexflow_route', $vars, true)) $vars[] = 'indexflow_route';
        return $vars;
    }

    public function rewritePlan(): array {
        $out = array();
        foreach ($this->architecture->preview()['routes'] as $preview) {
            if ('published' !== $preview['status'] || 'virtual' !== $preview['source']) continue;
            $row = $this->architecture->lookup($preview['identity'], $preview['locale']);
            $path = trim(rawurldecode((string)parse_url($row['url'], PHP_URL_PATH)), '/');
            if ('' !== $this->sitePath) {
                if (0 !== strpos($path, $this->sitePath . '/')) throw new \InvalidArgumentException('route_outside_wordpress_path');
                $path = substr($path, strlen($this->sitePath) + 1);
            }
            // No catch-all, no arbitrary combination, no flush during requests.
            $out[] = array('regex' => '^' . preg_quote($path, '#') . '/?$',
                'query' => 'index.php?page_id=' . $row['object_id'] . '&indexflow_route=' . rawurlencode($row['id']),
                'position' => 'top', 'route_id' => $row['id']);
        }
        return array('revision' => $this->architecture->revision(), 'rules' => $out, 'activation_authorized' => false);
    }

    /** Query variables alone cannot select a route on an unrelated URL. */
    public function resolveRequest(string $requestUri, string $routeId): array {
        $row = $this->architecture->resolve($requestUri);
        if ('' === $routeId || ($row['id'] ?? '') !== $routeId || 'published' !== ($row['status'] ?? '')) {
            throw new \InvalidArgumentException('route_request_binding_failed');
        }
        return $row;
    }
}
