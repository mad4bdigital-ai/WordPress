<?php
namespace ETG\DynamicFilterSEOBridge\IndexFlow;

/** Semantic identity is independent of transport, term order and translated IDs. */
final class NormalizedFilterState {
    private $termResolver;

    /** Resolver returns the real localized term and its default-language ID. */
    public function __construct(callable $termResolver) {
        $this->termResolver = $termResolver;
    }

    public function normalize(array $state): array {
        $out = array();
        if (array_diff(array_keys($state), array('profile_id', 'provider', 'query_id', 'locale', 'filters', 'identity'))) {
            throw new \InvalidArgumentException('unsupported_filter_state');
        }
        foreach (array('profile_id', 'provider', 'query_id', 'locale') as $key) {
            $value = $state[$key] ?? null;
            if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $value)) {
                throw new \InvalidArgumentException('invalid_state_' . $key);
            }
            $out[$key] = $value;
        }
        $filters = $state['filters'] ?? null;
        if (!is_array($filters) || !$filters || count($filters) > 10) {
            throw new \InvalidArgumentException('invalid_filter_set');
        }
        $out['filters'] = array();
        $semantic = array();
        foreach ($filters as $taxonomy => $ids) {
            if (!is_string($taxonomy) || !preg_match('/^[a-z0-9_-]{1,32}$/D', $taxonomy) || !is_array($ids) || !$ids || count($ids) > 20) {
                throw new \InvalidArgumentException('invalid_taxonomy_state');
            }
            $terms = array();
            foreach ($ids as $id) {
                if (!is_int($id) || $id < 1) throw new \InvalidArgumentException('term_id_required');
                $term = call_user_func($this->termResolver, $taxonomy, $id, $out['locale']);
                if (!is_array($term) || ($term['term_id'] ?? null) !== $id
                    || !is_int($term['semantic_id'] ?? null) || $term['semantic_id'] < 1
                    || !is_string($term['slug'] ?? null) || '' === $term['slug']) {
                    throw new \InvalidArgumentException('translation_mapping_unavailable');
                }
                $terms[$term['semantic_id']] = $id;
            }
            ksort($terms, SORT_NUMERIC);
            $out['filters'][$taxonomy] = array_values($terms);
            $semantic[$taxonomy] = array_keys($terms);
        }
        ksort($out['filters'], SORT_STRING);
        ksort($semantic, SORT_STRING);
        $out['identity'] = hash('sha256', json_encode(array($out['profile_id'], $out['provider'], $out['query_id'], $semantic)));
        return $out;
    }
}
